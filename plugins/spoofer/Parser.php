<?php

namespace McsPluginy\spoofer;

/**
 * Mapowanie sesji CAIDA Spoofer → pola MCS (tor sieć / prefix, nie badge hosta).
 *
 * Publiczne API zwraca prefiksy (/24, /40), nie pojedyncze IP.
 * Import tylko rodzin (IPv4/IPv6) należących do monitorowanego ASN.
 * Dashboard wymaga podmiot_typ=ip — zapisujemy adres sieci z prefiksu
 * (jak Cloudflare Botnet), prefiks zostaje w payload/tytule.
 *
 * klucz_dedupu = "session|{id}|v4" albo "session|{id}|v6".
 */
class Parser
{
    public const MAPA_WERSJA = 'caida-spoofer-v0';

    /**
     * @param array<string, mixed> $session
     *
     * @return list<array{
     *   session_id: int,
     *   ip_wersja: string,
     *   prefix: string,
     *   asn: int,
     *   routedspoof: string,
     *   privatespoof: ?string,
     *   country: ?string,
     *   nat: ?bool,
     *   klucz_dedupu: string,
     *   zaobserwowano_at: string,
     * podmiot_typ: ip (adres sieci z prefiksu API — dashboard filtruje tylko IP, nie cidr).
     *   podmiot: string,
     *   kategoria: string,
     *   tytul: string,
     *   severity_sygnal: ?string,
     *   tag: string,
     *   raw: array<string, mixed>
     * }>
     */
    public function mapujSesje(array $session, int $monitoredAsn): array
    {
        $sessionId = $this->sessionId($session);
        $ts        = $this->zaobserwowanoAt($session['timestamp'] ?? null);
        if ($sessionId === null || $ts === null) {
            return [];
        }

        $out = [];
        $v4  = $this->mapujRodzine(
            $session,
            $sessionId,
            $ts,
            $monitoredAsn,
            'v4',
            'asn4',
            'client4',
            'routedspoof',
            'privatespoof',
            'nat4',
        );
        if ($v4 !== null) {
            $out[] = $v4;
        }

        $v6 = $this->mapujRodzine(
            $session,
            $sessionId,
            $ts,
            $monitoredAsn,
            'v6',
            'asn6',
            'client6',
            'routedspoof6',
            'privatespoof6',
            'nat6',
        );
        if ($v6 !== null) {
            $out[] = $v6;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sessions
     *
     * @return list<array<string, mixed>>
     */
    public function mapujListe(array $sessions, int $monitoredAsn): array
    {
        $out = [];
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            foreach ($this->mapujSesje($session, $monitoredAsn) as $mapped) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Fixture / odpowiedź API → lista sesji.
     *
     * Akceptuje:
     * - Hydra { "hydra:member": [ ... ] }
     * - { "sessions": [ ... ] }
     * - [ {session, ...}, ... ]
     * - pojedynczy obiekt Session
     *
     * @return list<array<string, mixed>>
     */
    public function parsujJson(string $tresc): array
    {
        $decoded = json_decode($tresc, true);
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['hydra:member']) && is_array($decoded['hydra:member'])) {
            return $this->tylkoObiekty($decoded['hydra:member']);
        }
        if (isset($decoded['member']) && is_array($decoded['member'])) {
            return $this->tylkoObiekty($decoded['member']);
        }
        if (isset($decoded['sessions']) && is_array($decoded['sessions'])) {
            return $this->tylkoObiekty($decoded['sessions']);
        }
        if (array_is_list($decoded)) {
            return $this->tylkoObiekty($decoded);
        }
        if (isset($decoded['session']) || isset($decoded['client4']) || isset($decoded['client6'])) {
            return [$decoded];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>|null
     */
    private function mapujRodzine(
        array $session,
        int $sessionId,
        string $ts,
        int $monitoredAsn,
        string $ipWersja,
        string $asnKlucz,
        string $prefixKlucz,
        string $routedKlucz,
        string $privateKlucz,
        string $natKlucz,
    ): ?array {
        if (! $this->asnPasuje($session[$asnKlucz] ?? null, $monitoredAsn)) {
            return null;
        }

        $prefix = $this->normalizujPrefix($session[$prefixKlucz] ?? null);
        if ($prefix === null) {
            return null;
        }
        $ip = $this->ipZPrefixu($prefix);
        if ($ip === null) {
            return null;
        }

        $routed  = $this->normalizujStatus($session[$routedKlucz] ?? null);
        $private = $this->normalizujStatus($session[$privateKlucz] ?? null);
        $status  = $routed ?? $private ?? 'unknown';
        $nat     = $this->normalizujBool($session[$natKlucz] ?? null);
        $country = isset($session['country']) ? strtolower(trim((string) $session['country'])) : '';
        $country = $country !== '' ? $country : null;

        [$kategoria, $severity, $tag] = $this->klasyfikuj($status);
        $protoEtykieta = $ipWersja === 'v6' ? 'IPv6' : 'IPv4';
        $tytul         = 'Spoofer ' . $protoEtykieta . ': routed ' . $status . ' (' . $prefix . ')';

        return [
            'session_id'       => $sessionId,
            'ip_wersja'        => $ipWersja,
            'prefix'           => $prefix,
            'asn'              => $monitoredAsn,
            'routedspoof'      => $status,
            'privatespoof'     => $private,
            'country'          => $country,
            'nat'              => $nat,
            'klucz_dedupu'     => 'session|' . $sessionId . '|' . $ipWersja,
            'zaobserwowano_at' => $ts,
            'podmiot_typ'      => 'ip',
            'podmiot'          => $ip,
            'kategoria'        => $kategoria,
            'tytul'            => $tytul,
            'severity_sygnal'  => $severity,
            'tag'              => $tag,
            'raw'              => $session,
        ];
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function klasyfikuj(string $status): array
    {
        return match ($status) {
            'received'  => ['abuse', 'high', 'spoofer-received'],
            'rewritten' => ['exposure', 'medium', 'spoofer-rewritten'],
            'blocked'   => ['other', 'low', 'spoofer-blocked'],
            default     => ['other', 'low', 'spoofer-unknown'],
        };
    }

    /**
     * @param array<string, mixed> $session
     */
    private function sessionId(array $session): ?int
    {
        $raw = $session['session'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        $id = isset($session['@id']) ? (string) $session['@id'] : '';
        if (preg_match('#/sessions/(\d+)$#', $id, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function asnPasuje(mixed $raw, int $asn): bool
    {
        if ($asn < 1 || $raw === null || $raw === '') {
            return false;
        }
        if (is_int($raw)) {
            return $raw === $asn;
        }
        $s = trim((string) $raw);
        if ($s === '' || ! ctype_digit($s)) {
            return false;
        }

        return (int) $s === $asn;
    }

    private function ipZPrefixu(string $prefix): ?string
    {
        $parts = explode('/', $prefix, 2);
        $ip    = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    private function normalizujPrefix(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || ! str_contains($s, '/')) {
            return null;
        }
        [$net, $mask] = explode('/', $s, 2);
        $net  = trim($net);
        $mask = trim($mask);
        if ($net === '' || $mask === '' || ! ctype_digit($mask)) {
            return null;
        }
        $maskInt = (int) $mask;
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $maskInt >= 0 && $maskInt <= 32 ? $net . '/' . $maskInt : null;
        }
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $maskInt >= 0 && $maskInt <= 128 ? $net . '/' . $maskInt : null;
        }

        return null;
    }

    private function normalizujStatus(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $s = strtolower(trim($raw));

        return in_array($s, ['received', 'rewritten', 'blocked', 'unknown'], true) ? $s : null;
    }

    private function normalizujBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        $s = strtolower(trim((string) $raw));

        return match ($s) {
            '1', 'true', 'yes', 'tak' => true,
            '0', 'false', 'no', 'nie' => false,
            default                   => null,
        };
    }

    private function zaobserwowanoAt(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($s);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function tylkoObiekty(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && ! array_is_list($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
