<?php

namespace McsPluginy\spoofer;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła CAIDA Spoofer — pomiary BCP38 (czy sieć przepuszcza spoofing).
 *
 * Publiczne API, bez klucza. Import wyłącznie sesji monitorowanego ASN
 * (domyślnie AS198050). Prefiksy /24 i /40 zapisywane jako IP sieci (dashboard),
 * pełny prefiks w tytule/payload (nie badge hosta).
 *
 * Tryby pobierz():
 * 1) fixture JSON — mcs.spoofer.fixturePath
 * 2) REST GET /sessions?asn=…
 *
 * @see https://www.caida.org/projects/spoofer/data-api/
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
        return 'spoofer';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.spoofer.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste sessions)',
            );

            return;
        }

        $client = $this->apiClient ?? ApiClient::fromEnv();

        try {
            $stats = $this->importujZApi($client);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import API OK (asn=%d, api=%d, zapisano=%d, strony=%d, mapa=%s)',
                    $stats['asn'],
                    $stats['api_count'],
                    $stats['zapisano'],
                    $stats['pages'],
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
        $plik  = trim((string) env('mcs.spoofer.fixturePath', ''));
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $asn    = (string) $this->resolveAsn();

        return PluginOpisPolaczenia::plikIKanal(
            $plik,
            'HTTP CAIDA Spoofer',
            $client->getBaseUrl(),
            true,
            'http',
            'HTTP',
            [
                PluginOpisPolaczenia::pozycja('ASN', $asn, false, $asn !== ''),
            ],
        );
    }

    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Spoofer: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc    = (string) file_get_contents($sciezka);
        $sessions = $this->parser->parsujJson($tresc);
        $asn      = $this->resolveAsn();

        return $this->importujSesje(
            $sessions,
            $asn,
            hash('sha256', $tresc),
            'fixture:' . basename($sciezka),
            ['tryb' => 'fixture', 'plik' => basename($sciezka), 'asn' => $asn],
        );
    }

    /**
     * @return array{asn: int, api_count: int, zapisano: int, pages: int}
     */
    public function importujZApi(?ApiClient $client = null): array
    {
        $client = $client ?? $this->apiClient ?? ApiClient::fromEnv();
        $asn    = $this->resolveAsn();
        $after  = $this->timestampAfter();

        $res = $client->fetchSessions($asn, $after);
        if (! $res['ok']) {
            throw new RuntimeException(
                'Spoofer API: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code']),
            );
        }

        $sessions = $res['sessions'];
        if ($sessions === []) {
            return [
                'asn'       => $asn,
                'api_count' => 0,
                'zapisano'  => 0,
                'pages'     => $res['pages'],
            ];
        }

        $checksumMaterial = $res['raw_bodies'] !== []
            ? json_encode($res['raw_bodies'], JSON_UNESCAPED_UNICODE)
            : json_encode($sessions, JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $checksumMaterial !== false ? $checksumMaterial : '');
        $idWiad   = 'spoofer:asn' . $asn . ':' . substr($checksum, 0, 12);

        $zapisano = $this->importujSesje(
            $sessions,
            $asn,
            $checksum,
            $idWiad,
            [
                'tryb'      => 'api',
                'asn'       => $asn,
                'api_count' => count($sessions),
                'pages'     => $res['pages'],
            ],
        );

        return [
            'asn'       => $asn,
            'api_count' => count($sessions),
            'zapisano'  => $zapisano,
            'pages'     => $res['pages'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @param array<string, mixed>       $meta
     */
    private function importujSesje(
        array $sessions,
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
            if ($sessions === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Spoofer: pusta lista sessions');

                return 0;
            }

            $mapped  = $this->parser->mapujListe($sessions, $asn);
            $licznik = 0;
            foreach ($mapped as $wpis) {
                $licznik += $this->zapiszWpis($db, $importId, $wpis);
            }

            if ($licznik === 0) {
                $this->koperta->zakonczKwarantanna(
                    $importId,
                    'Spoofer: brak wierszy dla ASN ' . $asn,
                );

                return 0;
            }

            $this->koperta->zakonczOk($importId, [
                'wierszy'     => $licznik,
                'mapa_wersja' => Parser::MAPA_WERSJA,
                'api_count'   => count($sessions),
                'asn'         => $asn,
            ]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param \CodeIgniter\Database\BaseConnection $db
     * @param array<string, mixed>                 $mapped
     */
    private function zapiszWpis($db, int $importId, array $mapped): int
    {
        $sygnaly = [
            'tag' => $mapped['tag'],
        ];
        if ($mapped['severity_sygnal'] !== null) {
            $sygnaly['severity'] = $mapped['severity_sygnal'];
        }
        $wynikWagi = $this->waga->wylicz($mapped['kategoria'], $sygnaly);

        $istniejacy = $db->table('mcs_spoofer_wiersze')
            ->where('klucz_dedupu', $mapped['klucz_dedupu'])
            ->get()
            ->getRowArray();

        $payloadJson = json_encode(['raw' => $mapped['raw']], JSON_UNESCAPED_UNICODE);

        if (is_array($istniejacy) && isset($istniejacy['id'])) {
            $idWZrodle = (string) $istniejacy['id'];
            $db->table('mcs_spoofer_wiersze')
                ->where('id', $istniejacy['id'])
                ->update([
                    'routedspoof'  => $mapped['routedspoof'],
                    'privatespoof' => $mapped['privatespoof'],
                    'payload_json' => $payloadJson,
                ]);
        } else {
            $db->table('mcs_spoofer_wiersze')->insert([
                'import_id'        => $importId,
                'zaobserwowano_at' => $mapped['zaobserwowano_at'],
                'session_id'       => $mapped['session_id'],
                'ip_wersja'        => $mapped['ip_wersja'],
                'prefix'           => $mapped['prefix'],
                'asn'              => $mapped['asn'],
                'routedspoof'      => $mapped['routedspoof'],
                'privatespoof'     => $mapped['privatespoof'],
                'country'          => $mapped['country'],
                'klucz_dedupu'     => $mapped['klucz_dedupu'],
                'payload_json'     => $payloadJson,
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
                'session_id'   => $mapped['session_id'],
                'ip_wersja'    => $mapped['ip_wersja'],
                'prefix'       => $mapped['prefix'],
                'asn'          => $mapped['asn'],
                'routedspoof'  => $mapped['routedspoof'],
                'privatespoof' => $mapped['privatespoof'],
                'country'      => $mapped['country'],
                'mapa_wersja'  => Parser::MAPA_WERSJA,
                'waga_meta'    => $wynikWagi,
            ],
        ]);

        return 1;
    }

    public function resolveAsn(): int
    {
        $explicit = \App\Libraries\OpcjeMcs::tekst('mcs.spoofer.asn');
        if ($explicit !== '' && ctype_digit($explicit)) {
            return (int) $explicit;
        }

        return \App\Libraries\Siec\As198050Matcher::asn();
    }

    private function timestampAfter(): ?string
    {
        $days = \App\Libraries\OpcjeMcs::tekst('mcs.spoofer.lookbackDays');
        if ($days === '' || ! ctype_digit($days) || (int) $days < 1) {
            return null;
        }

        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $dt->modify('-' . (int) $days . ' days')->format(\DateTimeInterface::ATOM);
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.spoofer.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }

        return 'konfiguracja: publiczne API CAIDA Spoofer (jeszcze bez przebiegu); asn='
            . $this->resolveAsn();
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
