<?php

namespace App\Libraries\Enrichment;

use App\Models\EnrichmentAbuseipdbModel;

/**
 * Enrichment AbuseIPDB: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 */
class AbuseipdbSerwis
{
    private AbuseipdbClient $client;
    private EnrichmentAbuseipdbModel $model;
    private int $cacheTtl;
    private string $fixturePath;

    public function __construct(
        ?AbuseipdbClient $client = null,
        ?EnrichmentAbuseipdbModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client      = $client ?? AbuseipdbClient::fromEnv();
        $this->model       = $model ?? model(EnrichmentAbuseipdbModel::class);
        $ttl               = $cacheTtl ?? (int) env('mcs.abuseipdb.cacheTtl', 86400);
        $this->cacheTtl    = max(0, $ttl);
        $this->fixturePath = $fixturePath ?? trim((string) env('mcs.abuseipdb.fixturePath', ''));
    }

    public function hasApiKey(): bool
    {
        return $this->client->hasApiKey();
    }

    public function hasFixture(): bool
    {
        return $this->fixturePath !== '' && is_readable($this->fixturePath);
    }

    public function tryb(): string
    {
        if ($this->hasFixture()) {
            return 'fixture';
        }
        if ($this->hasApiKey()) {
            return 'api';
        }

        return 'brak_klucza';
    }

    /**
     * Podgląd ważnego cache bez wywołania API / fixture.
     *
     * @return array<string, mixed>|null
     */
    public function podgladCache(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $cache = $this->model->znajdzPoIp($ip);
        if (! $this->model->cacheWazny($cache, $this->cacheTtl) || ! is_array($cache)) {
            return null;
        }

        return $this->wierszDoWyniku($cache);
    }

    /**
     * @return array{
     *   ok:bool,
     *   z_cache:bool,
     *   tryb:string,
     *   blad:?string,
     *   ip:string,
     *   wynik:?array<string,mixed>
     * }
     */
    public function sprawdz(string $ip, bool $force = false): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => $this->tryb(),
                'blad'    => 'Wymagany prawidłowy adres IP.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        if (! $force) {
            $cache = $this->model->znajdzPoIp($ip);
            if ($this->model->cacheWazny($cache, $this->cacheTtl)) {
                return [
                    'ok'      => true,
                    'z_cache' => true,
                    'tryb'    => 'cache',
                    'blad'    => null,
                    'ip'      => $ip,
                    'wynik'   => $this->wierszDoWyniku($cache),
                ];
            }
        }

        if ($this->hasFixture()) {
            return $this->zFixture($ip);
        }

        if (! $this->hasApiKey()) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'brak_klucza',
                'blad'    => 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo mcs.abuseipdb.fixturePath do dry-run.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $resp = $this->client->checkIp($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'api',
                'blad'    => $resp['error'] !== '' ? $resp['error'] : 'Błąd API AbuseIPDB',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $data = $resp['data']['data'] ?? null;
        if (! is_array($data)) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'api',
                'blad'    => 'Nieoczekiwana odpowiedź API (brak data.data).',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $wiersz = $this->znormalizujIZapisz($ip, $data, 'api');

        return [
            'ok'      => true,
            'z_cache' => false,
            'tryb'    => 'api',
            'blad'    => null,
            'ip'      => $ip,
            'wynik'   => $this->wierszDoWyniku($wiersz),
        ];
    }

    /**
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    private function zFixture(string $ip): array
    {
        $raw = file_get_contents($this->fixturePath);
        if ($raw === false) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'fixture',
                'blad'    => 'Nie można odczytać fixturePath.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $json = json_decode($raw, true);
        $data = is_array($json) ? ($json['data'] ?? $json) : null;
        if (! is_array($data)) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'fixture',
                'blad'    => 'Fixture JSON nie zawiera obiektu data.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        // Dry-run: nadpisz IP z żądania, żeby cache był spójny z argumentem CLI/API.
        $data['ipAddress'] = $ip;
        $wiersz            = $this->znormalizujIZapisz($ip, $data, 'fixture');

        return [
            'ok'      => true,
            'z_cache' => false,
            'tryb'    => 'fixture',
            'blad'    => null,
            'ip'      => $ip,
            'wynik'   => $this->wierszDoWyniku($wiersz),
        ];
    }

    /**
     * @param array<string, mixed> $data pole `data` z odpowiedzi AbuseIPDB
     *
     * @return array<string, mixed>
     */
    private function znormalizujIZapisz(string $ip, array $data, string $zrodlo): array
    {
        $teraz = gmdate('Y-m-d H:i:sP');
        $last  = $data['lastReportedAt'] ?? null;
        $lastAt = null;
        if (is_string($last) && $last !== '') {
            $ts = strtotime($last);
            $lastAt = $ts !== false ? gmdate('Y-m-d H:i:sP', $ts) : null;
        }

        $wiersz = [
            'abuse_confidence_score' => isset($data['abuseConfidenceScore']) ? (int) $data['abuseConfidenceScore'] : null,
            'total_reports'          => isset($data['totalReports']) ? (int) $data['totalReports'] : null,
            'last_reported_at'       => $lastAt,
            'country_code'           => isset($data['countryCode']) ? (string) $data['countryCode'] : null,
            'isp'                    => isset($data['isp']) ? (string) $data['isp'] : null,
            'usage_type'             => isset($data['usageType']) ? (string) $data['usageType'] : null,
            'is_whitelisted'         => array_key_exists('isWhitelisted', $data)
                ? filter_var($data['isWhitelisted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'raw_json'               => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'          => $zrodlo,
            'checked_at'             => $teraz,
        ];

        $this->model->upsertDlaIp($ip, $wiersz);
        $zapisany = $this->model->znajdzPoIp($ip);

        return is_array($zapisany) ? $zapisany : array_merge(['ip' => $ip], $wiersz);
    }

    /**
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    private function wierszDoWyniku(array $wiersz): array
    {
        $raw = $wiersz['raw_json'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : $raw;
        }

        return [
            'ip'                     => $wiersz['ip'] ?? null,
            'abuse_confidence_score' => $wiersz['abuse_confidence_score'] ?? null,
            'total_reports'          => $wiersz['total_reports'] ?? null,
            'last_reported_at'       => $wiersz['last_reported_at'] ?? null,
            'country_code'           => $wiersz['country_code'] ?? null,
            'isp'                    => $wiersz['isp'] ?? null,
            'usage_type'             => $wiersz['usage_type'] ?? null,
            'is_whitelisted'         => $wiersz['is_whitelisted'] ?? null,
            'zrodlo_danych'          => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'             => $wiersz['checked_at'] ?? null,
            'raw'                    => $raw,
        ];
    }
}
