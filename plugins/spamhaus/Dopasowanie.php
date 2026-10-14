<?php

namespace McsPluginy\spamhaus;

/**
 * Czyste dopasowanie list Spamhaus DROP do prefiksów AS (bez DB i sieci).
 *
 * CIDR: trafienie, gdy wpis DROP i nasz prefiks nakładają się w dowolną stronę
 * (nasz ⊆ DROP albo DROP ⊆ nasz). Różne rodziny adresów (v4/v6) nigdy się nie nakładają.
 * ASN: trafienie, gdy numer ASN z asndrop = nasz ASN.
 */
final class Dopasowanie
{
    /**
     * Normalizuje CIDR do postaci "adres-sieci/maska" (małe litery, bity hosta wyzerowane).
     * Zwraca null dla błędnego wpisu. Gołe IP = /32 albo /128.
     */
    public static function normalizuj(string $cidr): ?string
    {
        $p = self::rozbierz($cidr);
        if ($p === null) {
            return null;
        }
        $adres = inet_ntop($p['bin']);

        return $adres === false ? null : strtolower($adres) . '/' . $p['maska'];
    }

    /**
     * Czy dwa CIDR-y się nakładają (w dowolną stronę).
     */
    public static function nakladajaSie(string $a, string $b): bool
    {
        return self::wezszy($a, $b) !== null;
    }

    /**
     * Węższy (bardziej szczegółowy) z dwóch nakładających się CIDR-ów; null gdy się nie nakładają.
     */
    public static function wezszy(string $a, string $b): ?string
    {
        $pa = self::rozbierz($a);
        $pb = self::rozbierz($b);
        if ($pa === null || $pb === null || strlen($pa['bin']) !== strlen($pb['bin'])) {
            return null;
        }
        $krotsza = min($pa['maska'], $pb['maska']);
        if (self::maskuj($pa['bin'], $krotsza) !== self::maskuj($pb['bin'], $krotsza)) {
            return null;
        }
        $w = $pa['maska'] >= $pb['maska'] ? $pa : $pb;
        $adres = inet_ntop($w['bin']);

        return $adres === false ? null : strtolower($adres) . '/' . $w['maska'];
    }

    /**
     * @param list<array{cidr: string, sblid?: string}> $wpisy  wpisy z drop_v4/drop_v6
     * @param list<string>                              $nasze  prefiksy AS (mcs_ustawienia.as198050_cidry)
     *
     * @return list<array{wpis: string, sblid: string, nasz_prefiks: string, zakres: string, podmiot: string}>
     */
    public static function dopasujCidry(array $wpisy, array $nasze): array
    {
        $out = [];
        foreach ($wpisy as $w) {
            $drop = self::normalizuj((string) ($w['cidr'] ?? ''));
            if ($drop === null) {
                continue;
            }
            foreach ($nasze as $nasz) {
                $naszN = self::normalizuj((string) $nasz);
                if ($naszN === null) {
                    continue;
                }
                $zakres = self::wezszy($drop, $naszN);
                if ($zakres === null) {
                    continue;
                }
                $out[] = [
                    'wpis'         => $drop,
                    'sblid'        => (string) ($w['sblid'] ?? ''),
                    'nasz_prefiks' => $naszN,
                    'zakres'       => $zakres,
                    'podmiot'      => explode('/', $zakres, 2)[0],
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $wpisy z asndrop.json
     *
     * @return array<string, mixed>|null wpis asndrop dla naszego ASN
     */
    public static function dopasujAsn(array $wpisy, int $asn): ?array
    {
        foreach ($wpisy as $w) {
            $raw = $w['asn'] ?? null;
            if (is_string($raw)) {
                $raw = preg_replace('/^AS/i', '', trim($raw));
            }
            if ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw === $asn) {
                return $w;
            }
        }

        return null;
    }

    /**
     * @return array{bin: string, maska: int}|null
     */
    private static function rozbierz(string $cidr): ?array
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
