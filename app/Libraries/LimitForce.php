<?php

namespace App\Libraries;

use CodeIgniter\Throttle\ThrottlerInterface;

/**
 * Limit odświeżeń z pominięciem cache (force=1) dla płatnych API enrichmentu.
 *
 * Kubełek tokenów (Throttler CI4): pojemność = limit na godzinę, odnawianie równomierne.
 * Osobno na użytkownika (UI, POST z sesji) i globalnie dla API z tokenem serwerowym.
 */
class LimitForce
{
    public const OKNO = 3600;

    private ThrottlerInterface $throttler;

    public function __construct(?ThrottlerInterface $throttler = null)
    {
        $this->throttler = $throttler ?? service('throttler');
    }

    public function pozwolUzytkownik(string $email): bool
    {
        return $this->throttler->check(
            'mcsforce_u_' . md5(strtolower(trim($email))),
            max(1, OpcjeMcs::int('mcs.force.limitUzytkownik')),
            self::OKNO,
        );
    }

    public function pozwolApi(): bool
    {
        return $this->throttler->check(
            'mcsforce_api',
            max(1, OpcjeMcs::int('mcs.force.limitApi')),
            self::OKNO,
        );
    }

    /**
     * Sekundy do odnowienia jednego tokenu (nagłówek Retry-After).
     */
    public static function retryAfter(int $limit): int
    {
        return max(1, (int) ceil(self::OKNO / max(1, $limit)));
    }
}
