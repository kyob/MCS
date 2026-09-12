<?php

namespace McsPluginy\cert_n6;

/**
 * Mapowanie zdarzenia n6 report/inside → pola MCS.
 *
 * jak LMS cert_n6_map_event_from_item: pierwszy address[], klucz_dedupu = id n6.
 */
class Parser
{
    /**
     * Kategoria n6 → kategoria MCS (WagaV1).
     *
     * @var array<string, string>
     */
    public const MAPA_KATEGORII = [
        'bots'            => 'malware',
        'cnc'             => 'malware',
        'malware-action'  => 'malware',
        'vulnerable'      => 'vulnerability',
        'server-exploit'  => 'vulnerability',
        'amplifier'       => 'amplification',
        'dos-attacker'    => 'amplification',
        'dos-victim'      => 'amplification',
        'spam'            => 'abuse',
        'phish'           => 'abuse',
        'proxy'           => 'abuse',
        'tor'             => 'abuse',
        'scanning'        => 'exposure',
        'other'           => 'other',
    ];

    /**
     * @param array<string, mixed> $item
     *
     * @return array{
     *   n6_id: string,
     *   klucz_dedupu: string,
     *   ip: string,
     *   asn: int,
     *   cc: string,
     *   category: string,
     *   confidence: string,
     *   name: string,
     *   source: string,
     *   time: string,
     *   zaobserwowano_at: string,
     *   dport: int,
     *   sport: int,
     *   fqdn: string,
     *   proto: string,
     *   origin: string,
     *   restriction: string,
     *   modified: string,
     *   adip: string,
     *   product: string,
     *   n6kb_url: string,
     *   kategoria: string,
     *   raw: array<string, mixed>
     * }|null
     */
    public function mapujZdarzenie(array $item): ?array
    {
        if (empty($item['id'])) {
            return null;
        }
        if (! isset($item['address'][0]) || ! is_array($item['address'][0])) {
            return null;
        }
        $addr = $item['address'][0];
        $ip   = isset($addr['ip']) ? trim((string) $addr['ip']) : '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $category   = isset($item['category']) ? (string) $item['category'] : 'other';
        $confidence = isset($item['confidence']) ? (string) $item['confidence'] : 'medium';
        $name       = isset($item['name']) ? (string) $item['name'] : '';
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        $n6Id = (string) $item['id'];

        return [
            'n6_id'            => $n6Id,
            'klucz_dedupu'     => $n6Id,
            'ip'               => $ip,
            'asn'              => isset($addr['asn']) ? (int) $addr['asn'] : 0,
            'cc'               => isset($addr['cc']) ? (string) $addr['cc'] : '',
            'category'         => $category,
            'confidence'       => $confidence,
            'name'             => $name,
            'source'           => isset($item['source']) ? (string) $item['source'] : '',
            'time'             => isset($item['time']) ? (string) $item['time'] : '',
            'zaobserwowano_at' => $this->normalizujCzas(isset($item['time']) ? (string) $item['time'] : ''),
            'dport'            => isset($item['dport']) ? (int) $item['dport'] : 0,
            'sport'            => isset($item['sport']) ? (int) $item['sport'] : 0,
            'fqdn'             => $this->clip((string) ($item['fqdn'] ?? ''), 255),
            'proto'            => $this->clip((string) ($item['proto'] ?? ''), 16),
            'origin'           => $this->clip((string) ($item['origin'] ?? ''), 64),
            'restriction'      => $this->clip((string) ($item['restriction'] ?? ''), 32),
            'modified'         => $this->clip((string) ($item['modified'] ?? ''), 64),
            'adip'             => $this->clip((string) ($item['adip'] ?? ''), 128),
            'product'          => $this->clip((string) ($item['product'] ?? ''), 255),
            'n6kb_url'         => $this->extractN6kbUrl($item),
            'kategoria'        => $this->kategoriaDlaN6($category),
            'raw'              => $item,
        ];
    }

    public function kategoriaDlaN6(string $category): string
    {
        $k = strtolower(trim($category));
        if ($k === '') {
            return 'other';
        }

        return self::MAPA_KATEGORII[$k] ?? 'other';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parsujReportJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('CERT N6: odpowiedz nie jest poprawnym JSON');
        }
        if (! array_is_list($data)) {
            throw new \RuntimeException('CERT N6: oczekiwano tablicy zdarzen');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param array<string, mixed> $item
     */
    public function extractN6kbUrl(array $item): string
    {
        if (empty($item['name_details']) || ! is_array($item['name_details'])) {
            return '';
        }
        $phraseBlock = isset($item['name_details']['phrase']) && is_array($item['name_details']['phrase'])
            ? $item['name_details']['phrase']
            : [];
        foreach ($phraseBlock as $phraseMeta) {
            if (! is_array($phraseMeta)) {
                continue;
            }
            if (! empty($phraseMeta['n6kb']) && is_array($phraseMeta['n6kb']) && isset($phraseMeta['n6kb'][0])) {
                return $this->clip((string) $phraseMeta['n6kb'][0], 1024);
            }
        }

        return '';
    }

    public static function buildReportUrl(
        string $baseUrl,
        string $resource,
        string $timeMin,
        ?int $optLimit = null,
        ?string $modifiedMin = null,
    ): string {
        $base     = rtrim($baseUrl, '/');
        $resource = trim($resource, '/');
        $params   = ['time.min' => $timeMin];
        if ($optLimit !== null && $optLimit > 0) {
            $params['opt.limit'] = $optLimit;
        }
        if ($modifiedMin !== null && $modifiedMin !== '') {
            $params['modified.min'] = $modifiedMin;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $base . '/' . $resource . '.json?' . $query;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function maxEventTime(array $items): ?string
    {
        $best   = null;
        $bestTs = null;
        foreach ($items as $item) {
            $t = isset($item['time']) ? trim((string) $item['time']) : '';
            if ($t === '') {
                continue;
            }
            $ts = strtotime($t . ' UTC');
            if ($ts === false) {
                $ts = strtotime($t);
            }
            if ($ts === false) {
                continue;
            }
            if ($bestTs === null || $ts > $bestTs) {
                $bestTs = $ts;
                $best   = $t;
            }
        }

        return $best;
    }

    private function clip(string $s, int $max): string
    {
        if (strlen($s) > $max) {
            return substr($s, 0, $max);
        }

        return $s;
    }

    private function normalizujCzas(string $timestamp): string
    {
        if ($timestamp === '') {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        }

        try {
            $dt = new \DateTimeImmutable($timestamp);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        }
    }
}
