<?php

namespace App\Libraries;

/**
 * Harmonogram importerów wołanych co 15 min, które same ograniczają się do 1 próby / h.
 * Kopia reguł Spamhaus bez ruszania plugins/spamhaus/Harmonogram.php.
 * Po sukcesie następny przebieg za godzinę. Po błędzie backoff 1 h, 2 h, 4 h … max 24 h.
 */
final class ImportHarmonogram
{
    public const MIN_ODSTEP_S = 3600;

    public const MAX_BACKOFF_S = 86400;

    public static function czyPora(int $teraz, ?int $nastepnePo, ?int $ostatniaProba): bool
    {
        if ($ostatniaProba !== null && $teraz - $ostatniaProba < self::MIN_ODSTEP_S) {
            return false;
        }

        return $nastepnePo === null || $teraz >= $nastepnePo;
    }

    public static function poSukcesie(int $teraz): int
    {
        return $teraz + self::MIN_ODSTEP_S;
    }

    /**
     * @param int $bledyZRzedu licznik błędów łącznie z bieżącym (>= 1)
     */
    public static function poBledzie(int $teraz, int $bledyZRzedu): int
    {
        $n     = max(1, $bledyZRzedu);
        $opozn = (int) min(self::MAX_BACKOFF_S, self::MIN_ODSTEP_S * (2 ** min(10, $n - 1)));

        return $teraz + max($opozn, self::MIN_ODSTEP_S);
    }
}
