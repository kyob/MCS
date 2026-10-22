<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentGreynoiseModel;
use App\Models\PluginModel;

/**
 * Enrichment GreyNoise Community: cache TTL + lookup na żądanie.
 * Nie zapisuje do mcs_incydenty / badge. Cron nie woła API.
 */
class GreynoiseSerwis
{
    private GreynoiseClient $client;

    private EnrichmentGreynoiseModel $model;

    private int $cacheTtl;

    private string $fixturePath;

    public function __construct(
        ?GreynoiseClient $client = null,
        ?EnrichmentGreynoiseModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client      = $client ?? GreynoiseClient::fromEnv();
        $this->model       = $model ?? model(EnrichmentGreynoiseModel::class);
        $ttl               = $cacheTtl ?? \App\Libraries\OpcjeMcs::int('mcs.greynoise.cacheTtl');
        $this->cacheTtl    = max(0, $ttl);
        $this->fixturePath = $fixturePath ?? trim((string) env('mcs.greynoise.fixturePath', ''));
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
            return 'plik';
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
        $jest = $this->hasCredentials();

        return PluginOpisPolaczenia::zloz(
            $jest ? 'http' : 'brak',
            $jest ? 'HTTP' : 'brak klucza',
            'GET api.greynoise.io/v3/community/{ip} (poza badge, ręcznie z /ip/{ip})',
            [
                PluginOpisPolaczenia::pozycja('Klucz API', PluginOpisPolaczenia::sekret($jest), false, $jest),
                PluginOpisPolaczenia::pozycja('Limit', 'cron nie odpytuje API', false, true),
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
     * @return array{ok: bool, z_cache: bool, tryb: string, blad: ?string, ip: string, wynik: ?array<string, mixed>}
     */
    public function sprawdz(string $ip, bool $force = false): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->odpowiedz(false, false, $this->tryb(), 'Wymagany prawidłowy adres IP.', $ip, null);
        }

        if (! $force) {
            $cache = $this->model->znajdzPoIp($ip);
            if ($this->model->cacheWazny($cache, $this->cacheTtl) && is_array($cache)) {
                return $this->odpowiedz(true, true, 'cache', null, $ip, $this->wierszDoWyniku($cache));
            }
        }

        if ($this->hasFixture()) {
            $raw = file_get_contents($this->fixturePath);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($data)) {
                return $this->odpowiedz(false, false, 'plik', 'Nieczytelny plik GreyNoise.', $ip, null);
            }
            $wiersz = $this->zapisz($ip, 200, $data, 'plik');

            return $this->odpowiedz(true, false, 'plik', null, $ip, $this->wierszDoWyniku($wiersz));
        }

        if (! $this->hasCredentials()) {
            return $this->odpowiedz(false, false, 'brak_klucza', $this->client->brakCredentialsKomunikat(), $ip, null);
        }

        $resp = $this->client->lookup($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return $this->odpowiedz(false, false, 'api', $resp['error'] !== '' ? $resp['error'] : 'Błąd API GreyNoise', $ip, null);
        }

        $wiersz = $this->zapisz($ip, $resp['http_code'], $resp['data'], 'api');

        return $this->odpowiedz(true, false, 'api', null, $ip, $this->wierszDoWyniku($wiersz));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function zapisz(string $ip, int $httpStatus, array $data, string $zrodlo): array
    {
        $wiersz = [
            'noise'          => $this->boolOrNull($data['noise'] ?? null),
            'riot'           => $this->boolOrNull($data['riot'] ?? null),
            'classification' => $this->tekst($data['classification'] ?? null, 64),
            'name'           => $this->tekst($data['name'] ?? null, 255),
            'link'           => $this->tekst($data['link'] ?? null, 512),
            'last_seen'      => $this->tekst($data['last_seen'] ?? null, 32),
            'message'        => $this->tekst($data['message'] ?? null, 2000),
            'http_status'    => $httpStatus,
            'raw_json'       => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych'  => $zrodlo,
            'checked_at'     => gmdate('Y-m-d H:i:sP'),
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
        return [
            'ip'             => (string) ($wiersz['ip'] ?? ''),
            'noise'          => $this->boolOrNull($wiersz['noise'] ?? null),
            'riot'           => $this->boolOrNull($wiersz['riot'] ?? null),
            'classification' => $wiersz['classification'] ?? null,
            'name'           => $wiersz['name'] ?? null,
            'link'           => $wiersz['link'] ?? null,
            'last_seen'      => $wiersz['last_seen'] ?? null,
            'message'        => $wiersz['message'] ?? null,
            'http_status'    => isset($wiersz['http_status']) ? (int) $wiersz['http_status'] : null,
            'zrodlo_danych'  => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'     => $wiersz['checked_at'] ?? null,
        ];
    }

    private function boolOrNull(mixed $wartosc): ?bool
    {
        if ($wartosc === null || $wartosc === '') {
            return null;
        }
        if (is_bool($wartosc)) {
            return $wartosc;
        }

        return PluginModel::jakoBool($wartosc);
    }

    private function tekst(mixed $wartosc, int $max): ?string
    {
        if (! is_string($wartosc) && ! is_numeric($wartosc)) {
            return null;
        }
        $tekst = trim((string) $wartosc);

        return $tekst === '' ? null : mb_substr($tekst, 0, $max);
    }

    /**
     * @param array<string, mixed>|null $wynik
     *
     * @return array{ok: bool, z_cache: bool, tryb: string, blad: ?string, ip: string, wynik: ?array<string, mixed>}
     */
    private function odpowiedz(bool $ok, bool $zCache, string $tryb, ?string $blad, string $ip, ?array $wynik): array
    {
        return [
            'ok'      => $ok,
            'z_cache' => $zCache,
            'tryb'    => $tryb,
            'blad'    => $blad,
            'ip'      => $ip,
            'wynik'   => $wynik,
        ];
    }
}
