<?php

namespace McsPluginy\mojecert;

/**
 * Mapowanie wiersza skanu moje.cert.pl → pola MCS.
 *
 * klucz_dedupu = sha256('scan' + \\0 + org + subnet + ip + ts + target + report_type)
 * — ten sam algorytm co LMSMojeCertPlugin (kompatybilność migracji).
 */
class Parser
{
    /**
     * @param array<string, mixed> $row          surowy wiersz API / fixture
     * @param string               $orgSlug
     * @param string               $subnetStorage etykieta CIDR/slug do hasha (jak w LMS)
     *
     * @return array{
     *   ip: string,
     *   klucz_dedupu: string,
     *   timestamp: string,
     *   zaobserwowano_at: string,
     *   report_type: string,
     *   severity: string,
     *   target: string,
     *   last_domain: string,
     *   name_title: string,
     *   detail_excerpt: string,
     *   kategoria: string,
     *   raw: array<string, mixed>
     * }|null
     */
    public function mapujWiersz(array $row, string $orgSlug, string $subnetStorage): ?array
    {
        $targetIp = isset($row['target_ip']) ? trim((string) $row['target_ip']) : '';
        if ($targetIp === '' || filter_var($targetIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $tsRaw  = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
        $target = isset($row['target']) ? (string) $row['target'] : '';
        $rpt    = isset($row['report_type']) ? (string) $row['report_type'] : '';
        $sev    = $this->flattenScalar($row['severity'] ?? '');
        $ld     = isset($row['last_domain']) ? (string) $row['last_domain'] : '';

        $html = '';
        if (isset($row['html']) && is_string($row['html'])) {
            $html = $row['html'];
        } elseif (isset($row['report_html']) && is_string($row['report_html'])) {
            $html = $row['report_html'];
        }

        $parts = [$orgSlug, $subnetStorage, $targetIp, $tsRaw, $target, $rpt];
        $eid   = hash('sha256', 'scan' . "\0" . implode("\0", $parts));

        $title = $target !== '' ? $target : ($rpt !== '' ? $rpt : 'scan');

        return [
            'ip'               => $targetIp,
            'klucz_dedupu'     => $eid,
            'timestamp'        => $tsRaw,
            'zaobserwowano_at' => $this->normalizujCzas($tsRaw),
            'report_type'      => $rpt,
            'severity'         => $sev,
            'target'           => $target,
            'last_domain'      => $ld,
            'name_title'       => $title,
            'detail_excerpt'   => $this->excerpt($html, 4000),
            'kategoria'        => $this->kategoriaDlaReportType($rpt),
            'raw'              => $row,
        ];
    }

    /**
     * Klucz dedupu 1:1 z LMS (jawny helper do testów).
     */
    public function kluczDedupu(
        string $orgSlug,
        string $subnetStorage,
        string $targetIp,
        string $tsRaw,
        string $target,
        string $reportType,
    ): string {
        $parts = [$orgSlug, $subnetStorage, $targetIp, $tsRaw, $target, $reportType];

        return hash('sha256', 'scan' . "\0" . implode("\0", $parts));
    }

    public function kategoriaDlaReportType(string $reportType): string
    {
        $t = strtolower(trim($reportType));
        if ($t === '') {
            return 'exposure';
        }
        // Typowe skany SSL / ekspozycja usług → exposure; „vuln” w nazwie → vulnerability
        if (str_contains($t, 'vuln') || str_contains($t, 'cve') || str_contains($t, 'rce')) {
            return 'vulnerability';
        }

        return 'exposure';
    }

    /**
     * @param mixed $v
     */
    public function flattenScalar(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            if (isset($v['value']) && is_scalar($v['value'])) {
                return (string) $v['value'];
            }
            if (isset($v[0]) && is_scalar($v[0])) {
                return (string) $v[0];
            }

            return (string) json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        return (string) $v;
    }

    public function excerpt(string $text, int $max = 4000): string
    {
        $t = trim(strip_tags($text));
        if (mb_strlen($t) > $max) {
            return mb_substr($t, 0, $max) . '…';
        }

        return $t;
    }

    /**
     * Parsuje fixture JSON: albo opakowanie {org_slug, subnet_cidr, results:[]},
     * albo goła tablica results (wtedy org/subnet z argumentów domyślnych).
     *
     * @return array{org_slug: string, org_name: string, subnet_cidr: string, results: list<array<string,mixed>>}
     */
    public function parsujFixtureJson(string $json, string $domyslnyOrg = 'fixture-org', string $domyslnySubnet = '0.0.0.0/0'): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('MojeCert: fixture nie jest poprawnym JSON');
        }

        if (array_is_list($data)) {
            return [
                'org_slug'    => $domyslnyOrg,
                'org_name'    => $domyslnyOrg,
                'subnet_cidr' => $domyslnySubnet,
                'results'     => $data,
            ];
        }

        $results = $data['results'] ?? null;
        if (! is_array($results)) {
            throw new \RuntimeException('MojeCert: fixture bez tablicy results');
        }

        return [
            'org_slug'    => (string) ($data['org_slug'] ?? $domyslnyOrg),
            'org_name'    => (string) ($data['org_name'] ?? ($data['org_slug'] ?? $domyslnyOrg)),
            'subnet_cidr' => (string) ($data['subnet_cidr'] ?? $domyslnySubnet),
            'results'     => array_values(array_filter($results, 'is_array')),
        ];
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
