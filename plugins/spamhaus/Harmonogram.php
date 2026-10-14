<?php

namespace McsPluginy\spamhaus;

/**
 * Własny harmonogram pluginu (cron mcs-pobierz.sh woła pobierz() co 15 min).
 *
 * - po sukcesie: następne pobranie za N h (domyślnie 24) + losowa minuta 0–59,
 * - po błędzie: backoff 1 h, 2 h, 4 h … max 24 h (jak abusix: Retry-After ma pierwszeństwo, gdy dłuższy),
 * - twarda granica: nigdy częściej niż 1 próba / h (FAQ Spamhaus DROP).
 */
final class Harmonogram
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

    public static function poSukcesie(int $teraz, int $interwalGodzin, int $losowaMinuta): int
    {
        $interwalGodzin = max(1, $interwalGodzin);
        $losowaMinuta   = max(0, min(59, $losowaMinuta));

        return $teraz + $interwalGodzin * 3600 + $losowaMinuta * 60;
    }

    /**
     * @param int $bledyZRzedu licznik błędów łącznie z bieżącym (>= 1)
     */
    public static function poBledzie(int $teraz, int $bledyZRzedu, int $retryAfter = 0): int
    {
        $n     = max(1, $bledyZRzedu);
        $opozn = (int) min(self::MAX_BACKOFF_S, self::MIN_ODSTEP_S * (2 ** min(10, $n - 1)));
        $opozn = max($opozn, min(self::MAX_BACKOFF_S, max(0, $retryAfter)), self::MIN_ODSTEP_S);

        return $teraz + $opozn;
    }
}
