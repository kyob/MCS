<?php

namespace McsPluginy\spamhaus_sbl;

/**
 * Filtr wiadomości Spamhaus SBL (odporny na podrobionego nadawcę).
 *
 * 1) Adres z From (po parsowaniu, nie nazwa wyświetlana): domena = spamhaus.org albo *.spamhaus.org.
 *    Tylko ASCII [a-z0-9.-]; jeden adres; odrzuca spamhaus.org.evil.com, notspamhaus.org, homografy.
 * 2) Temat zawiera „SBL” (bez rozróżniania wielkości liter).
 * 3) Authentication-Results naszego MTA (gdy obecny): dkim=pass z header.d w spamhaus.org
 *    LUB spf=pass dla smtp.mailfrom w spamhaus.org. Brak nagłówka = przyjmij jako nieuwierzytelniony.
 */
final class Filtr
{
    public const DOMENA = 'spamhaus.org';

    public const AUTH_PASS = 'pass';

    public const AUTH_FAIL = 'fail';

    public const AUTH_BRAK = 'brak';

    /**
     * @param string $authservId authserv-id naszego MTA (opcjonalnie). Puste = ufamy tylko
     *                           najwyższemu (ostatnio dodanemu) nagłówkowi Authentication-Results.
     */
    public function __construct(private readonly string $authservId = '')
    {
    }

    /**
     * Wyciąga dokładnie jeden adres e-mail z wartości nagłówka From (lub już sparsowanego adresu).
     * Zwraca null, gdy adresów jest 0 albo więcej niż 1, albo składnia jest podejrzana.
     */
    public function adresZFrom(string $from): ?string
    {
        $from = trim($from);
        if ($from === '' || strlen($from) > 998) {
            return null;
        }
        // Usuń quoted-string (nazwa wyświetlana) i komentarze — nie mogą wpływać na adres.
        $bez = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', ' ', $from);
        for ($i = 0; $i < 5; $i++) {
            $bez = (string) preg_replace('/\((?:[^()\\\\]|\\\\.)*\)/s', ' ', $bez);
        }
        if (str_contains($bez, '"') || str_contains($bez, '(') || str_contains($bez, ')')) {
            return null;
        }

        if (preg_match_all('/<([^<>]*)>/', $bez, $m) > 0) {
            if (count($m[1]) !== 1) {
                return null;
            }
            $adres = trim($m[1][0]);
            $reszta = trim((string) preg_replace('/<[^<>]*>/', '', $bez));
            // Reszta (nazwa wyświetlana bez cudzysłowów) nie może zawierać separatora listy.
            if (str_contains($reszta, ',') || str_contains($reszta, ';') || str_contains($reszta, '<') || str_contains($reszta, '>')) {
                return null;
            }
        } else {
            $adres = trim($bez);
        }

        if (substr_count($adres, '@') !== 1 || preg_match('/[\s,;<>]/', $adres) === 1) {
            return null;
        }

        return strtolower($adres);
    }

    /**
     * Domena adresu = spamhaus.org lub subdomena; wyłącznie ASCII (bez homografów/IDN w U-label).
     */
    public function nadawcaZaufanejDomeny(string $adres): bool
    {
        $poz = strrpos($adres, '@');
        if ($poz === false) {
            return false;
        }

        return $this->domenaSpamhaus(substr($adres, $poz + 1));
    }

