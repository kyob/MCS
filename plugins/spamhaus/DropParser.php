<?php

namespace McsPluginy\spamhaus;

use RuntimeException;

/**
 * Parser plików Spamhaus DROP w formacie JSON (jeden obiekt JSON na linię, NDJSON).
 *
 * Ostatnia linia to metadane, np.:
 *   {"type":"metadata","timestamp":1791926042,"size":102603,"records":1682,
 *    "copyright":"(c) 2026 The Spamhaus Project SLU","terms":"https://www.spamhaus.org/drop/terms/"}
 * Rekordy: drop_v4/drop_v6 → {"cidr","sblid","rir"}, asndrop → {"asn","rir","domain","cc","asname"}.
 *
 * Źródło formatu: https://www.spamhaus.org/blocklists/do-not-route-or-peer/ (FAQ, przykłady jq).
 *
 * Pusta albo niekompletna odpowiedź = wyjątek. Nigdy nie zwracamy „0 wpisów” jako sukcesu,
 * bo wynik „czysto” musi wynikać z kompletnego pliku.
 */
final class DropParser
{
    /**
     * @return array{
     *   wpisy: list<array<string, mixed>>,
     *   meta: array{timestamp: ?int, copyright: string, terms: string, records: ?int}
     * }
     */
    public function parsuj(string $tresc, string $lista): array
    {
        if (trim($tresc) === '') {
            throw new RuntimeException("Spamhaus {$lista}: pusta odpowiedź");
        }

        $kluczWpisu = $lista === 'asndrop' ? 'asn' : 'cidr';
        $wpisy      = [];
        $meta       = null;
        $zle        = 0;

        foreach (preg_split('/\r?\n/', $tresc) ?: [] as $linia) {
            $linia = trim($linia);
            if ($linia === '') {
                continue;
            }
            $obj = json_decode($linia, true);
            if (! is_array($obj)) {
                $zle++;
                continue;
            }
            if (($obj['type'] ?? null) === 'metadata') {
                $meta = $obj;
                continue;
            }
            if (! isset($obj[$kluczWpisu])) {
                $zle++;
                continue;
            }
            $wpisy[] = $obj;
        }

        if ($meta === null) {
            throw new RuntimeException("Spamhaus {$lista}: brak linii metadanych (plik niekompletny?)");
        }
        if ($wpisy === []) {
            throw new RuntimeException("Spamhaus {$lista}: 0 rekordów w pliku");
        }
        if ($zle > 0) {
            throw new RuntimeException("Spamhaus {$lista}: {$zle} nieczytelnych linii");
        }

        $records = isset($meta['records']) && is_numeric($meta['records']) ? (int) $meta['records'] : null;
        if ($records !== null && $records !== count($wpisy)) {
            throw new RuntimeException(sprintf(
                'Spamhaus %s: liczba rekordów %d ≠ metadane records=%d',
                $lista,
                count($wpisy),
                $records,
            ));
        }

        return [
            'wpisy' => $wpisy,
            'meta'  => [
                'timestamp' => isset($meta['timestamp']) && is_numeric($meta['timestamp']) ? (int) $meta['timestamp'] : null,
                'copyright' => (string) ($meta['copyright'] ?? ''),
                'terms'     => (string) ($meta['terms'] ?? ''),
                'records'   => $records,
            ],
        ];
    }
}
