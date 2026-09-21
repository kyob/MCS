<?php

namespace McsPluginy\shadowserver;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Shadowserver.
 *
 * Tryby pobierz():
 * 1) plik fixture — gdy ustawione mcs.shadowserver.fixturePath (dev / test)
 * 2) IMAP — gdy .env ma host/user/pass (wymaga php-imap)
 *
 * klucz_dedupu: ip|tag|port|protokol (stabilny, udokumentowany).
 */
class Plugin implements SourcePluginInterface
{
    private Parser $parser;
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private ?ImapKlientFabryka $imapFabryka;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?ImapKlientFabryka $imapFabryka = null,
    ) {
        $this->parser      = $parser ?? new Parser();
        $this->koperta     = $koperta ?? new ImportKoperta();
        $this->zapis       = $zapis ?? new IncydentZapis();
        $this->waga        = $waga ?? new WagaV1();
        $this->imapFabryka = $imapFabryka;
    }

    public function zrodlo(): string
    {
        return 'shadowserver';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.shadowserver.fixturePath', ''));
        if ($fixture !== '') {
            $liczba = $this->importujPlik($fixture, null);
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'brak nowych danych (duplikat checksum/id albo puste CSV)',
            );

            return;
        }

        $fabryka = $this->imapFabryka ?? new ImapKlientFabryka();
        try {
            $klient = $fabryka->dlaZrodla('shadowserver');
        } catch (RuntimeException $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }

        try {
            $stats = $this->importujZImap($klient);
            $this->aktualizujHealth(
                true,
                sprintf(
                    'import IMAP OK (wiadomosci=%d, nowe_importy=%d, wiersze=%d%s)',
                    $stats['wiadomosci'],
                    $stats['importy'],
                    $stats['wiersze'],
                    $stats['fallback_ostatnie'] ? ', tryb=ostatnie_SEEN' : '',
                ),
            );
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        } finally {
            $klient->zamknij();
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
        return PluginOpisPolaczenia::imap('mcs.shadowserver');
    }

    /**
     * Opis gotowości .env gdy jeszcze nie było przebiegu importu.
     */
    private function opisKonfiguracji(): string
    {
        if (trim((string) env('mcs.shadowserver.fixturePath', '')) !== '') {
            return 'konfiguracja: tryb fixturePath (jeszcze bez przebiegu)';
        }
        $host = trim((string) env('mcs.shadowserver.imapHost', ''));
        $user = trim((string) env('mcs.shadowserver.imapUser', ''));
        if ($host !== '' && $user !== '') {
            $limit = max(1, (int) env('mcs.shadowserver.imapRecentLimit', 40));

            return "konfiguracja: IMAP gotowy (imapRecentLimit={$limit}, jeszcze bez przebiegu)";
        }

        return 'brak fixturePath i IMAP (uzupelnij .env)';
    }

    /**
     * Import jednego CSV (fixture lub załącznik).
     */
    public function importujPlik(string $sciezka, ?string $idWiadomosci): int
    {
        if (! is_readable($sciezka)) {
            throw new RuntimeException('Shadowserver: nie można odczytać pliku ' . $sciezka);
        }

        $tresc    = (string) file_get_contents($sciezka);
        $checksum = hash('sha256', $tresc);

        return $this->importujTrescCsv($tresc, $checksum, $idWiadomosci, [
            'tryb'  => 'plik',
            'plik'  => basename($sciezka),
        ]);
    }

    /**
     * @return array{wiadomosci: int, importy: int, wiersze: int, fallback_ostatnie: bool}
     */
    private function importujZImap(ImapKlientInterface $klient): array
    {
        $fallback = false;
        $uidy     = $klient->listujUid(true);
        if ($uidy === []) {
            // Skrzynka produkcyjna Shadowserver bywa cala SEEN; bierzemy ostatnie UID
            // i polegamy na checksum/id_wiadomosci w kopercie (bez podwojnego importu).
            $limit = max(1, (int) env('mcs.shadowserver.imapRecentLimit', 40));
            $wszystkie = $klient->listujUid(false);
            rsort($wszystkie, SORT_NUMERIC);
            $uidy     = array_slice($wszystkie, 0, $limit);
            $fallback = true;
        }

        $sumaWierszy  = 0;
        $sumaImportow = 0;

        foreach ($uidy as $uid) {
            $wiadomosc     = $klient->pobierzWiadomosc($uid);
            $zaimportowano = false;
            foreach ($wiadomosc['zalaczniki'] as $zal) {
                $csv = $this->wyciagnijCsvZZalacznika($zal);
                if ($csv === null) {
                    continue;
                }
                $checksum = hash('sha256', $csv['tresc']);
                $liczba   = $this->importujTrescCsv(
                    $csv['tresc'],
                    $checksum,
                    $wiadomosc['message_id'],
                    [
                        'tryb'     => 'imap',
                        'uid'      => $uid,
                        'filename' => $csv['filename'],
                        'zip'      => $csv['z_zip'] ? $zal['filename'] : null,
                        'subject'  => $wiadomosc['subject'],
                    ],
                );
                if ($liczba > 0) {
                    $sumaWierszy += $liczba;
                    $sumaImportow++;
                }
                $zaimportowano = true;
            }
            if ($zaimportowano) {
                $klient->oznaczPrzeczytane($uid);
            }
        }

        return [
            'wiadomosci'         => count($uidy),
            'importy'            => $sumaImportow,
            'wiersze'            => $sumaWierszy,
            'fallback_ostatnie'  => $fallback,
        ];
    }

    /**
     * Akceptuje .csv albo .zip/.csv.zip z jednym (lub pierwszym) plikiem CSV w srodku.
     *
     * @param array{filename: string, mime: string, tresc: string} $zal
     *
     * @return array{filename: string, tresc: string, z_zip: bool}|null
     */
    private function wyciagnijCsvZZalacznika(array $zal): ?array
    {
        $nazwa = strtolower($zal['filename']);
        if (str_ends_with($nazwa, '.csv') && ! str_ends_with($nazwa, '.csv.zip')) {
            return [
                'filename' => $zal['filename'],
                'tresc'    => $zal['tresc'],
                'z_zip'    => false,
            ];
        }

        if (! str_ends_with($nazwa, '.zip')) {
            return null;
        }

        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Shadowserver: brak ZipArchive — apt install php-zip');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mcs_ss_');
        if ($tmp === false) {
            throw new RuntimeException('Shadowserver: nie mozna utworzyc pliku tymczasowego na ZIP');
        }

        try {
            file_put_contents($tmp, $zal['tresc']);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }

            $csvNazwa = null;
            $csvTresc = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if ($entry === false) {
                    continue;
                }
                if (str_ends_with(strtolower($entry), '.csv') && ! str_contains($entry, '..')) {
                    $body = $zip->getFromIndex($i);
                    if ($body !== false) {
                        $csvNazwa = basename($entry);
                        $csvTresc = $body;
                        break;
                    }
                }
            }
            $zip->close();

            if ($csvNazwa === null || $csvTresc === null) {
                return null;
            }

            return [
                'filename' => $csvNazwa,
                'tresc'    => $csvTresc,
                'z_zip'    => true,
            ];
        } finally {
            @unlink($tmp);
        }
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
                $this->koperta->zakonczKwarantanna($importId, 'CSV bez poprawnych wierszy IP');

                return 0;
            }

            $licznik = 0;
            foreach ($wiersze as $w) {
                $kategoria = $this->parser->kategoriaDlaWiersza($w);
                $sygnaly = [];
                if (! empty($w['severity'])) {
                    $sygnaly['severity'] = $w['severity'];
                }
                if (! empty($w['tag'])) {
                    $sygnaly['tag'] = $w['tag'];
                }
                $wynikWagi = $this->waga->wylicz($kategoria, $sygnaly);

                $db->table('mcs_shadowserver_wiersze')->insert([
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $this->normalizujCzas($w['timestamp']),
                    'ip'               => $w['ip'],
                    'port'             => $w['port'],
                    'protokol'         => $w['protokol'],
                    'tag'              => $w['tag'],
                    'severity'         => $w['severity'],
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'payload_json'     => json_encode($w['raw'], JSON_UNESCAPED_UNICODE),
                ]);
                $idWZrodle = (string) $db->insertID();

                $tytul = 'Shadowserver';
                if (! empty($w['tag'])) {
                    $tytul .= ' — ' . $w['tag'];
                }

                $this->zapis->zapisz([
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $w['ip'],
                    'zaobserwowano_at' => $this->normalizujCzas($w['timestamp']),
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => $kategoria,
                    'tytul'            => $tytul,
                    'port'             => $w['port'],
                    'protokol'         => $w['protokol'],
                    'import_id'        => $importId,
                    'id_w_zrodle'      => $idWZrodle,
                    'payload'          => [
                        'tag'       => $w['tag'],
                        'severity'  => $w['severity'],
                        'waga_meta' => $wynikWagi,
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