    public function domenaSpamhaus(string $domena): bool
    {
        $domena = strtolower(trim($domena));
        if ($domena === '' || strlen($domena) > 253) {
            return false;
        }
        // Tylko LDH ASCII; etykiety niepuste; bez kropki na końcu.
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domena) !== 1) {
            return false;
        }

        return $domena === self::DOMENA || str_ends_with($domena, '.' . self::DOMENA);
    }

    public function tematSbl(string $temat): bool
    {
        return stripos($temat, 'SBL') !== false;
    }

    /**
     * Wynik uwierzytelnienia z Authentication-Results (RFC 8601).
     *
     * @return array{wynik: string, metoda: string, opis: string}
     *         wynik: pass | fail | brak
     */
    public function uwierzytelnienie(Naglowki $naglowki): array
    {
        $wszystkie = $naglowki->wszystkie('authentication-results');
        if ($wszystkie === []) {
            return ['wynik' => self::AUTH_BRAK, 'metoda' => '', 'opis' => 'brak Authentication-Results'];
        }

        $zaufane = [];
        if ($this->authservId !== '') {
            foreach ($wszystkie as $ar) {
                if (strcasecmp($this->authservId($ar), $this->authservId) === 0) {
                    $zaufane[] = $ar;
                }
            }
            if ($zaufane === []) {
                // Nagłówki są, ale żaden nie pochodzi od naszego MTA — traktuj jak brak (nie ufamy obcym).
                return ['wynik' => self::AUTH_BRAK, 'metoda' => '', 'opis' => 'brak Authentication-Results od ' . $this->authservId];
            }
        } else {
            // Najwyższy nagłówek = dodany jako ostatni = przez nasz MTA.
            $zaufane = [$wszystkie[0]];
        }

        foreach ($zaufane as $ar) {
            $metoda = $this->sprawdzAr($ar);
            if ($metoda !== '') {
                return ['wynik' => self::AUTH_PASS, 'metoda' => $metoda, 'opis' => $metoda . ' dla ' . self::DOMENA];
            }
        }

        return ['wynik' => self::AUTH_FAIL, 'metoda' => '', 'opis' => 'brak dkim=pass/spf=pass dla ' . self::DOMENA];
    }

    private function authservId(string $ar): string
    {
        $bez = $this->bezKomentarzy($ar);
        $pierwszy = trim(explode(';', $bez, 2)[0]);
        $tokeny = preg_split('/\s+/', $pierwszy);

        return is_array($tokeny) ? (string) ($tokeny[0] ?? '') : '';
    }

    /**
     * Zwraca 'dkim' / 'spf' gdy jest pass dla spamhaus.org, inaczej ''.
     */
    private function sprawdzAr(string $ar): string
    {
        $czesci = explode(';', $this->bezKomentarzy($ar));
        array_shift($czesci); // authserv-id [version]

        foreach ($czesci as $resinfo) {
            if (preg_match('/^\s*(dkim|spf)\s*=\s*([a-z]+)\b(.*)$/is', $resinfo, $m) !== 1) {
                continue;
            }
            $metoda = strtolower($m[1]);
            if (strtolower($m[2]) !== 'pass') {
                continue;
            }
            if (preg_match_all('/\b(header\.d|header\.i|smtp\.mailfrom)\s*=\s*"?([^\s";]+)"?/i', $m[3], $pm, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($pm as $p) {
                $prop = strtolower($p[1]);
                $wart = strtolower($p[2]);
                if ($metoda === 'dkim' && $prop === 'header.d' && $this->domenaSpamhaus($wart)) {
                    return 'dkim';
                }
                if ($metoda === 'dkim' && $prop === 'header.i' && str_contains($wart, '@')
                    && $this->domenaSpamhaus(substr($wart, (int) strrpos($wart, '@') + 1))) {
                    return 'dkim';
                }
                if ($metoda === 'spf' && $prop === 'smtp.mailfrom') {
                    $dom = str_contains($wart, '@') ? substr($wart, (int) strrpos($wart, '@') + 1) : $wart;
                    if ($this->domenaSpamhaus($dom)) {
                        return 'spf';
                    }
                }
            }
        }

        return '';
    }

    private function bezKomentarzy(string $s): string
    {
        $s = substr($s, 0, 8192);
        for ($i = 0; $i < 5; $i++) {
            $s = (string) preg_replace('/\((?:[^()\\\\]|\\\\.)*\)/s', ' ', $s);
        }

        return $s;
    }
}
