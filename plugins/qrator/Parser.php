<?php

namespace McsPluginy\qrator;

/**
 * Mapowanie zdarzeń Qrator Radar → pola MCS (tor sieć / ASN, nie badge hosta).
 *
 * klucz_dedupu zależy od typu feedu (prefix + ASN + first_seen / IP amplifier).
 */
class Parser
{
    public const MAPA_WERSJA = 'qrator-radar-v0';

    /**
     * @param array{feed?: string, raw?: array<string, mixed>}|array<string, mixed> $event
     * @param int                                                                   $monitoredAsn
     *
     * @return array{
     *   event_typ: string,
     *   asn: int,
     *   prefix: ?string,
     *   ip: ?string,
     *   severity: ?string,
     *   first_seen: ?string,
     *   last_seen: ?string,
     *   klucz_dedupu: string,
     *   zaobserwowano_at: string,
     *   podmiot_typ: string,
     *   podmiot: string,
     *   kategoria: string,
     *   tytul: string,
     *   severity_sygnal: ?string,
     *   tag: string,
     *   raw: array<string, mixed>
     * }|null
     */
    public function mapujWpis(array $event, int $monitoredAsn): ?array
    {
        $feed = isset($event['feed']) ? strtolower(trim((string) $event['feed'])) : '';
        $raw  = $event['raw'] ?? null;
        if (! is_array($raw)) {
            // Fixture może podać już „płaski” wpis z event_typ.
            if (isset($event['event_typ']) || isset($event['hijacker']) || isset($event['prefix'])) {
                $raw  = $event;
                $feed = $feed !== '' ? $feed : strtolower(trim((string) ($event['event_typ'] ?? '')));
            } else {
                return null;
            }
        }

        if ($feed === '' && isset($raw['event_typ'])) {
            $feed = strtolower(trim((string) $raw['event_typ']));
        }

        return match (true) {
            str_starts_with($feed, 'hijack') => $this->mapujHijack($feed, $raw, $monitoredAsn),
            str_starts_with($feed, 'leak')   => $this->mapujLeak($feed, $raw, $monitoredAsn),
            str_starts_with($feed, 'bogon')  => $this->mapujBogon($feed, $raw, $monitoredAsn),
            str_starts_with($feed, 'amplif') => $this->mapujAmplifier($raw, $monitoredAsn),
            default                          => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    public function mapujListe(array $events, int $monitoredAsn): array
    {
        $out = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $mapped = $this->mapujWpis($event, $monitoredAsn);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Fixture JSON → lista {feed, raw}.
     *
     * Akceptuje:
     * - { "events": [ {feed, raw|...}, ... ], "asn": N }
     * - { "hijacks_affected": [...], "leaks_created": [...], ... }
     * - [ {feed, ...}, ... ]
     *
     * @return array{asn: ?int, events: list<array{feed: string, raw: array<string, mixed>}>}
     */
    public function parsujJson(string $tresc): array
    {
        $decoded = json_decode($tresc, true);
        if (! is_array($decoded)) {
            return ['asn' => null, 'events' => []];
        }

        $asn = null;
        if (isset($decoded['asn']) && (is_int($decoded['asn']) || ctype_digit((string) $decoded['asn']))) {
            $asn = (int) $decoded['asn'];
        }

        if (isset($decoded['events']) && is_array($decoded['events'])) {
            return [
                'asn'    => $asn,
                'events' => $this->normalizujListeEventow($decoded['events']),
            ];
        }

        if (array_is_list($decoded)) {
            return [
                'asn'    => $asn,
                'events' => $this->normalizujListeEventow($decoded),
            ];
        }

        $events = [];
        foreach (ApiClient::ALLOWED_FEEDS as $feed) {
            if (! isset($decoded[$feed]) || ! is_array($decoded[$feed])) {
                continue;
            }
            foreach ($decoded[$feed] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $events[] = ['feed' => $feed, 'raw' => $row];
            }
        }

        return ['asn' => $asn, 'events' => $events];
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array{feed: string, raw: array<string, mixed>}>
     */
    private function normalizujListeEventow(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['feed'], $row['raw']) && is_array($row['raw'])) {
                $out[] = [
                    'feed' => strtolower(trim((string) $row['feed'])),
                    'raw'  => $row['raw'],
                ];
                continue;
            }
            $feed = strtolower(trim((string) ($row['event_typ'] ?? $row['feed'] ?? '')));
            if ($feed === '') {
                continue;
            }
            $raw = $row;
            unset($raw['feed'], $raw['event_typ']);
            $out[] = ['feed' => $feed, 'raw' => $raw !== [] ? $raw : $row];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function mapujHijack(string $feed, array $raw, int $monitoredAsn): ?array
    {
        $eventTyp = $feed === ApiClient::FEED_HIJACKS_CREATED || $feed === 'hijack_created'
            ? 'hijack_created'
            : 'hijack_affected';

        $hijacker = is_array($raw['hijacker'] ?? null) ? $raw['hijacker'] : [];
        $affected = is_array($raw['affected'] ?? null) ? $raw['affected'] : [];

        $prefix = $this->prefixZ([
            $eventTyp === 'hijack_affected' ? ($affected['prefix'] ?? null) : ($hijacker['prefix'] ?? null),
            $affected['prefix'] ?? null,
            $hijacker['prefix'] ?? null,
            $raw['prefix'] ?? null,
        ]);

        $firstSeen = $this->normalizujCzas(isset($raw['first_seen']) ? (string) $raw['first_seen'] : null);
        $lastSeen  = $this->normalizujCzas(isset($raw['last_seen']) ? (string) $raw['last_seen'] : null);
        $severity = $this->normalizujSeverity(isset($raw['severity']) ? (string) $raw['severity'] : null);

        $hijackerAsn = isset($hijacker['asn']) ? (int) $hijacker['asn'] : 0;
        $affectedAsn = isset($affected['asn']) ? (int) $affected['asn'] : $monitoredAsn;

        $klucz = implode('|', [
            $eventTyp,
            $prefix ?? '-',
            (string) ($eventTyp === 'hijack_created' ? $affectedAsn : $hijackerAsn),
            $firstSeen ?? 'nodate',
        ]);

        $tytul = $eventTyp === 'hijack_created'
            ? 'Qrator Radar — hijack utworzony'
            : 'Qrator Radar — hijack dotyczący AS';
        if ($prefix !== null) {
            $tytul .= ' (' . $prefix . ')';
        }

        return $this->zloz(
            $eventTyp,
            $monitoredAsn,
            $prefix,
            null,
            $severity,
            $firstSeen,
            $lastSeen,
            $klucz,
            'abuse',
            $tytul,
            'bgp-hijack',
            $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function mapujLeak(string $feed, array $raw, int $monitoredAsn): ?array
    {
        $eventTyp = match ($feed) {
            ApiClient::FEED_LEAKS_ACCEPTED, 'leak_accepted' => 'leak_accepted',
            default                                         => 'leak_created',
        };

        $prefix = $this->prefixZ([$raw['prefix'] ?? null]);
        $leaker = is_array($raw['leaker'] ?? null) ? $raw['leaker'] : [];
        $leakerAsn = isset($leaker['asn']) ? (int) $leaker['asn'] : (isset($raw['leaker']) && is_numeric($raw['leaker']) ? (int) $raw['leaker'] : 0);

        $firstSeen = $this->normalizujCzas(isset($raw['first_seen']) ? (string) $raw['first_seen'] : null);
        $lastSeen  = $this->normalizujCzas(isset($raw['last_seen']) ? (string) $raw['last_seen'] : null);
        $severity = $this->normalizujSeverity(isset($raw['severity']) ? (string) $raw['severity'] : null);

        $klucz = implode('|', [
            $eventTyp,
            $prefix ?? '-',
            (string) $leakerAsn,
            $firstSeen ?? 'nodate',
        ]);

        $tytul = $eventTyp === 'leak_accepted'
            ? 'Qrator Radar — leak zaakceptowany'
            : 'Qrator Radar — leak utworzony';
        if ($prefix !== null) {
            $tytul .= ' (' . $prefix . ')';
        }

        return $this->zloz(
            $eventTyp,
            $monitoredAsn,
            $prefix,
            null,
            $severity,
            $firstSeen,
            $lastSeen,
            $klucz,
            'abuse',
            $tytul,
            'bgp-leak',
            $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function mapujBogon(string $feed, array $raw, int $monitoredAsn): ?array
    {
        $eventTyp = 'bogon_created';
        $prefix   = $this->prefixZ([$raw['prefix'] ?? null]);
        $firstSeen = $this->normalizujCzas(isset($raw['first_seen']) ? (string) $raw['first_seen'] : null);
        $lastSeen  = $this->normalizujCzas(isset($raw['last_seen']) ? (string) $raw['last_seen'] : null);
        $severity = $this->normalizujSeverity(isset($raw['severity']) ? (string) $raw['severity'] : null);

        $origin = is_array($raw['origin'] ?? null) ? $raw['origin'] : [];
        $originAsn = isset($origin['asn']) ? (int) $origin['asn'] : 0;

        $klucz = implode('|', [
            $eventTyp,
            $prefix ?? '-',
            (string) $originAsn,
            $firstSeen ?? 'nodate',
        ]);

        $tytul = 'Qrator Radar — bogon prefix';
        if ($prefix !== null) {
            $tytul .= ' (' . $prefix . ')';
        }

        return $this->zloz(
            $eventTyp,
            $monitoredAsn,
            $prefix,
            null,
            $severity,
            $firstSeen,
            $lastSeen,
            $klucz,
            'exposure',
            $tytul,
            'bgp-bogon',
            $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function mapujAmplifier(array $raw, int $monitoredAsn): ?array
    {
        $ip = null;
        foreach (['ip', 'address', 'host', 'src'] as $k) {
            if (! isset($raw[$k])) {
                continue;
            }
            $cand = trim((string) $raw[$k]);
            if (filter_var($cand, FILTER_VALIDATE_IP) !== false) {
                $ip = $cand;
                break;
            }
        }
        $prefix = $this->prefixZ([$raw['prefix'] ?? null]);
        if ($ip === null && $prefix !== null) {
            $parts = explode('/', $prefix, 2);
            if (filter_var($parts[0], FILTER_VALIDATE_IP) !== false) {
                $ip = $parts[0];
            }
        }

        $port  = isset($raw['port']) ? (int) $raw['port'] : (isset($raw['dport']) ? (int) $raw['dport'] : 0);
        $proto = isset($raw['protocol']) ? strtolower(trim((string) $raw['protocol'])) : (isset($raw['proto']) ? strtolower(trim((string) $raw['proto'])) : '');
        $typ   = isset($raw['type']) ? trim((string) $raw['type']) : (isset($raw['amplifier_type']) ? trim((string) $raw['amplifier_type']) : '');

        $firstSeen = $this->normalizujCzas(
            isset($raw['first_seen']) ? (string) $raw['first_seen'] : (isset($raw['seen']) ? (string) $raw['seen'] : null),
        );
        $lastSeen = $this->normalizujCzas(isset($raw['last_seen']) ? (string) $raw['last_seen'] : null);
        $severity = $this->normalizujSeverity(isset($raw['severity']) ? (string) $raw['severity'] : null);

        if ($ip === null && $prefix === null && $typ === '') {
            // Brak identyfikatora — hash payloadu (lepiej zapisać niż zgubić).
            $klucz = 'amplifier|' . substr(hash('sha256', json_encode($raw) ?: ''), 0, 16);
        } else {
            $klucz = implode('|', [
                'amplifier',
                $ip ?? ($prefix ?? '-'),
                (string) $port,
                $proto !== '' ? $proto : '-',
                $typ !== '' ? $typ : '-',
            ]);
        }

        $tytul = 'Qrator Radar — amplifier';
        if ($typ !== '') {
            $tytul .= ' ' . $typ;
        }
        if ($ip !== null) {
            $tytul .= ' (' . $ip . ')';
        } elseif ($prefix !== null) {
            $tytul .= ' (' . $prefix . ')';
        }

        return $this->zloz(
            'amplifier',
            $monitoredAsn,
            $prefix,
            $ip,
            $severity,
            $firstSeen,
            $lastSeen,
            $klucz,
            'amplification',
            $tytul,
            'ddos-amplifier',
            $raw,
        );
    }

    /**
     * @param list<mixed> $kandydaci
     */
    private function prefixZ(array $kandydaci): ?string
    {
        foreach ($kandydaci as $c) {
            if ($c === null) {
                continue;
            }
            $p = trim((string) $c);
            if ($p !== '' && str_contains($p, '/')) {
                return $p;
            }
        }

        return null;
    }

    private function normalizujSeverity(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = strtolower(trim($raw));
        if ($s === 'info') {
            return 'low';
        }
        if (in_array($s, ['low', 'medium', 'high', 'critical'], true)) {
            return $s;
        }

        return null;
    }

    private function normalizujCzas(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($raw);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function zloz(
        string $eventTyp,
        int $monitoredAsn,
        ?string $prefix,
        ?string $ip,
        ?string $severity,
        ?string $firstSeen,
        ?string $lastSeen,
        string $klucz,
        string $kategoria,
        string $tytul,
        string $tag,
        array $raw,
    ): array {
        $zaobserwowano = $firstSeen ?? $lastSeen;
        if ($zaobserwowano === null) {
            $zaobserwowano = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        }

        if ($ip !== null) {
            $podmiotTyp = 'ip';
            $podmiot    = $ip;
        } elseif ($prefix !== null) {
            $podmiotTyp = 'cidr';
            $podmiot    = $prefix;
        } else {
            $podmiotTyp = 'siec';
            $podmiot    = 'AS' . $monitoredAsn;
        }

        return [
            'event_typ'        => $eventTyp,
            'asn'              => $monitoredAsn,
            'prefix'           => $prefix,
            'ip'               => $ip,
            'severity'         => $severity,
            'first_seen'       => $firstSeen,
            'last_seen'        => $lastSeen,
            'klucz_dedupu'     => $klucz,
            'zaobserwowano_at' => $zaobserwowano,
            'podmiot_typ'      => $podmiotTyp,
            'podmiot'          => $podmiot,
            'kategoria'        => $kategoria,
            'tytul'            => $tytul,
            'severity_sygnal'  => $severity,
            'tag'              => $tag,
            'raw'              => $raw,
        ];
    }
}
