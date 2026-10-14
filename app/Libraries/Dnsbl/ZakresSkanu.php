<?php

namespace App\Libraries\Dnsbl;

/**
 * Walidacja zakresu skanu: tylko IPv4 z mcs_ustawienia.as198050_cidry, maska /21–/32.
 */
final class ZakresSkanu
{
    public const MASKA_MIN = 21;
    public const MASKA_MAX = 32;

    /**
     * @param list<string> $prefiksyAs prefiksy IPv4 z as198050_cidry
     *
     * @return array{ok: bool, cidry: list<string>, blad: string}
     */
    public static function waliduj(string $wejscie, array $prefiksyAs): array
    {
        $czesci = preg_split('/[\s,;]+/', trim($wejscie)) ?: [];
        $czesci = array_values(array_filter($czesci, static fn ($c) => $c !== ''));
        if ($czesci === []) {
            return ['ok' => false, 'cidry' => [], 'blad' => 'Podaj adres IPv4 albo CIDR.'];
        }
        if (count($czesci) > 16) {
            return ['ok' => false, 'cidry' => [], 'blad' => 'Maksymalnie 16 pozycji naraz.'];
        }

        $out = [];
        foreach ($czesci as $c) {
            if (str_contains($c, ':')) {
                return ['ok' => false, 'cidry' => [], 'blad' => 'Tylko IPv4: ' . $c];
            }
            $n = DnsblNarzedzia::normalizujCidr($c);
            if ($n === null) {
                return ['ok' => false, 'cidry' => [], 'blad' => 'Nieprawidłowy adres lub CIDR: ' . $c];
            }
            $m = (int) explode('/', $n)[1];
            if ($m < self::MASKA_MIN || $m > self::MASKA_MAX) {
                return ['ok' => false, 'cidry' => [], 'blad' => 'Dozwolona maska /21–/32: ' . $c];
            }
            $nasz = false;
            foreach ($prefiksyAs as $p) {
                if (DnsblNarzedzia::cidrZawieraCidr($p, $n)) {
                    $nasz = true;
                    break;
                }
            }
            if (! $nasz) {
                return ['ok' => false, 'cidry' => [], 'blad' => 'Poza prefiksami AS198050 (as198050_cidry): ' . $c];
            }
            $out[$n] = $n;
        }

        $lista = array_values($out);
        usort($lista, static fn ($a, $b) => [ip2long(explode('/', $a)[0]), $a] <=> [ip2long(explode('/', $b)[0]), $b]);

        return ['ok' => true, 'cidry' => $lista, 'blad' => ''];
    }

    /**
     * Unikalne IP z listy CIDR, w kolejności.
     *
     * @param list<string> $cidry
     *
     * @return list<string>
     */
    public static function adresy(array $cidry): array
    {
        $out = [];
        foreach ($cidry as $c) {
            foreach (DnsblNarzedzia::rozwinCidr($c) as $ip) {
                $out[$ip] = true;
            }
        }

        return array_keys($out);
    }
}
