<?php

namespace McsPluginy\wazuh;

use App\Libraries\ImportKoperta;
use App\Libraries\ImportLimity;
use App\Libraries\IncydentZapis;
use App\Libraries\PrzekroczonyLimitImportu;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\AsMatcher;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Wazuh — IP z naszego AS, które spamują / atakują nasze serwery (SMTP, SSH, WWW).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.wazuh.fixturePath
 * 2) SSH forced command — sshHost + sshUser + sshKey
 *
 * Wiersz źródła = ip + kategoria_wazuh + dzień (UTC); liczniki tylko rosną (okno eksportu przesuwa się).
 * Obserwacja/incydent tylko gdy wiersz nowy albo liczba wzrosła i liczba >= minZdarzen.
 * Bez wyjątków dla naszych serwerów (decyzja 2026-09-28) — rozpoznawalne po DNS.
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private ?ApiClient $apiClient;
    private ?AsMatcher $as;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ApiClient $apiClient = null,
        ?AsMatcher $as = null,
    ) {
        $this->parser    = $parser ?? new Parser();
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->zapis     = $zapis ?? new IncydentZapis();
        $this->waga      = $waga ?? new WagaV1();
        $this->apiClient = $apiClient;
        $this->as        = $as;
    }

    public function zrodlo(): string
    {
        return 'wazuh';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.wazuh.fixturePath', ''));
        try {
            if ($fixture !== '') {
                if (! is_readable($fixture)) {
                    throw new RuntimeException('Wazuh: nie mozna odczytac pliku ' . $fixture);
                }
                try {
                    ImportLimity::sprawdzPlik($fixture, 'Wazuh');
                } catch (PrzekroczonyLimitImportu $e) {
                    $this->koperta->oznaczBlad(
                        $this->zrodlo(),
                        hash_file('sha256', $fixture) ?: null,
                        'fixture:' . basename($fixture),
                        ['tryb' => 'fixture', 'plik' => basename($fixture)],
                        $e->getMessage(),
                    );
                    throw $e;
                }
                $s = $this->importujJson((string) file_get_contents($fixture), 'fixture:' . basename($fixture));
            } else {
                $client = $this->apiClient ?? ApiClient::fromEnv();
                if (! $client->gotowy()) {
                    throw new RuntimeException('Wazuh: ustaw mcs.wazuh.fixturePath (smoke) albo sshHost+sshUser+sshKey w .env');
                }
                $res = $client->pobierzEksport();
                if (! $res['ok']) {
                    throw new RuntimeException('Wazuh SSH: ' . $res['error']);
                }
                $s = $this->importujJson($res['stdout'], 'ssh:' . $client->getHost());
            }
            $this->aktualizujHealth(true, sprintf(
                'import OK (eksport=%d, AS%d=%d, nowe/zmienione=%d, incydenty=%d%s)',
                $s['eksport'], AsMatcher::asn(), $s['as'], $s['zmienione'], $s['incydenty'], $s['duplikat'] ? ', bez zmian' : '',
            ));
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

        return [
            'ok'  => (bool) ($ok ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
        ];
    }

    public function opisPolaczenia(): array
    {
        $plik   = trim((string) env('mcs.wazuh.fixturePath', ''));
        $client = $this->apiClient ?? ApiClient::fromEnv();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'SSH Wazuh',
            $client->getHost(),
            $client->gotowy(),
            'ssh',
            'SSH (forced command)',
            [
                PluginOpisPolaczenia::pozycja('Użytkownik', $client->getUser(), false),
                PluginOpisPolaczenia::pozycja('Okno (h)', (string) $client->getOknoGodzin(), false),
                PluginOpisPolaczenia::pozycja('Klucz SSH', PluginOpisPolaczenia::sekret($client->gotowy()), false, $client->gotowy()),
            ],
        );
    }

    /**
     * @return array{eksport: int, as: int, zmienione: int, incydenty: int, duplikat: bool}
     */
    public function importujJson(string $json, string $idWiadomosci): array
    {
        // Limity importu (issue #453): rozmiar eksportu i liczba wierszy — odrzucenie = `blad`.
        try {
            ImportLimity::sprawdzTresc($json, 'Wazuh', $idWiadomosci);
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad($this->zrodlo(), hash('sha256', $json), $idWiadomosci, ['id_wiadomosci' => $idWiadomosci], $e->getMessage());
            throw $e;
        }

        $eksport = $this->parser->parsujEksport($json);
        try {
            ImportLimity::sprawdzLiczbeWierszy('Wazuh', count($eksport['wiersze']));
        } catch (PrzekroczonyLimitImportu $e) {
            $this->koperta->oznaczBlad($this->zrodlo(), hash('sha256', $json), $idWiadomosci, ['id_wiadomosci' => $idWiadomosci], $e->getMessage());
            throw $e;
        }
        $as = $this->as ?? new AsMatcher();

        $nasze = [];
        foreach ($eksport['wiersze'] as $w) {
            $m = $this->parser->mapujWiersz($w);
            if ($m !== null && $as->czyNaszeIp($m['ip'])) {
                $nasze[] = $m;
            }
        }
        $wynik = ['eksport' => count($eksport['wiersze']), 'as' => count($nasze), 'zmienione' => 0, 'incydenty' => 0, 'duplikat' => false];
        if ($nasze === []) {
            return $wynik;
        }

        $klucze = array_map(static fn ($m) => $m['klucz_dedupu'] . '=' . $m['liczba'], $nasze);
        sort($klucze);
        $importId = $this->koperta->rozpocznij(
            $this->zrodlo(),
            hash('sha256', 'wazuh:' . implode(',', $klucze)),
            $idWiadomosci,
            ['okno_od' => $eksport['okno_od'], 'okno_do' => $eksport['okno_do'], 'eksport' => $wynik['eksport'], 'as' => $wynik['as']],
        );
        if ($importId === null) {
            $wynik['duplikat'] = true;

            return $wynik;
        }

        $db   = Database::connect();
        $min  = max(1, \App\Libraries\OpcjeMcs::int('mcs.wazuh.minZdarzen'));
        $high = max($min, \App\Libraries\OpcjeMcs::int('mcs.wazuh.progHigh'));

        try {
            $r = $this->zapiszWiersze($db, $importId, $nasze, $min, $high);
            $wynik['zmienione'] = $r['zmienione'];
            $wynik['incydenty'] = $r['incydenty'];
            $this->koperta->zakonczOk($importId, [
                'zmienione'   => $wynik['zmienione'],
                'incydenty'   => $wynik['incydenty'],
                'mapa_wersja' => Parser::MAPA_WERSJA,
            ]);
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }

        return $wynik;
    }

    /**
     * Zbiorczy zapis wierszy Wazuh (issue #442): per paczka (500) jeden SELECT istniejących
     * (whereIn ip + dzien), INSERT nowych paczką, aktualizacje upsert
     * ON CONFLICT (ip, kategoria_wazuh, dzien) — tylko gdy liczba wzrosła
     * (okno eksportu przesuwa się: mniejsza liczba = obcięty początek dnia, nie spadek),
     * a incydenty jednym zapiszWiele zamiast pętli per wiersz.
     *
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param list<array<string, mixed>>           $nasze
     *
     * @return array{zmienione: int, incydenty: int}
     */
    private function zapiszWiersze($db, int $importId, array $nasze, int $min, int $high): array
    {
        $zmienione     = 0;
        $incydenty     = 0;
        $daneIncydenty = [];

        foreach (array_chunk($nasze, \App\Libraries\IncydentZapis::ROZMIAR_PACZKI) as $paczka) {
            // Dedup po kluczu złożonym — wygrywa wiersz z największą liczbą.
            $poKluczu = [];
            foreach ($paczka as $m) {
                $k = $m['ip'] . '|' . $m['kategoria_wazuh'] . '|' . $m['dzien'];
                if (! isset($poKluczu[$k]) || $m['liczba'] > $poKluczu[$k]['liczba']) {
                    $poKluczu[$k] = $m;
                }
            }
            $wiersze = array_values($poKluczu);

            // Jeden SELECT na paczkę: istniejące wiersze (klucz złożony ip+kategoria_wazuh+dzien).
            $istniejace = [];
            foreach ($db->table('mcs_wazuh_wiersze')
                ->select('id, ip, kategoria_wazuh, dzien, liczba')
                ->whereIn('ip', array_values(array_unique(array_column($wiersze, 'ip'))))
                ->whereIn('dzien', array_values(array_unique(array_column($wiersze, 'dzien'))))
                ->get()->getResultArray() as $w) {
                $istniejace[$w['ip'] . '|' . $w['kategoria_wazuh'] . '|' . $w['dzien']] = $w;
            }

            $doInsertu = [];
            $doUpdate  = [];
            foreach ($wiersze as $m) {
                $k     = $m['ip'] . '|' . $m['kategoria_wazuh'] . '|' . $m['dzien'];
                $stary = $istniejace[$k] ?? null;
                if ($stary === null) {
                    $doInsertu[] = [$m, null];
                } elseif ($m['liczba'] > (int) $stary['liczba']) {
                    $doUpdate[] = [$m, (int) $stary['id']];
                }
                // liczba <= istniejącej → bez zmian
            }

            $wierszDane = static fn (array $m): array => [
                'import_id'        => $importId,
                'zaobserwowano_at' => $m['ostatnie_at'],
                'liczba'           => $m['liczba'],
                'poziom_max'       => $m['poziom_max'],
                'pierwsze_at'      => $m['pierwsze_at'],
                'ostatnie_at'      => $m['ostatnie_at'],
                'hosty_docelowe'   => json_encode($m['hosty_docelowe'], JSON_UNESCAPED_UNICODE),
                'reguly'           => json_encode($m['reguly'], JSON_UNESCAPED_UNICODE),
                'payload_json'     => json_encode(['raw' => $m['raw']], JSON_UNESCAPED_UNICODE),
            ];

            // INSERT nowych wierszy paczką (id w kolejności VALUES)
            if ($doInsertu !== []) {
                $wierszeInsert = [];
                foreach ($doInsertu as [$m]) {
                    $wierszeInsert[] = $wierszDane($m) + [
                        'ip'              => $m['ip'],
                        'dzien'           => $m['dzien'],
                        'kategoria_wazuh' => $m['kategoria_wazuh'],
                        'klucz_dedupu'    => $m['klucz_dedupu'],
                    ];
                }
                $ids = \App\Libraries\ZapisBatch::insertZwrocId($db, 'mcs_wazuh_wiersze', $wierszeInsert);
                foreach ($doInsertu as $j => [$m]) {
                    $doInsertu[$j][1] = $ids[$j];
                }
            }
            // Aktualizacje upsert ON CONFLICT (klucz złożony) — jedna runda na paczkę
            if ($doUpdate !== []) {
                $wierszeUpdate = [];
                foreach ($doUpdate as [$m]) {
                    $wierszeUpdate[] = $wierszDane($m) + [
                        'ip'              => $m['ip'],
                        'dzien'           => $m['dzien'],
                        'kategoria_wazuh' => $m['kategoria_wazuh'],
                        'klucz_dedupu'    => $m['klucz_dedupu'],
                    ];
                }
                \App\Libraries\ZapisBatch::upsert(
                    $db,
                    'mcs_wazuh_wiersze',
                    $wierszeUpdate,
                    '(ip, kategoria_wazuh, dzien)',
                    [
                        'import_id = excluded.import_id',
                        'zaobserwowano_at = excluded.zaobserwowano_at',
                        'liczba = excluded.liczba',
                        'poziom_max = excluded.poziom_max',
                        'pierwsze_at = excluded.pierwsze_at',
                        'ostatnie_at = excluded.ostatnie_at',
                        'hosty_docelowe = excluded.hosty_docelowe',
                        'reguly = excluded.reguly',
                        'payload_json = excluded.payload_json',
                    ],
                );
            }

            foreach (array_merge($doInsertu, $doUpdate) as [$m, $id]) {
                $zmienione++;
                if ($m['liczba'] < $min) {
                    continue;
                }
                $wagaMeta = $this->waga->wylicz('abuse');
                if ($m['liczba'] < $high && $wagaMeta['waga'] === 'high') {
                    $wagaMeta['waga']         = 'medium';
                    $wagaMeta['uzasadnienie'] .= '; wazuh: liczba ' . $m['liczba'] . ' < progHigh ' . $high . ' → medium';
                }
                $incydenty++;
                $daneIncydenty[] = [
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $m['klucz_dedupu'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $m['ip'],
                    'zaobserwowano_at' => $m['ostatnie_at'],
                    'waga'             => $wagaMeta['waga'],
                    'wersja_wagi'      => $wagaMeta['wersja'],
                    'kategoria'        => 'abuse',
                    'tytul'            => $this->parser->tytul($m),
                    'port'             => null,
                    'protokol'         => null,
                    'import_id'        => $importId,
                    'id_w_zrodle'      => (string) $id,
                    'payload'          => [
                        'kategoria_wazuh' => $m['kategoria_wazuh'],
                        'dzien'           => $m['dzien'],
                        'liczba'          => $m['liczba'],
                        'poziom_max'      => $m['poziom_max'],
                        'hosty_docelowe'  => $m['hosty_docelowe'],
                        'reguly'          => array_slice($m['reguly'], 0, 10),
                        'mapa_wersja'     => Parser::MAPA_WERSJA,
                        'waga_meta'       => $wagaMeta,
                    ],
                ];
            }
        }

        if ($daneIncydenty !== []) {
            $this->zapis->zapiszWiele($daneIncydenty);
        }

        return ['zmienione' => $zmienione, 'incydenty' => $incydenty];
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
