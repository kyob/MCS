<?php

namespace App\Libraries\Dnsbl;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Skaner DNSBL, E3: harmonogram automatycznych skanów, wołany z plugins/dnsbl Plugin::pobierz() (cron co 15 min).
 *
 * - „noc”: pełny skan prefiksów IPv4 z as198050_cidry raz na dobę, okno 03:30–05:00 (czas Europe/Warsaw).
 * - „recheck”: co godzinę tylko pary ip+strefa z aktywnych listingów.
 * Skan zawsze w tle (setsid, --skan-id); nigdy dwa skany naraz (trwający skan = pomiń).
 */
class HarmonogramSkanera
{
    public const TRYB_NOC     = 'noc';
    public const TRYB_RECHECK = 'recheck';

    public const OKNO_OD     = '03:30';
    public const OKNO_DO     = '05:00';
    public const RECHECK_CO_S = 3600;

    /** Margines na rozjazd crona co 15 min — recheck co ~1 h, nie co 1 h 15 min. */
    private const LUZ_S = 300;

    private const STREFA_CZASU = 'Europe/Warsaw';

    private BaseConnection $db;

    /** @var callable(int): void */
    private $uruchom;

    /**
     * @param callable(int): void|null $uruchom start skanu w tle (domyślnie Skaner::uruchomWTle)
     */
    public function __construct(?BaseConnection $db = null, ?callable $uruchom = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->uruchom = $uruchom ?? [Skaner::class, 'uruchomWTle'];
    }

    /**
     * Czysta decyzja: '' | 'noc' | 'recheck'.
     */
    public static function decyzja(int $teraz, ?int $ostatniaNoc, ?int $ostatniRecheck, bool $trwa, int $aktywnePary): string
    {
        if ($trwa) {
            return '';
        }
        $tz   = new \DateTimeZone(self::STREFA_CZASU);
        $dt   = (new \DateTimeImmutable('@' . $teraz))->setTimezone($tz);
        $hm   = $dt->format('H:i');
        if ($hm >= self::OKNO_OD && $hm < self::OKNO_DO) {
            $dzis = $dt->format('Y-m-d');
            $byla = $ostatniaNoc !== null && (new \DateTimeImmutable('@' . $ostatniaNoc))->setTimezone($tz)->format('Y-m-d') === $dzis;
            if (! $byla) {
                return self::TRYB_NOC;
            }
        }
        if ($aktywnePary > 0 && ($ostatniRecheck === null || $teraz - $ostatniRecheck >= self::RECHECK_CO_S - self::LUZ_S)) {
            return self::TRYB_RECHECK;
        }

        return '';
    }

    /**
     * Jeden krok harmonogramu. Zwraca krótki opis dla health pluginu.
     */
    public function krok(?int $teraz = null): string
    {
        $teraz  = $teraz ?? time();
        $widok  = new SkanyWidok($this->db);
        $widok->oznaczPorzucone();
        $trwa   = $widok->trwajacy();
        $skaner = new Skaner($this->db);
        $pary   = $skaner->aktywnePary();

        $d = self::decyzja($teraz, $this->ostatni(self::TRYB_NOC), $this->ostatni(self::TRYB_RECHECK), $trwa !== null, count($pary));
        if ($d === '') {
            return $trwa !== null ? 'trwa skan #' . $trwa['id'] : '';
        }

        if ($d === self::TRYB_NOC) {
            $prefiksy = Skaner::prefiksyAs();
            if ($prefiksy === []) {
                return 'noc: puste as198050_cidry';
            }
            $id = $skaner->utworzSkan($prefiksy, null, self::TRYB_NOC, 'auto');
        } else {
            $id = $skaner->utworzSkanPar($pary, self::TRYB_RECHECK, 'auto');
        }

        try {
            ($this->uruchom)($id);
        } catch (\Throwable $e) {
            $this->db->table('mcs_dnsbl_skany')->where('id', $id)->update([
                'status' => 'blad', 'koniec_at' => gmdate('Y-m-d H:i:sP'), 'komunikat' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return 'zlecenie ' . $d . ' #' . $id . ' nieudane: ' . $e->getMessage();
        }

        return 'zlecono ' . $d . ' #' . $id;
    }

    /**
     * Opis ostatniego zakończonego skanu + flaga fali (dla health pluginu).
     *
     * @return array{msg: string, fala: bool}
     */
    public function stanOstatniego(): array
    {
        $r = $this->db->table('mcs_dnsbl_skany')->where('status', 'zakonczony')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if (! is_array($r)) {
            return ['msg' => 'skaner: brak zakończonego skanu', 'fala' => false];
        }
        $fala = in_array($r['fala'] ?? false, [true, 't', '1', 1], true);
        $msg  = sprintf(
            'skaner: #%d %s listowane=%d nowe=%d zdjete=%d incydenty=%d',
            (int) $r['id'], (string) $r['tryb'], (int) $r['listowane'], (int) ($r['nowe'] ?? 0), (int) ($r['zdjete'] ?? 0), (int) ($r['incydenty'] ?? 0),
        );
        if ($fala) {
            $msg = 'OSTRZEŻENIE: możliwy błąd skanu #' . (int) $r['id'] . ' (' . (int) $r['nowe'] . ' nowych listingów > ' . ZmianySkanu::PROG_FALI
                . ', incydenty pominięte). ' . $msg;
        }

        return ['msg' => $msg, 'fala' => $fala];
    }

    private function ostatni(string $tryb): ?int
    {
        $r = $this->db->table('mcs_dnsbl_skany')->select('utworzono_at')->where('tryb', $tryb)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return is_array($r) ? (strtotime((string) $r['utworzono_at']) ?: null) : null;
    }
}
