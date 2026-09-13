<?php

namespace App\Libraries;

/**
 * Krótki kontekst „co robić” dla problematycznego IP na dashboardzie.
 * Duch jak kompaktowy badge LMS: chipy + 1–2 zdania, bez ściany tekstu.
 */
class KontekstIp
{
    private EtykietyUi $etykiety;

    public function __construct(?EtykietyUi $etykiety = null)
    {
        $this->etykiety = $etykiety ?? new EtykietyUi();
    }

    /**
     * @param array{
     *   ip?: string,
     *   liczba_incydentow?: int,
     *   liczba_zrodel?: int,
     *   zrodla?: list<string>,
     *   kategorie?: list<string>,
     *   max_waga?: ?string
     * } $agregat
     *
     * @return array{
     *   zdanie: string,
     *   chipy: list<array{typ: string, tekst: string}>,
     *   cta: string
     * }
     */
    public function zbuduj(array $agregat): array
    {
        $zrodla    = $this->listaStringow($agregat['zrodla'] ?? []);
        $kategorie = $this->listaStringow($agregat['kategorie'] ?? []);
        $maxWaga   = isset($agregat['max_waga']) && is_string($agregat['max_waga']) && $agregat['max_waga'] !== ''
            ? $agregat['max_waga']
            : null;
        $liczba    = (int) ($agregat['liczba_incydentow'] ?? 0);
        $liczbaZr  = (int) ($agregat['liczba_zrodel'] ?? count($zrodla));

        $etykietyZr = $this->etykiety->zrodla($zrodla);
        $katChip    = $this->etykiety->kategorieChip($kategorie, 2);

        $chipy = [];
        if ($maxWaga !== null) {
            $chipy[] = ['typ' => 'waga-' . $maxWaga, 'tekst' => strtoupper($maxWaga)];
        }
        if ($liczba > 0) {
            $chipy[] = [
                'typ'   => 'liczba',
                'tekst' => $liczba . ' ' . $this->odmienIncydenty($liczba),
            ];
        }
        foreach ($etykietyZr as $e) {
            $chipy[] = ['typ' => 'zrodlo', 'tekst' => $e];
        }
        foreach ($katChip as $k) {
            $chipy[] = ['typ' => 'kategoria', 'tekst' => $k];
        }

        $zdanie = $this->zdanie($etykietyZr, $katChip, $maxWaga, $liczbaZr, $liczba);

        return [
            'zdanie' => $zdanie,
            'chipy'  => $chipy,
            'cta'    => 'Szczegóły',
        ];
    }

    /**
     * @param list<string> $etykietyZr
     * @param list<string> $katChip
     */
    private function zdanie(
        array $etykietyZr,
        array $katChip,
        ?string $maxWaga,
        int $liczbaZr,
        int $liczba,
    ): string {
        if ($liczba <= 0 && $etykietyZr === []) {
            return 'Brak widocznych zgłoszeń w oknie.';
        }

        $czesci = [];
        if ($katChip !== []) {
            $czesci[] = implode('/', $katChip);
        }
        if ($etykietyZr !== []) {
            $czesci[] = 'źródła: ' . implode('+', $etykietyZr);
        } elseif ($liczbaZr > 1) {
            $czesci[] = $liczbaZr . ' źródła';
        }
        if ($maxWaga !== null) {
            $czesci[] = 'waga ' . $maxWaga;
        }

        if ($czesci === []) {
            return $liczba . ' ' . $this->odmienIncydenty($liczba) . ' w oknie — sprawdź szczegóły.';
        }

        return implode(', ', $czesci) . '. Sprawdź szczegóły IP.';
    }

    private function odmienIncydenty(int $n): string
    {
        $n = abs($n);
        if ($n === 1) {
            return 'incydent';
        }
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'incydenty';
        }

        return 'incydentów';
    }

    /**
     * @param mixed $wartosc
     *
     * @return list<string>
     */
    private function listaStringow($wartosc): array
    {
        if (! is_array($wartosc)) {
            return [];
        }
        $out = [];
        foreach ($wartosc as $el) {
            if (! is_string($el) && ! is_int($el) && ! is_float($el)) {
                continue;
            }
            $s = trim((string) $el);
            if ($s === '') {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }
}
