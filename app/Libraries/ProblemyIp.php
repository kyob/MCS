<?php

namespace App\Libraries;

use App\Models\IncydentModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Agregacja incydentów IP do „problemów” (źródło+tytuł+port+protokół) + status + mini-timeline.
 */
class ProblemyIp
{
    public const OKNO_TIMELINE_DNI = 14;

    private BaseConnection $db;
    private EtykietyUi $etykiety;

    public function __construct(
        ?BaseConnection $db = null,
        ?EtykietyUi $etykiety = null,
    ) {
        $this->db       = $db ?? Database::connect();
        $this->etykiety = $etykiety ?? new EtykietyUi();
    }

    /**
     * @param list<array<string, mixed>> $incydenty wiersze z IncydentModel::dlaPodmiotuIp
     *
     * @return array{
     *   problemy: list<array<string, mixed>>,
     *   status: array{
     *     max_waga: string,
     *     liczba_problemow: int,
     *     top_tytuly: list<string>
     *   }
     * }
     */
    public function zbuduj(string $ip, array $incydenty): array
    {
        $ip = trim($ip);
        if ($ip === '' || $incydenty === []) {
            return [
                'problemy' => [],
                'status'   => [
                    'max_waga'         => '',
                    'liczba_problemow' => 0,
                    'top_tytuly'       => [],
                ],
            ];
        }

        $grupy = [];
        foreach ($incydenty as $inc) {
            $id = (int) ($inc['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $klucz = $this->kluczGrupy($inc);
            if (! isset($grupy[$klucz])) {
                $grupy[$klucz] = [
                    'klucz'             => $klucz,
                    'zrodlo'            => trim((string) ($inc['zrodlo'] ?? '')),
                    'tytul'             => (string) ($inc['tytul'] ?? ''),
                    'port'              => $inc['port'] ?? null,
                    'protokol'          => $inc['protokol'] ?? null,
                    'kategoria'         => trim((string) ($inc['kategoria'] ?? '')),
                    'waga'              => trim((string) ($inc['waga'] ?? '')),
                    'pierwsze_widziane' => (string) ($inc['pierwsze_widziane'] ?? $inc['ostatnio_widziane'] ?? ''),
                    'ostatnio_widziane' => (string) ($inc['ostatnio_widziane'] ?? ''),
                    'czlonkowie'        => [],
                ];
            }

            $g = &$grupy[$klucz];
            $g['czlonkowie'][] = [
                'id'                => $id,
                'waga'              => trim((string) ($inc['waga'] ?? '')),
                'ostatnio_widziane' => (string) ($inc['ostatnio_widziane'] ?? ''),
                'pierwsze_widziane' => (string) ($inc['pierwsze_widziane'] ?? ''),
                'url'               => site_url('ip/' . $ip . '/incydent/' . $id),
            ];

            $g['waga'] = $this->maxWaga($g['waga'], trim((string) ($inc['waga'] ?? '')));
            if ($this->porownajDaty(
                (string) ($inc['ostatnio_widziane'] ?? ''),
                $g['ostatnio_widziane'],
            ) > 0) {
                $g['ostatnio_widziane'] = (string) ($inc['ostatnio_widziane'] ?? '');
            }
            $pierwsze = (string) ($inc['pierwsze_widziane'] ?? $inc['ostatnio_widziane'] ?? '');
            if ($g['pierwsze_widziane'] === ''
                || ($pierwsze !== '' && $this->porownajDaty($pierwsze, $g['pierwsze_widziane']) < 0)
            ) {
                $g['pierwsze_widziane'] = $pierwsze;
            }

            $kat = trim((string) ($inc['kategoria'] ?? ''));
            if ($g['kategoria'] === '' && $kat !== '') {
                $g['kategoria'] = $kat;
            }

            unset($g);
        }

        $wszystkieIds = [];
        foreach ($grupy as $g) {
            foreach ($g['czlonkowie'] as $c) {
                $wszystkieIds[] = $c['id'];
            }
        }
        $dniAktywne = $this->dniAktywneObserwacji($wszystkieIds, self::OKNO_TIMELINE_DNI);

        $problemy = [];
        foreach ($grupy as $g) {
            $port     = $g['port'];
            $proto    = $g['protokol'];
            $portTekst = ($port !== null && $port !== '')
                ? (string) $port . ($proto ? '/' . $proto : '')
                : '—';

            $ids = array_map(static fn (array $c): int => $c['id'], $g['czlonkowie']);
            $timeline = $this->miniTimeline(
                $dniAktywne,
                $ids,
                self::OKNO_TIMELINE_DNI,
                $g['czlonkowie'],
            );

            usort(
                $g['czlonkowie'],
                static function (array $a, array $b): int {
                    return strcmp($b['ostatnio_widziane'], $a['ostatnio_widziane']);
                },
            );

            $problemy[] = [
                'klucz'              => $g['klucz'],
                'zrodlo'             => $g['zrodlo'],
                'zrodlo_etykieta'    => $this->etykiety->zrodlo($g['zrodlo']),
                'waga'               => $g['waga'],
                'kategoria'          => $g['kategoria'],
                'kategoria_etykieta' => $this->etykiety->kategoria($g['kategoria']),
                'tytul'              => $g['tytul'],
                'port_tekst'         => $portTekst,
                'liczba_zdarzen'     => count($g['czlonkowie']),
                'pierwsze_widziane'  => $g['pierwsze_widziane'],
                'ostatnio_widziane'  => $g['ostatnio_widziane'],
                'timeline'           => $timeline,
                'czlonkowie'         => $g['czlonkowie'],
            ];
        }

        $ranga = IncydentModel::rangaWag();
        usort(
            $problemy,
            static function (array $a, array $b) use ($ranga): int {
                $ra = $ranga[$a['waga']] ?? 0;
                $rb = $ranga[$b['waga']] ?? 0;
                if ($ra !== $rb) {
                    return $rb <=> $ra;
                }

                return strcmp($b['ostatnio_widziane'], $a['ostatnio_widziane']);
            },
        );

        return [
            'problemy' => $problemy,
            'status'   => $this->statusZProblemow($problemy),
        ];
    }

    /**
     * @param array<string, mixed> $inc
     */
    public function kluczGrupy(array $inc): string
    {
        $zrodlo = trim((string) ($inc['zrodlo'] ?? ''));
        $tytul  = trim((string) ($inc['tytul'] ?? ''));
        $port   = $inc['port'] ?? null;
        $proto  = strtolower(trim((string) ($inc['protokol'] ?? '')));
        $portS  = ($port === null || $port === '') ? '' : (string) (int) $port;

        return $zrodlo . "\0" . $tytul . "\0" . $portS . "\0" . $proto;
    }

    /**
     * @param list<array<string, mixed>> $problemy
     *
     * @return array{max_waga: string, liczba_problemow: int, top_tytuly: list<string>}
     */
    public function statusZProblemow(array $problemy): array
    {
        if ($problemy === []) {
            return [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ];
        }

        $maxWaga = '';
        $ranga   = IncydentModel::rangaWag();
        foreach ($problemy as $p) {
            $w = (string) ($p['waga'] ?? '');
            if (($ranga[$w] ?? 0) > ($ranga[$maxWaga] ?? 0)) {
                $maxWaga = $w;
            }
        }

        $top = [];
        foreach (array_slice($problemy, 0, 2) as $p) {
            $t = trim((string) ($p['tytul'] ?? ''));
            if ($t !== '') {
                $top[] = $t;
            }
        }

        return [
            'max_waga'         => $maxWaga,
            'liczba_problemow' => count($problemy),
            'top_tytuly'       => $top,
        ];
    }

    private function maxWaga(string $a, string $b): string
    {
        $ranga = IncydentModel::rangaWag();
        $ra    = $ranga[$a] ?? 0;
        $rb    = $ranga[$b] ?? 0;

        return $rb >= $ra ? ($b !== '' ? $b : $a) : $a;
    }

    private function porownajDaty(string $a, string $b): int
    {
        return strcmp($a, $b);
    }

    /**
     * @param list<int> $incydentIds
     *
     * @return array<int, array<string, true>> incydent_id => [Y-m-d => true]
     */
    private function dniAktywneObserwacji(array $incydentIds, int $oknoDni): array
    {
        $mapa = [];
        foreach ($incydentIds as $id) {
            $mapa[$id] = [];
        }
        if ($incydentIds === []) {
            return $mapa;
        }

        $od = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->modify('-' . ($oknoDni - 1) . ' days')
            ->format('Y-m-d');

        $wiersze = $this->db->table('mcs_obserwacje')
            ->select('incydent_id, zaobserwowano_at')
            ->whereIn('incydent_id', $incydentIds)
            ->where('zaobserwowano_at >=', $od)
            ->get()
            ->getResultArray();

        if (! is_array($wiersze)) {
            return $mapa;
        }

        foreach ($wiersze as $w) {
            $iid = (int) ($w['incydent_id'] ?? 0);
            if (! isset($mapa[$iid])) {
                continue;
            }
            $at = (string) ($w['zaobserwowano_at'] ?? '');
            if ($at === '') {
                continue;
            }
            $dzien = substr($at, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dzien) === 1) {
                $mapa[$iid][$dzien] = true;
            }
        }

        // Fallback: jeśli brak obserwacji w oknie, użyj ostatnio_widziane z incydentów (już w grupie).
        return $mapa;
    }

    /**
     * @param array<int, array<string, true>> $dniAktywne
     * @param list<int>                       $ids
     * @param list<array<string, mixed>>      $czlonkowie
     *
     * @return list<array{dzien: string, aktywny: bool}>
     */
    private function miniTimeline(array $dniAktywne, array $ids, int $oknoDni, array $czlonkowie): array
    {
        $union = [];
        foreach ($ids as $id) {
            foreach ($dniAktywne[$id] ?? [] as $dzien => $_) {
                $union[$dzien] = true;
            }
        }

        // Fallback: daty widzialności incydentu, gdy brak obserwacji w oknie.
        foreach ($czlonkowie as $c) {
            foreach (['ostatnio_widziane', 'pierwsze_widziane'] as $pole) {
                $at = (string) ($c[$pole] ?? '');
                if ($at === '') {
                    continue;
                }
                $dzien = substr($at, 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dzien) === 1) {
                    $union[$dzien] = true;
                }
            }
        }

        $timeline = [];
        $dzis     = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $od       = $dzis->modify('-' . ($oknoDni - 1) . ' days')->format('Y-m-d');
        for ($i = $oknoDni - 1; $i >= 0; $i--) {
            $dzien = $dzis->modify('-' . $i . ' days')->format('Y-m-d');
            $timeline[] = [
                'dzien'   => $dzien,
                'aktywny' => isset($union[$dzien]) && $dzien >= $od,
            ];
        }

        return $timeline;
    }
}
