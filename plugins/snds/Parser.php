<?php

namespace McsPluginy\snds;

/**
 * Parser CSV SNDS (Microsoft Smart Network Data Services).
 *
 * Mapowanie po nazwach nagłówków (eksport IP Status / data.aspx).
 * Fixture w tests/_support/fixtures/ jest syntetyczna — kolumny wg publicznej
 * dokumentacji SNDS; brak prawdziwej próbki z Waszego konta.
 *
 * klucz_dedupu: ip|filtr|data_aktywnosci (dzień UTC).
 */
final class Parser
{
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
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $filtr = $this->wartosc($raw, [
                'filter result',
                'filter_result',
                'filterresult',
                'filtr',
            ]);
            $filtr = $filtr !== '' ? strtoupper($filtr) : null;
            if ($filtr !== null && ! in_array($filtr, ['GREEN', 'YELLOW', 'RED'], true)) {
                // Zachowaj nieznaną etykietę (uppercase), WagaV0 zignoruje poza G/Y/R
                $filtr = strtoupper($filtr);
            }

            $timestamp = $this->wartosc($raw, [
                'activity period start',
                'activity start',
                'activity_period_start',
                'date',
                'timestamp',
            ]);
            if ($timestamp === '') {
                $timestamp = gmdate('Y-m-d H:i:s');
            }

            $complaint = $this->wartosc($raw, [
                'complaint rate',
                'complaint_rate',
                'complaintrate',
            ]);
            $complaint = $complaint !== '' ? $complaint : null;

            $trapRaw = $this->wartosc($raw, [
                'trap hits',
                'trap_hits',
                'traphits',
            ]);
            $trapHits = null;
            if ($trapRaw !== '' && ctype_digit($trapRaw)) {
                $trapHits = (int) $trapRaw;
            }

            $dzien = $this->dzienZTimestamp($timestamp);
            $klucz = strtolower($ip) . '|' . strtolower((string) ($filtr ?? '')) . '|' . $dzien;

            $wynik[] = [
                'ip'             => $ip,
                'filtr'          => $filtr,
                'timestamp'      => $timestamp,
                'klucz_dedupu'   => $klucz,
                'complaint_rate' => $complaint,
                'trap_hits'      => $trapHits,
                'raw'            => $raw,
            ];
        }

        return $wynik;
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
        try {
            $dt = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));

            return $dt->format('Y-m-d');
        } catch (\Exception) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $timestamp, $m) === 1) {
                return $m[1];
            }

            return gmdate('Y-m-d');
        }
    }
}
