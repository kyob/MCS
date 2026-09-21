<?php

namespace McsPluginy\speedtest;

/**
 * Parser eksportu CSV Speedtest Servers reporting (account.ookla.com/servers/reports).
 *
 * Czysty: bez filtra AS198050 (to robi SpeedtestSerwis). Dedup test_id w obrębie pliku
 * — pierwszy wiersz wygrywa.
 */
class Parser
{
    /**
     * @return list<array{
     *   test_id: string,
     *   ip: string,
     *   zaobserwowano_at: string,
     *   download_kbps: ?int,
     *   upload_kbps: ?int,
     *   latency_ms: ?float,
     *   isp: ?string,
     *   platform: ?string,
     *   server_name: ?string,
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

        $naglowek = array_shift($linie);
        if ($naglowek === null || trim($naglowek) === '') {
            return [];
        }

        $separator = $this->wykryjSeparator($naglowek);
        $naglowki  = str_getcsv($naglowek, $separator);
        if ($naglowki === [null] || $naglowki === []) {
            return [];
        }
        $naglowki = array_map(static fn ($h) => strtolower(trim((string) $h)), $naglowki);

        $wynik = [];
        $widziane = [];
        foreach ($linie as $linia) {
            if (trim($linia) === '') {
                continue;
            }
            $pola = str_getcsv($linia, $separator);
            $raw  = [];
            foreach ($naglowki as $i => $nazwa) {
                $raw[$nazwa] = isset($pola[$i]) ? trim((string) $pola[$i]) : '';
            }

            $wiersz = $this->zlozWiersz($raw);
            if ($wiersz === null) {
                continue;
            }
            if (isset($widziane[$wiersz['test_id']])) {
                continue;
            }
            $widziane[$wiersz['test_id']] = true;
            $wynik[] = $wiersz;
        }

        return $wynik;
    }

    /**
     * @param array<string, string> $raw
     *
     * @return array{
     *   test_id: string,
     *   ip: string,
     *   zaobserwowano_at: string,
     *   download_kbps: ?int,
     *   upload_kbps: ?int,
     *   latency_ms: ?float,
     *   isp: ?string,
     *   platform: ?string,
     *   server_name: ?string,
     *   raw: array<string, string>
     * }|null
     */
    private function zlozWiersz(array $raw): ?array
    {
        $testId = $this->wartosc($raw, [
            'result id',
            'test id',
            'testid',
            'id',
            'result_id',
            'test_id',
        ]);
        $ip = $this->wartosc($raw, [
            'ip address',
            'ipaddress',
            'client ip',
            'ip',
            'ip_address',
            'client_ip',
        ]);
        // Ookla często zostawia ipAddress puste przy teście IPv6 — kolumna „ipv6 address”.
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = $this->wartosc($raw, [
                'ipv6 address',
                'ipv6address',
                'ipv6',
                'client ipv6',
                'client_ipv6',
            ]);
        }
        if ($testId === '' || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $czasRaw = $this->wartosc($raw, [
            'date',
            'resultdate',
            'result date',
            'timestamp',
            'time',
            'test date',
            'datetime',
        ]);
        $zaobserwowano = $this->normalizujCzas($czasRaw);

        $downloadKol = $this->kolumnaPredkosci($raw, [
            'download',
            'download (kbps)',
            'download (mbps)',
            'download_kbps',
            'download mbps',
            'download kbps',
        ]);
        $uploadKol = $this->kolumnaPredkosci($raw, [
            'upload',
            'upload (kbps)',
            'upload (mbps)',
            'upload_kbps',
            'upload mbps',
            'upload kbps',
        ]);

        $latencyRaw = $this->wartosc($raw, [
            'latency',
            'ping',
            'ping (ms)',
            'idle latency',
            'latency_ms',
        ]);

        return [
            'test_id'           => $testId,
            'ip'                => $ip,
            'zaobserwowano_at'  => $zaobserwowano,
            'download_kbps'     => $this->kbps($downloadKol['wartosc'], $downloadKol['nazwa']),
            'upload_kbps'       => $this->kbps($uploadKol['wartosc'], $uploadKol['nazwa']),
            'latency_ms'        => $this->ms($latencyRaw),
            'isp'               => $this->opcjonalny($raw, [
                'isp',
                'isp_name',
                'isp name',
                'ispname',
                'ispnameraw',
                'provider',
            ]),
            'platform'          => $this->opcjonalny($raw, [
                'operating system',
                'platform',
                'os',
                'client',
                'useragent',
                'user agent',
            ]),
            'server_name'       => $this->opcjonalny($raw, [
                'server',
                'server name',
                'server_name',
                'servername',
                'host',
            ]),
            'raw'               => $raw,
        ];
    }

    /**
     * @param array<string, string> $raw
     * @param list<string>          $kandydaci
     *
     * @return array{nazwa: string, wartosc: string}
     */
    private function kolumnaPredkosci(array $raw, array $kandydaci): array
    {
        foreach ($kandydaci as $k) {
            if (isset($raw[$k]) && $raw[$k] !== '') {
                return ['nazwa' => $k, 'wartosc' => $raw[$k]];
            }
        }

        return ['nazwa' => '', 'wartosc' => ''];
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

    /**
     * @param array<string, string> $raw
     * @param list<string>          $kandydaci
     */
    private function opcjonalny(array $raw, array $kandydaci): ?string
    {
        $v = $this->wartosc($raw, $kandydaci);

        return $v !== '' ? $v : null;
    }

    private function kbps(string $wartosc, string $nazwaKolumny): ?int
    {
        $w = strtolower(trim($wartosc));
        if ($w === '') {
            return null;
        }
        $mbpsZWartosci = str_contains($w, 'mbps');
        $w             = str_replace(['mbps', 'kbps', 'mbit', 'kbit', ','], ['', '', '', '', '.'], $w);
        $w             = trim($w);
        if ($w === '' || ! is_numeric($w)) {
            return null;
        }
        $liczba = (float) $w;
        $jakoMbps = $mbpsZWartosci || str_contains($nazwaKolumny, 'mbps');
        if ($jakoMbps) {
            return (int) round($liczba * 1000);
        }

        return (int) round($liczba);
    }

    private function ms(string $wartosc): ?float
    {
        $w = strtolower(trim($wartosc));
        if ($w === '') {
            return null;
        }
        $w = str_replace(['ms', ','], ['', '.'], $w);
        $w = trim($w);
        if ($w === '' || ! is_numeric($w)) {
            return null;
        }

        return (float) $w;
    }

    private function normalizujCzas(string $raw): string
    {
        if ($raw === '') {
            return gmdate('Y-m-d H:i:sP');
        }

        $formaty = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s',
            'm/d/Y g:i A',
            'm/d/Y H:i:s',
        ];
        foreach ($formaty as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                $bledy = \DateTimeImmutable::getLastErrors();
                if (is_array($bledy) && (($bledy['warning_count'] ?? 0) > 0 || ($bledy['error_count'] ?? 0) > 0)) {
                    continue;
                }

                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
            }
        }

        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return gmdate('Y-m-d H:i:sP');
        }
    }

    private function wykryjSeparator(string $naglowek): string
    {
        $przecinki = substr_count($naglowek, ',');
        $sredniki  = substr_count($naglowek, ';');

        return $sredniki > $przecinki ? ';' : ',';
    }
}
