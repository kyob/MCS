<?php

namespace App\Libraries\Dnsbl;

/**
 * Minimalny koder/dekoder pakietów DNS (RFC 1035) dla zapytań A i TXT po UDP.
 */
final class DnsPakiet
{
    public const TYP_A   = 1;
    public const TYP_TXT = 16;

    public const RCODE_NOERROR  = 0;
    public const RCODE_SERVFAIL = 2;
    public const RCODE_NXDOMAIN = 3;
    public const RCODE_REFUSED  = 5;

    public static function zapytanie(int $id, string $nazwa, int $typ): string
    {
        $naglowek = pack('nnnnnn', $id & 0xFFFF, 0x0100, 1, 0, 0, 0); // RD=1
        $q        = '';
        foreach (explode('.', rtrim($nazwa, '.')) as $etykieta) {
            $dl = strlen($etykieta);
            if ($dl === 0 || $dl > 63) {
                throw new \InvalidArgumentException('Nieprawidłowa etykieta DNS w: ' . $nazwa);
            }
            $q .= chr($dl) . $etykieta;
        }
        if (strlen($q) + 1 > 255) {
            throw new \InvalidArgumentException('Nazwa DNS za długa: ' . $nazwa);
        }

        return $naglowek . $q . "\0" . pack('nn', $typ, 1);
    }

    /**
     * @return array{id: int, rcode: int, tc: bool, a: list<string>, txt: list<string>}
     */
    public static function parsuj(string $buf): array
    {
        if (strlen($buf) < 12) {
            throw new \UnexpectedValueException('Za krótka odpowiedź DNS');
        }
        $h = unpack('nid/nflagi/nqd/nan/nns/nar', substr($buf, 0, 12));
        if (($h['flagi'] & 0x8000) === 0) {
            throw new \UnexpectedValueException('Pakiet nie jest odpowiedzią (QR=0)');
        }

        $off = 12;
        for ($i = 0; $i < $h['qd']; $i++) {
            $off = self::pominNazwe($buf, $off) + 4;
        }

        $a   = [];
        $txt = [];
        for ($i = 0; $i < $h['an']; $i++) {
            $off = self::pominNazwe($buf, $off);
            if ($off + 10 > strlen($buf)) {
                throw new \UnexpectedValueException('Ucięty rekord DNS');
            }
            $r     = unpack('ntyp/nklasa/Nttl/ndl', substr($buf, $off, 10));
            $off  += 10;
            $rdata = substr($buf, $off, $r['dl']);
            if (strlen($rdata) !== $r['dl']) {
                throw new \UnexpectedValueException('Ucięte RDATA');
            }
            if ($r['typ'] === self::TYP_A && $r['dl'] === 4) {
                $a[] = (string) inet_ntop($rdata);
            } elseif ($r['typ'] === self::TYP_TXT) {
                $p = 0;
                $s = '';
                while ($p < $r['dl']) {
                    $dl = ord($rdata[$p]);
                    $s .= substr($rdata, $p + 1, $dl);
                    $p += 1 + $dl;
                }
                $txt[] = $s;
            }
            $off += $r['dl'];
        }

        return [
            'id'    => $h['id'],
            'rcode' => $h['flagi'] & 0x000F,
            'tc'    => ($h['flagi'] & 0x0200) !== 0,
            'a'     => $a,
            'txt'   => $txt,
        ];
    }

    private static function pominNazwe(string $buf, int $off): int
    {
        $len = strlen($buf);
        while (true) {
            if ($off >= $len) {
                throw new \UnexpectedValueException('Ucięta nazwa DNS');
            }
            $dl = ord($buf[$off]);
            if ($dl === 0) {
                return $off + 1;
            }
            if (($dl & 0xC0) === 0xC0) {
                return $off + 2;
            }
            $off += $dl + 1;
        }
    }
}
