<?php

namespace App\Libraries\Dnsbl;

use App\Libraries\OpcjeMcs;
use App\Libraries\Siec\As198050Matcher;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Skaner DNSBL dla IPv4 AS198050 (plugin dnsbl, E1).
 *
 * Resolver: lokalny unbound (mcs.dnsbl.skanerResolver, domyślnie 127.0.0.1) — nie systemowy resolv.conf.
 * Zapisuje mcs_dnsbl_skany / _wyniki / _listingi. E3: zdarzenia nowy/zdjęty (mcs_dnsbl_zdarzenia),
 * incydenty dla nowych listingów stref o wysokiej wadze (ZmianySkanu), retencja wyników.
 * Tryb „pary” (recheck): pytamy tylko o pary ip+strefa z aktywnych listingów.
 */
class Skaner
{
    private BaseConnection $db;
    private ?SilnikZapytan $silnik;

    /** @var callable(string): void|null */
    private $log;

    public function __construct(?BaseConnection $db = null, ?SilnikZapytan $silnik = null, ?callable $log = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->silnik = $silnik;
        $this->log    = $log;
    }

    /**
     * @return array{resolver: string, qps: int, w_locie: int, timeout_ms: int, retry: int, cache_ttl: int, blokada_min: int}
     */
    public static function ustawienia(): array
    {
        $int = static function (string $k, int $dom): int {
            try {
                return OpcjeMcs::int($k);
            } catch (\Throwable) {
                return (int) env($k, $dom);
            }
        };
        $resolver = trim((string) env('mcs.dnsbl.skanerResolver', '127.0.0.1'));
        if (filter_var($resolver, FILTER_VALIDATE_IP) === false) {
            $resolver = '127.0.0.1';
        }

        return [
            'resolver'    => $resolver,
            'qps'         => max(1, $int('mcs.dnsbl.skanerQpsStrefa', 10)),
            'w_locie'     => max(1, $int('mcs.dnsbl.skanerWLocie', 32)),
            'timeout_ms'  => max(200, $int('mcs.dnsbl.skanerTimeoutMs', 2000)),
            'retry'       => max(0, $int('mcs.dnsbl.skanerRetry', 1)),
            'cache_ttl'   => max(0, $int('mcs.dnsbl.skanerCacheTtl', 21600)),
            'blokada_min' => max(0, $int('mcs.dnsbl.skanerBlokadaMin', 15)),
        ];
    }

    private function silnik(): SilnikZapytan
    {
        if ($this->silnik === null) {
            $u            = self::ustawienia();
            $this->silnik = new SilnikZapytan($u['resolver'], 53, (float) $u['qps'], $u['w_locie'], $u['timeout_ms'] / 1000, $u['retry']);
        }

        return $this->silnik;
    }

    /**
     * Prefiksy IPv4 z as198050_cidry.
     *
     * @return list<string>
     */
    public static function prefiksyAs(): array
    {
        return array_values(array_filter(
            (new As198050Matcher())->cidryIpv4(),
            static fn ($c) => ! str_contains((string) $c, ':'),
        ));
    }

