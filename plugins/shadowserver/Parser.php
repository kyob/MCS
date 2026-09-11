<?php

namespace McsPluginy\shadowserver;

/**
 * Parser CSV Shadowserver — mapowanie po nazwach nagłówków (nie po pozycji kolumn).
 *
 * Fixture / próbki syntetyczne do czasu prawdziwych raportów z Waszej skrzynki.
 * Dedup (logiczny): ip + tag + port + protokol.
 */
final class Parser
{
    /**
     * @return list<array{
     *   ip: string,
     *   port: ?int,
     *   protokol: ?string,
     *   tag: ?string,
     *   severity: ?string,
     *   timestamp: string,
     *   klucz_dedupu: string,
     *   raw: array<string, string>
     * }>
     */
    public function parsujCsv(string $tresc): array
    {
        $tresc = preg_replace("/^\xEF\xBB\xBF/", '', $tresc) ?? $tresc;
        $wiersze = preg_split("/\r\n|\n|\r/", trim($tresc)) ?: [];
        if ($wiersze === [] || $wiersze[0] === '') {
            return [];
        }

        $naglowki = str_getcsv(array_shift($wiersze));
        if ($naglowki === false || $naglowki === [null] || $naglowki === []) {
            return [];
        }
        $naglowki = array_map(static fn ($h) => strtolower(trim((string) $h)), $naglowki);

        $wynik = [];
        foreach ($wiersze as $linia) {
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

            $ip = $raw['ip'] ?? $raw['src_ip'] ?? $raw['dst_ip'] ?? '';
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $port = null;
            if (isset($raw['port']) && $raw['port'] !== '' && ctype_digit($raw['port'])) {
                $port = (int) $raw['port'];
            }

            $protokol = $raw['protocol'] ?? $raw['proto'] ?? null;
            if ($protokol === '') {
                $protokol = null;
            }

            $tag = $raw['tag'] ?? $raw['type'] ?? $raw['category'] ?? null;
            if ($tag === '') {
                $tag = null;
            }

            $severity = $raw['severity'] ?? null;
            if ($severity === '') {
                $severity = null;
            }

            $timestamp = $raw['timestamp'] ?? $raw['time'] ?? $raw['date'] ?? '';
            if ($timestamp === '') {
                $timestamp = gmdate('Y-m-d H:i:s');
            }

            $klucz = strtolower($ip) . '|' . strtolower((string) ($tag ?? ''))
                . '|' . ($port ?? '') . '|' . strtolower((string) ($protokol ?? ''));

            $wynik[] = [
                'ip'            => $ip,
                'port'          => $port,
                'protokol'      => $protokol !== null ? strtolower($protokol) : null,
                'tag'           => $tag,
                'severity'      => $severity !== null ? strtolower($severity) : null,
                'timestamp'     => $timestamp,
                'klucz_dedupu'  => $klucz,
                'raw'           => $raw,
            ];
        }

        return $wynik;
    }

    /**
     * Mapowanie tag/severity → kategoria MCS pod WagaV0.
     */
    public function kategoriaDlaWiersza(array $wiersz): string
    {
        $tag = strtolower((string) ($wiersz['tag'] ?? ''));
        if (str_contains($tag, 'amplif') || str_contains($tag, 'dns-open') || str_contains($tag, 'ntp')) {
            return 'amplification';
        }
        if (str_contains($tag, 'malware') || str_contains($tag, 'botnet') || str_contains($tag, 'c2')) {
            return 'malware';
        }
        if (str_contains($tag, 'vuln') || str_contains($tag, 'exploit')) {
            return 'vulnerability';
        }
        if (str_contains($tag, 'scan') || str_contains($tag, 'bruteforce') || str_contains($tag, 'spam')) {
            return 'abuse';
        }
        if (str_contains($tag, 'open') || str_contains($tag, 'accessible') || str_contains($tag, 'exposed')) {
            return 'exposure';
        }

        return 'other';
    }
}
