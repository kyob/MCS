<?php

namespace McsPluginy\spamhaus_sbl;

/**
 * Parser treści powiadomienia Spamhaus SBL. Treść = dane niezaufane:
 * bez zapytań sieciowych, bez podążania za linkami, bez HTML w zapisie, limity długości.
 *
 * Format maila Spamhaus nie jest znany z dokumentacji (UNVERIFIED) — parser szuka wzorców:
 * numer `SBL123456`, adresy IPv4 / CIDR IPv4, opcjonalnie linia „Reason:” i link spamhaus.org.
 */
final class Parser
{
    public const MAX_TEMAT = 255;

    public const MAX_POWOD = 300;

    public const MAX_LINK = 255;

    public const MAX_ADRESOW = 32;

    private const MAX_TRESC = 65536;

    /**
     * Tekst bez HTML, znaków sterujących i niepoprawnego UTF-8, przycięty do limitu.
     */
    public static function czystyTekst(string $s, int $limit): string
    {
        $s = substr($s, 0, $limit * 8);
        $s = mb_scrub($s, 'UTF-8');
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = (string) preg_replace('/[<>]/', '', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        return mb_substr($s, 0, $limit);
    }

    /**
     * Treść maila jako zwykły tekst: HTML (script/style usunięte, tagi zdjęte), limit rozmiaru, poprawny UTF-8.
     */
    public static function tekstZTresci(string $tresc): string
    {
        $tresc = mb_scrub(substr($tresc, 0, self::MAX_TRESC), 'UTF-8');
        if (preg_match('/<\s*[a-z!\/]/i', $tresc) === 1) {
            $tresc = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $tresc);
            $tresc = (string) preg_replace('#<\s*(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $tresc);
            $tresc = strip_tags($tresc);
            $tresc = html_entity_decode($tresc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $tresc;
    }

    /**
     * Numer SBL (np. „SBL123456”) — najpierw z tematu, potem z treści.
     */
    public function numerSbl(string $temat, string $tresc): ?string
    {
        foreach ([$temat, substr($tresc, 0, self::MAX_TRESC)] as $tekst) {
            if (preg_match('/(?<![A-Za-z0-9])SBL\s?-?(\d{1,10})(?!\d)/i', $tekst, $m) === 1) {
                $nr = ltrim($m[1], '0');

                return 'SBL' . ($nr === '' ? '0' : $nr);
            }
        }

        return null;
    }

    /**
     * Kandydaci IPv4 / CIDR IPv4 z tematu i treści (znormalizowani do adresu sieci dla CIDR).
     *
     * @return list<array{ip: string, cidr: ?string}> unikalne, max MAX_ADRESOW
     */
    public function adresy(string $temat, string $tresc): array
    {
        $tekst = $temat . "\n" . substr($tresc, 0, self::MAX_TRESC);
        $wzorzec = '/(?<![0-9.])((?:\d{1,3}\.){3}\d{1,3})(?:\/(\d{1,2}))?(?![0-9]|\.\d)/';
        if (preg_match_all($wzorzec, $tekst, $m, PREG_SET_ORDER) < 1) {
            return [];
        }

        $out = [];
        foreach ($m as $traf) {
            $ip = $traf[1];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $cidr = null;
            if (isset($traf[2]) && $traf[2] !== '') {
                $dl = (int) $traf[2];
                if ($dl < 8 || $dl > 32) {
                    continue;
                }
                $siec = $this->adresSieci($ip, $dl);
                if ($dl < 32) {
                    $cidr = $siec . '/' . $dl;
                }
                $ip = $siec;
            }
            $klucz = $cidr ?? $ip;
            if (isset($out[$klucz])) {
                continue;
            }
            $out[$klucz] = ['ip' => $ip, 'cidr' => $cidr];
            if (count($out) >= self::MAX_ADRESOW) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Opcjonalny powód z linii „Reason:” / „Listing reason:” / „Description:”.
     */
    public function powod(string $tresc): string
    {
        $tekst = substr($tresc, 0, self::MAX_TRESC);
        if (preg_match('/^[ \t>]*(?:listing\s+reason|reason|description|listed\s+for)\s*[:\-]\s*(.+)$/im', $tekst, $m) !== 1) {
            return '';
        }

        return self::czystyTekst($m[1], self::MAX_POWOD);
    }

    /**
     * Opcjonalny link z treści — tylko https w domenie spamhaus.org, tylko jako tekst (nie odwiedzamy).
     */
    public function link(string $tresc): string
    {
        $tekst = substr($tresc, 0, self::MAX_TRESC);
        if (preg_match_all('#https://([a-z0-9.-]+)(/[A-Za-z0-9/_?=&.%\-]*)?#i', $tekst, $m, PREG_SET_ORDER) < 1) {
            return '';
        }
        $filtr = new Filtr();
        foreach ($m as $traf) {
            if ($filtr->domenaSpamhaus($traf[1])) {
                return substr($traf[0], 0, self::MAX_LINK);
            }
        }

        return '';
    }

    /**
     * Link do ręcznego przeglądu (dla człowieka; MCS go nie odpytuje).
     */
    public static function linkPrzegladu(string $sbl): ?string
    {
        if (preg_match('/^SBL(\d{1,10})$/', $sbl, $m) !== 1) {
            return null;
        }

        return 'https://check.spamhaus.org/sbl/query/SBL' . $m[1];
    }

    private function adresSieci(string $ip, int $dl): string
    {
        $n    = ip2long($ip);
        $mask = $dl === 0 ? 0 : ((-1 << (32 - $dl)) & 0xFFFFFFFF);

        return (string) long2ip($n & $mask);
    }
}
