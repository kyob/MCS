<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Models\EnrichmentLmsModel;

/**
 * Enrichment LMS: cache TTL + lookup na żądanie + sync zbiorczy.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 */
class LmsMapowanieSerwis
{
    private LmsMapowanieClient $http;
    private LmsMapowanieDbClient $db;
    private EnrichmentLmsModel $model;
    private int $cacheTtl;
    private string $fixturePath;
    private string $nodeUrlTemplate;

    public function __construct(
        ?LmsMapowanieClient $http = null,
        ?LmsMapowanieDbClient $db = null,
        ?EnrichmentLmsModel $model = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
        ?string $nodeUrlTemplate = null,
    ) {
        $this->http            = $http ?? LmsMapowanieClient::fromEnv();
        $this->db              = $db ?? LmsMapowanieDbClient::fromEnv();
        $this->model           = $model ?? model(EnrichmentLmsModel::class);
        $ttl                   = $cacheTtl ?? \App\Libraries\OpcjeMcs::int('mcs.lms.cacheTtl');
        $this->cacheTtl        = max(0, $ttl);
        $this->fixturePath     = $fixturePath ?? trim((string) env('mcs.lms.fixturePath', ''));
        $this->nodeUrlTemplate = $nodeUrlTemplate
            ?? trim((string) env('mcs.lms.nodeUrlTemplate', ''));
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
        if ($this->http->hasConfig()) {
            return 'api';
        }
        if ($this->db->hasConfig()) {
            return 'db';
        }

        return 'brak_konfiguracji';
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
        $tryb = $this->tryb();
        $trybKod = match ($tryb) {
            'fixture' => 'plik_testowy',
            'api'     => 'http',
            'db'      => 'baza',
            default   => 'brak',
        };
        $trybEtykieta = match ($tryb) {
            'fixture' => 'plik testowy (JSON)',
            'api'     => 'HTTP',
            'db'      => 'baza PostgreSQL',
            default   => 'brak konfiguracji',
        };

        $plik    = $this->fixturePath;
        $httpUrl = $this->http->opisUrl();
        $dbOpis  = $this->db->opisHosta();
        $hostHttp = PluginOpisPolaczenia::hostZUrl($this->http->getBaseUrl());

        $skrot = match ($tryb) {
            'fixture' => 'plik testowy · ' . basename($plik),
            'api'     => 'HTTP · ' . ($hostHttp !== '' ? $hostHttp : $httpUrl),
            'db'      => 'baza · ' . $dbOpis,
            default   => 'brak konfiguracji',
        };

        return PluginOpisPolaczenia::zloz($trybKod, $trybEtykieta, $skrot, [
            PluginOpisPolaczenia::pozycja('Plik testowy (JSON)', $plik, $tryb === 'fixture'),
            PluginOpisPolaczenia::pozycja('HTTP LMS', $httpUrl, $tryb === 'api'),
            PluginOpisPolaczenia::pozycja('Baza LMS (PostgreSQL)', $dbOpis, $tryb === 'db'),
            PluginOpisPolaczenia::pozycja(
                'Token mapowania',
                PluginOpisPolaczenia::sekret($this->http->hasMapToken()),
                false,
                $this->http->hasMapToken(),
            ),
        ]);
    }

    /**
     * Podgląd ważnego cache bez wywołania LMS.
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
            return $this->zFixtureLookup($ip);
        }

        if ($this->http->hasConfig()) {
            return $this->zHttpLookup($ip);
        }

        if ($this->db->hasConfig()) {
            return $this->zDbLookup($ip);
        }

        return [
            'ok'      => false,
            'z_cache' => false,
            'tryb'    => 'brak_konfiguracji',
            'blad'    => 'Brak konfiguracji LMS — ustaw mcs.lms.baseUrl+mapToken, albo db*, albo fixturePath.',
            'ip'      => $ip,
            'wynik'   => null,
        ];
    }

    /**
     * Synchronizacja zbiorcza do cache (plugin / cron).
     *
     * @return array{ok:bool,tryb:string,blad:?string,zapisano:int,trafionych:int}
     */
    public function sync(bool $force = true): array
    {
        if ($this->hasFixture()) {
            return $this->syncZFixture();
        }

        if ($this->http->hasConfig()) {
            return $this->syncZHttp();
        }

        if ($this->db->hasConfig()) {
            return $this->syncZDb();
        }

        return [
            'ok'         => false,
            'tryb'       => 'brak_konfiguracji',
            'blad'       => 'Brak konfiguracji LMS — ustaw mcs.lms.baseUrl+mapToken, albo db*, albo fixturePath.',
            'zapisano'   => 0,
            'trafionych' => 0,
        ];
    }

