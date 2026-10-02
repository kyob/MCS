<?php

namespace McsPluginy\wazuh;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Wazuh — IP z AS198050, które spamują / atakują nasze serwery (SMTP, SSH, WWW).
 * Plan: docs/11-plan-zrodlo-wazuh.md
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
    private ?As198050Matcher $as;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ApiClient $apiClient = null,
        ?As198050Matcher $as = null,
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
                'import OK (eksport=%d, AS198050=%d, nowe/zmienione=%d, incydenty=%d%s)',
                $s['eksport'], $s['as'], $s['zmienione'], $s['incydenty'], $s['duplikat'] ? ', bez zmian' : '',
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
        $eksport = $this->parser->parsujEksport($json);
        $as      = $this->as ?? new As198050Matcher();

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
            foreach ($nasze as $m) {
                $r = $this->zapiszWiersz($db, $importId, $m, $min, $high);
                $wynik['zmienione'] += $r['zmieniony'] ? 1 : 0;
                $wynik['incydenty'] += $r['incydent'] ? 1 : 0;
            }
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
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $m
     *
     * @return array{zmieniony: bool, incydent: bool}
     */
    private function zapiszWiersz($db, int $importId, array $m, int $min, int $high): array
    {
        $tab = $db->table('mcs_wazuh_wiersze');
        $ist = $tab->where('ip', $m['ip'])->where('kategoria_wazuh', $m['kategoria_wazuh'])->where('dzien', $m['dzien'])
            ->get()->getRowArray();

        $dane = [
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

        if (is_array($ist)) {
            // okno eksportu przesuwa się — mniejsza liczba = obcięty początek dnia, nie spadek
            if ($m['liczba'] <= (int) $ist['liczba']) {
                return ['zmieniony' => false, 'incydent' => false];
            }
            $db->table('mcs_wazuh_wiersze')->where('id', $ist['id'])->update($dane);
            $id = (string) $ist['id'];
        } else {
            $db->table('mcs_wazuh_wiersze')->insert($dane + [
                'ip'              => $m['ip'],
                'dzien'           => $m['dzien'],
                'kategoria_wazuh' => $m['kategoria_wazuh'],
                'klucz_dedupu'    => $m['klucz_dedupu'],
            ]);
            $id = (string) $db->insertID();
        }

        if ($m['liczba'] < $min) {
            return ['zmieniony' => true, 'incydent' => false];
        }

        $wagaMeta = $this->waga->wylicz('abuse');
        if ($m['liczba'] < $high && $wagaMeta['waga'] === 'high') {
            $wagaMeta['waga']         = 'medium';
            $wagaMeta['uzasadnienie'] .= '; wazuh: liczba ' . $m['liczba'] . ' < progHigh ' . $high . ' → medium';
        }

        $this->zapis->zapisz([
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
            'id_w_zrodle'      => $id,
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
        ]);

        return ['zmieniony' => true, 'incydent' => true];
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
