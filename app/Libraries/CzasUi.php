<?php

namespace App\Libraries;

use DateTimeImmutable;
use Throwable;

/**
 * Krótka data i „ile temu” po polsku — do tabel ops.
 */
final class CzasUi
{
    public static function dataGodzina(string $at): string
    {
        $dt = self::parsuj($at);
        if ($dt === null) {
            return '';
        }

        return $dt->format('Y-m-d H:i');
    }

    public static function wzglednie(string $at, ?int $terazTs = null): string
    {
        $dt = self::parsuj($at);
        if ($dt === null) {
            return '';
        }

        $teraz = $terazTs === null
            ? new DateTimeImmutable('now')
            : (new DateTimeImmutable('@' . $terazTs))->setTimezone($dt->getTimezone());

        $sek = $teraz->getTimestamp() - $dt->getTimestamp();
        if ($sek < 0) {
            $sek = 0;
        }

        if ($sek < 60) {
            return $sek . ' s temu';
        }

        $min = intdiv($sek, 60);
        if ($min < 60) {
            return $min . ' min temu';
        }

        $godz = intdiv($min, 60);
        if ($godz < 24) {
            return $godz . ' godz. temu';
        }

        $dni = intdiv($godz, 24);

        return $dni === 1 ? '1 dzień temu' : $dni . ' dni temu';
    }

    private static function parsuj(string $at): ?DateTimeImmutable
    {
        $at = trim($at);
        if ($at === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($at);
        } catch (Throwable) {
            return null;
        }
    }
}
