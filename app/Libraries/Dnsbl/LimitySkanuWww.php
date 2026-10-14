<?php

namespace App\Libraries\Dnsbl;

use App\Libraries\OpcjeMcs;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Limity skanu DNSBL zlecanego z WWW (formularz /dashboard/dnsbl).
 *
 * - maks. liczba IP w jednym skanie (mcs.dnsbl.skanerMaksIpWww, domyślnie 4096 = /20),
 * - maks. liczba skanów WWW w ostatnich 24 h, dla wszystkich użytkowników razem
 *   (mcs.dnsbl.skanerLimitDobaWww, domyślnie 20),
 * - sekcja krytyczna (flock) dla sprawdzenia „trwa inny skan” + limitu + INSERT,
 *   żeby dwa równoległe żądania nie uruchomiły dwóch skanów.
 *
 * Skany z crona (noc/recheck) i CLI nie są liczone ani blokowane.
 */
class LimitySkanuWww
{
    public const TRYB_WWW = 'web';

    private BaseConnection $db;
    private string $plikBlokady;

    public function __construct(?BaseConnection $db = null, ?string $plikBlokady = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->plikBlokady = $plikBlokady ?? WRITEPATH . 'cache/mcs-dnsbl-skan-www.lock';
    }

    public static function maksIp(): int
    {
        return max(1, self::opcja('mcs.dnsbl.skanerMaksIpWww', 4096));
    }

    public static function limitDoba(): int
    {
        return max(1, self::opcja('mcs.dnsbl.skanerLimitDobaWww', 20));
    }

    /**
     * Komunikat błędu albo '' gdy liczba IP mieści się w limicie.
     */
    public function bladLiczbyIp(int $liczbaIp, ?int $maks = null): string
    {
        $maks ??= self::maksIp();
        if ($liczbaIp > $maks) {
            return 'Za duży zakres: ' . $liczbaIp . ' IP. Limit jednego skanu z WWW: ' . $maks . ' IP.';
        }

        return '';
    }

    /**
     * Liczba skanów WWW zleconych w ostatnich 24 h.
     */
    public function liczbaDoba(?int $teraz = null): int
    {
        $od = gmdate('Y-m-d H:i:sP', ($teraz ?? time()) - 86400);

        return $this->db->table('mcs_dnsbl_skany')
            ->where('tryb', self::TRYB_WWW)
            ->where('utworzono_at >=', $od)
            ->countAllResults();
    }

    /**
     * Komunikat błędu albo '' gdy dzienny limit nie jest wyczerpany.
     */
    public function bladLimituDoby(?int $limit = null, ?int $teraz = null): string
    {
        $limit ??= self::limitDoba();
        if ($this->liczbaDoba($teraz) >= $limit) {
            return 'Wyczerpany limit skanów z WWW: ' . $limit . ' na 24 h. Spróbuj później.';
        }

        return '';
    }

    /**
     * Wykonuje $fn pod wyłączną blokadą pliku (flock LOCK_EX). Blokada znika po wyjściu.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function wSekcjiKrytycznej(callable $fn): mixed
    {
        $fh = fopen($this->plikBlokady, 'c');
        if ($fh === false) {
            throw new \RuntimeException('Nie można otworzyć pliku blokady skanu.');
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Nie można założyć blokady skanu.');
            }

            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function opcja(string $klucz, int $domyslna): int
    {
        try {
            return OpcjeMcs::int($klucz);
        } catch (\Throwable) {
            return (int) env($klucz, $domyslna);
        }
    }
}
