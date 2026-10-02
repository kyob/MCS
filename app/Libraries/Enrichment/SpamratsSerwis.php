<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentSpamratsModel;
use App\Models\PluginModel;

/**
 * Enrichment SpamRats DNSBL RATS-All: cache TTL + lookup na żądanie.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 */
class SpamratsSerwis
{
    private SpamratsClient $client;
    private EnrichmentSpamratsModel $model;
    private int $cacheTtl;
    private string $fixturePath;

    public function __construct(
        ?SpamratsClient $client = null,
        ?EnrichmentSpamratsModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
    ) {
        $this->client      = $client ?? SpamratsClient::fromEnv();
        $this->model       = $model ?? model(EnrichmentSpamratsModel::class);
        $ttl               = $cacheTtl ?? \App\Libraries\OpcjeMcs::int('mcs.spamrats.cacheTtl');
        $this->cacheTtl    = max(0, $ttl);
        $this->fixturePath = $fixturePath ?? trim((string) env('mcs.spamrats.fixturePath', ''));
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
            return 'dns';
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
        $maKlucz = $this->hasCredentials();

        return PluginOpisPolaczenia::plikIKanal(
            $this->fixturePath,
            'DNS RATS-All',
            $this->client->getZone(),
            $maKlucz,
            'dns',
            'DNS',
            [
                PluginOpisPolaczenia::pozycja(
                    'Klucz API',
                    PluginOpisPolaczenia::sekret($maKlucz),
                    false,
                    $maKlucz,
                ),
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

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => $this->tryb(),
                'blad'    => 'SpamRats DNSBL obsługuje tylko IPv4.',
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
                'tryb'    => 'dns',
                'blad'    => $resp['error'] !== '' ? $resp['error'] : 'Błąd DNS SpamRats',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $wiersz = $this->znormalizujIZapisz($ip, $resp['data'], 'dns');

        return [
            'ok'      => true,
            'z_cache' => false,
            'tryb'    => 'dns',
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
                'blad'    => 'Fixture SpamRats nie jest poprawnym JSON.',
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
        $listed = $this->jakoBoolNullable($data['listed'] ?? null);
        $dyna   = $this->jakoBoolNullable($data['listed_dyna'] ?? null);
        $noptr  = $this->jakoBoolNullable($data['listed_noptr'] ?? null);
        $spam   = $this->jakoBoolNullable($data['listed_spam'] ?? null);
        $auth   = $this->jakoBoolNullable($data['listed_auth'] ?? null);

        $kody = $data['kody'] ?? [];
        if (! is_array($kody)) {
            $kody = [];
        }
        $kodyOut = [];
        foreach ($kody as $k) {
            if (is_string($k) && $k !== '') {
                $kodyOut[] = $k;
            }
        }

        $wiersz = [
            'listed'        => $listed,
            'listed_dyna'   => $dyna,
            'listed_noptr'  => $noptr,
            'listed_spam'   => $spam,
            'listed_auth'   => $auth,
            'kody_json'     => json_encode($kodyOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json'      => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych' => $zrodlo,
            'checked_at'    => gmdate('Y-m-d H:i:sP'),
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
        $kody = $wiersz['kody_json'] ?? [];
        if (is_string($kody)) {
            $decoded = json_decode($kody, true);
            $kody    = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($kody)) {
            $kody = [];
        }

        return [
            'ip'           => (string) ($wiersz['ip'] ?? ''),
            'listed'       => $this->jakoBoolNullable($wiersz['listed'] ?? null),
            'listed_dyna'  => $this->jakoBoolNullable($wiersz['listed_dyna'] ?? null),
            'listed_noptr' => $this->jakoBoolNullable($wiersz['listed_noptr'] ?? null),
            'listed_spam'  => $this->jakoBoolNullable($wiersz['listed_spam'] ?? null),
            'listed_auth'  => $this->jakoBoolNullable($wiersz['listed_auth'] ?? null),
            'kody'         => $kody,
            'zrodlo_danych'=> $wiersz['zrodlo_danych'] ?? null,
            'checked_at'   => $wiersz['checked_at'] ?? null,
        ];
    }

    private function jakoBoolNullable(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        return PluginModel::jakoBool($v);
    }
}
