<?php

namespace App\Libraries;

/**
 * Reguły wagi MCS — wersja v1 (sygnały per źródło).
 *
 * Etykiety na zewnątrz: low | medium | high | critical.
 * Wynik niesie `wersja=v1` (zapisywane jako `wersja_wagi` w indeksie).
 *
 * Mapowanie skrót:
 * - baza z kategorii: malware→critical; amplification/vulnerability/abuse→high;
 *   reputation/exposure→medium; other→low
 * - SNDS `filtr`: RED→critical, YELLOW→high, GREEN→low (nadpisuje bazę)
 * - `severity` / `confidence` / `waga_jawna`: max(baza, sygnał) — nie obniża malware itd.
 * - Shadowserver `tag` / MojeCert `report_type`: podbicie wg typu raportu (max)
 * - Play `message_type`: Mitigation→critical, Event→high, CIDR→low (max)
 *
 * WagaV0 zostaje w repo jako fallback / porównanie historyczne.
 */
class WagaV1
{
    public const WERSJA = 'v1';

    private const DOZWOLONE = ['low', 'medium', 'high', 'critical'];

    private const RZAD = [
        'low'      => 1,
        'medium'   => 2,
        'high'     => 3,
        'critical' => 4,
    ];

    /**
     * @param string               $kategoria  exposure|vulnerability|malware|abuse|reputation|amplification|other
     * @param array<string, mixed> $sygnaly    sygnały ze źródła (filtr, severity, tag, …)
     *
     * @return array{waga: string, wersja: string, kategoria: string, uzasadnienie: string}
     */
    public function wylicz(string $kategoria, array $sygnaly = []): array
    {
        $kategoria = strtolower(trim($kategoria));
        if ($kategoria === '') {
            $kategoria = 'other';
        }

        [$waga, $uzasadnienie] = $this->wagaZKategorii($kategoria);

        $filtr = $this->sygnalFiltr($sygnaly);
        if ($filtr !== null) {
            $waga         = $filtr['waga'];
            $uzasadnienie = $filtr['uzasadnienie'];
        } else {
            $kandydaci = $this->kandydaciZSygnalow($sygnaly);
            foreach ($kandydaci as $kandydat) {
                if ($this->rzad($kandydat['waga']) > $this->rzad($waga)) {
                    $waga         = $kandydat['waga'];
                    $uzasadnienie = $kandydat['uzasadnienie'] . ' (max z bazą)';
                }
            }
        }

        if (! in_array($waga, self::DOZWOLONE, true)) {
            $waga         = 'low';
            $uzasadnienie = 'korekta: niedozwolona waga → low';
        }

        return [
            'waga'         => $waga,
            'wersja'       => self::WERSJA,
            'kategoria'    => $kategoria,
            'uzasadnienie' => $uzasadnienie,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function wagaZKategorii(string $kategoria): array
    {
        return match ($kategoria) {
            'malware'        => ['critical', 'malware → critical'],
            'amplification'  => ['high', 'amplification → high'],
            'vulnerability'  => ['high', 'vulnerability → high'],
            'abuse'          => ['high', 'abuse → high'],
            'reputation'     => ['medium', 'reputation → medium'],
            'exposure'       => ['medium', 'exposure → medium'],
            default          => ['low', 'other / nieznana kategoria → low'],
        };
    }

    /**
     * SNDS filtr jest autorytatywny (nadpisuje bazę kategorii).
     *
     * @param array<string, mixed> $sygnaly
     *
     * @return array{waga: string, uzasadnienie: string}|null
     */
    private function sygnalFiltr(array $sygnaly): ?array
    {
        $filtr = isset($sygnaly['filtr']) && is_string($sygnaly['filtr'])
            ? strtoupper(trim($sygnaly['filtr']))
            : '';

        return match ($filtr) {
            'RED'    => ['waga' => 'critical', 'uzasadnienie' => 'sygnał filtr=RED → critical'],
            'YELLOW' => ['waga' => 'high', 'uzasadnienie' => 'sygnał filtr=YELLOW → high'],
            'GREEN'  => ['waga' => 'low', 'uzasadnienie' => 'sygnał filtr=GREEN → low'],
            default  => null,
        };
    }

    /**
     * @param array<string, mixed> $sygnaly
     *
     * @return list<array{waga: string, uzasadnienie: string}>
     */
    private function kandydaciZSygnalow(array $sygnaly): array
    {
        $out = [];

        $severity = $this->normalizujEtykiete(
            $this->pierwszyString($sygnaly, ['severity', 'confidence', 'waga_jawna']),
        );
        if ($severity !== null) {
            $out[] = [
                'waga'         => $severity,
                'uzasadnienie' => 'sygnał severity/confidence/waga_jawna=' . $severity,
            ];
        }

        $tag = $this->pierwszyString($sygnaly, ['tag', 'report_type']);
        if ($tag !== null && $tag !== '') {
            $zTagu = $this->wagaZTaguLubTypu($tag);
            if ($zTagu !== null) {
                $out[] = $zTagu;
            }
        }

        $messageType = $this->pierwszyString($sygnaly, ['message_type']);
        if ($messageType !== null && $messageType !== '') {
            $zPlay = $this->wagaZMessageType($messageType);
            if ($zPlay !== null) {
                $out[] = $zPlay;
            }
        }

        return $out;
    }

    /**
     * Shadowserver tag / MojeCert report_type.
     *
     * @return array{waga: string, uzasadnienie: string}|null
     */
    private function wagaZTaguLubTypu(string $tag): ?array
    {
        $t = strtolower($tag);

        if (preg_match('/botnet|\bc2\b|malware|cnc|drone/', $t) === 1) {
            return ['waga' => 'critical', 'uzasadnienie' => 'sygnał tag/report_type (malware/c2) → critical'];
        }
        if (preg_match('/amplif|open-resolver|dns-open|ntp|ssdp|memcached|chargen|mdns/', $t) === 1) {
            return ['waga' => 'high', 'uzasadnienie' => 'sygnał tag/report_type (amplification) → high'];
        }
        if (preg_match('/vuln|exploit|cve|\brce\b/', $t) === 1) {
            return ['waga' => 'high', 'uzasadnienie' => 'sygnał tag/report_type (vuln) → high'];
        }
        if (preg_match('/bruteforce|spam|scan|phishing|phish/', $t) === 1) {
            return ['waga' => 'high', 'uzasadnienie' => 'sygnał tag/report_type (abuse/scan) → high'];
        }
        if (preg_match('/\bopen\b|accessible|exposed|ssl|tls|banner/', $t) === 1) {
            return ['waga' => 'medium', 'uzasadnienie' => 'sygnał tag/report_type (exposure) → medium'];
        }

        return null;
    }

    /**
     * Play Anty-DDoS message_type.
     *
     * @return array{waga: string, uzasadnienie: string}|null
     */
    private function wagaZMessageType(string $messageType): ?array
    {
        $t = strtolower($messageType);

        if (str_contains($t, 'mitigation')) {
            return ['waga' => 'critical', 'uzasadnienie' => 'sygnał message_type (mitigation) → critical'];
        }
        if (str_contains($t, 'event')) {
            return ['waga' => 'high', 'uzasadnienie' => 'sygnał message_type (event) → high'];
        }
        if (str_contains($t, 'cidr')) {
            return ['waga' => 'low', 'uzasadnienie' => 'sygnał message_type (cidr) → low'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sygnaly
     * @param list<string>         $klucze
     */
    private function pierwszyString(array $sygnaly, array $klucze): ?string
    {
        foreach ($klucze as $klucz) {
            if (isset($sygnaly[$klucz]) && is_string($sygnaly[$klucz])) {
                $v = trim($sygnaly[$klucz]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function normalizujEtykiete(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $low = strtolower(trim($raw));
        if (in_array($low, self::DOZWOLONE, true)) {
            return $low;
        }

        // N6 / inne źródła czasem podają synonimy
        return match ($low) {
            'info', 'informational' => 'low',
            'warn', 'warning'       => 'medium',
            'error', 'severe'       => 'high',
            'fatal', 'emergency'    => 'critical',
            default                 => null,
        };
    }

    private function rzad(string $waga): int
    {
        return self::RZAD[$waga] ?? 0;
    }
}
