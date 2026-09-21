<?php

namespace App\Libraries;

/**
 * Role MCS: superadmin (dawny admin) · admin · operator.
 */
final class Rola
{
    public const SUPERADMIN = 'superadmin';
    public const ADMIN      = 'admin';
    public const OPERATOR   = 'operator';

    /** @var list<string> */
    public const WSZYSTKIE = [self::SUPERADMIN, self::ADMIN, self::OPERATOR];

    /** @var array<string, string> */
    private const ETYKIETY = [
        self::SUPERADMIN => 'Superadmin',
        self::ADMIN      => 'Admin',
        self::OPERATOR   => 'Operator',
    ];

    public static function jestDozwolona(string $rola): bool
    {
        return in_array($rola, self::WSZYSTKIE, true);
    }

    public static function etykieta(string $rola): string
    {
        return self::ETYKIETY[$rola] ?? $rola;
    }

    public static function mozePanelAdmin(string $rola): bool
    {
        return $rola === self::SUPERADMIN || $rola === self::ADMIN;
    }

    public static function mozeUzytkownicy(string $rola): bool
    {
        return $rola === self::SUPERADMIN;
    }
}
