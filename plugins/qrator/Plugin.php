<?php

namespace McsPluginy\qrator;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Qrator Radar — tor sieć / ASN (BGP hijacks, leaks, opcjonalnie amplifiers).
 *
 * Nie miesza z badge hosta: podmiot_typ zwykle cidr|siec (amplifier → ip gdy dostępne).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.qrator.fixturePath
 * 2) REST — mcs.qrator.apiKey (nagłówek QRADAR-API-KEY)
 *
 * @see https://api.radar.qrator.net/openapi.json
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private ?ApiClient $apiClient;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ApiClient $apiClient = null,
    ) {
        $this->parser    = $parser ?? new Parser();
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->zapis     = $zapis ?? new IncydentZapis();
        $this->waga      = $waga ?? new WagaV1();
        $this->apiClient = $apiClient;
    }

    public function zrodlo(): string
    {
        return 'qrator';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.qrator.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste events)',
            );

            return;
        }

        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasKey()) {
            $msg = 'Qrator Radar: ustaw mcs.qrator.fixturePath (smoke) albo apiKey w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi($client);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (asn=%d, feeds=%s, api=%d, zapisano=%d, mapa=%s)',
                    $stats['asn'],
                    implode(',', $stats['feeds']),
                    $stats['api_count'],
                    $stats['zapisano'],
                    Parser::MAPA_WERSJA,
                ),
            );
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{ok: bool, msg: string}
     */
    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        $ok  = array_key_exists('health_ok', $wiersz) ? $wiersz['health_ok'] : null;
        $msg = (string) ($wiersz['health_msg'] ?? '');

        if ($msg === '' && $ok === null) {
            $msg = $this->opisKonfiguracji();
        }

        return [
            'ok'  => (bool) ($ok ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
        ];
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
        $plik   = trim((string) env('mcs.qrator.fixturePath', ''));
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $asn    = (string) $this->resolveAsn();
        $feeds  = trim((string) env('mcs.qrator.feeds', ''));
        $tokOk  = $client->hasKey();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'HTTP Qrator Radar',
            $client->getBaseUrl(),
            $tokOk,
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja('ASN', $asn, false, $asn !== ''),
                PluginOpisPolaczenia::pozycja('Feedy', $feeds, false),
                PluginOpisPolaczenia::pozycja(
                    'Klucz API',
                    PluginOpisPolaczenia::sekret($tokOk),
                    false,
                    $tokOk,
                ),
            ],
        );
    }

    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Qrator: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc  = (string) file_get_contents($sciezka);
        $parsed = $this->parser->parsujJson($tresc);
        $asn    = $parsed['asn'] ?? $this->resolveAsn();

        return $this->importujEvents(
            $parsed['events'],
            $asn,
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka), 'asn' => $asn],
        );
    }

    /**
     * @return array{asn: int, feeds: list<string>, api_count: int, zapisano: int}
     */
    public function importujZApi(?ApiClient $client = null): array
    {
        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasKey()) {
            throw new RuntimeException('Qrator: brak apiKey');
        }

        $asn         = $this->resolveAsn();
        $ipVersion   = trim((string) env('mcs.qrator.ipVersion', 'all'));
        $withArchive = filter_var(env('mcs.qrator.withArchive', false), FILTER_VALIDATE_BOOLEAN);
        $feeds       = ApiClient::parsujFeeds((string) env('mcs.qrator.feeds', ''));

        $res = $client->fetchFeeds($asn, $ipVersion, $withArchive, $feeds);
        if (! $res['ok']) {
            throw new RuntimeException(
                'Qrator Radar API: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code']),
            );
        }

        $events = $res['events'];
        if ($events === []) {
            return [
                'asn'       => $asn,
                'feeds'     => $feeds,
                'api_count' => 0,
                'zapisano'  => 0,
            ];
        }

        $checksumMaterial = $res['raw_bodies'] !== []
            ? json_encode($res['raw_bodies'], JSON_UNESCAPED_UNICODE)
            : json_encode($events, JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $checksumMaterial !== false ? $checksumMaterial : '');
        $idWiad   = 'qrator:asn' . $asn . ':' . substr($checksum, 0, 12);

        $zapisano = $this->importujEvents(
            $events,
            $asn,
            $checksum,
            $idWiad,
            [
                'tryb'       => 'api',
                'asn'        => $asn,
                'feeds'      => $feeds,
                'ip_version' => $client->normalizeIpVersion($ipVersion),
                'api_count'  => count($events),
            ],
        );

        return [
            'asn'       => $asn,
            'feeds'     => $feeds,
            'api_count' => count($events),
            'zapisano'  => $zapisano,
        ];
    }

    /**
     * @param list<array{feed: string, raw: array<string, mixed>}> $events
     * @param array<string, mixed>                                 $meta
     */
    private function importujEvents(
        array $events,
        int $asn,
        string $checksum,
        string $idWiadomosci,
        array $meta,
    ): int {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            if ($events === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Qrator: pusta lista events');

                return 0;
            }

            $licznik = 0;
            foreach ($events as $event) {
                $licznik += $this->zapiszWpis($db, $importId, $event, $asn);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna(
                    $importId,
                    'Qrator: brak zmapowanych wierszy (nieznany feed albo zly payload)',
                );

                return 0;
            }

            $this->koperta->zakonczOk($importId, [
                'wierszy'     => $licznik,
                'mapa_wersja' => Parser::MAPA_WERSJA,
                'api_count'   => count($events),
                'asn'         => $asn,
            ]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection                 $db
     * @param array{feed: string, raw: array<string, mixed>}|array $event
     */
    private function zapiszWpis($db, int $importId, array $event, int $asn): int
    {
        $mapped = $this->parser->mapujWpis($event, $asn);
        if ($mapped === null) {
            return 0;
        }

        $sygnaly = [
            'tag' => $mapped['tag'],
        ];
        if ($mapped['severity_sygnal'] !== null) {
            $sygnaly['severity'] = $mapped['severity_sygnal'];
        }
        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        $istniejacy = $db->table('mcs_qrator_wiersze')
            ->where('klucz_dedupu', $mapped['klucz_dedupu'])
            ->get()
            ->getRowArray();

        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            $idWZrodle = (string) $istniejacy['id'];
            $db->table('mcs_qrator_wiersze')
                ->where('id', $istniejacy['id'])
                ->update([
                    'severity'     => $mapped['severity'],
                    'last_seen'    => $mapped['last_seen'],
                    'payload_json' => json_encode(['raw' => $mapped['raw'], 'feed' => $mapped['event_typ']], JSON_UNESCAPED_UNICODE),
                ]);
        } else {
            $db->table('mcs_qrator_wiersze')->insert([
                'import_id'        => $importId,
                'zaobserwowano_at' => $mapped['zaobserwowano_at'],
                'event_typ'        => $mapped['event_typ'],
                'asn'              => $mapped['asn'],
                'prefix'           => $mapped['prefix'],
                'ip'               => $mapped['ip'],
                'severity'         => $mapped['severity'],
                'first_seen'       => $mapped['first_seen'],
                'last_seen'        => $mapped['last_seen'],
                'klucz_dedupu'     => $mapped['klucz_dedupu'],
                'payload_json'     => json_encode(['raw' => $mapped['raw'], 'feed' => $mapped['event_typ']], JSON_UNESCAPED_UNICODE),
            ]);
            $idWZrodle = (string) $db->insertID();
        }

        $this->zapis->zapisz([
            'zrodlo'           => $this->zrodlo(),
            'klucz_dedupu'     => $mapped['klucz_dedupu'],
            'podmiot_typ'      => $mapped['podmiot_typ'],
            'podmiot'          => $mapped['podmiot'],
            'zaobserwowano_at' => $mapped['zaobserwowano_at'],
            'waga'             => $wynikWagi['waga'],
            'wersja_wagi'      => $wynikWagi['wersja'],
            'kategoria'        => $mapped['kategoria'],
            'tytul'            => $mapped['tytul'],
            'port'             => null,
            'protokol'         => null,
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'event_typ'   => $mapped['event_typ'],
                'asn'         => $mapped['asn'],
                'prefix'      => $mapped['prefix'],
                'severity'    => $mapped['severity'],
                'mapa_wersja' => Parser::MAPA_WERSJA,
                'waga_meta'   => $wynikWagi,
            ],
        ]);

        return 1;
    }

    public function resolveAsn(): int
    {
        $explicit = \App\Libraries\OpcjeMcs::tekst('mcs.qrator.asn');
        if ($explicit !== '' && ctype_digit($explicit)) {
            return (int) $explicit;
        }

        // Domyślnie ASN globalny (mcs.as198050.asn, jak CF Botnet).
        return \App\Libraries\Siec\As198050Matcher::asn();
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.qrator.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasKey()) {
            return 'brak fixturePath i apiKey (uzupelnij .env — klucz z radar.qrator.net)';
        }

        $feeds = ApiClient::parsujFeeds((string) env('mcs.qrator.feeds', ''));

        return 'konfiguracja: API QRADAR-API-KEY gotowe (jeszcze bez przebiegu); asn='
            . $this->resolveAsn()
            . ' feeds=' . implode(',', $feeds);
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
        ]);
    }
}
