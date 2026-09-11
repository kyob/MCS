<?php

namespace McsPluginy\snds;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV0;
use App\Models\PluginModel;
use Config\Database;
use Config\Services;
use RuntimeException;

/**
 * Plugin źródła SNDS (Microsoft Smart Network Data Services).
 *
 * Tryby pobierz():
 * 1) plik fixture — gdy ustawione mcs.snds.fixturePath (dev / test)
 * 2) HTTP GET — mcs.snds.dataUrl (+ opcjonalnie mcs.snds.apiKey jako ?key=)
 *
 * Bez IMAP. klucz_dedupu: ip|filtr|data (patrz Parser).
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV0 $waga;

    /** @var callable(string): string|null */
    private $httpPobierz;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV0 $waga = null,
        ?callable $httpPobierz = null,
    ) {
        $this->parser      = $parser ?? new Parser();
        $this->koperta     = $koperta ?? new ImportKoperta();
        $this->zapis       = $zapis ?? new IncydentZapis();
        $this->waga        = $waga ?? new WagaV0();
        $this->httpPobierz = $httpPobierz;
    }

    public function zrodlo(): string
    {
        return 'snds';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.snds.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum albo puste CSV)',
            );

            return;
        }

        $url = trim((string) env('mcs.snds.dataUrl', ''));
        $key = trim((string) env('mcs.snds.apiKey', ''));

        if ($url === '') {
            $msg = 'SNDS: ustaw mcs.snds.fixturePath (smoke) albo mcs.snds.dataUrl'
                . ' (+ opcjonalnie mcs.snds.apiKey) w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        try {
            $docelowy = $this->zbudujUrl($url, $key);
            $tresc    = $this->pobierzHttp($docelowy);
            $liczba   = $this->importujTrescCsv($tresc, hash('sha256', $tresc), [
                'tryb' => 'http',
                'url'  => $this->urlBezSekretow($docelowy),
            ]);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import HTTP OK ({$liczba} wierszy)"
                    : 'HTTP OK — brak nowych wierszy (duplikat / puste CSV)',
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
     * Import jednego CSV (fixture).
     */
    public function importujPlik(string $sciezka): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('SNDS: nie mozna odczytac pliku ' . $sciezka);
        }

        $tresc = (string) file_get_contents($sciezka);

        return $this->importujTrescCsv($tresc, hash('sha256', $tresc), [
            'tryb' => 'plik',
            'plik' => basename($sciezka),
        ]);
    }

    /**
     * Buduje URL: dokleja ?key= / &key= gdy apiKey ustawione i brak key= w URL.
     */
    public function zbudujUrl(string $dataUrl, string $apiKey): string
    {
        if ($apiKey === '') {
            return $dataUrl;
        }
        if (preg_match('/[?&]key=/i', $dataUrl) === 1) {
            return $dataUrl;
        }
        $sep = str_contains($dataUrl, '?') ? '&' : '?';

        return $dataUrl . $sep . 'key=' . rawurlencode($apiKey);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function importujTrescCsv(string $tresc, string $checksum, array $meta): int
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, null, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            $wiersze = $this->parser->parsujCsv($tresc);
            if ($wiersze === []) {
                $this->koperta->zakonczKwarantanna($importId, 'CSV SNDS bez poprawnych wierszy IP');

                return 0;
            }

            $licznik = 0;
            foreach ($wiersze as $w) {
                $kategoria = $this->parser->kategoriaDlaWiersza($w);
                $sygnaly   = [];
                if (! empty($w['filtr'])) {
                    $sygnaly['filtr'] = $w['filtr'];
                }
                $wynikWagi = $this->waga->wylicz($kategoria, $sygnaly);
                $czas      = $this->normalizujCzas($w['timestamp']);

                $db->table('mcs_snds_wiersze')->insert([
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $czas,
                    'ip'               => $w['ip'],
                    'filtr'            => $w['filtr'],
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'payload_json'     => json_encode($w['raw'], JSON_UNESCAPED_UNICODE),
                ]);
                $idWZrodle = (string) $db->insertID();

                $tytul = 'SNDS';
                if (! empty($w['filtr'])) {
                    $tytul .= ' — filtr ' . $w['filtr'];
                }

                $this->zapis->zapisz([
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $w['ip'],
                    'zaobserwowano_at' => $czas,
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => $kategoria,
                    'tytul'            => $tytul,
                    'import_id'        => $importId,
                    'id_w_zrodle'      => $idWZrodle,
                    'payload'          => [
                        'filtr'          => $w['filtr'],
                        'complaint_rate' => $w['complaint_rate'],
                        'trap_hits'      => $w['trap_hits'],
                        'waga_meta'      => $wynikWagi,
                    ],
                ]);
                $licznik++;
            }

            $this->koperta->zakonczOk($importId, ['wierszy' => $licznik]);

            return $licznik;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            throw $e;
        }
    }

    private function pobierzHttp(string $url): string
    {
        if ($this->httpPobierz !== null) {
            $tresc = ($this->httpPobierz)($url);
            if (! is_string($tresc) || $tresc === '') {
                throw new RuntimeException('SNDS: HTTP (mock) zwrocil pusta odpowiedz');
            }

            return $tresc;
        }

        $client   = Services::curlrequest();
        $response = $client->get($url, [
            'http_errors'     => false,
            'timeout'         => 60,
            'connect_timeout' => 15,
            'headers'         => [
                'Accept'     => 'text/csv,text/plain,*/*',
                'User-Agent' => 'MCS-SNDS/1.0',
            ],
        ]);
        $kod = $response->getStatusCode();
        if ($kod < 200 || $kod >= 300) {
            throw new RuntimeException(
                'SNDS: HTTP ' . $kod . ' z ' . $this->urlBezSekretow($url),
            );
        }
        $tresc = (string) $response->getBody();
        if ($tresc === '') {
            throw new RuntimeException('SNDS: pusta odpowiedz HTTP z ' . $this->urlBezSekretow($url));
        }

        return $tresc;
    }

    private function urlBezSekretow(string $url): string
    {
        return (string) preg_replace('/([?&]key=)[^&]*/i', '$1***', $url);
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.snds.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        if (trim((string) env('mcs.snds.dataUrl', '')) !== '') {
            return 'konfiguracja: HTTP dataUrl gotowy (jeszcze bez przebiegu)';
        }

        return 'brak fixturePath i dataUrl (uzupelnij .env)';
    }

    private function normalizujCzas(string $timestamp): string
    {
        try {
            $dt = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        }
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
