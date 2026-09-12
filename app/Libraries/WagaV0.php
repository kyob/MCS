<?php

namespace App\Libraries;

/**
 * Minimalne reguły wagi MCS — wersja v0 (historyczna).
 *
 * Nowe zapisy indeksu używają {@see WagaV1}. Ta klasa zostaje jako fallback
 * i punkt odniesienia w testach regresji mapowań.
 *
 * Na zewnątrz zawsze etykieta: low | medium | high | critical.
 * Wynik niesie `wersja`, żeby badge/operacje wiedziały, czym liczono.
 */
class WagaV0
{
    public const WERSJA = 'v0';

    private const DOZWOLONE = ['low', 'medium', 'high', 'critical'];

    /**
     * @param string               $kategoria  exposure|vulnerability|malware|abuse|reputation|amplification|other
     * @param array<string, mixed> $sygnaly    opcjonalne sygnały ze źródła (np. filtr SNDS, severity)
     *
     * @return array{waga: string, wersja: string, kategoria: string, uzasadnienie: string}
     */
    public function wylicz(string $kategoria, array $sygnaly = []): array
    {
        $kategoria = strtolower(trim($kategoria));
        if ($kategoria === '') {
            $kategoria = 'other';
        }

        $waga          = 'low';
        $uzasadnienie  = 'domyślnie low (other / nieznane)';

        switch ($kategoria) {
            case 'malware':
                $waga         = 'critical';
                $uzasadnienie = 'malware → critical';
                break;
            case 'amplification':
                $waga         = 'high';
                $uzasadnienie = 'amplification → high';
                break;
            case 'vulnerability':
                $waga         = 'high';
                $uzasadnienie = 'vulnerability → high';
                break;
            case 'abuse':
                $waga         = 'high';
                $uzasadnienie = 'abuse → high';
                break;
            case 'reputation':
                $waga         = 'medium';
                $uzasadnienie = 'reputation → medium';
                break;
            case 'exposure':
                $waga         = 'medium';
                $uzasadnienie = 'exposure → medium';
                break;
            case 'other':
            default:
                $waga         = 'low';
                $uzasadnienie = 'other / nieznana kategoria → low';
                break;
        }

        $nadpisanie = $this->nadpiszZSygnalow($sygnaly);
        if ($nadpisanie !== null) {
            $waga         = $nadpisanie['waga'];
            $uzasadnienie = $nadpisanie['uzasadnienie'];
        }

        if (! in_array($waga, self::DOZWOLONE, true)) {
            $waga         = 'low';
            $uzasadnienie = 'korekta: niedozwolona waga → low';
        }

        return [
            'waga'          => $waga,
            'wersja'        => self::WERSJA,
            'kategoria'     => $kategoria,
            'uzasadnienie'  => $uzasadnienie,
        ];
    }

    /**
     * @param array<string, mixed> $sygnaly
     *
     * @return array{waga: string, uzasadnienie: string}|null
     */
    private function nadpiszZSygnalow(array $sygnaly): ?array
    {
        $filtr = isset($sygnaly['filtr']) && is_string($sygnaly['filtr'])
            ? strtoupper(trim($sygnaly['filtr']))
            : '';

        if ($filtr === 'RED') {
            return ['waga' => 'high', 'uzasadnienie' => 'sygnał filtr=RED → high'];
        }
        if ($filtr === 'YELLOW') {
            return ['waga' => 'medium', 'uzasadnienie' => 'sygnał filtr=YELLOW → medium'];
        }
        if ($filtr === 'GREEN') {
            return ['waga' => 'low', 'uzasadnienie' => 'sygnał filtr=GREEN → low'];
        }

        $severity = null;
        foreach (['severity', 'waga_jawna'] as $klucz) {
            if (isset($sygnaly[$klucz]) && is_string($sygnaly[$klucz])) {
                $severity = strtolower(trim($sygnaly[$klucz]));
                break;
            }
        }
        if ($severity === null) {
            $severity = '';
        }

        if (in_array($severity, self::DOZWOLONE, true)) {
            return [
                'waga'         => $severity,
                'uzasadnienie' => 'sygnał severity/waga_jawna=' . $severity,
            ];
        }

        return null;
    }
}
