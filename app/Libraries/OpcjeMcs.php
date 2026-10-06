<?php

namespace App\Libraries;

use App\Models\UstawieniaModel;

/**
 * Opcje strojenia MCS (progi, limity, TTL, timeouty, ASN, okna czasowe).
 *
 * Kolejność odczytu: wiersz w mcs_ustawienia (klucz = nazwa klucza .env) → .env → domyślna.
 * Domyślna = wartość wcześniej zahardkodowana w kodzie. Brak wiersza w DB = bez zmian.
 *
 * Sekrety, hasła, tokeny, hosty/URL-e z poświadczeniami, TLS i ścieżki plików
 * NIE są tu zdefiniowane — zostają tylko w .env (bez edycji z WWW).
 */
final class OpcjeMcs
{
    public const ZRODLO_DB       = 'db';
    public const ZRODLO_ENV      = 'env';
    public const ZRODLO_DOMYSLNA = 'domyslna';

    public const ASN_MAX = 4294967295;

    private const DOBA = 86400;

    private static ?UstawieniaModel $model = null;

    private static bool $modelWstrzykniety = false;

    /** @var array<string, string>|null */
    private static ?array $wierszeDb = null;

    /**
     * Definicje opcji. typ: int | int? (puste dozwolone) | asn | asn? | email | enum.
     *
     * @return array<string, array{grupa: string, etykieta: string, typ: string, domyslna: string, min?: int, max?: int, opcje?: list<string>, opis?: string}>
     */
    public static function definicje(): array
    {
        $http = static fn (string $grupa, int $timeout, ?int $connect): array => array_filter([
            'timeout' => ['grupa' => $grupa, 'etykieta' => 'Timeout HTTP (s)', 'typ' => 'int', 'domyslna' => (string) $timeout, 'min' => 1, 'max' => 600],
            'connect' => $connect === null ? null : ['grupa' => $grupa, 'etykieta' => 'Timeout połączenia HTTP (s)', 'typ' => 'int', 'domyslna' => (string) $connect, 'min' => 1, 'max' => 120],
        ]);
        $ttl = static fn (string $grupa, int $dom): array => [
            'grupa' => $grupa, 'etykieta' => 'Cache TTL (s, 0 = bez cache)', 'typ' => 'int', 'domyslna' => (string) $dom, 'min' => 0, 'max' => 90 * self::DOBA,
        ];
        $asnPlugin = static fn (string $grupa): array => [
            'grupa' => $grupa, 'etykieta' => 'ASN (puste = ASN globalny)', 'typ' => 'asn?', 'domyslna' => '',
        ];

        $d = [];

        // --- Sieć / AS ---
        $d['mcs.as198050.asn'] = [
            'grupa' => 'Sieć / AS', 'etykieta' => 'ASN globalny („nasze IP”, domyślny dla pluginów)', 'typ' => 'asn', 'domyslna' => '198050',
        ];
        $d['mcs.revDns.cacheTtl'] = [
            'grupa' => 'Sieć / AS', 'etykieta' => 'Reverse DNS — cache TTL (s)', 'typ' => 'int', 'domyslna' => '3600', 'min' => 60, 'max' => 7 * self::DOBA,
        ];

        // --- Przegląd / kolejka / badge ---
        $d['mcs.kolejkaDnia.oknoDni'] = [
            'grupa' => 'Przegląd i badge', 'etykieta' => 'Kolejka dnia — okno (dni)', 'typ' => 'enum', 'domyslna' => '2',
            'opcje' => array_map('strval', DashboardFiltry::OKNA),
        ];
        $d['mcs.kolejkaDnia.minWaga'] = [
            'grupa' => 'Przegląd i badge', 'etykieta' => 'Kolejka dnia — min. waga', 'typ' => 'enum', 'domyslna' => 'high',
            'opcje' => ['low', 'medium', 'high', 'critical'],
        ];
        $d['mcs.problemyIp.oknoTimelineDni'] = [
            'grupa' => 'Przegląd i badge', 'etykieta' => 'Problematyczne IP — oś czasu (dni)', 'typ' => 'int', 'domyslna' => '14', 'min' => 1, 'max' => 90,
        ];
        $d['mcs.badge.maxIpsBatch'] = [
            'grupa' => 'Przegląd i badge', 'etykieta' => 'API badge — max IP w jednym żądaniu', 'typ' => 'int', 'domyslna' => '100', 'min' => 1, 'max' => 1000,
        ];

        // --- Speedtest (dashboard + portal) ---
        $d['mcs.speedtest.minDownloadMbps'] = [
            'grupa' => 'Speedtest', 'etykieta' => 'Dashboard — domyślny min. download (Mbps)', 'typ' => 'int', 'domyslna' => '500', 'min' => 0, 'max' => 100000,
        ];
        $d['mcs.speedtest.minUploadMbps'] = [
            'grupa' => 'Speedtest', 'etykieta' => 'Dashboard — domyślny min. upload (Mbps)', 'typ' => 'int', 'domyslna' => '500', 'min' => 0, 'max' => 100000,
        ];
        $d['mcs.speedtest.topN'] = [
            'grupa' => 'Speedtest', 'etykieta' => 'Dashboard — domyślne top N', 'typ' => 'int', 'domyslna' => '20', 'min' => 1, 'max' => 100,
        ];
        $h = $http('Speedtest', 120, 15);
        $d['mcs.speedtest.httpTimeout']        = $h['timeout'];
        $d['mcs.speedtest.httpConnectTimeout'] = $h['connect'];

        // --- LMS ---
        $d['mcs.lms.httpTimeout'] = [
            'grupa' => 'LMS mapowanie', 'etykieta' => 'Timeout HTTP (s)', 'typ' => 'int', 'domyslna' => '60', 'min' => 3, 'max' => 600,
        ];
        $d['mcs.lms.cacheTtl']     = $ttl('LMS mapowanie', self::DOBA);
        $d['mcs.lms.syncPageSize'] = [
            'grupa' => 'LMS mapowanie', 'etykieta' => 'Sync — rekordów na stronę', 'typ' => 'int', 'domyslna' => '2000', 'min' => 1, 'max' => 50000,
        ];
        $d['mcs.lms.syncMaxPages'] = [
            'grupa' => 'LMS mapowanie', 'etykieta' => 'Sync — max stron', 'typ' => 'int', 'domyslna' => '100', 'min' => 1, 'max' => 10000,
        ];

        // --- Enrichment ---
        $d['mcs.abuseipdb.maxAgeInDays'] = [
            'grupa' => 'AbuseIPDB', 'etykieta' => 'maxAgeInDays', 'typ' => 'int', 'domyslna' => '90', 'min' => 1, 'max' => 365,
        ];
        $d['mcs.abuseipdb.cacheTtl']    = $ttl('AbuseIPDB', self::DOBA);
        $d['mcs.abuseipdb.httpTimeout'] = $http('AbuseIPDB', 30, null)['timeout'];

        $d['mcs.ismalicious.cacheTtl']    = $ttl('isMalicious', self::DOBA);
        $d['mcs.ismalicious.httpTimeout'] = $http('isMalicious', 30, null)['timeout'];

        $d['mcs.spamrats.cacheTtl'] = $ttl('SpamRats', self::DOBA);

        $takNie = ['tak', 'nie'];
        $d['mcs.dnsbl.listaUceprotect'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Lista UCEPROTECT Level 1', 'typ' => 'enum', 'domyslna' => 'tak', 'opcje' => $takNie,
            'opis' => 'dnsbl-1.uceprotect.net',
        ];
        $d['mcs.dnsbl.listaSpamcop'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Lista SpamCop', 'typ' => 'enum', 'domyslna' => 'tak', 'opcje' => $takNie,
            'opis' => 'bl.spamcop.net',
        ];
        $d['mcs.dnsbl.listaSpamhaus'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Lista Spamhaus DQS', 'typ' => 'enum', 'domyslna' => 'nie', 'opcje' => $takNie,
            'opis' => 'Wymaga mcs.dnsbl.spamhausDqsKey w .env (klucz komercyjny).',
        ];
        $d['mcs.dnsbl.skanLms'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Skan codzienny — IP z LMS ∩ AS', 'typ' => 'enum', 'domyslna' => 'tak', 'opcje' => $takNie,
        ];
        $d['mcs.dnsbl.skanAs'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Skan tygodniowy — całe AS (IPv4)', 'typ' => 'enum', 'domyslna' => 'tak', 'opcje' => $takNie,
        ];
        $d['mcs.dnsbl.forceZakres'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Wymuś zakres skanu', 'typ' => 'enum', 'domyslna' => 'auto',
            'opcje' => ['auto', 'lms', 'as'],
            'opis' => 'auto = wg dnia tygodnia; lms/as = zawsze ten zakres (gdy włączony).',
        ];
        $d['mcs.dnsbl.asScanWeekday'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Dzień skanu całego AS (UTC)', 'typ' => 'enum', 'domyslna' => '0',
            'opcje' => ['0', '1', '2', '3', '4', '5', '6'],
            'opis' => '0=niedziela, 1=poniedziałek, … 6=sobota (UTC).',
        ];
        $d['mcs.dnsbl.cacheTtl'] = $ttl('DNSBL', self::DOBA);
        $d['mcs.dnsbl.rateLimitQps'] = [
            'grupa' => 'DNSBL', 'etykieta' => 'Limit zapytań DNS (QPS)', 'typ' => 'int', 'domyslna' => '25', 'min' => 1, 'max' => 500,
        ];

        // --- Źródła IMAP ---
        $d['mcs.shadowserver.imapRecentLimit'] = [
            'grupa' => 'Shadowserver', 'etykieta' => 'IMAP — ile ostatnich UID gdy brak UNSEEN', 'typ' => 'int', 'domyslna' => '40', 'min' => 1, 'max' => 5000,
        ];
        $d['mcs.play.imapRecentLimit'] = [
            'grupa' => 'Play Scrubbing', 'etykieta' => 'IMAP — ile ostatnich UID gdy brak UNSEEN', 'typ' => 'int', 'domyslna' => '100', 'min' => 1, 'max' => 5000,
        ];
        $d['mcs.play.fromAddress'] = [
            'grupa' => 'Play Scrubbing', 'etykieta' => 'Adres nadawcy alertów DDoS', 'typ' => 'email', 'domyslna' => 'antyddos@play.pl',
        ];

        // --- SNDS ---
        $d['mcs.snds.lookbackDays'] = [
            'grupa' => 'SNDS', 'etykieta' => 'Ile dni wstecz', 'typ' => 'int', 'domyslna' => '3', 'min' => 1, 'max' => 90,
        ];
        $h = $http('SNDS', 60, 15);
        $d['mcs.snds.httpTimeout']        = $h['timeout'];
        $d['mcs.snds.httpConnectTimeout'] = $h['connect'];

        // --- MojeCert ---
        $d['mcs.mojecert.syncDaysBack'] = [
            'grupa' => 'MojeCert', 'etykieta' => 'Sync — dni wstecz (0 = bez limitu)', 'typ' => 'int', 'domyslna' => '14', 'min' => 0, 'max' => 3650,
        ];
        $d['mcs.mojecert.syncMaxPagesPerSubnet'] = [
            'grupa' => 'MojeCert', 'etykieta' => 'Sync — max stron na podsieć', 'typ' => 'int', 'domyslna' => '100', 'min' => 1, 'max' => 500,
        ];
        $h = $http('MojeCert', 60, 15);
        $d['mcs.mojecert.httpTimeout']        = $h['timeout'];
        $d['mcs.mojecert.httpConnectTimeout'] = $h['connect'];

        // --- CERT n6 ---
        $d['mcs.cert_n6.lookbackDays'] = [
            'grupa' => 'CERT n6', 'etykieta' => 'Pierwsze uruchomienie — dni wstecz', 'typ' => 'int', 'domyslna' => '90', 'min' => 1, 'max' => 3650,
        ];
        $h = $http('CERT n6', 120, 30);
        $d['mcs.cert_n6.httpTimeout']        = $h['timeout'];
        $d['mcs.cert_n6.httpConnectTimeout'] = $h['connect'];

        // --- Wanguard ---
        $d['mcs.wanguard.apiListLimit'] = [
            'grupa' => 'Wanguard', 'etykieta' => 'Limit listy anomalii na stronę', 'typ' => 'int', 'domyslna' => '500', 'min' => 1, 'max' => 10000,
        ];
        $d['mcs.wanguard.concurrency'] = [
            'grupa' => 'Wanguard', 'etykieta' => 'Równoległe zapytania o detale', 'typ' => 'int', 'domyslna' => '15', 'min' => 1, 'max' => 100,
        ];
        $h = $http('Wanguard', 60, 10);
        $d['mcs.wanguard.httpTimeout']        = $h['timeout'];
        $d['mcs.wanguard.httpConnectTimeout'] = $h['connect'];

        // --- Cloudflare / Qrator / Spoofer ---
        $d['mcs.cloudflare_botnet.asn'] = $asnPlugin('Cloudflare Botnet');
        $d['mcs.cloudflare_botnet.asn']['etykieta'] = 'ASN (puste = z konta CF, potem ASN globalny)';
        $h = $http('Cloudflare Botnet', 60, 10);
        $d['mcs.cloudflare_botnet.httpTimeout']        = $h['timeout'];
        $d['mcs.cloudflare_botnet.httpConnectTimeout'] = $h['connect'];

        $d['mcs.qrator.asn'] = $asnPlugin('Qrator Radar');
        $h = $http('Qrator Radar', 60, 10);
        $d['mcs.qrator.httpTimeout']        = $h['timeout'];
        $d['mcs.qrator.httpConnectTimeout'] = $h['connect'];

        $d['mcs.spoofer.asn']          = $asnPlugin('CAIDA Spoofer');
        $d['mcs.spoofer.lookbackDays'] = [
            'grupa' => 'CAIDA Spoofer', 'etykieta' => 'Tylko sesje z ostatnich N dni (puste = cała historia)', 'typ' => 'int?', 'domyslna' => '', 'min' => 1, 'max' => 3650,
        ];
        $d['mcs.spoofer.maxPages'] = [
            'grupa' => 'CAIDA Spoofer', 'etykieta' => 'Max stron API', 'typ' => 'int', 'domyslna' => '50', 'min' => 1, 'max' => 1000,
        ];
        $h = $http('CAIDA Spoofer', 60, 10);
        $d['mcs.spoofer.httpTimeout']        = $h['timeout'];
        $d['mcs.spoofer.httpConnectTimeout'] = $h['connect'];

        // --- Wazuh ---
        $d['mcs.wazuh.oknoGodzin'] = [
            'grupa' => 'Wazuh', 'etykieta' => 'Okno eksportu (h)', 'typ' => 'int', 'domyslna' => '48', 'min' => 1, 'max' => 168,
        ];
        $d['mcs.wazuh.minZdarzen'] = [
            'grupa' => 'Wazuh', 'etykieta' => 'Min. zdarzeń na IP', 'typ' => 'int', 'domyslna' => '5', 'min' => 1, 'max' => 100000,
        ];
        $d['mcs.wazuh.progHigh'] = [
            'grupa' => 'Wazuh', 'etykieta' => 'Próg wagi high (zdarzeń)', 'typ' => 'int', 'domyslna' => '20', 'min' => 1, 'max' => 100000,
        ];
        $d['mcs.wazuh.timeout'] = [
            'grupa' => 'Wazuh', 'etykieta' => 'Timeout eksportu SSH (s)', 'typ' => 'int', 'domyslna' => '60', 'min' => 5, 'max' => 600,
        ];
        $d['mcs.wazuh.connectTimeout'] = [
            'grupa' => 'Wazuh', 'etykieta' => 'SSH ConnectTimeout (s)', 'typ' => 'int', 'domyslna' => '10', 'min' => 1, 'max' => 120,
        ];

        return $d;
    }

