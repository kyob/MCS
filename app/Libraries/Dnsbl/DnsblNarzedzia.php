<?php

namespace App\Libraries\Dnsbl;

/**
 * Czyste funkcje skanera DNSBL (bez DB i sieci) — testowane w DnsblSkanerTest.
 */
final class DnsblNarzedzia
{
    public const CZYSTY      = 'czysty';
    public const LISTED      = 'listed';
    public const BLAD        = 'blad';
    public const BLAD_STREFY = 'blad_strefy';

    public static function odwrocOktety(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('To nie jest IPv4: ' . $ip);
        }

        return implode('.', array_reverse(explode('.', $ip)));
    }

    /**
     * Normalizuje CIDR / pojedyncze IP do postaci sieć/maska (np. "91.231.70.5" → "91.231.70.5/32").
     */
    public static function normalizujCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }
        if (! str_contains($cidr, '/')) {
            $cidr .= '/32';
        }
        [$adres, $maska] = explode('/', $cidr, 2);
        if (filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! ctype_digit($maska)) {
            return null;
        }
        $m = (int) $maska;
        if ($m < 0 || $m > 32) {
            return null;
        }
        $long = ip2long($adres) & self::maskaLong($m);

        return long2ip($long) . '/' . $m;
    }

    /**
     * Wszystkie adresy z CIDR (z adresem sieci i rozgłoszeniowym). Limit /16.
     *
     * @return list<string>
     */
    public static function rozwinCidr(string $cidr): array
    {
        $n = self::normalizujCidr($cidr);
        if ($n === null) {
            throw new \InvalidArgumentException('Nieprawidłowy CIDR: ' . $cidr);
        }
        [$adres, $m] = explode('/', $n);
        $m = (int) $m;
        if ($m < 16) {
            throw new \InvalidArgumentException('Za szeroki zakres (min. /16): ' . $cidr);
        }
        $start = ip2long($adres);
        $ile   = 1 << (32 - $m);
        $out   = [];
        for ($i = 0; $i < $ile; $i++) {
            $out[] = long2ip($start + $i);
        }

        return $out;
    }

    public static function cidrZawieraCidr(string $zewn, string $wewn): bool
    {
        $a = self::normalizujCidr($zewn);
        $b = self::normalizujCidr($wewn);
        if ($a === null || $b === null) {
            return false;
        }
        [$aIp, $aM] = explode('/', $a);
        [$bIp, $bM] = explode('/', $b);
        if ((int) $bM < (int) $aM) {
            return false;
        }

        return (ip2long($bIp) & self::maskaLong((int) $aM)) === ip2long($aIp);
    }

    /**
     * Sieć /24 dla IP, np. "91.231.70.0/24".
     */
    public static function siec24(string $ip): string
    {
        $o = explode('.', $ip);

        return $o[0] . '.' . $o[1] . '.' . $o[2] . '.0/24';
    }

    /**
     * Klasyfikacja odpowiedzi A z SilnikZapytan. Błąd sieci/SERVFAIL nigdy nie daje „czysty”.
     *
     * @param array{status: string, rcode: ?int, a: list<string>, txt?: list<string>, blad?: string} $odp
     *
     * @return array{status: string, kody: list<string>, blad: string}
     */
    public static function klasyfikujA(array $odp): array
    {
        if ($odp['status'] === 'timeout') {
            return ['status' => self::BLAD, 'kody' => [], 'blad' => 'timeout'];
        }
        if ($odp['status'] !== 'odp') {
            return ['status' => self::BLAD, 'kody' => [], 'blad' => (string) (($odp['blad'] ?? '') !== '' ? $odp['blad'] : $odp['status'])];
        }

        $rcode = (int) $odp['rcode'];
        if ($rcode === DnsPakiet::RCODE_NXDOMAIN) {
            return ['status' => self::CZYSTY, 'kody' => [], 'blad' => ''];
        }
        if ($rcode !== DnsPakiet::RCODE_NOERROR) {
            $nazwa = match ($rcode) {
                DnsPakiet::RCODE_SERVFAIL => 'SERVFAIL',
                DnsPakiet::RCODE_REFUSED  => 'REFUSED',
                default                   => 'RCODE ' . $rcode,
            };

            return ['status' => self::BLAD, 'kody' => [], 'blad' => $nazwa];
        }

        $kody = array_values(array_unique($odp['a']));
        sort($kody);
        if ($kody === []) {
            return ['status' => self::BLAD, 'kody' => [], 'blad' => 'NOERROR bez rekordu A (NODATA)'];
        }
        foreach ($kody as $k) {
            if (str_starts_with($k, '127.255.255.')) {
                return ['status' => self::BLAD_STREFY, 'kody' => $kody, 'blad' => 'kod błędu strefy ' . $k];
            }
        }
        foreach ($kody as $k) {
            if (! str_starts_with($k, '127.')) {
                return ['status' => self::BLAD, 'kody' => $kody, 'blad' => 'nieoczekiwana odpowiedź ' . $k];
            }
        }

        return ['status' => self::LISTED, 'kody' => $kody, 'blad' => ''];
    }

    /**
     * Test kontrolny strefy: testowe IP musi być listowane, 127.0.0.1 czysty.
     *
     * @param array<string, mixed> $odpTest
     * @param array<string, mixed> $odpCzysty
     *
     * @return array{ok: bool, powod: string}
     */
    public static function testKontrolny(array $odpTest, array $odpCzysty): array
    {
        $t = self::klasyfikujA($odpTest);
        $c = self::klasyfikujA($odpCzysty);
        if ($t['status'] !== self::LISTED) {
            return ['ok' => false, 'powod' => 'test listed: ' . $t['status'] . ($t['blad'] !== '' ? ' (' . $t['blad'] . ')' : '')];
        }
        if ($c['status'] !== self::CZYSTY) {
            return ['ok' => false, 'powod' => '127.0.0.1: ' . $c['status'] . ($c['blad'] !== '' ? ' (' . $c['blad'] . ')' : '')];
        }

        return ['ok' => true, 'powod' => ''];
    }

    /**
     * Diff stanu bieżącego jednej strefy.
     *
     * @param list<string> $aktywneStare IP z aktywnym listingiem przed skanem (w zakresie skanu)
     * @param list<string> $listowane    IP listowane w tym skanie
     * @param list<string> $czyste       IP potwierdzone jako czyste (NXDOMAIN) w tym skanie
     *
     * @return array{nowe: list<string>, utrzymane: list<string>, zdjete: list<string>}
     */
    public static function diff(array $aktywneStare, array $listowane, array $czyste): array
    {
        $stare = array_fill_keys($aktywneStare, true);
        $czy   = array_fill_keys($czyste, true);
        $nowe  = [];
        $utrz  = [];
        foreach (array_unique($listowane) as $ip) {
            if (isset($stare[$ip])) {
                $utrz[] = $ip;
            } else {
                $nowe[] = $ip;
            }
        }
        $zdjete = [];
        foreach ($aktywneStare as $ip) {
            // zdejmij tylko po potwierdzonym NXDOMAIN; błąd zostawia stan bez zmian
            if (isset($czy[$ip])) {
                $zdjete[] = $ip;
            }
        }

        return ['nowe' => $nowe, 'utrzymane' => $utrz, 'zdjete' => array_values(array_unique($zdjete))];
    }

    public static function urlDelisting(string $szablon, string $ip): string
    {
        $ip = preg_replace('#/\d+$#', '', $ip) ?? $ip;

        return str_replace('{ip}', rawurlencode($ip), $szablon);
    }

    /**
     * Link z konfiguracji (url_delisting) trafia do href: tylko http(s), inaczej „#” (blokuje javascript:).
     */
    public static function bezpiecznyUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? $url : '#';
    }

    private static function maskaLong(int $m): int
    {
        return $m === 0 ? 0 : ((0xFFFFFFFF << (32 - $m)) & 0xFFFFFFFF);
    }
}
