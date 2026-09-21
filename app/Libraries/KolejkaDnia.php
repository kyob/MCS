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
            'okno'      => self::OKNO_DNI,
            'zrodlo'    => null,
            'ip'        => null,
            'minWaga'   => self::MIN_WAGA,
            'minZrodel' => 1,
            'kategoria' => null,
            'port'      => null,
        ];
    }

    public function doQuery(): string
    {
        return http_build_query([
            'okno'        => self::OKNO_DNI,
            'waga'        => self::MIN_WAGA,
            'min_zrodel'  => 1,
        ]);
    }
}
