<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentIsmaliciousModel;
use App\Models\PluginModel;

/**
 * Enrichment isMalicious: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 */
class IsmaliciousSerwis
{
    private IsmaliciousClient $client;
    private EnrichmentIsmaliciousModel $model;
    private int $cacheTtl;
    private string $fixturePath;

    public function __construct(
        ?IsmaliciousClient $client = null,
        ?EnrichmentIsmaliciousModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client      = $client ?? IsmaliciousClient::fromEnv();
        $this->model       = $model ?? model(EnrichmentIsmaliciousModel::class);
        $ttl               = $cacheTtl ?? (int) env('mcs.ismalicious.cacheTtl', 86400);
        $this->cacheTtl    = max(0, $ttl);
        $this->fixturePath = $fixturePath ?? trim((string) env('mcs.ismalicious.fixturePath', ''));
    }

    public function hasCredentials(): bool
    {
        return $this->client->hasCredentials();
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
        if ($this->hasCredentials()) {
            return 'api';
        }

        return 'brak_klucza';
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        return PluginOpisPolaczenia::plikIKanal(
            $this->fixturePath,
            'HTTP isMalicious',
            $this->client->getBaseUrl() . '/check',
            $this->hasCredentials(),
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja(
                    'Klucz API',
                    PluginOpisPolaczenia::sekret($this->hasCredentials()),
                    false,
                    $this->hasCredentials(),
                ),
                PluginOpisPolaczenia::pozycja('Poziom enrichment', $this->client->enrichment(), false),
            ],
        );
    }

    /**
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

        if (! $this->hasCredentials()) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'brak_klucza',
                'blad'    => $this->client->brakCredentialsKomunikat(),
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
                'blad'    => $resp['error'] !== '' ? $resp['error'] : 'Błąd API isMalicious',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $wiersz = $this->znormalizujIZapisz($ip, $resp['data'], 'api');

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
        if (! is_array($json)) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'fixture',
                'blad'    => 'Fixture isMalicious nie jest poprawnym JSON.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $wiersz = $this->znormalizujIZapisz($ip, $json, 'fixture');

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
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function znormalizujIZapisz(string $ip, array $data, string $zrodlo): array
    {
        $classification = $data['classification'] ?? null;
        $classStr       = null;
        if (is_string($classification) && $classification !== '') {
            $classStr = $classification;
        } elseif (is_array($classification)) {
            $classStr = (string) ($classification['category']
                ?? $classification['label']
                ?? $classification['name']
                ?? json_encode($classification, JSON_UNESCAPED_UNICODE));
            if (strlen($classStr) > 128) {
                $classStr = substr($classStr, 0, 125) . '…';
            }
        }

        $malicious = $data['malicious'] ?? null;
        if (is_string($malicious)) {
            $malicious = PluginModel::jakoBool($malicious);
        } elseif (! is_bool($malicious)) {
            $malicious = null;
        }

        $listed = $data['blocklistListed'] ?? null;
        if (is_string($listed)) {
            $listed = PluginModel::jakoBool($listed);
        } elseif (! is_bool($listed)) {
            $listed = null;
        }

        $wiersz = [
            'malicious'         => $malicious,
            'risk_score'        => isset($data['riskScore']) && is_numeric($data['riskScore'])
                ? (float) $data['riskScore'] : null,
            'confidence'        => isset($data['confidence']) && is_numeric($data['confidence'])
                ? (float) $data['confidence'] : null,
            'blocklist_hits'    => isset($data['blocklistHits']) && is_numeric($data['blocklistHits'])
                ? (int) $data['blocklistHits'] : null,
            'blocklist_listed'  => $listed,
            'classification'    => $classStr,
            'raw_json'          => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'     => $zrodlo,
            'checked_at'        => gmdate('Y-m-d H:i:sP'),
        ];

        $this->model->upsertDlaIp($ip, $wiersz);
        $odczyt = $this->model->znajdzPoIp($ip);

        return is_array($odczyt) ? $odczyt : array_merge(['ip' => $ip], $wiersz);
    }

    /**
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    private function wierszDoWyniku(array $wiersz): array
    {
        $malicious = $wiersz['malicious'] ?? null;
        if (is_string($malicious)) {
            $malicious = PluginModel::jakoBool($malicious);
        }
        $listed = $wiersz['blocklist_listed'] ?? null;
        if (is_string($listed)) {
            $listed = PluginModel::jakoBool($listed);
        }

        return [
            'ip'                => (string) ($wiersz['ip'] ?? ''),
            'malicious'         => $malicious,
            'risk_score'        => $wiersz['risk_score'] ?? null,
            'confidence'        => $wiersz['confidence'] ?? null,
            'blocklist_hits'    => $wiersz['blocklist_hits'] ?? null,
            'blocklist_listed'  => $listed,
            'classification'    => $wiersz['classification'] ?? null,
            'zrodlo_danych'     => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'        => $wiersz['checked_at'] ?? null,
        ];
    }
}