    /**
     * Strefy aktywne w rejestrze, z gotowym sufiksem zapytania.
     *
     * @return list<array<string, mixed>>
     */
    public function strefy(?string $filtr = null): array
    {
        $b = $this->db->table('mcs_dnsbl_strefy')->where('aktywna', true)->orderBy('kolejnosc', 'ASC');
        if ($filtr !== null && $filtr !== '') {
            $b->where('kod', $filtr);
        }
        $out = [];
        foreach ($b->get()->getResultArray() as $r) {
            $r['informacyjna'] = in_array($r['informacyjna'], [true, 't', '1', 1], true);
            $r['mapa_kodow']   = is_string($r['mapa_kodow']) ? (json_decode($r['mapa_kodow'], true) ?: []) : [];
            $r['sufiks']       = (string) $r['strefa'];
            $r['brak_klucza']  = '';
            if (($r['wymaga_klucza'] ?? '') === 'spamrats') {
                $klucz = trim((string) env('mcs.spamrats.apiKey', ''));
                if ($klucz === '' || preg_match('/^[A-Za-z0-9]+$/', $klucz) !== 1) {
                    $r['brak_klucza'] = 'brak mcs.spamrats.apiKey w .env';
                } else {
                    $r['sufiks'] = $klucz . '.' . $r['strefa'];
                }
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param list<string> $cidry
     */
    public function utworzSkan(array $cidry, ?string $strefa, string $tryb, string $uzytkownik): int
    {
        $this->db->table('mcs_dnsbl_skany')->insert([
            'zakres'       => implode(',', $cidry),
            'strefa_filtr' => $strefa !== '' ? $strefa : null,
            'tryb'         => $tryb,
            'status'       => 'oczekuje',
            'uzytkownik'   => mb_substr($uzytkownik, 0, 255),
            'utworzono_at' => gmdate('Y-m-d H:i:sP'),
            'liczba_ip'    => count(ZakresSkanu::adresy($cidry)),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Skan „recheck”: tylko pary ip+strefa (klucz = IP albo sieć /24) z aktywnych listingów.
     *
     * @param list<array{kod: string, ip: string}> $pary
     */
    public function utworzSkanPar(array $pary, string $tryb, string $uzytkownik): int
    {
        $ips = [];
        foreach ($pary as $p) {
            $ips[self::ipZapytania($p['ip'])] = true;
        }
        $this->db->table('mcs_dnsbl_skany')->insert([
            'zakres'       => implode(',', array_map(static fn ($ip) => $ip . '/32', array_keys($ips))),
            'strefa_filtr' => null,
            'tryb'         => $tryb,
            'status'       => 'oczekuje',
            'uzytkownik'   => mb_substr($uzytkownik, 0, 255),
            'utworzono_at' => gmdate('Y-m-d H:i:sP'),
            'liczba_ip'    => count($ips),
            'pary'         => json_encode(array_values($pary), JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Aktywne listingi jako pary do recheck (tylko aktywne strefy).
     *
     * @return list<array{kod: string, ip: string}>
     */
    public function aktywnePary(): array
    {
        $aktywne = array_column($this->db->table('mcs_dnsbl_strefy')->select('kod')->where('aktywna', true)->get()->getResultArray(), 'kod');
        if ($aktywne === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('mcs_dnsbl_listingi')->select('ip, strefa_kod')->where('aktywny', true)
            ->whereIn('strefa_kod', $aktywne)->orderBy('ip')->get()->getResultArray() as $r) {
            $out[] = ['kod' => (string) $r['strefa_kod'], 'ip' => (string) $r['ip']];
        }

        return $out;
    }

    /** Klucz listingu (IP albo a.b.c.0/24) → IP użyte w zapytaniu. */
    public static function ipZapytania(string $klucz): string
    {
        return str_contains($klucz, '/') ? (string) strstr($klucz, '/', true) : $klucz;
    }

    /**
     * Uruchamia `mcs:dnsbl-skan --skan-id=N` w tle (setsid). Nie czeka na koniec.
     */
    public static function uruchomWTle(int $id): void
    {
        $php = trim((string) env('mcs.dnsbl.phpCli', '/usr/bin/php'));
        $log = WRITEPATH . 'logs/mcs-dnsbl-skan.log';
        $cmd = sprintf(
            '/usr/bin/setsid -f %s %s mcs:dnsbl-skan --skan-id=%d >> %s 2>&1 < /dev/null',
            escapeshellarg($php),
            escapeshellarg(ROOTPATH . 'spark'),
            $id,
            escapeshellarg($log),
        );
        exec($cmd, $wyj, $kod);
        if ($kod !== 0) {
            throw new \RuntimeException('Nie uruchomiono skanu w tle (kod ' . $kod . ').');
        }
    }

    /**
     * Plan bez zapytań o IP (dla --dry-run).
     *
     * @param list<string> $cidry
     *
     * @return array{ip: int, sieci24: int, zapytan: int, szac_s: int, strefy: list<string>}
     */
    public function plan(array $cidry, ?string $strefa): array
    {
        $ips  = ZakresSkanu::adresy($cidry);
        $s24  = count(array_unique(array_map([DnsblNarzedzia::class, 'siec24'], $ips)));
        $zap  = 0;
        $max  = 0;
        $kody = [];
        foreach ($this->strefy($strefa) as $s) {
            if ($s['brak_klucza'] !== '') {
                continue;
            }
            $n     = $s['typ'] === 'siec24' ? $s24 : count($ips);
            $zap  += $n + 2;
            $max   = max($max, $n);
            $kody[] = (string) $s['kod'];
        }
        $qps = self::ustawienia()['qps'];

        return ['ip' => count($ips), 'sieci24' => $s24, 'zapytan' => $zap, 'szac_s' => (int) ceil($max / $qps), 'strefy' => $kody];
    }

    /**
     * Testy kontrolne stref (127.0.0.2 / test_ip listed, 127.0.0.1 czysty).
     *
     * @param list<array<string, mixed>> $strefy
     *
     * @return array<string, array{ok: bool, powod: string}>
     */
    public function testyKontrolne(array $strefy): array
    {
        $zad = [];
        foreach ($strefy as $s) {
            if ($s['brak_klucza'] !== '') {
                continue;
            }
            $zad[] = ['klucz' => 't+|' . $s['kod'], 'grupa' => (string) $s['kod'], 'nazwa' => DnsblNarzedzia::odwrocOktety((string) $s['test_ip']) . '.' . $s['sufiks'], 'typ' => DnsPakiet::TYP_A];
            $zad[] = ['klucz' => 't-|' . $s['kod'], 'grupa' => (string) $s['kod'], 'nazwa' => '1.0.0.127.' . $s['sufiks'], 'typ' => DnsPakiet::TYP_A];
        }
        $w   = $this->silnik()->wykonaj($zad);
        $out = [];
        foreach ($strefy as $s) {
            if ($s['brak_klucza'] !== '') {
                $out[$s['kod']] = ['ok' => false, 'powod' => $s['brak_klucza']];

                continue;
            }
            $out[$s['kod']] = DnsblNarzedzia::testKontrolny(
                $w['t+|' . $s['kod']] ?? SilnikZapytan::wynik('blad', null, [], [], 'brak wyniku'),
                $w['t-|' . $s['kod']] ?? SilnikZapytan::wynik('blad', null, [], [], 'brak wyniku'),
            );
        }

        return $out;
    }

    /**
     * Pełny przebieg skanu dla wiersza mcs_dnsbl_skany.
     *
     * @return array<string, mixed>
     */
    public function wykonaj(int $skanId): array
    {
        $skan = $this->db->table('mcs_dnsbl_skany')->where('id', $skanId)->get()->getRowArray();
        if (! is_array($skan)) {
            throw new \RuntimeException('Brak skanu #' . $skanId);
        }
        if ($skan['status'] !== 'oczekuje') {
            throw new \RuntimeException('Skan #' . $skanId . ' ma status ' . $skan['status'] . ' (oczekiwano: oczekuje).');
        }

        $t0 = microtime(true);
        $this->aktualizuj($skanId, ['status' => 'w_toku', 'start_at' => gmdate('Y-m-d H:i:sP'), 'heartbeat_at' => gmdate('Y-m-d H:i:sP')]);

        try {
            return $this->przebieg($skanId, $skan, $t0);
        } catch (\Throwable $e) {
            $this->aktualizuj($skanId, [
                'status'    => 'blad',
                'koniec_at' => gmdate('Y-m-d H:i:sP'),
                'czas_ms'   => (int) ((microtime(true) - $t0) * 1000),
                'komunikat' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $skan
     *
     * @return array<string, mixed>
     */
    private function przebieg(int $skanId, array $skan, float $t0): array
    {
        $pary   = self::dekodujPary($skan['pary'] ?? null);
        $strefy = $this->strefy($skan['strefa_filtr'] ?? null);
        /** @var array<string, array<string, string>>|null $paryKod kod → [klucz → IP zapytania] */
        $paryKod = null;
        $sieci   = [];
        if ($pary !== null) {
            $paryKod = [];
            $ips     = [];
            foreach ($pary as $p) {
                $ipZap                          = self::ipZapytania($p['ip']);
                $paryKod[$p['kod']][$p['ip']]   = $ipZap;
                $ips[$ipZap]                    = $ipZap;
                if (str_contains($p['ip'], '/')) {
                    $sieci[$p['ip']] = $ipZap;
                }
            }
            $ips    = array_values($ips);
            $strefy = array_values(array_filter($strefy, static fn ($s) => isset($paryKod[$s['kod']])));
        } else {
            $cidry = array_values(array_filter(explode(',', (string) $skan['zakres'])));
            $ips   = ZakresSkanu::adresy($cidry);
            // klucz zapytania /24 → IP użyte w zapytaniu (pierwsze z zakresu w tej sieci)
            foreach ($ips as $ip) {
                $s24 = DnsblNarzedzia::siec24($ip);
                if (! isset($sieci[$s24])) {
                    $sieci[$s24] = $ip;
                }
            }
        }
        if ($strefy === []) {
            throw new \RuntimeException('Brak aktywnych stref w mcs_dnsbl_strefy.');
        }

        $info = [];
        foreach ($strefy as $s) {
            $info[$s['kod']] = [
                'kod'          => (string) $s['kod'],
                'nazwa'        => (string) $s['nazwa'],
                'strefa'       => (string) $s['strefa'],
                'typ'          => (string) $s['typ'],
                'informacyjna' => (bool) $s['informacyjna'],
                'url'          => (string) $s['url_delisting'],
                'status'       => 'ok',
                'powod'        => '',
                'listed'       => 0,
                'bledy'        => 0,
                'czyste'       => 0,
                'zapytan'      => 0,
            ];
        }

        $this->loguj('testy kontrolne: ' . count($strefy) . ' stref');
        foreach ($this->testyKontrolne($strefy) as $kod => $t) {
            if (! $t['ok']) {
                $info[$kod]['status'] = 'wylaczona_test';
                $info[$kod]['powod']  = $t['powod'];
                $this->loguj('strefa ' . $kod . ' WYŁĄCZONA: ' . $t['powod']);
            }
        }

        $zad = [];
        /** @var array<string, array<string, true>> $zapytano strefa => klucze, które zaplanowano do odpytania */
        $zapytano = [];
        foreach ($strefy as $s) {
            if ($info[$s['kod']]['status'] !== 'ok') {
                continue;
            }
            if ($paryKod !== null) {
                $klucze = $paryKod[$s['kod']] ?? [];
            } else {
                $klucze = $s['typ'] === 'siec24' ? $sieci : array_combine($ips, $ips);
            }
            foreach ($klucze as $kluczIp => $ipZap) {
                $zad[] = [
                    'klucz' => $s['kod'] . '|' . $kluczIp,
                    'grupa' => (string) $s['kod'],
                    'nazwa' => DnsblNarzedzia::odwrocOktety($ipZap) . '.' . $s['sufiks'],
                    'typ'   => DnsPakiet::TYP_A,
                ];
                $info[$s['kod']]['zapytan']++;
                $zapytano[$s['kod']][$kluczIp] = true;
            }
        }
        $this->aktualizuj($skanId, ['zapytan_plan' => count($zad), 'liczba_ip' => count($ips)]);
        $this->loguj(sprintf('zapytania: %d (IP=%d, /24=%d)', count($zad), count($ips), count($sieci)));

        /** @var array<string, array<string, array{kody: list<string>, txt: string}>> $listed */
        $listed = [];
        /** @var array<string, array<string, string>> $bledy */
        $bledy = [];
        /** @var array<string, array<string, true>> $czyste */
        $czyste    = [];
        $wykonano  = 0;
        $ostatniHb = microtime(true);
        $silnik    = $this->silnik();

        $silnik->wykonaj($zad, function (string $klucz, array $odp, array $z) use (&$info, &$listed, &$bledy, &$czyste, &$wykonano, &$ostatniHb, $silnik, $skanId): void {
            [$kod, $kluczIp] = explode('|', $klucz, 2);
            $wykonano++;
            $k = DnsblNarzedzia::klasyfikujA($odp);
            switch ($k['status']) {
                case DnsblNarzedzia::CZYSTY:
                    $czyste[$kod][$kluczIp] = true;
                    break;

                case DnsblNarzedzia::LISTED:
                    $listed[$kod][$kluczIp] = ['kody' => $k['kody'], 'txt' => ''];
                    break;

                case DnsblNarzedzia::BLAD_STREFY:
                    $bledy[$kod][$kluczIp] = $k['blad'];
                    if ($info[$kod]['status'] === 'ok') {
                        $info[$kod]['status'] = 'przerwana';
                        $info[$kod]['powod']  = $k['blad'];
                        $silnik->anulujGrupe($kod);
                        $this->loguj('strefa ' . $kod . ' PRZERWANA: ' . $k['blad']);
                    }
                    break;

                default:
                    $bledy[$kod][$kluczIp] = $k['blad'];
            }
            if (microtime(true) - $ostatniHb >= 1.0) {
                $ostatniHb = microtime(true);
                $this->aktualizuj($skanId, ['zapytan_wykonano' => $wykonano, 'heartbeat_at' => gmdate('Y-m-d H:i:sP')]);
            }
        });

        // TXT tylko dla listowanych
        $zadTxt = [];
        $sufiksy = array_column($strefy, 'sufiks', 'kod');
        foreach ($listed as $kod => $wpisy) {
            foreach ($wpisy as $kluczIp => $_) {
                $ipZap = str_contains($kluczIp, '/') ? $sieci[$kluczIp] : $kluczIp;
                $zadTxt[] = ['klucz' => $kod . '|' . $kluczIp, 'grupa' => $kod, 'nazwa' => DnsblNarzedzia::odwrocOktety($ipZap) . '.' . $sufiksy[$kod], 'typ' => DnsPakiet::TYP_TXT];
            }
        }
        if ($zadTxt !== []) {
            $this->loguj('TXT dla listowanych: ' . count($zadTxt));
            foreach ($silnik->wykonaj($zadTxt) as $klucz => $odp) {
                [$kod, $kluczIp] = explode('|', $klucz, 2);
                if ($odp['status'] === 'odp' && $odp['txt'] !== []) {
                    $listed[$kod][$kluczIp]['txt'] = mb_substr(implode(' | ', $odp['txt']), 0, 1000);
                }
            }
        }

        $teraz = gmdate('Y-m-d H:i:sP');
        $this->db->transStart();

        $wiersze = [];
        foreach ($listed as $kod => $wpisy) {
            foreach ($wpisy as $kluczIp => $w) {
                $wiersze[] = ['skan_id' => $skanId, 'ip' => $kluczIp, 'strefa_kod' => $kod, 'status' => 'listed', 'kody' => implode(',', $w['kody']), 'txt' => $w['txt'], 'blad' => ''];
            }
        }
        foreach ($bledy as $kod => $wpisy) {
            foreach ($wpisy as $kluczIp => $msg) {
                $wiersze[] = ['skan_id' => $skanId, 'ip' => $kluczIp, 'strefa_kod' => $kod, 'status' => 'blad', 'kody' => '', 'txt' => null, 'blad' => mb_substr($msg, 0, 255)];
            }
        }
        foreach ($info as $kod => $i) {
            if ($i['status'] === 'wylaczona_test') {
                $wiersze[] = ['skan_id' => $skanId, 'ip' => '*', 'strefa_kod' => $kod, 'status' => 'blad', 'kody' => '', 'txt' => null, 'blad' => mb_substr('test kontrolny: ' . $i['powod'], 0, 255)];
            }
        }
        foreach (array_chunk($wiersze, 500) as $partia) {
            $this->db->table('mcs_dnsbl_wyniki')->insertBatch($partia);
        }

        // stan bieżący — tylko strefy, które przeszły cały skan
        $zakresKluczy = array_fill_keys($ips, true) + array_fill_keys(array_keys($sieci), true);
        $zmianyNowe   = [];
        $zmianyZdjete = [];
        foreach ($info as $kod => $i) {
            if ($i['status'] !== 'ok') {
                continue;
            }
            $kluczeStrefy = $paryKod !== null ? ($paryKod[$kod] ?? []) : $zakresKluczy;
            $stare = [];
            foreach ($this->db->table('mcs_dnsbl_listingi')->select('ip')->where('strefa_kod', $kod)->where('aktywny', true)->get()->getResultArray() as $r) {
                if (isset($kluczeStrefy[$r['ip']])) {
                    $stare[] = (string) $r['ip'];
                }
            }
            $d = DnsblNarzedzia::diff($stare, array_keys($listed[$kod] ?? []), array_keys($czyste[$kod] ?? []));
            foreach ($d['nowe'] as $kluczIp) {
                $zmianyNowe[] = ['kod' => (string) $kod, 'ip' => (string) $kluczIp, 'kody' => implode(',', $listed[$kod][$kluczIp]['kody']), 'txt' => (string) $listed[$kod][$kluczIp]['txt']];
            }
            foreach ($d['zdjete'] as $kluczIp) {
                $zmianyZdjete[] = ['kod' => (string) $kod, 'ip' => (string) $kluczIp];
            }
            foreach (array_merge($d['nowe'], $d['utrzymane']) as $kluczIp) {
                $w = $listed[$kod][$kluczIp];
                $t = $this->db->prefixTable('mcs_dnsbl_listingi');
                $this->db->query(
                    'INSERT INTO ' . $t . ' (ip, strefa_kod, kody, txt, pierwszy_raz, ostatni_raz, aktywny, skan_id)
                     VALUES (?, ?, ?, ?, ?, ?, TRUE, ?)
                     ON CONFLICT (ip, strefa_kod) DO UPDATE SET
                       kody = EXCLUDED.kody, txt = EXCLUDED.txt, ostatni_raz = EXCLUDED.ostatni_raz, skan_id = EXCLUDED.skan_id,
                       pierwszy_raz = CASE WHEN ' . $t . '.aktywny THEN ' . $t . '.pierwszy_raz ELSE EXCLUDED.pierwszy_raz END,
                       aktywny = TRUE',
                    [$kluczIp, $kod, implode(',', $w['kody']), $w['txt'], $teraz, $teraz, $skanId],
                );
            }
            foreach (array_chunk($d['zdjete'], 500) as $partia) {
                $this->db->table('mcs_dnsbl_listingi')->where('strefa_kod', $kod)->whereIn('ip', $partia)
                    ->update(['aktywny' => false, 'skan_id' => $skanId]);
            }
            $this->loguj(sprintf('strefa %s: nowe=%d utrzymane=%d zdjete=%d', $kod, count($d['nowe']), count($d['utrzymane']), count($d['zdjete'])));
        }

        // podsumowanie
        $ipListed = [];
        $ipBlad   = [];
        $liczBledy = 0;
        foreach ($info as $kod => &$i) {
            $i['listed'] = count($listed[$kod] ?? []);
            $i['bledy']  = count($bledy[$kod] ?? []);
            $i['czyste'] = count($czyste[$kod] ?? []);
            $liczBledy  += $i['bledy'];
            if ($i['informacyjna']) {
                continue;
            }
            foreach (array_keys($listed[$kod] ?? []) as $ip) {
                $ipListed[$ip] = true;
            }
            foreach (array_keys($bledy[$kod] ?? []) as $ip) {
                $ipBlad[$ip] = true;
            }
        }
        unset($i);
        // „Czyste” = potwierdzone NXDOMAIN w każdej odpytywanej strefie (poza informacyjnymi i /24).
        // Strefa przerwana w trakcie skanu nie potwierdziła pozostałych IP, więc nie są czyste.
        $czysteIp = 0;
        foreach ($ips as $ip) {
            if (isset($ipListed[$ip]) || isset($ipBlad[$ip])) {
                continue;
            }
            $potwierdzone = true;
            foreach ($info as $kod => $i) {
                if ($i['informacyjna'] || $i['typ'] === 'siec24' || ! isset($zapytano[$kod][$ip])) {
                    continue;
                }
                if (! isset($czyste[$kod][$ip])) {
                    $potwierdzone = false;
                    break;
                }
            }
            if ($potwierdzone) {
                $czysteIp++;
            }
        }

        $wynik = [
            'status'           => 'zakonczony',
            'koniec_at'        => gmdate('Y-m-d H:i:sP'),
            'heartbeat_at'     => gmdate('Y-m-d H:i:sP'),
            'czas_ms'          => (int) ((microtime(true) - $t0) * 1000),
            'zapytan_wykonano' => $wykonano,
            'listowane'        => count($ipListed),
            'czyste'           => $czysteIp,
            'bledy'            => $liczBledy,
            'strefy_json'      => json_encode(array_values($info), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $this->aktualizuj($skanId, $wynik);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Błąd zapisu wyników skanu #' . $skanId);
        }

        // E3: zdarzenia + incydenty (po zapisie stanu; IncydentZapis ma własną transakcję)
        $zm = new ZmianySkanu($this->db);
        $e3 = $zm->zapisz($skanId, $zmianyNowe, $zmianyZdjete);
        $this->aktualizuj($skanId, [
            'nowe'      => $e3['nowe'],
            'zdjete'    => $e3['zdjete'],
            'incydenty' => $e3['incydenty'],
            'fala'      => $e3['fala'],
            'komunikat' => $e3['fala'] ? sprintf('Możliwy błąd skanu: %d nowych listingów (> %d). Incydenty pominięte.', $e3['nowe'], ZmianySkanu::PROG_FALI) : null,
        ]);
        $this->loguj(sprintf('E3: nowe=%d zdjete=%d incydenty=%d%s', $e3['nowe'], $e3['zdjete'], $e3['incydenty'], $e3['fala'] ? ' FALA (incydenty pominięte)' : ''));
        $usun = $zm->czyscRetencje(self::retencjaDni());
        if ($usun > 0) {
            $this->loguj('retencja: usunięto ' . $usun . ' wierszy mcs_dnsbl_wyniki');
        }

        $wynik           = array_merge($wynik, $e3);
        $wynik['strefy'] = array_values($info);
        $wynik['liczba_ip'] = count($ips);

        return $wynik;
    }

    public static function retencjaDni(): int
    {
        try {
            return max(0, OpcjeMcs::int('mcs.dnsbl.skanerRetencjaDni'));
        } catch (\Throwable) {
            return max(0, (int) env('mcs.dnsbl.skanerRetencjaDni', 90));
        }
    }

    /**
     * @return list<array{kod: string, ip: string}>|null
     */
    public static function dekodujPary(mixed $v): ?array
    {
        if (is_string($v) && $v !== '') {
            $v = json_decode($v, true);
        }
        if (! is_array($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $p) {
            if (is_array($p) && isset($p['kod'], $p['ip'])) {
                $out[] = ['kod' => (string) $p['kod'], 'ip' => (string) $p['ip']];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $dane
     */
    private function aktualizuj(int $skanId, array $dane): void
    {
        $this->db->table('mcs_dnsbl_skany')->where('id', $skanId)->update($dane);
    }

    private function loguj(string $msg): void
    {
        if ($this->log !== null) {
            ($this->log)($msg);
        }
    }
}
