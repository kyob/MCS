<?php

namespace App\Libraries\Watchlista;

use App\Libraries\Siec\As198050Matcher;
use App\Models\WatchlistaModel;
use Config\Database;

/**
 * Digest operacyjny: high|critical dla IP z watchlisty (tylko INFO — bez blokad).
 */
final class DigestSerwis
{
    private WatchlistaModel $watchlista;

    private As198050Matcher $matcher;

    /** @var \CodeIgniter\Database\BaseConnection|null */
    private $db;

    public function __construct(
        ?WatchlistaModel $watchlista = null,
        ?As198050Matcher $matcher = null,
        $db = null,
    ) {
        $this->watchlista = $watchlista ?? model(WatchlistaModel::class);
        $this->matcher    = $matcher ?? new As198050Matcher();
        $this->db         = $db;
    }

    /**
     * @return array{
     *   okno_godzin: int,
     *   tylko_as198050: bool,
     *   liczba_ip_watchlista: int,
     *   wygenerowano_at: string,
     *   incydenty: list<array<string, mixed>>
     * }
     */
    public function zbierz(int $oknoGodzin = 24, bool $tylkoAs198050 = false): array
    {
        $oknoGodzin = max(1, min(168, $oknoGodzin));
        $wszystkie  = $this->watchlista->listaIp();
        $ips        = $wszystkie;

        if ($tylkoAs198050) {
            $ips = array_values(array_filter(
                $ips,
                fn (string $ip): bool => $this->matcher->czyNaszeIp($ip),
            ));
        }

        $incydenty = $ips === [] ? [] : $this->pobierzHighCritical($ips, $oknoGodzin);

        return [
            'okno_godzin'          => $oknoGodzin,
            'tylko_as198050'       => $tylkoAs198050,
            'liczba_ip_watchlista' => count($wszystkie),
            'wygenerowano_at'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
            'incydenty'            => $incydenty,
        ];
    }

    /**
     * @param array{
     *   okno_godzin: int,
     *   tylko_as198050: bool,
     *   liczba_ip_watchlista: int,
     *   wygenerowano_at: string,
     *   incydenty: list<array<string, mixed>>
     * } $wynik
     */
    public function formatujTekst(array $wynik): string
    {
        $linie   = [];
        $linie[] = '=== Digest operacyjny MCS (INFO — bez auto-blokad) ===';
        $linie[] = sprintf(
            'Wygenerowano: %s | okno: %dh | IP na watchliście: %d | filtr AS198050: %s',
            $wynik['wygenerowano_at'],
            $wynik['okno_godzin'],
            $wynik['liczba_ip_watchlista'],
            $wynik['tylko_as198050'] ? 'tak' : 'nie',
        );
        $linie[] = '';

        $lista = $wynik['incydenty'];
        if ($lista === []) {
            $linie[] = 'Brak nowych incydentów high|critical dla obserwowanych IP w oknie.';
            $linie[] = '';
            $linie[] = 'Koniec digesta.';

            return implode("\n", $linie);
        }

        $linie[] = sprintf('Znaleziono: %d (high|critical)', count($lista));
        $linie[] = '';

        foreach ($lista as $i => $w) {
            $nr      = $i + 1;
            $linie[] = sprintf(
                '%d. [%s] %s — %s / %s',
                $nr,
                strtoupper((string) ($w['waga'] ?? '')),
                (string) ($w['podmiot'] ?? ''),
                (string) ($w['zrodlo'] ?? ''),
                (string) ($w['tytul'] ?? ''),
            );
            $linie[] = sprintf(
                '   ostatnio: %s | kategoria: %s | /ip/%s',
                (string) ($w['ostatnio_widziane'] ?? ''),
                (string) ($w['kategoria'] ?? ''),
                (string) ($w['podmiot'] ?? ''),
            );
        }

        $linie[] = '';
        $linie[] = 'Koniec digesta (tylko informacja — żadnych automatycznych blokad).';

        return implode("\n", $linie);
    }

    /**
     * @param list<string> $ips
     *
     * @return list<array<string, mixed>>
     */
    private function pobierzHighCritical(array $ips, int $oknoGodzin): array
    {
        $od = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoGodzin} hours")
            ->format('Y-m-d H:i:s');

        $db = $this->db ?? Database::connect();

        $builder = $db->table('mcs_incydenty AS i')
            ->select('i.id, i.podmiot, i.zrodlo, i.waga, i.tytul, i.kategoria, i.ostatnio_widziane')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->whereIn('i.podmiot', $ips)
            ->whereIn('i.waga', ['high', 'critical'])
            ->where('i.ostatnio_widziane >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd()
            ->orderBy(
                "CASE i.waga WHEN 'critical' THEN 4 WHEN 'high' THEN 3 ELSE 0 END",
                'DESC',
                false,
            )
            ->orderBy('i.ostatnio_widziane', 'DESC');

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }
}