    /**
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    private function zFixtureLookup(string $ip): array
    {
        $items = $this->wczytajFixtureItems();
        if ($items === null) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'fixture',
                'blad'    => 'Nie można odczytać / sparsować mcs.lms.fixturePath.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $match = null;
        foreach ($items as $item) {
            if ((string) ($item['ip'] ?? '') === $ip) {
                $match = $item;
                break;
            }
        }

        // Świadomie BEZ remapowania pierwszego wpisu na obce IP — inaczej
        // powstają fałszywe deep-linki nodeinfo (np. 11106 zamiast 9833).
        if ($match === null) {
            $wiersz = $this->zapiszBrak($ip, 'fixture');

            return [
                'ok'      => true,
                'z_cache' => false,
                'tryb'    => 'fixture',
                'blad'    => null,
                'ip'      => $ip,
                'wynik'   => $this->wierszDoWyniku($wiersz),
            ];
        }

        $wiersz = $this->zapiszTrafienie($ip, $match, [$match], 'fixture');

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
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>}
     */
    private function zHttpLookup(string $ip): array
    {
        $resp = $this->http->lookup($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'api',
                'blad'    => $resp['error'] !== '' ? $resp['error'] : 'Błąd API LMS',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $data  = $resp['data'];
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            $item = $data['item'] ?? $data['wynik'] ?? null;
            $items = is_array($item) ? [$item] : [];
        }

        /** @var list<array<string,mixed>> $norm */
        $norm = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $norm[] = $row;
            }
        }

        if ($norm === []) {
            $wiersz = $this->zapiszBrak($ip, 'api');

            return [
                'ok'      => true,
                'z_cache' => false,
                'tryb'    => 'api',
                'blad'    => null,
                'ip'      => $ip,
                'wynik'   => $this->wierszDoWyniku($wiersz),
            ];
        }

        $glowny = $this->wybierzGlownyWpis($norm);
        $wiersz = $this->zapiszTrafienie($ip, $glowny, $norm, 'api');

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
    private function zDbLookup(string $ip): array
    {
        try {
            $rows = $this->db->lookup($ip);
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'db',
                'blad'    => 'Błąd DB LMS: ' . $e->getMessage(),
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        if ($rows === []) {
            $wiersz = $this->zapiszBrak($ip, 'db');

            return [
                'ok'      => true,
                'z_cache' => false,
                'tryb'    => 'db',
                'blad'    => null,
                'ip'      => $ip,
                'wynik'   => $this->wierszDoWyniku($wiersz),
            ];
        }

        $glowny = $this->wybierzGlownyWpis($rows);
        $wiersz = $this->zapiszTrafienie($ip, $glowny, $rows, 'db');

        return [
            'ok'      => true,
            'z_cache' => false,
            'tryb'    => 'db',
            'blad'    => null,
            'ip'      => $ip,
            'wynik'   => $this->wierszDoWyniku($wiersz),
        ];
    }

    /**
     * @return array{ok:bool,tryb:string,blad:?string,zapisano:int,trafionych:int}
     */
    private function syncZFixture(): array
    {
        $items = $this->wczytajFixtureItems();
        if ($items === null) {
            return [
                'ok'         => false,
                'tryb'       => 'fixture',
                'blad'       => 'Nie można odczytać / sparsować mcs.lms.fixturePath.',
                'zapisano'   => 0,
                'trafionych' => 0,
            ];
        }

        return $this->zapiszListe($items, 'fixture');
    }

    /**
     * @return array{ok:bool,tryb:string,blad:?string,zapisano:int,trafionych:int}
     */
    private function syncZHttp(): array
    {
        $resp = $this->http->sync();
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return [
                'ok'         => false,
                'tryb'       => 'api',
                'blad'       => $resp['error'] !== '' ? $resp['error'] : 'Błąd API LMS sync',
                'zapisano'   => 0,
                'trafionych' => 0,
            ];
        }

        $items = $resp['data']['items'] ?? null;
        if (! is_array($items)) {
            return [
                'ok'         => false,
                'tryb'       => 'api',
                'blad'       => 'Odpowiedź sync bez tablicy items[].',
                'zapisano'   => 0,
                'trafionych' => 0,
            ];
        }

        /** @var list<array<string,mixed>> $norm */
        $norm = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $norm[] = $row;
            }
        }

        return $this->zapiszListe($norm, 'api');
    }

    /**
     * @return array{ok:bool,tryb:string,blad:?string,zapisano:int,trafionych:int}
     */
    private function syncZDb(): array
    {
        try {
            $rows = $this->db->syncAll();
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'tryb'       => 'db',
                'blad'       => 'Błąd DB LMS sync: ' . $e->getMessage(),
                'zapisano'   => 0,
                'trafionych' => 0,
            ];
        }

        return $this->zapiszListe($rows, 'db');
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{ok:bool,tryb:string,blad:?string,zapisano:int,trafionych:int}
     */
    private function zapiszListe(array $items, string $zrodlo): array
    {
        $poIp = [];
        foreach ($items as $item) {
            $ip = trim((string) ($item['ip'] ?? ''));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (! isset($poIp[$ip])) {
                $poIp[$ip] = [];
            }
            $poIp[$ip][] = $item;
        }

        $zapisano   = 0;
        $trafionych = 0;
        foreach ($poIp as $ip => $grupa) {
            $this->zapiszTrafienie($ip, $this->wybierzGlownyWpis($grupa), $grupa, $zrodlo);
            $zapisano++;
            $trafionych++;
        }

        return [
            'ok'         => true,
            'tryb'       => $zrodlo,
            'blad'       => null,
            'zapisano'   => $zapisano,
            'trafionych' => $trafionych,
        ];
    }

    /**
     * Wybór „głównego” noda przy wielu trafieniach (vnodes/MAC, ipaddr vs ipaddr_pub).
     * Preferuj trafienie po ipaddr (nie tylko ipaddr_pub), potem access=1, potem niższe node_id
     * (stabilne; zgodne z ORDER BY id ASC w API LMS).
     *
     * @param list<array<string, mixed>> $grupa
     *
     * @return array<string, mixed>
     */
    private function wybierzGlownyWpis(array $grupa): array
    {
        if ($grupa === []) {
            return [];
        }

        usort($grupa, static function (array $a, array $b): int {
            $priA = (int) ($a['trafienie_ipaddr'] ?? 1);
            $priB = (int) ($b['trafienie_ipaddr'] ?? 1);
            if ($priA !== $priB) {
                return $priB <=> $priA;
            }

            $accessA = (int) ($a['access'] ?? 0);
            $accessB = (int) ($b['access'] ?? 0);
            if ($accessA !== $accessB) {
                return $accessB <=> $accessA;
            }

            return ((int) ($a['node_id'] ?? 0)) <=> ((int) ($b['node_id'] ?? 0));
        });

        return $grupa[0];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function wczytajFixtureItems(): ?array
    {
        $raw = @file_get_contents($this->fixturePath);
        if ($raw === false) {
            return null;
        }
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return null;
        }
        $items = $json['items'] ?? null;
        if (! is_array($items)) {
            // pojedynczy obiekt
            if (isset($json['ip'])) {
                return [$json];
            }

            return null;
        }

        $out = [];
        foreach ($items as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $glowny
     * @param list<array<string, mixed>> $wszystkie
     *
     * @return array<string, mixed>
     */
    private function zapiszTrafienie(string $ip, array $glowny, array $wszystkie, string $zrodlo): array
    {
        $teraz = gmdate('Y-m-d H:i:sP');
        $wiersz = [
            'node_id'           => isset($glowny['node_id']) ? (int) $glowny['node_id'] : null,
            'komputer'          => isset($glowny['komputer']) ? (string) $glowny['komputer'] : null,
            'node_login'        => isset($glowny['node_login']) && $glowny['node_login'] !== ''
                ? (string) $glowny['node_login'] : null,
            'customer_id'       => isset($glowny['customer_id']) && $glowny['customer_id'] !== null && $glowny['customer_id'] !== ''
                ? (int) $glowny['customer_id'] : null,
            'abonent'           => isset($glowny['abonent']) ? trim((string) $glowny['abonent']) : null,
            'customer_lastname' => isset($glowny['customer_lastname']) ? (string) $glowny['customer_lastname'] : null,
            'customer_name'     => isset($glowny['customer_name']) ? (string) $glowny['customer_name'] : null,
            'netid'             => isset($glowny['netid']) ? (int) $glowny['netid'] : null,
            'access'            => isset($glowny['access']) ? (int) $glowny['access'] : null,
            'trafiony'          => true,
            'raw_json'          => json_encode(
                ['items' => $wszystkie],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'zrodlo_danych'     => $zrodlo,
            'checked_at'        => $teraz,
        ];

        $this->model->upsertDlaIp($ip, $wiersz);
        $zapisany = $this->model->znajdzPoIp($ip);

        return is_array($zapisany) ? $zapisany : array_merge(['ip' => $ip], $wiersz);
    }

    /**
     * @return array<string, mixed>
     */
    private function zapiszBrak(string $ip, string $zrodlo): array
    {
        $teraz  = gmdate('Y-m-d H:i:sP');
        $wiersz = [
            'node_id'           => null,
            'komputer'          => null,
            'node_login'        => null,
            'customer_id'       => null,
            'abonent'           => null,
            'customer_lastname' => null,
            'customer_name'     => null,
            'netid'             => null,
            'access'            => null,
            'trafiony'          => false,
            'raw_json'          => json_encode(['items' => []], JSON_UNESCAPED_UNICODE),
            'zrodlo_danych'     => $zrodlo,
            'checked_at'        => $teraz,
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

        $nodeId = $wiersz['node_id'] ?? null;
        $url    = null;
        if ($this->nodeUrlTemplate !== '' && $nodeId !== null && (int) $nodeId > 0) {
            $url = str_replace(
                ['{id}', '{node_id}'],
                [(string) (int) $nodeId, (string) (int) $nodeId],
                $this->nodeUrlTemplate,
            );
        }

        $trafiony = $wiersz['trafiony'] ?? null;
        if (is_bool($trafiony)) {
            // ok
        } elseif (is_int($trafiony) || is_float($trafiony)) {
            $trafiony = ((int) $trafiony) !== 0;
        } elseif (is_string($trafiony)) {
            $s = strtolower(trim($trafiony));
            $trafiony = in_array($s, ['1', 't', 'true', 'yes', 'on'], true);
        } else {
            $trafiony = false;
        }

        return [
            'ip'                => $wiersz['ip'] ?? null,
            'node_id'           => $nodeId !== null ? (int) $nodeId : null,
            'komputer'          => $wiersz['komputer'] ?? null,
            'node_login'        => $wiersz['node_login'] ?? null,
            'customer_id'       => isset($wiersz['customer_id']) && $wiersz['customer_id'] !== null
                ? (int) $wiersz['customer_id'] : null,
            'abonent'           => $wiersz['abonent'] ?? null,
            'customer_lastname' => $wiersz['customer_lastname'] ?? null,
            'customer_name'     => $wiersz['customer_name'] ?? null,
            'netid'             => isset($wiersz['netid']) && $wiersz['netid'] !== null
                ? (int) $wiersz['netid'] : null,
            'access'            => isset($wiersz['access']) && $wiersz['access'] !== null
                ? (int) $wiersz['access'] : null,
            'trafiony'          => $trafiony,
            'lms_url'           => $url,
            'zrodlo_danych'     => $wiersz['zrodlo_danych'] ?? null,
            'checked_at'        => $wiersz['checked_at'] ?? null,
            'raw'               => $raw,
        ];
    }
}
