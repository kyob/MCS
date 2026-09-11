<?php

namespace McsPluginy\cert_n6;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV0;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła CERT N6 (n6api.cert.pl report/inside).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.cert_n6.fixturePath
 * 2) REST — Bearer i/lub mTLS + inkrementalny time.min (state file)
 *
 * klucz_dedupu = id zdarzenia n6 (jak LMS UNIQUE id).
 * Notify / userpanel zostają w LMS — tu tylko indeks MCS.
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV0 $waga;
    private ?ApiClient $apiClient;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV0 $waga = null,
        ?ApiClient $apiClient = null,
    ) {
        $this->parser    = $parser ?? new Parser();
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->zapis     = $zapis ?? new IncydentZapis();
        $this->waga      = $waga ?? new WagaV0();
        $this->apiClient = $apiClient;
    }

    public function zrodlo(): string
    {
        return 'cert_n6';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.cert_n6.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste zdarzenia)',
            );

            return;
        }

        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            $msg = 'CERT N6: ustaw mcs.cert_n6.fixturePath (smoke) albo bearerToken/apiKeyFile lub clientCert+Key w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi($client);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (api=%d, zapisano=%d, time.min=%s)',
                    $stats['api_count'],
                    $stats['zapisano'],
                    $stats['time_min'],
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

    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('CERT N6: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc = (string) file_get_contents($sciezka);

        return $this->importujTresc(
            $tresc,
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
            null,
        );
    }

    /**
     * @return array{api_count: int, zapisano: int, time_min: string, next_time_min: string}
     */
    public function importujZApi(?ApiClient $client = null): array
    {
        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            throw new RuntimeException('CERT N6: brak autoryzacji API');
        }

        $timeMin = $this->resolveTimeMin();
        $optLimit = env('mcs.cert_n6.optLimit', '');
        $optLimit = filter_var($optLimit, FILTER_VALIDATE_INT);
        $optLimit = ($optLimit !== false && $optLimit > 0) ? $optLimit : null;
        $modMin   = trim((string) env('mcs.cert_n6.modifiedMin', ''));
        $modMin   = $modMin !== '' ? $modMin : null;

        $res = $client->fetchReport($timeMin, $optLimit, $modMin);
        if (! $res['ok']) {
            throw new RuntimeException('CERT N6 API: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code']));
        }

        $body     = $res['body'];
        $events   = $this->parser->parsujReportJson($body);
        $maxTime  = $this->parser->maxEventTime($events);
        $nextMin  = $maxTime !== null && $maxTime !== '' ? $maxTime : gmdate('Y-m-d\TH:i:s\Z');
        $idWiad   = 'cert_n6:' . $timeMin . '->' . $nextMin;
        $checksum = hash('sha256', $body);

        $zapisano = $this->importujTresc(
            $body,
            $checksum,
            $idWiad,
            [
                'tryb'          => 'api',
                'time_min'      => $timeMin,
                'next_time_min' => $nextMin,
                'api_count'     => count($events),
            ],
            $events,
        );

        $this->zapiszStan(['next_time_min' => $nextMin]);

        return [
            'api_count'     => count($events),
            'zapisano'      => $zapisano,
            'time_min'      => $timeMin,
            'next_time_min' => $nextMin,
        ];
    }

    /**
     * @param array<string, mixed>|null       $meta
     * @param list<array<string, mixed>>|null $eventsPreparsed
     */
    private function importujTresc(
        string $tresc,
        string $checksum,
        string $idWiadomosci,
        array $meta,
        ?array $eventsPreparsed,
    ): int {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            $events = $eventsPreparsed ?? $this->parser->parsujReportJson($tresc);
            if ($events === []) {
                $this->koperta->zakonczKwarantanna($importId, 'CERT N6: pusta tablica zdarzen');

                return 0;
            }

            $licznik = 0;
            foreach ($events as $item) {
                $licznik += $this->zapiszZdarzenie($db, $importId, $item);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna($importId, 'CERT N6: brak wierszy z id+IP (address[0])');

                return 0;
            }

            $this->koperta->zakonczOk($importId, [
                'wierszy'   => $licznik,
                'api_count' => count($events),
            ]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $item
     */
    private function zapiszZdarzenie($db, int $importId, array $item): int
    {
        $mapped = $this->parser->mapujZdarzenie($item);
        if ($mapped === null) {
            return 0;
        }

        // confidence jako severity tylko dla kategorii „miękkich” — nie obniża malware/vuln/amplification
        $sygnaly = [];
        $conf    = strtolower(trim($mapped['confidence']));
        if (
            in_array($mapped['kategoria'], ['other', 'exposure', 'reputation'], true)
            && in_array($conf, ['low', 'medium', 'high', 'critical'], true)
        ) {
            $sygnaly['severity'] = $conf;
        }

        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        // Jak LMS ON CONFLICT (id) DO NOTHING — unikamy crasha przy powtórce n6_id.
        $istniejacy = $db->table('mcs_cert_n6_zdarzenia')
            ->where('n6_id', $mapped['n6_id'])
            ->get()
            ->getRowArray();

        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            $idWZrodle = (string) $istniejacy['id'];
        } else {
            $db->table('mcs_cert_n6_zdarzenia')->insert([
                'import_id'        => $importId,
                'zaobserwowano_at' => $mapped['zaobserwowano_at'],
                'n6_id'            => $mapped['n6_id'],
                'ip'               => $mapped['ip'],
                'asn'              => $mapped['asn'],
                'cc'               => $mapped['cc'],
                'category'         => $mapped['category'],
                'confidence'       => $mapped['confidence'],
                'name'             => $mapped['name'],
                'source'           => $mapped['source'],
                'dport'            => $mapped['dport'],
                'sport'            => $mapped['sport'],
                'fqdn'             => $mapped['fqdn'],
                'proto'            => $mapped['proto'],
                'origin'           => $mapped['origin'],
                'restriction'      => $mapped['restriction'],
                'modified'         => $mapped['modified'],
                'adip'             => $mapped['adip'],
                'product'          => $mapped['product'],
                'n6kb_url'         => $mapped['n6kb_url'],
                'klucz_dedupu'     => $mapped['klucz_dedupu'],
                'payload_json'     => json_encode(['raw' => $mapped['raw']], JSON_UNESCAPED_UNICODE),
            ]);
            $idWZrodle = (string) $db->insertID();
        }

        $tytul = 'CERT N6';
        if ($mapped['category'] !== '') {
            $tytul .= ' — ' . $mapped['category'];
        }
        if ($mapped['name'] !== '') {
            $tytul .= ': ' . $mapped['name'];
        }

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
            'port'             => $mapped['dport'] > 0 ? $mapped['dport'] : null,
            'protokol'         => $mapped['proto'] !== '' ? $mapped['proto'] : null,
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'n6_id'      => $mapped['n6_id'],
                'category'   => $mapped['category'],
                'confidence' => $mapped['confidence'],
                'name'       => $mapped['name'],
                'fqdn'       => $mapped['fqdn'],
                'n6kb_url'   => $mapped['n6kb_url'],
                'waga_meta'  => $wynikWagi,
            ],
        ]);

        return 1;
    }

    public function resolveTimeMin(): string
    {
        $state = $this->odczytajStan();
        if (is_array($state) && ! empty($state['next_time_min'])) {
            return (string) $state['next_time_min'];
        }

        $explicit = trim((string) env('mcs.cert_n6.timeMin', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $days = (int) env('mcs.cert_n6.lookbackDays', 90);
        if ($days < 1) {
            $days = 90;
        }

        return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
    }

    public function sciezkaStanu(): string
    {
        $override = trim((string) env('mcs.cert_n6.stateFile', ''));
        if ($override !== '') {
            return $override;
        }

        return WRITEPATH . 'cert_n6_state.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function odczytajStan(): ?array
    {
        $path = $this->sciezkaStanu();
        if (! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function zapiszStan(array $data): bool
    {
        $path = $this->sciezkaStanu();
        $dir  = dirname($path);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return false;
        }
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($payload === false) {
            return false;
        }

        return @file_put_contents($path, $payload . PHP_EOL) !== false;
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.cert_n6.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if ($client->hasAuth()) {
            return 'konfiguracja: API auth gotowe (jeszcze bez przebiegu)';
        }

        return 'brak fixturePath i auth (bearer/apiKeyFile lub client cert)';
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
