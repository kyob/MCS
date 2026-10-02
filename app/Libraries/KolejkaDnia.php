<?php

namespace App\Libraries;

/**
 * Preset „kolejka dnia”: ostatnie 48h, waga high+ (critical włącznie via ranga modelu).
 */
final class KolejkaDnia
{
    public const OKNO_DNI = 2;

    public const MIN_WAGA = 'high';

    /**
     * Okno kolejki w dniach (opcja mcs.kolejkaDnia.oknoDni; domyślnie OKNO_DNI).
     */
    public static function oknoDni(): int
    {
        $okno = \App\Libraries\OpcjeMcs::int('mcs.kolejkaDnia.oknoDni');

        return in_array($okno, DashboardFiltry::OKNA, true) ? $okno : self::OKNO_DNI;
    }

    /**
     * Minimalna waga (opcja mcs.kolejkaDnia.minWaga; domyślnie MIN_WAGA).
     */
    public static function minWaga(): string
    {
        $waga = \App\Libraries\OpcjeMcs::tekst('mcs.kolejkaDnia.minWaga');

        return in_array($waga, ['low', 'medium', 'high', 'critical'], true) ? $waga : self::MIN_WAGA;
    }

    /**
     * @return array{
     *   okno: int,
     *   zrodlo: null,
     *   ip: null,
     *   minWaga: string,
     *   minZrodel: int,
     *   kategoria: null,
     *   port: null
     * }
     */
    public function filtryBazowe(): array
    {
        return [
            'okno'      => self::oknoDni(),
            'zrodlo'    => null,
            'ip'        => null,
            'minWaga'   => self::minWaga(),
            'minZrodel' => 1,
            'kategoria' => null,
            'port'      => null,
        ];
    }

    public function doQuery(): string
    {
        return http_build_query([
            'okno'        => self::oknoDni(),
            'waga'        => self::minWaga(),
            'min_zrodel'  => 1,
        ]);
    }
}
