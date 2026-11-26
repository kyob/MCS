<?php

namespace McsPluginy\threatfox;

/**
 * Czyste dopasowanie IOC ThreatFox (pojedyncze adresy IP) do prefiksów AS (bez DB i sieci).
 *
 * Trafienie tylko dla ioc_type ip / ip:port, gdy wyciągnięty IP należy do któregoś
 * z naszych prefiksów (mcs_ustawienia.as_cidry). Inne typy IOC i cudze IP — bez trafień.
 * Różne rodziny adresów (v4/v6) nigdy się nie nakładają.
 */
final class Dopasowanie
{
    /**
     * Czy adres IP należy do CIDR (ta sama rodzina adresów).
     */
    public static function ipWCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $p = self::rozbierzCidr($cidr);
        if ($p === null) {
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== strlen($p['bin'])) {
            return false;
        }

        return self::maskuj($bin, $p['maska']) === $p['bin'];
    }

    /**
     * Normalizuje CIDR do postaci "adres-sieci/maska" (małe litery, bity hosta wyzerowane).
     * Zwraca null dla błędnego wpisu. Gołe IP = /32 albo /128.
     */
    public static function normalizujCidr(string $cidr): ?string
    {
        $p = self::rozbierzCidr($cidr);
        if ($p === null) {
            return null;
        }
        $adres = inet_ntop($p['bin']);

        return $adres === false ? null : strtolower($adres) . '/' . $p['maska'];
    }

    /**
     * @param list<array<string, mixed>> $rekordy z Parser::parsuj (pole ip może być null)
     * @param list<string>               $nasze   prefiksy AS (mcs_ustawienia.as_cidry)
     *
     * @return array<string, array<string, mixed>> klucz = ioc|nasz_prefiks; wartość = rekord + nasz_prefiks
     */
    public static function dopasujIps(array $rekordy, array $nasze): array
    {
        $out = [];
        foreach ($rekordy as $r) {
            $ip = $r['ip'] ?? null;
            if (! is_string($ip) || $ip === '') {
                continue;
            }
            foreach ($nasze as $nasz) {
                $naszN = self::normalizujCidr((string) $nasz);
                if ($naszN === null) {
                    continue;
                }
                if (! self::ipWCidr($ip, $naszN)) {
                    continue;
                }
                $out[$r['ioc'] . '|' . $naszN] = $r + ['nasz_prefiks' => $naszN];
            }
        }

        return $out;
    }

    /**
     * @return array{bin: string, maska: int}|null
     */
    private static function rozbierzCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }
        if (str_contains($cidr, '/')) {
            [$adres, $maska] = explode('/', $cidr, 2);
            if (! ctype_digit($maska)) {
                return null;
            }
        } else {
            $adres = $cidr;
            $maska = null;
        }
        if (filter_var($adres, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($adres);
        if ($bin === false) {
            return null;
        }
        $max = strlen($bin) * 8;
        $m   = $maska === null ? $max : (int) $maska;
        if ($m < 0 || $m > $max) {
            return null;
        }

        return ['bin' => self::maskuj($bin, $m), 'maska' => $m];
    }

    private static function maskuj(string $bin, int $maska): string
    {
        $out = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $bity = max(0, min(8, $maska - $i * 8));
            $m    = $bity === 0 ? 0 : (0xFF << (8 - $bity)) & 0xFF;
            $out .= chr(ord($bin[$i]) & $m);
        }

        return $out;
    }
}
