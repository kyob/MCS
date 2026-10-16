<?php

namespace App\Libraries;

/**
 * Rozbija zbite komunikaty „klucz=wartość” na pary do siatki w UI.
 * Zwykłe zdania (bez co najmniej dwóch par) zostawia nietknięte.
 */
final class MetrykiTekstu
{
    /** @var array<string, string> */
    private const ETYKIETY = [
        'v4'              => 'IPv4',
        'v6'              => 'IPv6',
        'drop_v4'         => 'IPv4',
        'drop_v6'         => 'IPv6',
        'asndrop'         => 'ASN-DROP',
        'wiadomosci'      => 'Wiadomości',
        'nowe_importy'    => 'Nowe importy',
        'zdjete'          => 'Zdjęte',
        'zdjęte'          => 'Zdjęte',
        'prefiksow'       => 'Prefiksy',
        'prefiksów'       => 'Prefiksy',
        'time.min'        => 'Od',
        'nowe/zmienione'  => 'Nowe / zmienione',
        'sprawdzono'      => 'Sprawdzono',
        'listowane'       => 'Listowane',
        'incydenty'       => 'Incydenty',
        'trafienia'       => 'Trafienia',
        'zapisano'        => 'Zapisano',
        'asn'             => 'ASN',
        'api'             => 'API',
        'ip'              => 'IP',
        'ddos'            => 'DDoS',
        'orgs'            => 'Organizacje',
        'subnets'         => 'Podsieci',
        'skany'           => 'Skany',
    ];

    /**
     * @return list<array{0: string, 1: string, 2: string}>|null etykieta, wartość, klasa CSS
     */
    public static function pary(string $tekst): ?array
    {
        $wzorKlucza = '[\p{L}][\p{L}\p{N}_.\/+-]{0,40}';
        $wzorWart   = '[^\s,;|()]+(?:,(?!\s)[^\s,;|()]+)*';
        if (! preg_match_all(
            '/(?<![\p{L}\p{N}_.\/])(' . $wzorKlucza . ')=(' . $wzorWart . '(?:\s+(?!' . $wzorKlucza . '=)' . $wzorWart . ')*)/u',
            $tekst,
            $m,
            PREG_SET_ORDER,
        ) || count($m) < 2) {
            return null;
        }

        $surowe = [];
        foreach ($m as $c) {
            if (in_array(mb_strtolower($c[1]), ['date', 'data'], true)) {
                continue;
            }
            $surowe[] = [$c[1], $c[2]];
        }
        if (count($surowe) < 2) {
            return null;
        }
        $klucze = array_map(static fn (array $p): string => $p[0], $surowe);
        $drop   = in_array('v4', $klucze, true) && in_array('v6', $klucze, true);

        $pary = [];
        foreach ($surowe as [$klucz, $surowa]) {
            $etykieta = self::ETYKIETY[$klucz] ?? self::etykietaZKlucza($klucz);
            if ($drop && $klucz === 'asn') {
                $etykieta = 'ASN-DROP';
            }
            $pary[] = [$etykieta, self::formatuj($klucz, $surowa), self::klasa($klucz, $surowa)];
        }

        if (preg_match('/(?<![=\\p{L}\\p{N}_])AS(\\d{3,})(?!\\d|=)/u', $tekst, $as) === 1) {
            $pary[] = ['ASN', 'AS' . $as[1], 'font-monospace'];
        }

        return $pary;
    }

    private static function etykietaZKlucza(string $klucz): string
    {
        $tekst = str_replace('_', ' ', $klucz);

        return mb_strtoupper(mb_substr($tekst, 0, 1)) . mb_substr($tekst, 1);
    }

    private static function formatuj(string $klucz, string $surowa): string
    {
        if (in_array($klucz, ['asn', 'ip'], true)) {
            return $surowa;
        }
        if (str_contains($surowa, ',')) {
            return str_replace(',', ' · ', $surowa);
        }
        if (preg_match('/^\d+$/', $surowa) === 1) {
            return number_format((int) $surowa, 0, ',', "\u{00A0}");
        }

        return $surowa;
    }

    private static function klasa(string $klucz, string $surowa): string
    {
        $klasy = [];
        if (in_array($klucz, ['ip', 'zakres', 'strefy'], true) || preg_match('/^\d+\.\d+/', $surowa) === 1) {
            $klasy[] = 'font-monospace';
        }
        $n = preg_match('/^\d+$/', $surowa) === 1 ? (int) $surowa : null;
        if ($n !== null && $n > 0 && in_array($klucz, ['trafienia', 'nowe', 'listed', 'incydenty', 'ddos', 'bledy', 'błędy'], true)) {
            $klasy[] = 'text-danger';
        }
        if ($n !== null && $n > 0 && in_array($klucz, ['zdjete', 'zdjęte'], true)) {
            $klasy[] = 'text-success';
        }
        if ($surowa === 'tak' && in_array($klucz, ['listed', 'malicious', 'dyna', 'noptr', 'spam', 'auth'], true)) {
            $klasy[] = 'text-danger';
        }

        return implode(' ', $klasy);
    }
}
