<?php

namespace McsPluginy\cloudflare_botnet;

/**
 * Mapowanie wpisu Cloudflare Botnet Threat Feed → pola MCS.
 *
 * klucz_dedupu = "{cidr}|{YYYY-MM-DD}" (ofensywy per dzień per CIDR).
 */
class Parser
{
    public const MAPA_WERSJA = 'cloudflare-botnet-v0';

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $cidrAllowlist puste = bez filtra
     *
     * @return array{
     *   cidr: string,
     *   ip: string,
     *   date_raw: string,
     *   date_ymd: string,
     *   offense_count: int,
     *   klucz_dedupu: string,
     *   zaobserwowano_at: string,
     *   kategoria: string,
     *   severity_sygnal: ?string,
     *   tag: string,
     *   raw: array<string, mixed>
     * }|null
     */
    public function mapujWpis(array $entry, array $cidrAllowlist = []): ?array
    {
        $cidr = isset($entry['cidr']) ? trim((string) $entry['cidr']) : '';
        $ip   = $this->ipZCidr($cidr);
        if ($ip === null) {
            return null;
        }

        if ($cidrAllowlist !== [] && ! $this->ipWAllowlist($ip, $cidrAllowlist)) {
            return null;
        }

        $dateRaw = isset($entry['date']) ? trim((string) $entry['date']) : '';
        $dateYmd = $this->normalizujDateYmd($dateRaw);
        if ($dateYmd === null) {
            return null;
        }

        $offenses = isset($entry['offense_count']) ? (int) $entry['offense_count'] : 0;
        if ($offenses < 0) {
            $offenses = 0;
        }

        $zaobserwowano = $this->zaobserwowanoAt($dateRaw, $dateYmd);

        return [
            'cidr'             => $cidr,
            'ip'               => $ip,
            'date_raw'         => $dateRaw,
            'date_ymd'         => $dateYmd,
            'offense_count'    => $offenses,
            'klucz_dedupu'     => $cidr . '|' . $dateYmd,
            'zaobserwowano_at' => $zaobserwowano,
            'kategoria'        => 'malware',
            'severity_sygnal'  => $this->severityZOffenses($offenses),
            'tag'              => 'botnet-ddos',
            'raw'              => $entry,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $cidrAllowlist
     *
     * @return list<array<string, mixed>>
     */
    public function mapujListe(array $entries, array $cidrAllowlist = []): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $mapped = $this->mapujWpis($entry, $cidrAllowlist);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Fixture / raw API body → lista wpisów (result[] albo sama tablica).
     *
     * @return list<array<string, mixed>>
     */
    public function parsujJson(string $tresc): array
    {
        $decoded = json_decode($tresc, true);
        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            $rows = $decoded;
        } elseif (isset($decoded['result'])) {
            $result = $decoded['result'];
            if ($result === null || $result === 0 || $result === []) {
                return [];
            }
            $rows = is_array($result) ? $result : [];
        } else {
            $rows = [$decoded];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['cidr'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function ipZCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }
        $parts = explode('/', $cidr, 2);
        $ip    = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    public function normalizujDateYmd(string $dateRaw): ?string
    {
        $dateRaw = trim($dateRaw);
        if ($dateRaw === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateRaw, $m) === 1) {
            return $m[1];
        }
        try {
            $dt = new \DateTimeImmutable($dateRaw);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return string timestamp Postgres-friendly UTC
     */
    public function zaobserwowanoAt(string $dateRaw, string $dateYmd): string
    {
        try {
            if ($dateRaw !== '') {
                $dt = new \DateTimeImmutable($dateRaw);

                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
            }
        } catch (\Throwable) {
            // fallback poniżej
        }

        return $dateYmd . ' 00:00:00+00';
    }

    /**
     * Progi heurystyczne na podstawie offense_count (mitigated HTTP req).
     */
    public function severityZOffenses(int $offenses): ?string
    {
        if ($offenses >= 10000) {
            return 'critical';
        }
        if ($offenses >= 1000) {
            return 'high';
        }
        if ($offenses >= 100) {
            return 'medium';
        }
        if ($offenses > 0) {
            return 'low';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function parsujCidrAllowlist(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $allowlist
     */
    public function ipWAllowlist(string $ip, array $allowlist): bool
    {
        foreach ($allowlist as $cidr) {
            if ($this->ipWCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public function ipWCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }
        if (! str_contains($cidr, '/')) {
            return strcasecmp($ip, $cidr) === 0;
        }

        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $bits = (int) $bits;
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            if ($bits < 0 || $bits > 32) {
                return false;
            }
            $ipLong  = ip2long($ip);
            $netLong = ip2long($subnet);
            if ($ipLong === false || $netLong === false) {
                return false;
            }
            $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xFFFFFFFF);

            return ($ipLong & $mask) === ($netLong & $mask);
        }

        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            if ($bits < 0 || $bits > 128) {
                return false;
            }
            $ipBin  = inet_pton($ip);
            $netBin = inet_pton($subnet);
            if ($ipBin === false || $netBin === false) {
                return false;
            }
            $bytes = intdiv($bits, 8);
            $rem   = $bits % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                return false;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = (~((1 << (8 - $rem)) - 1)) & 0xFF;

            return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
        }

        return false;
    }
}
