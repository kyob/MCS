<?php

namespace App\Libraries;

use CodeIgniter\Throttle\ThrottlerInterface;

/**
 * Limit prób logowania: osobny kubełek tokenów dla adresu IP i dla e-maila.
 *
 * E-mail: 5 prób na 10 minut (Throttler CI4: 5 tokenów, odnawianie 1 token / 120 s).
 * IP: 20 prób na 10 minut. Wyższy limit, bo wielu pracowników może wychodzić
 * przez jeden adres NAT.
 * Liczone są wszystkie próby (także udane), żeby nie ujawniać, czy konto istnieje.
 */
class LimitLogowania
{
    public const PROBY    = 5;
    public const PROBY_IP = 20;
    public const OKNO     = 600;

    private ThrottlerInterface $throttler;

    public function __construct(?ThrottlerInterface $throttler = null)
    {
        $this->throttler = $throttler ?? service('throttler');
    }

    /**
     * Zużywa token dla IP i (jeśli podany) dla e-maila.
     * Zwraca false, gdy którykolwiek limit jest wyczerpany.
     */
    public function pozwol(string $ip, string $email): bool
    {
        if (! $this->throttler->check(self::klucz('ip', $ip), self::PROBY_IP, self::OKNO)) {
            return false;
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            return true;
        }

        return $this->throttler->check(self::klucz('em', $email), self::PROBY, self::OKNO);
    }

    /**
     * Klucz cache bez znaków zastrzeżonych ({}()/\@:).
     */
    public static function klucz(string $typ, string $wartosc): string
    {
        return 'mcslogin_' . $typ . '_' . md5($wartosc);
    }
}
