<?php

namespace McsPluginy\wanguard;

/**
 * Mapowanie detail anomalii Wanguard → pola MCS.
 *
 * klucz_dedupu = (string) anomaly_id (jak LMS UNIQUE anomaly_id).
 * IP z prefix (np. 1.2.3.4/32) — bez wymogu istnienia hosta w LMS.
 */
class Parser
{
    /**
     * Wersja tabeli mapowań wagi/kategorii w tym pluginie.
     */
    public const MAPA_WERSJA = 'wanguard-v0';

    /**
     * Fragmenty typu anomalii → kategoria MCS (WagaV0).
     *
     * @var array<string, string>
     */
    public const MAPA_ANOMALII = [
        'udp'          => 'amplification',
        'tcp'          => 'amplification',
        'icmp'         => 'amplification',
        'syn'          => 'amplification',
        'flood'        => 'amplification',
        'ddos'         => 'amplification',
        'pkts/s'       => 'amplification',
        'bits/s'       => 'amplification',
        'amplif'       => 'amplification',
        'dns'          => 'amplification',
        'ntp'          => 'amplification',
        'ssdp'         => 'amplification',
        'memcached'    => 'amplification',
    ];

    /**
     * @param array<string, mixed> $detail
     * @param list<string>         $cidrAllowlist puste = bez filtra CIDR
     *
     * @return array{
     *   anomaly_id: int,
     *   klucz_dedupu: string,
     *   ip: string,
     *   prefix: string,
     *   status: string,
     *   anomaly: string,
     *   direction: string,
     *   from_unix: ?int,
     *   until_unix: ?int,
     *   duration_seconds: ?int,
     *   mitigated: ?int,
     *   severity_raw: string,
     *   link_severity: string,
     *   peak_pkts_s: string,
     *   peak_bits_s: string,
     *   zaobserwowano_at: string,
     *   kategoria: string,
     *   severity_sygnal: ?string,
     *   raw: array<string, mixed>
     * }|null
     */
    public function mapujAnomalie(array $detail, array $cidrAllowlist = []): ?array
    {
        $anomalyId = $this->extractAnomalyId($detail);
        if ($anomalyId === null || $anomalyId < 1) {
            return null;
        }

        $prefix = isset($detail['prefix']) ? trim((string) $detail['prefix']) : '';
        $ip     = $this->ipZPrefix($prefix);
        if ($ip === null) {
            return null;
        }

        if ($cidrAllowlist !== [] && ! $this->ipWAllowlist($ip, $cidrAllowlist)) {
            return null;
        }

        $extra = $this->extraKolumny($detail);
        $until = $extra['until_unix'];
        $from  = $extra['from_unix'];

        $anomaly = isset($detail['anomaly']) ? trim((string) $detail['anomaly']) : '';
        $kategoria = $this->kategoriaDlaAnomalii($anomaly);

        return [
            'anomaly_id'        => $anomalyId,
            'klucz_dedupu'      => (string) $anomalyId,
            'ip'                => $ip,
            'prefix'            => $prefix,
            'status'            => isset($detail['status']) ? trim((string) $detail['status']) : '',
            'anomaly'           => $anomaly,
            'direction'         => isset($detail['direction']) ? trim((string) $detail['direction']) : '',
            'from_unix'         => $from,
            'until_unix'        => $until,
            'duration_seconds'  => $extra['duration_seconds'],
            'mitigated'         => $extra['mitigated'],
            'severity_raw'      => $extra['severity_raw'],
            'link_severity'     => $extra['link_severity'],
            'peak_pkts_s'       => $extra['peak_pkts_s'],
            'peak_bits_s'       => $extra['peak_bits_s'],
            'zaobserwowano_at'  => $this->czasZUnix($until ?? $from),
            'kategoria'         => $kategoria,
            'severity_sygnal'   => $this->severityNaSygnal($extra['severity_raw']),
            'raw'               => $detail,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parsujFixtureJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('Wanguard: fixture nie jest poprawnym JSON');
        }

        // Opakowanie {details:[...]} albo surowa tablica detaili
        if (isset($data['details']) && is_array($data['details'])) {
            $details = $data['details'];
            if (array_is_list($details)) {
                return array_values(array_filter($details, 'is_array'));
            }
            $out = [];
            foreach ($details as $id => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (! isset($row['anomaly_id'])) {
                    $row['anomaly_id'] = (int) $id;
                }
                $out[] = $row;
            }

            return $out;
        }

        if (! array_is_list($data)) {
            throw new \RuntimeException('Wanguard: oczekiwano tablicy anomalii albo {details:[]}');
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function extractAnomalyId(array $detail): ?int
    {
        if (isset($detail['anomaly_id']) && is_numeric($detail['anomaly_id'])) {
            $id = (int) $detail['anomaly_id'];

            return $id > 0 ? $id : null;
        }
        if (! empty($detail['href']) && is_string($detail['href'])) {
            $base = basename(parse_url($detail['href'], PHP_URL_PATH) ?: $detail['href']);
            if (is_numeric($base)) {
                $id = (int) $base;

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    public function ipZPrefix(string $prefix): ?string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }
        $parts = explode('/', $prefix, 2);
        $ip    = trim($parts[0]);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    public function kategoriaDlaAnomalii(string $anomaly): string
    {
        $hay = strtolower($anomaly);
        if ($hay === '') {
            return 'other';
        }
        foreach (self::MAPA_ANOMALII as $fragment => $kat) {
            if (str_contains($hay, $fragment)) {
                return $kat;
            }
        }

        return 'other';
    }

    /**
     * Mapuje numeryczny severity Wanguard → low|medium|high|critical (albo null).
     */
    public function severityNaSygnal(string $severityRaw): ?string
    {
        $s = trim($severityRaw);
        if ($s === '' || $s === '-') {
            return null;
        }
        if (! is_numeric($s)) {
            $low = strtolower($s);
            if (in_array($low, ['low', 'medium', 'high', 'critical'], true)) {
                return $low;
            }

            return null;
        }
        $n = (float) $s;
        if ($n < 25.0) {
            return 'low';
        }
        if ($n < 75.0) {
            return 'medium';
        }
        if ($n < 150.0) {
            return 'high';
        }

        return 'critical';
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array{
     *   from_unix:?int,
     *   until_unix:?int,
     *   duration_seconds:?int,
     *   mitigated:?int,
     *   severity_raw:string,
     *   link_severity:string,
     *   peak_pkts_s:string,
     *   peak_bits_s:string
     * }
     */
    public function extraKolumny(array $detail): array
    {
        $fromUnix = $this->unixZPola($detail, 'from');
        $untilUnix = $this->unixZPola($detail, 'until');

        $duration = null;
        if (isset($detail['duration']) && $detail['duration'] !== '' && $detail['duration'] !== null && $detail['duration'] !== '-') {
            if (is_numeric($detail['duration'])) {
                $duration = (int) $detail['duration'];
            }
        }
        if ($duration === null && $fromUnix !== null && $untilUnix !== null && $untilUnix >= $fromUnix) {
            $duration = $untilUnix - $fromUnix;
        }

        $mitigated = null;
        if (array_key_exists('mitigated', $detail)) {
            $m = $detail['mitigated'];
            if (is_bool($m)) {
                $mitigated = $m ? 1 : 0;
            } elseif (is_numeric($m)) {
                $mitigated = (int) $m;
            }
        }

        $severityRaw = '';
        if (isset($detail['severity']) && $detail['severity'] !== null && $detail['severity'] !== '') {
            $severityRaw = mb_substr((string) $detail['severity'], 0, 32);
        }
        $linkSeverity = '';
        if (isset($detail['link_severity']) && $detail['link_severity'] !== null && $detail['link_severity'] !== '') {
            $linkSeverity = mb_substr((string) $detail['link_severity'], 0, 32);
        }

        $peakPkts = '';
        $peakBits = '';
        if (isset($detail['pkts/s'])) {
            $peakPkts = mb_substr((string) $detail['pkts/s'], 0, 64);
        } elseif (isset($detail['latest_anomaly_pps'])) {
            $peakPkts = mb_substr((string) $detail['latest_anomaly_pps'], 0, 64);
        }
        if (isset($detail['bits/s'])) {
            $peakBits = mb_substr((string) $detail['bits/s'], 0, 64);
        } elseif (isset($detail['latest_anomaly_bps'])) {
            $peakBits = mb_substr((string) $detail['latest_anomaly_bps'], 0, 64);
        }

        return [
            'from_unix'         => $fromUnix,
            'until_unix'        => $untilUnix,
            'duration_seconds'  => $duration,
            'mitigated'         => $mitigated,
            'severity_raw'      => $severityRaw,
            'link_severity'     => $linkSeverity,
            'peak_pkts_s'       => $peakPkts,
            'peak_bits_s'       => $peakBits,
        ];
    }

    /**
     * @param list<string> $cidrs
     */
    public function ipWAllowlist(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            $cidr = trim($cidr);
            if ($cidr === '') {
                continue;
            }
            if ($this->ipWCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prosty match CIDR IPv4/IPv6 (bez bibliotek zewnętrznych).
     */
    public function ipWCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            $mask = (int) $mask;
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $ipLong     = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            $mask = (int) $mask;
            if ($mask < 0 || $mask > 128) {
                return false;
            }
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $bytes     = intdiv($mask, 8);
            $bitsLeft  = $mask % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }
            if ($bitsLeft === 0) {
                return true;
            }
            $maskByte = (~((1 << (8 - $bitsLeft)) - 1)) & 0xFF;

            return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
        }

        return false;
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
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function unixZPola(array $detail, string $key): ?int
    {
        if (isset($detail[$key]) && is_array($detail[$key]) && isset($detail[$key]['unixtime'])) {
            if (is_numeric($detail[$key]['unixtime'])) {
                return (int) $detail[$key]['unixtime'];
            }
        }
        $alt = $key . '_unixtime';
        if (isset($detail[$alt]) && is_numeric($detail[$alt])) {
            return (int) $detail[$alt];
        }

        return null;
    }

    private function czasZUnix(?int $unix): string
    {
        $tz = new \DateTimeZone('UTC');
        if ($unix === null || $unix <= 0) {
            return (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i:sP');
        }

        return (new \DateTimeImmutable('@' . $unix))->setTimezone($tz)->format('Y-m-d H:i:sP');
    }
}
