<?php

namespace McsPluginy\shadowserver;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV0;
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
    private WagaV0 $waga;
    private ?ImapKlientFabryka $imapFabryka;

    public function __construct(
        ?Parser $parser = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV0 $waga = null,
        ?ImapKlientFabryka $imapFabryka = null,
    ) {
        $this->parser      = $parser ?? new Parser();
        $this->koperta     = $koperta ?? new ImportKoperta();
        $this->zapis       = $zapis ?? new IncydentZapis();
        $this->waga        = $waga ?? new WagaV0();
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
            $this->importujZImap($klient);
            $this->aktualizujHealth(true, 'import IMAP OK');
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

        return [
            'ok'  => (bool) ($wiersz['health_ok'] ?? false),
            'msg' => (string) ($wiersz['health_msg'] ?? ''),
        ];
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

    private function importujZImap(ImapKlientInterface $klient): void
    {
        $uidy = $klient->listujUid(true);
        if ($uidy === []) {
            return;
        }

        foreach ($uidy as $uid) {
            $wiadomosc = $klient->pobierzWiadomosc($uid);
            $zaimportowano = false;
            foreach ($wiadomosc['zalaczniki'] as $zal) {
                $nazwa = strtolower($zal['filename']);
                if (! str_ends_with($nazwa, '.csv')) {
                    continue;
                }
                $checksum = hash('sha256', $zal['tresc']);
                $this->importujTrescCsv(
                    $zal['tresc'],
                    $checksum,
                    $wiadomosc['message_id'],
                    [
                        'tryb'     => 'imap',
                        'uid'      => $uid,
                        'filename' => $zal['filename'],
                        'subject'  => $wiadomosc['subject'],
                    ],
                );
                $zaimportowano = true;
            }
            if ($zaimportowano) {
                $klient->oznaczPrzeczytane($uid);
            }
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
                $sygnaly   = [];
                if (! empty($w['severity'])) {
                    $sygnaly['severity'] = $w['severity'];
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
