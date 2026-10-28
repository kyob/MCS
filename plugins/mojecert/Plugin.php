<?php

namespace McsPluginy\mojecert;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła MojeCert (moje.cert.pl) — wyniki skanów.
 *
 * Tryby pobierz():
 * 1) plik fixture — gdy ustawione mcs.mojecert.fixturePath (dev / test)
 * 2) REST API — mcs.mojecert.apiToken (+ opcjonalnie baseUrl)
 *
 * klucz_dedupu: sha256 jak w LMSMojeCertPlugin (scan + org + subnet + ip + ts + target + type).
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
        return 'mojecert';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.mojecert.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste results)',
            );

            return;
        }

        $token = trim((string) env('mcs.mojecert.apiToken', ''));
        if ($token === '') {
            $msg = 'MojeCert: ustaw mcs.mojecert.fixturePath (smoke) albo mcs.mojecert.apiToken w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $stats = $this->importujZApi();
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (orgs=%d, subnets=%d, skany=%d)',
                    $stats['orgs'],
                    $stats['subnets'],
                    $stats['skany'],
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
        $plik   = trim((string) env('mcs.mojecert.fixturePath', ''));
        $base   = trim((string) env('mcs.mojecert.baseUrl', 'https://moje.cert.pl'));
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $tokOk  = $client->hasToken();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'HTTP moje.cert.pl',
            $base !== '' ? $base : 'https://moje.cert.pl',
            $tokOk,
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja(
                    'Token API',
                    PluginOpisPolaczenia::sekret($tokOk),
                    false,
                    $tokOk,
                ),
            ],
        );
    }

    /**
     * Import jednego pliku fixture JSON.
     */
    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('MojeCert: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc = (string) file_get_contents($sciezka);
        $pakiet = $this->parser->parsujFixtureJson($tresc);

        return $this->importujWyniki(
            $pakiet['results'],
            $pakiet['org_slug'],
            $pakiet['org_name'],
            $pakiet['subnet_cidr'],
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka)],
            null,
        );
    }

    /**
     * @return array{orgs: int, subnets: int, skany: int}
     */
    public function importujZApi(): array
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasToken()) {
            throw new RuntimeException('MojeCert: mcs.mojecert.apiToken is not set');
        }

        $syncDaysBack = \App\Libraries\OpcjeMcs::int('mcs.mojecert.syncDaysBack');
        if ($syncDaysBack < 0) {
            $syncDaysBack = 0;
        }
        $maxPages = \App\Libraries\OpcjeMcs::int('mcs.mojecert.syncMaxPagesPerSubnet');
        if ($maxPages < 1) {
            $maxPages = 100;
        }
        $cutoffUnix = $syncDaysBack >= 1 ? (time() - ($syncDaysBack * 86400)) : null;

        $orgSlugs = [];
        $page     = 1;
        while ($page <= 500) {
            $r = $client->listOrganizations(['page' => $page]);
            if (! $r['ok']) {
                throw new RuntimeException(
                    'MojeCert: organizations list failed: ' . ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['http_code']),
                );
            }
            $data = $r['data'];
            if (! is_array($data) || empty($data['results'])) {
                break;
            }
            foreach ($data['results'] as $o) {
                if (is_array($o) && ! empty($o['slug'])) {
                    $orgSlugs[(string) $o['slug']] = true;
                }
            }
            if (empty($data['next'])) {
                break;
            }
            ++$page;
        }

        $stats = ['orgs' => count($orgSlugs), 'subnets' => 0, 'skany' => 0];

        // Jedna koperta na cały przebieg API (unikalny id — LMS też upsertuje przy każdym sync).
        $runId    = 'mojecert:api:' . gmdate('Y-m-d\TH:i:s') . ':' . bin2hex(random_bytes(4));
        $importId = $this->koperta->rozpocznij(
            $this->zrodlo(),
            hash('sha256', $runId),
            $runId,
            [
                'tryb'            => 'api',
                'sync_days_back'  => $syncDaysBack,
                'orgs'            => $stats['orgs'],
            ],
        );
        if ($importId === null) {
            return $stats;
        }

        $db    = Database::connect();
        $bledy = [];

        try {
            foreach (array_keys($orgSlugs) as $orgSlug) {
                $rg = $client->getOrganization($orgSlug);
                if (! $rg['ok'] || ! is_array($rg['data'])) {
                    $bledy[] = $orgSlug . ': ' . (string) ($rg['error'] ?? 'brak danych organizacji');
                    continue;
                }
                $detail  = $rg['data'];
                $orgName = isset($detail['name']) ? (string) $detail['name'] : '';
                $subnets = isset($detail['subnets']) && is_array($detail['subnets']) ? $detail['subnets'] : [];

                foreach ($subnets as $sn) {
                    if (! is_array($sn)) {
                        continue;
                    }
                    $subnetPath = SubnetHelper::apiPathFromSn($sn);
                    if ($subnetPath === null) {
                        continue;
                    }
                    $subnetStorage = SubnetHelper::storageLabelFromSn($sn) ?? $subnetPath;
                    if (array_key_exists('verified', $sn) && $sn['verified'] === false) {
                        continue;
                    }
                    ++$stats['subnets'];

                    for ($p = 1; $p <= $maxPages; ++$p) {
                        $scanQuery = ['page' => $p];
                        if ($cutoffUnix !== null) {
                            $scanQuery['ordering'] = ['-timestamp'];
                        }
                        $rs = $client->listOrganizationSubnetScanResults($orgSlug, $subnetPath, $scanQuery);
                        if (! $rs['ok']) {
                            $bledy[] = $orgSlug . ' ' . $subnetStorage . ' str. ' . $p . ': ' . (string) ($rs['error'] ?? 'błąd API');
                            break;
                        }
                        $sd = $rs['data'];
                        if (! is_array($sd) || empty($sd['results']) || ! is_array($sd['results'])) {
                            break;
                        }

                        $pozaOknem = false;
                        foreach ($sd['results'] as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $tsRaw = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
                            if ($cutoffUnix !== null && $tsRaw !== '') {
                                $rowUnix = strtotime($tsRaw);
                                if ($rowUnix !== false && $rowUnix < $cutoffUnix) {
                                    $pozaOknem = true;
                                    break;
                                }
                            }
                            $n = $this->zapiszWiersz(
                                $db,
                                $importId,
                                $row,
                                $orgSlug,
                                $orgName,
                                $subnetStorage,
                            );
                            $stats['skany'] += $n;
                        }

                        if ($pozaOknem || empty($sd['next'])) {
                            break;
                        }
                    }
                }
            }

            if ($bledy !== []) {
                // Niepełny import nie może wyglądać jak „czysto”: koperta = błąd, health = błąd.
                throw new \RuntimeException(
                    'MojeCert API: ' . count($bledy) . ' błędów (zapisano ' . $stats['skany'] . ' wierszy). '
                    . implode('; ', array_slice($bledy, 0, 3)),
                );
            }

            $this->koperta->zakonczOk($importId, [
                'orgs'    => $stats['orgs'],
                'subnets' => $stats['subnets'],
                'wierszy' => $stats['skany'],
            ]);

            return $stats;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed>       $meta
     * @param int|null                   $cutoffUnix
     */
    private function importujWyniki(
        array $results,
        string $orgSlug,
        string $orgName,
        string $subnetCidr,
        string $checksum,
        string $idWiadomosci,
        array $meta,
        ?int $cutoffUnix,
    ): int {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            if ($results === []) {
                $this->koperta->zakonczKwarantanna($importId, 'MojeCert: puste results');

                return 0;
            }

            $licznik = 0;
            foreach ($results as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $tsRaw = isset($row['timestamp']) ? (string) $row['timestamp'] : '';
                if ($cutoffUnix !== null && $tsRaw !== '') {
                    $rowUnix = strtotime($tsRaw);
                    if ($rowUnix !== false && $rowUnix < $cutoffUnix) {
                        continue;
                    }
                }
                $licznik += $this->zapiszWiersz($db, $importId, $row, $orgSlug, $orgName, $subnetCidr);
            }

            $this->koperta->zakonczOk($importId, ['wierszy' => $licznik]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $row
     */
    private function zapiszWiersz(
        $db,
        int $importId,
        array $row,
        string $orgSlug,
        string $orgName,
        string $subnetStorage,
    ): int {
        $mapped = $this->parser->mapujWiersz($row, $orgSlug, $subnetStorage);
        if ($mapped === null) {
            return 0;
        }

        $istniejacy = $db->table('mcs_mojecert_skany')
            ->where('klucz_dedupu', $mapped['klucz_dedupu'])
            ->get()
            ->getRowArray();
        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            return 0;
        }

        $sygnaly = [];
        if ($mapped['severity'] !== '') {
            $sygnaly['severity'] = $mapped['severity'];
        }
        if ($mapped['report_type'] !== '') {
            $sygnaly['report_type'] = $mapped['report_type'];
        }
        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        $db->table('mcs_mojecert_skany')->insert([
            'import_id'        => $importId,
            'zaobserwowano_at' => $mapped['zaobserwowano_at'],
            'ip'               => $mapped['ip'],
            'org_slug'         => $orgSlug,
            'org_name'         => $orgName,
            'subnet_cidr'      => $subnetStorage,
            'report_type'      => $mapped['report_type'],
            'severity'         => $mapped['severity'],
            'target'           => $mapped['target'],
            'klucz_dedupu'     => $mapped['klucz_dedupu'],
            'payload_json'     => json_encode([
                'raw'            => $mapped['raw'],
                'last_domain'    => $mapped['last_domain'],
                'detail_excerpt' => $mapped['detail_excerpt'],
                'name_title'     => $mapped['name_title'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $idWZrodle = (string) $db->insertID();

        $tytul = 'MojeCert';
        if ($mapped['report_type'] !== '') {
            $tytul .= ' — ' . $mapped['report_type'];
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
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'org_slug'     => $orgSlug,
                'subnet_cidr'  => $subnetStorage,
                'report_type'  => $mapped['report_type'],
                'severity'     => $mapped['severity'],
                'target'       => $mapped['target'],
                'last_domain'  => $mapped['last_domain'],
                'waga_meta'    => $wynikWagi,
            ],
        ]);

        return 1;
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.mojecert.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        if (trim((string) env('mcs.mojecert.apiToken', '')) !== '') {
            return 'konfiguracja: API token gotowy (jeszcze bez przebiegu)';
        }

        return 'brak fixturePath i apiToken (uzupelnij .env)';
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