    public static function istnieje(string $klucz): bool
    {
        return isset(self::definicje()[$klucz]);
    }

    /**
     * Efektywna wartość (surowy tekst) i jej źródło.
     *
     * @return array{wartosc: string, zrodlo: string}
     */
    public static function odczyt(string $klucz): array
    {
        $def = self::definicje()[$klucz] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Nieznana opcja MCS: ' . $klucz);
        }

        $db = self::wierszeDb();
        if (array_key_exists($klucz, $db)) {
            $wal = self::waliduj($klucz, $db[$klucz]);
            if ($wal['ok']) {
                return ['wartosc' => $wal['wartosc'], 'zrodlo' => self::ZRODLO_DB];
            }
        }

        $env = env($klucz);
        if ($env !== null) {
            if (is_bool($env)) {
                $env = $env ? '1' : '';
            }

            return ['wartosc' => trim((string) $env), 'zrodlo' => self::ZRODLO_ENV];
        }

        return ['wartosc' => $def['domyslna'], 'zrodlo' => self::ZRODLO_DOMYSLNA];
    }

    public static function tekst(string $klucz): string
    {
        return self::odczyt($klucz)['wartosc'];
    }

    /**
     * Wartość całkowita. Dla .env rzutowanie jak wcześniej `(int) env(...)`.
     */
    public static function int(string $klucz): int
    {
        return (int) self::odczyt($klucz)['wartosc'];
    }

    /**
     * Enum tak/nie (oraz 1/true/yes/on) — przełączniki w opcjach WWW.
     */
    public static function jestTak(string $klucz): bool
    {
        $v = strtolower(trim(self::tekst($klucz)));

        return in_array($v, ['tak', '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Walidacja wartości z formularza / DB wg definicji.
     *
     * @return array{ok: bool, wartosc: string, blad: string}
     */
    public static function waliduj(string $klucz, mixed $raw): array
    {
        $def = self::definicje()[$klucz] ?? null;
        if ($def === null) {
            return ['ok' => false, 'wartosc' => '', 'blad' => 'nieznana opcja'];
        }

        $v   = trim(is_scalar($raw) ? (string) $raw : '');
        $typ = $def['typ'];
        $ok  = static fn (string $w): array => ['ok' => true, 'wartosc' => $w, 'blad' => ''];
        $err = static fn (string $b): array => ['ok' => false, 'wartosc' => '', 'blad' => $b];

        if ($v === '' && str_ends_with($typ, '?')) {
            return $ok('');
        }

        switch (rtrim($typ, '?')) {
            case 'int':
                if (preg_match('/^-?\d{1,10}$/', $v) !== 1) {
                    return $err('wymagana liczba całkowita');
                }
                $n   = (int) $v;
                $min = $def['min'] ?? PHP_INT_MIN;
                $max = $def['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    return $err("zakres {$min}–{$max}");
                }

                return $ok((string) $n);

            case 'asn':
                $v = preg_replace('/^AS/i', '', $v) ?? $v;
                if (preg_match('/^\d{1,10}$/', $v) !== 1 || (int) $v < 1 || (int) $v > self::ASN_MAX) {
                    return $err('ASN: liczba 1–' . self::ASN_MAX);
                }

                return $ok((string) (int) $v);

            case 'email':
                if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                    return $err('niepoprawny adres e-mail');
                }

                return $ok(strtolower($v));

            case 'enum':
                if (! in_array($v, $def['opcje'] ?? [], true)) {
                    return $err('dozwolone: ' . implode(', ', $def['opcje'] ?? []));
                }

                return $ok($v);
        }

        return $err('nieobsługiwany typ');
    }

    /**
     * Definicje pogrupowane z efektywną wartością (do widoku).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function doWidoku(): array
    {
        $out = [];
        foreach (self::definicje() as $klucz => $def) {
            $odczyt                         = self::odczyt($klucz);
            $out[$def['grupa']][$klucz] = $def + $odczyt;
        }

        return $out;
    }

    /**
     * Zapis z formularza. Wszystko albo nic: przy błędzie walidacji nic nie zapisuje.
     * Wiersz DB powstaje tylko, gdy wartość różni się od efektywnej (albo wiersz już istnieje).
     * Klucze z $przywroc: usunięcie wiersza DB (powrót do .env / domyślnej).
     *
     * @param array<string, mixed> $wartosci
     * @param list<string>         $przywroc
     *
     * @return array{ok: bool, bledy: array<string, string>, zmienione: int}
     */
    public static function zapisz(UstawieniaModel $model, array $wartosci, array $przywroc = []): array
    {
        self::ustawModel($model);
        $db    = self::wierszeDb();
        $bledy = [];
        $plan  = [];

        foreach (self::definicje() as $klucz => $def) {
            if (in_array($klucz, $przywroc, true)) {
                if (array_key_exists($klucz, $db)) {
                    $plan[$klucz] = null;
                }

                continue;
            }
            if (! array_key_exists($klucz, $wartosci)) {
                continue;
            }
            $wal = self::waliduj($klucz, $wartosci[$klucz]);
            if (! $wal['ok']) {
                $bledy[$klucz] = $def['grupa'] . ' / ' . $def['etykieta'] . ': ' . $wal['blad'];

                continue;
            }
            $teraz = self::odczyt($klucz);
            if (array_key_exists($klucz, $db) ? $db[$klucz] !== $wal['wartosc'] : $teraz['wartosc'] !== $wal['wartosc']) {
                $plan[$klucz] = $wal['wartosc'];
            }
        }

        if ($bledy !== []) {
            return ['ok' => false, 'bledy' => $bledy, 'zmienione' => 0];
        }

        $ok = true;
        foreach ($plan as $klucz => $wartosc) {
            $wynik = $wartosc === null ? $model->delete($klucz) !== false : $model->ustaw($klucz, $wartosc);
            $ok    = $ok && $wynik;
        }
        self::resetuj();
        self::ustawModel($model);

        return ['ok' => $ok, 'bledy' => $ok ? [] : ['_' => 'błąd zapisu w mcs_ustawienia'], 'zmienione' => count($plan)];
    }

    /**
     * Testy / kontroler: wstrzyknięcie modelu (null = brak DB).
     */
    public static function ustawModel(?UstawieniaModel $model): void
    {
        self::$model             = $model;
        self::$modelWstrzykniety = true;
        self::$wierszeDb         = null;
    }

    public static function resetuj(): void
    {
        self::$model             = null;
        self::$modelWstrzykniety = false;
        self::$wierszeDb         = null;
    }

    /**
     * @return array<string, string>
     */
    private static function wierszeDb(): array
    {
        if (self::$wierszeDb !== null) {
            return self::$wierszeDb;
        }

        self::$wierszeDb = [];
        try {
            $model = self::$modelWstrzykniety ? self::$model : model(UstawieniaModel::class);
            if ($model === null) {
                return self::$wierszeDb;
            }
            foreach ($model->pobierzWiele(array_keys(self::definicje())) as $k => $v) {
                self::$wierszeDb[$k] = $v;
            }
        } catch (\Throwable) {
            // brak tabeli / DB — fallback .env / domyślna
        }

        return self::$wierszeDb;
    }
}
