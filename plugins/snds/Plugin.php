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
 * 1) plik fixture — mcs.snds.fixturePath (dev / test)
 * 2) HTTP DataKey — jak LMS: klucz UUID + Date=MMDDYY, pętla lookback dni
 *
 * URL domyślny: substrate.office.com …/DataKey?Key=%s&Date=%s
 * Legacy: sendersupport.olc.protection.outlook.com (mcs.snds.useLegacyUrl=1)
 *
 * klucz_dedupu: ip|filtr|data (patrz Parser). Koperta per dzień: id_wiadomosci=snds:YYYY-MM-DD.
 */
class Plugin implements SourcePluginInterface
{
    public const URL_SUBSTRATE = 'https://substrate.office.com/ip-domain-management-snds/SNDS/DataKey?Key=%s&Date=%s';

    public const URL_LEGACY = 'https://sendersupport.olc.protection.outlook.com/snds/data/?key=%s&date=%s';

    public const AUTO_PORTAL_URL = 'https://substrate.office.com/ip-domain-management-snds/SNDS/Auto';

    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV0 $waga;

    /** @var callable(string): array{code:int,body:string}|null */
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

        $key = $this->kluczApi();
        if ($key === '') {
            $msg = 'SNDS: ustaw mcs.snds.key (UUID Auto Data Access) albo mcs.snds.fixturePath w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $lookback = max(1, (int) env('mcs.snds.lookbackDays', 3));
        $daty     = $this->datyLookback($lookback);
        $okDni    = 0;
        $suma     = 0;
        $bledy    = [];

        foreach ($daty as $mmddyy) {
            try {
                $wynik = $this->pobierzDzien($key, $mmddyy);
                $okDni++;
                $suma += $wynik['wierszy'];
            } catch (\Throwable $e) {
                $bledy[] = $mmddyy . ': ' . $e->getMessage();
                // Jak LMS cron: 404 / brak dnia nie przerywa pozostalych dat.
            }
        }

        if ($okDni === 0) {
            $msg = 'SNDS: import nieudany dla wszystkich ' . count($daty) . ' dni. '
                . implode('; ', array_slice($bledy, 0, 3));
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $msg = sprintf(
            'import DataKey OK (%d/%d dni, %d wierszy)%s',
            $okDni,
            count($daty),
            $suma,
            $bledy !== [] ? '; pominięte: ' . implode('; ', array_slice($bledy, 0, 2)) : '',
        );
        $this->aktualizujHealth(true, mb_substr($msg, 0, 500));
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

        return $this->importujTrescCsv($tresc, hash('sha256', $tresc), null, [
            'tryb' => 'plik',
            'plik' => basename($sciezka),
        ]);
    }

    /**
     * Buduje URL DataKey: sprintf(template, key, dateMmddyy).
     * Pusty template → substrate albo legacy wg mcs.snds.useLegacyUrl.
     */
    public function zbudujDataUrl(string $key, string $dateMmddyy, ?string $template = null, ?bool $legacy = null): string
    {
        if (! preg_match('/^\d{6}$/', $dateMmddyy)) {
            throw new RuntimeException('SNDS: data musi byc MMDDYY, dostano: ' . $dateMmddyy);
        }

        if ($template === null) {
            $template = trim((string) env('mcs.snds.dataUrlTemplate', ''));
        }
        if ($template === '') {
            if ($legacy === null) {
                $legacy = $this->uzyjLegacyUrl();
            }
            $template = $legacy ? self::URL_LEGACY : self::URL_SUBSTRATE;
        }

        return sprintf($template, rawurlencode($key), rawurlencode($dateMmddyy));
    }

    /**
     * @deprecated Używaj zbudujDataUrl(). Zostawione pod stare testy / wywołania.
     */
    public function zbudujUrl(string $dataUrl, string $apiKey): string
    {
        // Kompatybilność wsteczna: jeśli dataUrl wygląda na sprintf z %s — traktuj jako template.
        if (substr_count($dataUrl, '%s') >= 2 && $apiKey !== '') {
            return $this->zbudujDataUrl($apiKey, date('mdy', strtotime('-1 day')), $dataUrl);
        }
        if ($apiKey === '') {
            return $dataUrl;
        }
        if (preg_match('/[?&](?:key|Key)=/i', $dataUrl) === 1) {
            return $dataUrl;
        }
        $sep = str_contains($dataUrl, '?') ? '&' : '?';

        return $dataUrl . $sep . 'key=' . rawurlencode($apiKey);
    }

    /**
     * Daty MMDDYY: wczoraj … lookback (jak LMS bez -t).
     *
     * @return list<string>
     */
    public function datyLookback(int $lookbackDays, ?\DateTimeImmutable $teraz = null): array
    {
        $teraz = $teraz ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out   = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $out[] = $teraz->modify('-' . $i . ' day')->format('mdy');
        }

        return $out;
    }

