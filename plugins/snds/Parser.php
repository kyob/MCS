<?php

namespace McsPluginy\snds;

use RuntimeException;

/**
 * Parser CSV SNDS (Microsoft Smart Network Data Services).
 *
 * Tryby:
 * 1) DataKey / LMS — CSV **bez nagłówka**, 13 lub 14 kolumn pozycyjnych
 *    (jak `lms-snds.php` / portal Automated Data Access).
 * 2) Eksport z nagłówkami — mapowanie po nazwach (stara fixture / IP Status).
 *
 * klucz_dedupu: ip|filtr|data_aktywnosci (dzień UTC) — semantyka MCS.
 */
final class Parser
{
    /** Nazwy pól raw dla układu pozycyjnego (zgodnie z LMS alfa_snds). */
    public const KOLUMNY_POZYCYJNE = [
        'ip_address',
        'activity_period_start',
        'activity_period_end',
        'rcpt_commands',
        'data_commands',
        'message_recipients',
        'filter_result',
        'complaint_rate',
        'trap_message_period_start',
        'trap_message_period_end',
        'trap_hits',
        'sample_helo',
        'jmr_p1_sender',
        'comments',
    ];

    /**
     * @return list<array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }>
     */
    public function parsujCsv(string $tresc): array
    {
        $tresc = preg_replace("/^\xEF\xBB\xBF/", '', $tresc) ?? $tresc;
        $linie = preg_split("/\r\n|\n|\r/", trim($tresc)) ?: [];
        if ($linie === [] || $linie[0] === '') {
            return [];
        }

        $pierwsza = $this->pierwszaNiepusta($linie);
        if ($pierwsza === null) {
            return [];
        }

        if ($this->liniaWygladaNaWierszDataKey($pierwsza)) {
            return $this->parsujPozycyjnie($linie);
        }

        return $this->parsujPoNaglowkach($linie);
    }

    /**
     * SNDS = reputacja nadawcy u Microsoftu.
     *
     * @param array<string, mixed> $wiersz
     */
    public function kategoriaDlaWiersza(array $wiersz): string
    {
        return 'reputation';
    }

    public function liniaWygladaNaWierszDataKey(string $linia): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+\.\d+,/', $linia);
    }

    /**
     * @param list<string> $linie
     *
     * @return list<array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }>
     */
    private function parsujPozycyjnie(array $linie): array
    {
        $wynik = [];
        foreach ($linie as $linia) {
            if (trim($linia) === '') {
                continue;
            }
            $data = str_getcsv($linia);
            if ($data === false) {
                continue;
            }
            $liczba = count($data);
            if ($liczba !== 13 && $liczba !== 14) {
                throw new RuntimeException(
                    'SNDS: nieprawidlowa liczba kolumn (oczekiwano 13 lub 14, jest '
                    . $liczba . ').',
                );
            }
            if ($liczba === 13) {
                $data[] = '';
            }

            $raw = [];
            foreach (self::KOLUMNY_POZYCYJNE as $i => $nazwa) {
                $raw[$nazwa] = trim((string) ($data[$i] ?? ''));
            }

            $wiersz = $this->zlozWierszZRaw($raw, $raw['ip_address'], $raw['filter_result'], $raw['activity_period_start'], $raw['complaint_rate'], $raw['trap_hits']);
            if ($wiersz !== null) {
                $wynik[] = $wiersz;
            }
        }

        return $wynik;
    }

    /**
     * @param list<string> $linie
     *
     * @return list<array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }>
     */
    private function parsujPoNaglowkach(array $linie): array
    {
        $naglowki = str_getcsv(array_shift($linie));
        if ($naglowki === false || $naglowki === [null] || $naglowki === []) {
            return [];
        }
        $naglowki = array_map(static fn ($h) => strtolower(trim((string) $h)), $naglowki);

        $wynik = [];
        foreach ($linie as $linia) {
            if (trim($linia) === '') {
                continue;
            }
            $pola = str_getcsv($linia);
            if ($pola === false) {
                continue;
            }
            $raw = [];
            foreach ($naglowki as $i => $nazwa) {
                $raw[$nazwa] = isset($pola[$i]) ? trim((string) $pola[$i]) : '';
            }

            $ip = $this->wartosc($raw, [
                'ip address',
                'ip',
                'ip_address',
                'ipaddress',
            ]);
            $filtr = $this->wartosc($raw, [
                'filter result',
                'filter_result',
                'filterresult',
                'filtr',
            ]);
            $timestamp = $this->wartosc($raw, [
                'activity period start',
                'activity start',
                'activity_period_start',
                'date',
                'timestamp',
            ]);
            $complaint = $this->wartosc($raw, [
                'complaint rate',
                'complaint_rate',
                'complaintrate',
            ]);
            $trapRaw = $this->wartosc($raw, [
                'trap hits',
                'trap_hits',
                'traphits',
            ]);

            $wiersz = $this->zlozWierszZRaw($raw, $ip, $filtr, $timestamp, $complaint, $trapRaw);
            if ($wiersz !== null) {
                $wynik[] = $wiersz;
            }
        }

        return $wynik;
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array{
     *   ip: string,
     *   filtr: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   complaint_rate: ?string,
     *   trap_hits: ?int,
     *   raw: array<string, string>
     * }|null
     */
    private function zlozWierszZRaw(
        array $raw,
        string $ip,
        string $filtr,
        string $timestamp,
        string $complaint,
        string $trapRaw,
    ): ?array {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $filtrNorm = $filtr !== '' ? strtoupper($filtr) : null;
        if ($timestamp === '') {
            $timestamp = gmdate('Y-m-d H:i:s');
        }

        $complaintNorm = $complaint !== '' ? $complaint : null;
        $trapHits      = null;
        if ($trapRaw !== '' && ctype_digit($trapRaw)) {
            $trapHits = (int) $trapRaw;
        }

        $dzien = $this->dzienZTimestamp($timestamp);
        $klucz = strtolower($ip) . '|' . strtolower((string) ($filtrNorm ?? '')) . '|' . $dzien;

        return [
            'ip'             => $ip,
            'filtr'          => $filtrNorm,
            'timestamp'      => $timestamp,
            'klucz_dedupu'   => $klucz,
            'complaint_rate' => $complaintNorm,
            'trap_hits'      => $trapHits,
            'raw'            => $raw,
        ];
    }

    /**
     * @param list<string> $linie
     */
    private function pierwszaNiepusta(array $linie): ?string
    {
        foreach ($linie as $linia) {
            if (trim($linia) !== '') {
                return $linia;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $raw
     * @param list<string>          $kandydaci
     */
    private function wartosc(array $raw, array $kandydaci): string
    {
        foreach ($kandydaci as $k) {
            if (isset($raw[$k]) && $raw[$k] !== '') {
                return $raw[$k];
            }
        }

        return '';
    }

    private function dzienZTimestamp(string $timestamp): string
    {
        $dt = $this->parsujCzas($timestamp);
        if ($dt !== null) {
            return $dt->format('Y-m-d');
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $timestamp, $m) === 1) {
            return $m[1];
        }

        return gmdate('Y-m-d');
    }

    public function parsujCzas(string $timestamp): ?\DateTimeImmutable
    {
        $formaty = [
            'm/d/Y g:i A',
            'm/d/Y h:i A',
            'm/d/Y G:i',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
        ];
        foreach ($formaty as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $timestamp, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                $bledy = \DateTimeImmutable::getLastErrors();
                if (is_array($bledy) && (($bledy['warning_count'] ?? 0) > 0 || ($bledy['error_count'] ?? 0) > 0)) {
                    continue;
                }

                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
