<?php

namespace McsPluginy\cloudflare_botnet;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Cloudflare DDoS Botnet Threat Feed (AS-scoped).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.cloudflare_botnet.fixturePath
 * 2) REST Bearer — apiToken + accountId (+ asn)
 *
 * klucz_dedupu = "{cidr}|{YYYY-MM-DD}".
 *
 * @see https://developers.cloudflare.com/ddos-protection/botnet-threat-feed/
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
        return 'cloudflare_botnet';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.cloudflare_botnet.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste result)',
            );

            return;
        }

        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasToken()) {
            $msg = 'Cloudflare Botnet: ustaw mcs.cloudflare_botnet.fixturePath (smoke) albo apiToken w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi($client);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (asn=%d, tryb=%s, api=%d, zapisano=%d, mapa=%s)',
                    $stats['asn'],
                    $stats['tryb'],
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
        $plik   = trim((string) env('mcs.cloudflare_botnet.fixturePath', ''));
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $asn    = trim((string) env('mcs.cloudflare_botnet.asn', '198050'));
        $tokOk  = $client->hasToken();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'HTTP Cloudflare',
            $client->getBaseUrl(),
            $tokOk,
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja('Konto (accountId)', $client->getAccountId(), false),
                PluginOpisPolaczenia::pozycja('ASN', $asn, false, $asn !== ''),
                PluginOpisPolaczenia::pozycja(
                    'Token API',
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
            throw new RuntimeException('Cloudflare Botnet: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc   = (string) file_get_contents($sciezka);
        $entries = $this->parser->parsujJson($tresc);

        return $this->importujEntries(
            $entries,
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
        );
    }

    /**
     * @return array{asn: int, tryb: string, api_count: int, zapisano: int}
     */
    public function importujZApi(?ApiClient $client = null): array
    {
        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasToken()) {
            throw new RuntimeException('Cloudflare Botnet: brak apiToken');
        }

        $resolved = $client->resolveAccountId();
        if (! $resolved['ok']) {
            throw new RuntimeException('Cloudflare Botnet: ' . $resolved['error']);
        }

        $asn  = $this->resolveAsn($client);
        $tryb = strtolower(trim((string) env('mcs.cloudflare_botnet.mode', 'full_report')));
        if ($tryb !== 'day_report') {
            $tryb = 'full_report';
        }

        if ($tryb === 'day_report') {
            $date = trim((string) env('mcs.cloudflare_botnet.date', ''));
            $date = $date !== '' ? $date : null;
            $res  = $client->fetchDayReport($asn, $date);
        } else {
            $res = $client->fetchFullReport($asn);
        }

        if (! $res['ok']) {
            throw new RuntimeException(
                'Cloudflare Botnet API: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code']),
            );
        }

        $entries = $res['entries'];
        if ($entries === []) {
            return [
                'asn'       => $asn,
                'tryb'      => $tryb,
                'api_count' => 0,
                'zapisano'  => 0,
            ];
        }

        $checksum = hash('sha256', $res['raw_body'] !== '' ? $res['raw_body'] : json_encode($entries));
        $idWiad   = 'cloudflare_botnet:' . $tryb . ':asn' . $asn . ':' . substr($checksum, 0, 12);

        $zapisano = $this->importujEntries(
            $entries,
            $checksum,
            $idWiad,
            [
                'tryb'       => $tryb,
                'asn'        => $asn,
                'account_id' => $client->getAccountId(),
                'api_count'  => count($entries),
            ],
        );

        return [
            'asn'       => $asn,
            'tryb'      => $tryb,
            'api_count' => count($entries),
            'zapisano'  => $zapisano,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>       $meta
     */
    private function importujEntries(array $entries, string $checksum, string $idWiadomosci, array $meta): int
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db    = Database::connect();
        $allow = Parser::parsujCidrAllowlist((string) env('mcs.cloudflare_botnet.cidrAllowlist', ''));

        try {
            if ($entries === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Cloudflare Botnet: pusta lista result');

                return 0;
            }

            $licznik = 0;
            foreach ($entries as $entry) {
                $licznik += $this->zapiszWpis($db, $importId, $entry, $allow);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna(
                    $importId,
                    'Cloudflare Botnet: brak wierszy z cidr/IP (filtr CIDR albo zly payload)',
                );

                return 0;
            }

            $this->koperta->zakonczOk($importId, [
                'wierszy'     => $licznik,
                'mapa_wersja' => Parser::MAPA_WERSJA,
                'api_count'   => count($entries),
            ]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $entry
     * @param list<string>                         $allow
     */
    private function zapiszWpis($db, int $importId, array $entry, array $allow): int
    {
        $mapped = $this->parser->mapujWpis($entry, $allow);
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

        $istniejacy = $db->table('mcs_cloudflare_botnet_wiersze')
            ->where('klucz_dedupu', $mapped['klucz_dedupu'])
            ->get()
            ->getRowArray();

        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            $idWZrodle = (string) $istniejacy['id'];
            // Odśwież offense_count przy powtórce tego samego dnia.
            $db->table('mcs_cloudflare_botnet_wiersze')
                ->where('id', $istniejacy['id'])
                ->update([
                    'offense_count' => $mapped['offense_count'],
                    'payload_json'  => json_encode(['raw' => $mapped['raw']], JSON_UNESCAPED_UNICODE),
                ]);
        } else {
            $db->table('mcs_cloudflare_botnet_wiersze')->insert([
                'import_id'         => $importId,
                'zaobserwowano_at'  => $mapped['zaobserwowano_at'],
                'cidr'              => $mapped['cidr'],
                'ip'                => $mapped['ip'],
                'report_date'       => $mapped['date_ymd'],
                'offense_count'     => $mapped['offense_count'],
                'klucz_dedupu'      => $mapped['klucz_dedupu'],
                'payload_json'      => json_encode(['raw' => $mapped['raw']], JSON_UNESCAPED_UNICODE),
            ]);
            $idWZrodle = (string) $db->insertID();
        }

        $tytul = 'Cloudflare Botnet';
        if ($mapped['offense_count'] > 0) {
            $tytul .= ' — ' . number_format($mapped['offense_count'], 0, '.', ' ') . ' ofensyw';
        }
        $tytul .= ' (' . $mapped['date_ymd'] . ')';

        $this->zapis->zapisz([
            'zrodlo'           => $this->zrodlo(),
            'klucz_dedupu'     => $mapped['klucz_dedupu'],
            'podmiot_typ'      => 'ip',
            'podmiot'          => $mapped['ip'],
            'zaobserwowano_at' => $mapped['zaobserwowano_at'],
            'waga'             => $wynikWagi['waga'],
            'wersja_wagi'      => $wynikWagi['wersja'],
            'kategoria'        => $mapped['kategoria'],
            'tytul'            => $tytul,
            'port'             => null,
            'protokol'         => 'http',
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'cidr'          => $mapped['cidr'],
                'date'          => $mapped['date_ymd'],
                'offense_count' => $mapped['offense_count'],
                'mapa_wersja'   => Parser::MAPA_WERSJA,
                'waga_meta'     => $wynikWagi,
            ],
        ]);

        return 1;
    }

    public function resolveAsn(?ApiClient $client = null): int
    {
        $explicit = trim((string) env('mcs.cloudflare_botnet.asn', ''));
        if ($explicit !== '' && ctype_digit($explicit)) {
            return (int) $explicit;
        }

        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        $listed = $client->listRegisteredAsns();
        if ($listed['ok'] && $listed['asns'] !== []) {
            return $listed['asns'][0];
        }

        // Domyślnie AS Alfa-System (jak As198050Matcher).
        return 198050;
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.cloudflare_botnet.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasToken()) {
            return 'brak fixturePath i apiToken (uzupelnij .env)';
        }
        if ($client->getAccountId() === '') {
            return 'konfiguracja: apiToken OK, accountId pusty (auto z GET /accounts przy pierwszym pobierz)';
        }

        return 'konfiguracja: API Bearer gotowe (jeszcze bez przebiegu); account='
            . $client->getAccountId()
            . ' asn=' . trim((string) env('mcs.cloudflare_botnet.asn', '198050'));
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