    /**
     * @return array{wierszy: int, puste: bool}
     */
    public function pobierzDzien(string $key, string $mmddyy): array
    {
        $url    = $this->zbudujDataUrl($key, $mmddyy);
        $odpow  = $this->pobierzHttp($url);
        $code   = $odpow['code'];
        $body   = $odpow['body'];
        $safeUrl = $this->urlBezSekretow($url);

        if ($this->cialoWygladaNaHtml($body)) {
            $snippet = mb_substr($this->pierwszaNiepustaLinia($body), 0, 120);
            throw new RuntimeException(
                'SNDS odpowiedz HTML zamiast CSV (HTTP ' . $code . '). '
                . 'Sprawdz klucz Auto: ' . self::AUTO_PORTAL_URL . '. Snippet: ' . $snippet,
            );
        }

        if ($code === 400) {
            throw new RuntimeException(
                'SNDS HTTP 400: zly Key lub Date (' . $mmddyy . '). Portal: ' . self::AUTO_PORTAL_URL,
            );
        }

        if ($code === 404) {
            throw new RuntimeException(
                'SNDS HTTP 404 date=' . $mmddyy . ' (brak danych / lag / wygasly klucz ~30 dni). '
                . self::AUTO_PORTAL_URL,
            );
        }

        if ($code < 200 || $code >= 300) {
            $snippet = mb_substr($this->pierwszaNiepustaLinia($body), 0, 120);
            throw new RuntimeException('SNDS HTTP ' . $code . ' z ' . $safeUrl . '. Snippet: ' . $snippet);
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return ['wierszy' => 0, 'puste' => true];
        }

        $first = $this->pierwszaNiepustaLinia($body);
        if (! $this->parser->liniaWygladaNaWierszDataKey($first)) {
            throw new RuntimeException(
                'SNDS HTTP 200 ale body nie wyglada na CSV (pierwsze pole = IP). firstLine='
                . mb_substr($first, 0, 120),
            );
        }

        $iso = $this->mmddyyNaIso($mmddyy);
        $liczba = $this->importujTrescCsv($body, hash('sha256', $body), 'snds:' . $iso, [
            'tryb'    => 'datakey',
            'date'    => $mmddyy,
            'date_iso'=> $iso,
            'url'     => $safeUrl,
        ]);

        return ['wierszy' => $liczba, 'puste' => false];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function importujTrescCsv(string $tresc, string $checksum, ?string $idWiadomosci, array $meta): int
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
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

    /**
     * @return array{code: int, body: string}
     */
    private function pobierzHttp(string $url): array
    {
        if ($this->httpPobierz !== null) {
            $wynik = ($this->httpPobierz)($url);
            if (! is_array($wynik) || ! isset($wynik['code'], $wynik['body'])) {
                throw new RuntimeException('SNDS: HTTP (mock) zwrocil nieprawidlowy wynik');
            }

            return [
                'code' => (int) $wynik['code'],
                'body' => (string) $wynik['body'],
            ];
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

        return [
            'code' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }

    private function kluczApi(): string
    {
        $key = trim((string) env('mcs.snds.key', ''));
        if ($key !== '') {
            return $key;
        }
        // Kompatybilnosc ze starym .env
        return trim((string) env('mcs.snds.apiKey', ''));
    }

    private function uzyjLegacyUrl(): bool
    {
        $v = env('mcs.snds.useLegacyUrl', '0');
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    private function urlBezSekretow(string $url): string
    {
        $url = (string) preg_replace('/([?&](?:key|Key)=)[^&]*/i', '$1***', $url);

        return $url;
    }

    private function cialoWygladaNaHtml(string $body): bool
    {
        $trim = ltrim($body);
        if ($trim === '') {
            return false;
        }

        return stripos($trim, '<!DOCTYPE') === 0
            || stripos($trim, '<html') === 0;
    }

    private function pierwszaNiepustaLinia(string $body): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if (trim($line) !== '') {
                return $line;
            }
        }

        return '';
    }

    private function mmddyyNaIso(string $mmddyy): string
    {
        $dt = \DateTimeImmutable::createFromFormat('mdy', $mmddyy, new \DateTimeZone('UTC'));
        if (! $dt instanceof \DateTimeImmutable) {
            return '19' . substr($mmddyy, 4, 2) . '-' . substr($mmddyy, 0, 2) . '-' . substr($mmddyy, 2, 2);
        }

        return $dt->format('Y-m-d');
    }

    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.snds.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        if ($this->kluczApi() !== '') {
            $lookback = max(1, (int) env('mcs.snds.lookbackDays', 3));

            return "konfiguracja: DataKey key gotowy (lookbackDays={$lookback}, jeszcze bez przebiegu)";
        }

        return 'brak mcs.snds.key i fixturePath (uzupelnij .env)';
    }

    private function normalizujCzas(string $timestamp): string
    {
        $dt = $this->parser->parsujCzas($timestamp);
        if ($dt !== null) {
            return $dt->format('Y-m-d H:i:sP');
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
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
