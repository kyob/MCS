<?php

namespace McsPluginy\wanguard;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV0;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Wanguard — Finished anomalies (REST Basic Auth).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.wanguard.fixturePath
 * 2) REST — apiUrl + apiUser + apiPassword
 *
 * klucz_dedupu = anomaly_id. Notify zostaje w LMS — tu tylko indeks MCS.
 * Cutover: nie wyłączamy fetch w LMS z poziomu tego kodu.
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
        return 'wanguard';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.wanguard.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste anomalie z IP)',
            );

            return;
        }

        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            $msg = 'Wanguard: ustaw mcs.wanguard.fixturePath (smoke) albo apiUrl+apiUser+apiPassword w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi($client);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (lista=%d, nowe=%d, zapisano=%d, mapa=%s)',
                    $stats['lista'],
                    $stats['nowe'],
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

    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Wanguard: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc   = (string) file_get_contents($sciezka);
        $details = $this->parser->parsujFixtureJson($tresc);

        return $this->importujDetails(
            $details,
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
        );
    }

    /**
     * @return array{lista: int, nowe: int, zapisano: int}
     */
    public function importujZApi(?ApiClient $client = null): array
    {
        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            throw new RuntimeException('Wanguard: brak autoryzacji API');
        }

        $listRes = $client->listFinishedAnomalies();
        if (! $listRes['ok']) {
            throw new RuntimeException('Wanguard API lista: ' . ($listRes['error'] !== '' ? $listRes['error'] : 'HTTP ' . $listRes['http_code']));
        }

        $idsFromApi = [];
        foreach ($listRes['items'] as $item) {
            $id = $this->parser->extractAnomalyId($item);
            if ($id !== null) {
                $idsFromApi[] = $id;
            }
        }
        $idsFromApi = array_values(array_unique($idsFromApi));

        $existing = $this->istniejaceAnomalyIds($idsFromApi);
        $idsToFetch = [];
        foreach ($idsFromApi as $id) {
            if (! isset($existing[$id])) {
                $idsToFetch[] = $id;
            }
        }

        if ($idsToFetch === []) {
            return [
                'lista'    => count($idsFromApi),
                'nowe'     => 0,
                'zapisano' => 0,
            ];
        }

        $detRes = $client->fetchDetailsConcurrent($idsToFetch);
        if (! $detRes['ok']) {
            throw new RuntimeException('Wanguard API detail: ' . $detRes['error']);
        }

        $details = [];
        foreach ($idsToFetch as $id) {
            $row = $detRes['details'][$id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            if (! isset($row['anomaly_id'])) {
                $row['anomaly_id'] = $id;
            }
            $details[] = $row;
        }

        sort($idsToFetch);
        $checksum = hash('sha256', 'wanguard:' . implode(',', $idsToFetch));
        $idWiad   = 'wanguard:new:' . implode(',', array_slice($idsToFetch, 0, 20))
            . (count($idsToFetch) > 20 ? (':+' . (count($idsToFetch) - 20)) : '');

        $zapisano = $this->importujDetails(
            $details,
            $checksum,
            $idWiad,
            [
                'tryb'  => 'api',
                'lista' => count($idsFromApi),
                'nowe'  => count($idsToFetch),
            ],
        );

        return [
            'lista'    => count($idsFromApi),
            'nowe'     => count($idsToFetch),
            'zapisano' => $zapisano,
        ];
    }

    /**
     * @param list<array<string, mixed>> $details
     * @param array<string, mixed>       $meta
     */
    private function importujDetails(array $details, string $checksum, string $idWiadomosci, array $meta): int
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();
        $allow = Parser::parsujCidrAllowlist((string) env('mcs.wanguard.cidrAllowlist', ''));

        try {
            if ($details === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Wanguard: brak detaili do zapisu');

                return 0;
            }

            $licznik = 0;
            foreach ($details as $detail) {
                $licznik += $this->zapiszAnomalie($db, $importId, $detail, $allow);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna($importId, 'Wanguard: brak wierszy z anomaly_id+IP (prefix)');

                return 0;
            }

            $this->koperta->zakonczOk($importId, [
                'wierszy'      => $licznik,
                'mapa_wersja'  => Parser::MAPA_WERSJA,
                'details'      => count($details),
            ]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $detail
     * @param list<string>                         $allow
     */
    private function zapiszAnomalie($db, int $importId, array $detail, array $allow): int
    {
        $mapped = $this->parser->mapujAnomalie($detail, $allow);
        if ($mapped === null) {
            return 0;
        }

        $sygnaly = [];
        if ($mapped['severity_sygnal'] !== null) {
            $sygnaly['severity'] = $mapped['severity_sygnal'];
        }
        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        $istniejacy = $db->table('mcs_wanguard_anomalie')
            ->where('anomaly_id', $mapped['anomaly_id'])
            ->get()
            ->getRowArray();

        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            $idWZrodle = (string) $istniejacy['id'];
        } else {
            $db->table('mcs_wanguard_anomalie')->insert([
                'import_id'         => $importId,
                'zaobserwowano_at'  => $mapped['zaobserwowano_at'],
                'anomaly_id'        => $mapped['anomaly_id'],
                'ip'                => $mapped['ip'],
                'prefix'            => $mapped['prefix'],
                'status'            => $mapped['status'],
                'anomaly'           => $mapped['anomaly'],
                'direction'         => $mapped['direction'],
                'from_unix'         => $mapped['from_unix'],
                'until_unix'        => $mapped['until_unix'],
                'duration_seconds'  => $mapped['duration_seconds'],
                'mitigated'         => $mapped['mitigated'],
                'severity'          => $mapped['severity_raw'],
                'link_severity'     => $mapped['link_severity'],
                'peak_pkts_s'       => $mapped['peak_pkts_s'],
                'peak_bits_s'       => $mapped['peak_bits_s'],
                'klucz_dedupu'      => $mapped['klucz_dedupu'],
                'payload_json'      => json_encode(['raw' => $mapped['raw']], JSON_UNESCAPED_UNICODE),
            ]);
            $idWZrodle = (string) $db->insertID();
        }

        $tytul = 'Wanguard';
        if ($mapped['anomaly'] !== '') {
            $tytul .= ' — ' . $mapped['anomaly'];
        }
        if ($mapped['direction'] !== '') {
            $tytul .= ' (' . $mapped['direction'] . ')';
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
            'port'             => null,
            'protokol'         => null,
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'anomaly_id'    => $mapped['anomaly_id'],
                'anomaly'       => $mapped['anomaly'],
                'direction'     => $mapped['direction'],
                'severity'      => $mapped['severity_raw'],
                'peak_pkts_s'   => $mapped['peak_pkts_s'],
                'peak_bits_s'   => $mapped['peak_bits_s'],
                'mapa_wersja'   => Parser::MAPA_WERSJA,
                'waga_meta'     => $wynikWagi,
            ],
        ]);

        return 1;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, true>
     */
    private function istniejaceAnomalyIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $db       = Database::connect();
        $existing = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $db->table('mcs_wanguard_anomalie')
                ->select('anomaly_id')
                ->whereIn('anomaly_id', $chunk)
                ->get()
                ->getResultArray();
            foreach ($rows as $row) {
                $existing[(int) $row['anomaly_id']] = true;
            }
        }

        return $existing;
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.wanguard.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if ($client->hasAuth()) {
            return 'konfiguracja: API Basic Auth gotowe (jeszcze bez przebiegu); url=' . $client->getApiUrl()
                . ' user=' . $client->getApiUser();
        }

        return 'brak fixturePath i apiUrl/apiUser/apiPassword';
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
