<?php

namespace App\Models;

use CodeIgniter\Model;

class IncydentModel extends Model
{
    protected $table            = 'mcs_incydenty';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'klucz_dedupu',
        'podmiot_typ',
        'podmiot',
        'pierwsze_widziane',
        'ostatnio_widziane',
        'waga',
        'wersja_wagi',
        'kategoria',
        'tytul',
        'port',
        'protokol',
        'ukryty_w_ui',
    ];
    protected $useTimestamps = false;

    /**
     * Lista skrótów incydentów dla podmiotu IP (strona /ip/{ip}).
     *
     * Pomija źródła z mcs_pluginy.ukryty_w_ui oraz własne ukryty_w_ui incydentu.
     * Sort: waga (critical→low), potem ostatnio_widziane malejąco.
     *
     * @return list<array<string, mixed>>
     */
    public function dlaPodmiotuIp(string $ip, ?string $zrodlo = null): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.*')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }

        $builder->orderBy(
            "CASE i.waga
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 1
                ELSE 0
            END",
            'DESC',
            false,
        );
        $builder->orderBy('i.ostatnio_widziane', 'DESC');

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    /**
     * Distinct źródła widoczne dla IP (bez filtra zrodlo) — do listy filtrów UI.
     *
     * @return list<string>
     */
    public function zrodlaDlaPodmiotuIp(string $ip): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.zrodlo')
            ->distinct()
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd()
            ->orderBy('i.zrodlo', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo'])) {
                $lista[] = $wiersz['zrodlo'];
            }
        }

        return $lista;
    }

    /** Limit kategorii w odpowiedzi badge (najczęstsze pierwsze). */
    public const BADGE_TOP_KATEGORII = 5;

    /** Limit przykładowych tytułów w odpowiedzi badge. */
    public const BADGE_PRZYKLADOW = 3;

    /**
     * Agregat pod API badge: okno ostatnio_widziane, bez źródeł/incydentów ukrytych w UI.
     *
     * @return array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }
     */
    public function agregatBadgeDlaIp(string $ip, int $oknoDni = 7): array
    {
        $mapa = $this->agregatBadgeDlaIpLista([$ip], $oknoDni);

        return $mapa[$ip] ?? $this->pustyAgregatBadge(max(1, $oknoDni));
    }

    /**
     * Batch agregat badge (jedno whereIn) — klucz = IP z listy wejściowej (kolejność zachowana).
     * IP bez wierszy w oknie dostają zerowy agregat.
     *
     * @param list<string> $ips
     * @return array<string, array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }>
     */
    public function agregatBadgeDlaIpLista(array $ips, int $oknoDni = 7): array
    {
        $oknoDni = max(1, $oknoDni);
        $puste   = $this->pustyAgregatBadge($oknoDni);

        $unikalne = [];
        foreach ($ips as $ip) {
            if (! is_string($ip)) {
                continue;
            }
            $ip = trim($ip);
            if ($ip === '' || isset($unikalne[$ip])) {
                continue;
            }
            $unikalne[$ip] = $puste;
        }

        if ($unikalne === []) {
            return [];
        }

        $od = $this->odDatyOkna($oknoDni);

        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.podmiot, i.waga, i.zrodlo, i.kategoria, i.tytul')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->whereIn('i.podmiot', array_keys($unikalne))
            ->where('i.ukryty_w_ui', false)
            ->where('i.ostatnio_widziane >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze)) {
            $wiersze = [];
        }

        $ranga = self::rangaWag();
        /**
         * @var array<string, array{
         *   liczba: int,
         *   max_ranga: int,
         *   max_waga: ?string,
         *   zrodla: array<string, true>,
         *   kategorie: array<string, int>,
         *   przyklady_kand: list<array{0: int, 1: string}>
         * }> $agg
         */
        $agg = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = isset($wiersz['podmiot']) && is_string($wiersz['podmiot']) ? $wiersz['podmiot'] : '';
            if ($podmiot === '' || ! isset($unikalne[$podmiot])) {
                continue;
            }
            if (! isset($agg[$podmiot])) {
                $agg[$podmiot] = [
                    'liczba'          => 0,
                    'max_ranga'       => 0,
                    'max_waga'        => null,
                    'zrodla'          => [],
                    'kategorie'       => [],
                    'przyklady_kand'  => [],
                ];
            }
            $agg[$podmiot]['liczba']++;
            $waga = isset($wiersz['waga']) && is_string($wiersz['waga']) ? $wiersz['waga'] : '';
            $nr   = $ranga[$waga] ?? 0;
            if ($nr > $agg[$podmiot]['max_ranga']) {
                $agg[$podmiot]['max_ranga'] = $nr;
                $agg[$podmiot]['max_waga']  = $waga !== '' ? $waga : null;
            }
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo']) && $wiersz['zrodlo'] !== '') {
                $agg[$podmiot]['zrodla'][$wiersz['zrodlo']] = true;
            }
            $kat = isset($wiersz['kategoria']) && is_string($wiersz['kategoria'])
                ? trim($wiersz['kategoria'])
                : '';
            if ($kat !== '') {
                $agg[$podmiot]['kategorie'][$kat] = ($agg[$podmiot]['kategorie'][$kat] ?? 0) + 1;
            }
            $tytul = isset($wiersz['tytul']) && is_string($wiersz['tytul'])
                ? trim($wiersz['tytul'])
                : '';
            if ($tytul !== '' && count($agg[$podmiot]['przyklady_kand']) < 40) {
                $agg[$podmiot]['przyklady_kand'][] = [$nr, $tytul];
            }
        }

        foreach ($agg as $ip => $dane) {
            $zrodlaLista = array_keys($dane['zrodla']);
            sort($zrodlaLista, SORT_STRING);

            $katCount = $dane['kategorie'];
            arsort($katCount, SORT_NUMERIC);
            $kategorieLista = array_slice(array_keys($katCount), 0, self::BADGE_TOP_KATEGORII);

            $kandydaci = $dane['przyklady_kand'];
            usort(
                $kandydaci,
                static fn (array $a, array $b): int => $b[0] <=> $a[0],
            );
            $przyklady = [];
            $widziane  = [];
            foreach ($kandydaci as $kandydat) {
                $tytul = $kandydat[1];
                if (isset($widziane[$tytul])) {
                    continue;
                }
                $widziane[$tytul] = true;
                $przyklady[]     = $tytul;
                if (count($przyklady) >= self::BADGE_PRZYKLADOW) {
                    break;
                }
            }

            $unikalne[$ip] = [
                'okno_dni'      => $oknoDni,
                'liczba'        => $dane['liczba'],
                'max_waga'      => $dane['max_waga'],
                'liczba_zrodel' => count($zrodlaLista),
                'zrodla'        => array_values($zrodlaLista),
                'kategorie'     => array_values($kategorieLista),
                'przyklady'     => $przyklady,
            ];
        }

        return $unikalne;
    }

    /**
     * @return array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }
     */
    private function pustyAgregatBadge(int $oknoDni): array
    {
        return [
            'okno_dni'      => $oknoDni,
            'liczba'        => 0,
            'max_waga'      => null,
            'liczba_zrodel' => 0,
            'zrodla'        => [],
            'kategorie'     => [],
            'przyklady'     => [],
        ];
    }

    /**
     * Widoczne źródła obecne w indeksie (do filtrów dashboardu).
     *
     * @return list<string>
     */
    public function widoczneZrodla(): array
    {
        $builder = $this->builderWidocznych()
            ->select('i.zrodlo')
            ->distinct()
            ->orderBy('i.zrodlo', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo']) && $wiersz['zrodlo'] !== '') {
                $lista[] = $wiersz['zrodlo'];
            }
        }

        return $lista;
    }

    /**
     * KPI przeglądu: okno czasowe + opcjonalny filtr źródła / IP.
     *
     * @return array{
     *   okno_dni: int,
     *   liczba_incydentow: int,
     *   liczba_ip: int,
     *   ip_wielozrodlowe: int,
     *   critical_high: int,
     *   max_waga: ?string
     * }
     */
    public function kpiPrzegladu(int $oknoDni = 7, ?string $zrodlo = null, ?string $ip = null): array
    {
        $wiersze = $this->wierszeWidoczneWOknie($oknoDni, $zrodlo, $ip, null);
        $poIp    = [];
        $ranga   = self::rangaWag();
        $maxRanga = 0;
        $maxWaga  = null;
        $criticalHigh = 0;

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            $z       = (string) ($wiersz['zrodlo'] ?? '');
            $waga    = (string) ($wiersz['waga'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if (! isset($poIp[$podmiot])) {
                $poIp[$podmiot] = [];
            }
            if ($z !== '') {
                $poIp[$podmiot][$z] = true;
            }
            $nr = $ranga[$waga] ?? 0;
            if ($nr > $maxRanga) {
                $maxRanga = $nr;
                $maxWaga  = $waga;
            }
            if ($nr >= 3) {
                $criticalHigh++;
            }
        }

        $wielozrodlowe = 0;
        foreach ($poIp as $zrodla) {
            if (count($zrodla) >= 2) {
                $wielozrodlowe++;
            }
        }

        return [
            'okno_dni'           => max(1, $oknoDni),
            'liczba_incydentow'  => count($wiersze),
            'liczba_ip'          => count($poIp),
            'ip_wielozrodlowe'   => $wielozrodlowe,
            'critical_high'      => $criticalHigh,
            'max_waga'           => $maxWaga,
        ];
    }

    /**
     * Ranking problematycznych IP (korelacja źródeł w oknie).
     *
     * Sort: liczba_zrodel DESC, max_waga DESC, liczba_incydentow DESC, ostatnio DESC.
     *
     * @return list<array{
     *   ip: string,
     *   liczba_incydentow: int,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   max_waga: ?string,
     *   ostatnio_widziane: ?string
     * }>
     */
    public function topProblematyczneIp(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $minWaga = null,
        int $minZrodel = 1,
        int $limit = 50,
    ): array {
        $wiersze   = $this->wierszeWidoczneWOknie($oknoDni, $zrodlo, $ip, $minWaga);
        $ranga     = self::rangaWag();
        $minZrodel = max(1, $minZrodel);
        $limit     = max(1, min(200, $limit));
        $poIp      = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if (! isset($poIp[$podmiot])) {
                $poIp[$podmiot] = [
                    'ip'                => $podmiot,
                    'liczba_incydentow' => 0,
                    'zrodla'            => [],
                    'max_ranga'         => 0,
                    'max_waga'          => null,
                    'ostatnio_widziane' => null,
                ];
            }
            $poIp[$podmiot]['liczba_incydentow']++;
            $z = (string) ($wiersz['zrodlo'] ?? '');
            if ($z !== '') {
                $poIp[$podmiot]['zrodla'][$z] = true;
            }
            $waga = (string) ($wiersz['waga'] ?? '');
            $nr   = $ranga[$waga] ?? 0;
            if ($nr > $poIp[$podmiot]['max_ranga']) {
                $poIp[$podmiot]['max_ranga'] = $nr;
                $poIp[$podmiot]['max_waga']  = $waga !== '' ? $waga : null;
            }
            $ostatnio = isset($wiersz['ostatnio_widziane']) ? (string) $wiersz['ostatnio_widziane'] : '';
            if ($ostatnio !== ''
                && ($poIp[$podmiot]['ostatnio_widziane'] === null
                    || $ostatnio > (string) $poIp[$podmiot]['ostatnio_widziane'])
            ) {
                $poIp[$podmiot]['ostatnio_widziane'] = $ostatnio;
            }
        }

        $lista = [];
        foreach ($poIp as $wiersz) {
            $zrodla = array_keys($wiersz['zrodla']);
            sort($zrodla);
            $liczbaZrodel = count($zrodla);
            if ($liczbaZrodel < $minZrodel) {
                continue;
            }
            $lista[] = [
                'ip'                => $wiersz['ip'],
                'liczba_incydentow' => $wiersz['liczba_incydentow'],
                'liczba_zrodel'     => $liczbaZrodel,
                'zrodla'            => $zrodla,
                'max_waga'          => $wiersz['max_waga'],
                'ostatnio_widziane' => $wiersz['ostatnio_widziane'],
                '_max_ranga'        => $wiersz['max_ranga'],
            ];
        }

        usort($lista, static function (array $a, array $b): int {
            return [$b['liczba_zrodel'], $b['_max_ranga'], $b['liczba_incydentow'], (string) $b['ostatnio_widziane']]
                <=> [$a['liczba_zrodel'], $a['_max_ranga'], $a['liczba_incydentow'], (string) $a['ostatnio_widziane']];
        });

        $lista = array_slice($lista, 0, $limit);
        foreach ($lista as &$wiersz) {
            unset($wiersz['_max_ranga']);
        }
        unset($wiersz);

        return $lista;
    }

    /**
     * Ostatnie skróty incydentów (przegląd chronologiczny).
     *
     * @return list<array<string, mixed>>
     */
    public function ostatnieIncydenty(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $minWaga = null,
        int $limit = 40,
    ): array {
        $limit   = max(1, min(200, $limit));
        $builder = $this->builderWidocznych()
            ->select('i.*')
            ->where('i.ostatnio_widziane >=', $this->odDatyOkna($oknoDni));

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }
        if ($ip !== null && $ip !== '') {
            $builder->where('i.podmiot', $ip);
        }
        $this->dolaczFiltrMinWagi($builder, $minWaga);

        $builder->orderBy('i.ostatnio_widziane', 'DESC');
        $builder->orderBy(
            "CASE i.waga
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 1
                ELSE 0
            END",
            'DESC',
            false,
        );
        $builder->limit($limit);

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    /**
     * @return array{low: int, medium: int, high: int, critical: int}
     */
    public static function rangaWag(): array
    {
        return [
            'low'      => 1,
            'medium'   => 2,
            'high'     => 3,
            'critical' => 4,
        ];
    }

    /**
     * Builder widocznych incydentów IP (bez ukrytych źródeł / wierszy).
     */
    protected function builderWidocznych()
    {
        return $this->db->table($this->table . ' AS i')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function wierszeWidoczneWOknie(
        int $oknoDni,
        ?string $zrodlo,
        ?string $ip,
        ?string $minWaga,
    ): array {
        $builder = $this->builderWidocznych()
            ->select('i.podmiot, i.zrodlo, i.waga, i.ostatnio_widziane')
            ->where('i.ostatnio_widziane >=', $this->odDatyOkna($oknoDni));

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }
        if ($ip !== null && $ip !== '') {
            $builder->where('i.podmiot', $ip);
        }
        $this->dolaczFiltrMinWagi($builder, $minWaga);

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    protected function odDatyOkna(int $oknoDni): string
    {
        $oknoDni = max(1, $oknoDni);

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param object $builder Query Builder
     */
    protected function dolaczFiltrMinWagi($builder, ?string $minWaga): void
    {
        if ($minWaga === null || $minWaga === '') {
            return;
        }
        $ranga = self::rangaWag();
        if (! isset($ranga[$minWaga])) {
            return;
        }
        $dozwolone = [];
        foreach ($ranga as $waga => $nr) {
            if ($nr >= $ranga[$minWaga]) {
                $dozwolone[] = $waga;
            }
        }
        if ($dozwolone !== []) {
            $builder->whereIn('i.waga', $dozwolone);
        }
    }
}
