<?php

namespace McsPluginy\play;

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
 * Plugin źródła Play (Ochrona Anty-DDoS) — powiadomienia DDoS w treści maila
 * (antyddos@play.pl) przez IMAP. Usługa Play chroni przed atakami wolumetrycznymi;
 * MCS importuje alerty Event/Mitigation/CIDR, nie sam tor ochrony sieciowej.
 *
 * Uwaga operacyjna: FreeScout na tej samej skrzynce potrafi ściągnąć i usunąć
 * maile z IMAP; MCS widzi wtedy tylko resztki (np. Witamy/test).
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
        return 'play';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.play.fixturePath', ''));
        if ($fixture !== '') {
            if (! is_readable($fixture)) {
                throw new RuntimeException('Play: nie mozna odczytac fixturePath=' . $fixture);
            }
            $tresc   = (string) file_get_contents($fixture);
            $subject = 'DDoS notification - fixture';
            if (preg_match('/^Subject:\s*(.+)$/mi', $tresc, $m) === 1) {
                $subject = trim($m[1]);
                $tresc   = (string) preg_replace('/^Subject:\s*.+$/mi', '', $tresc, 1);
            }
            $liczba = $this->importujPowiadomienie(
                $subject,
                $tresc,
                null,
                hash('sha256', $subject . "\n" . $tresc),
                'fixture:' . basename($fixture),
                ['tryb' => 'fixture', 'plik' => basename($fixture)],
            );
            $this->aktualizujHealth(
                true,
                $liczba > 0
                    ? "import fixture OK ({$liczba} wierszy)"
                    : 'fixture bez wierszy DDoS / duplikat',
            );

            return;
        }

        $fabryka = $this->imapFabryka ?? new ImapKlientFabryka();
        try {
            $klient = $fabryka->dlaZrodla('play');
        } catch (RuntimeException $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }

        try {
            $stats = $this->importujZImap($klient);
            $msg   = sprintf(
                'import IMAP OK (wiadomosci=%d, ddos=%d, nowe_importy=%d, wiersze=%d%s)',
                $stats['wiadomosci'],
                $stats['ddos'],
                $stats['importy'],
                $stats['wiersze'],
                $stats['fallback_ostatnie'] ? ', tryb=ostatnie_SEEN' : '',
            );
            // IMAP działa, ale brak DDoS w skrzynce = tor gotowy (czekamy na alert).
            // Historyczne maile mogły zniknąć po FreeScout; to nie jest awaria credentials.
            if ($stats['ddos'] === 0) {
                $msg = $stats['wiadomosci'] === 0
                    ? 'Play IMAP OK — skrzynka pusta; tor gotowy, czekam na powiadomienia DDoS (' . Parser::nadawca() . ').'
                    : sprintf(
                        'Play IMAP OK — %d wiadomości, 0× DDoS od %s; tor gotowy, czekam na kolejne alerty.',
                        $stats['wiadomosci'],
                        Parser::nadawca(),
                    );
                $this->aktualizujHealth(true, $msg);

                return;
            }
            $this->aktualizujHealth(true, $msg);
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        } finally {
            $klient->zamknij();
        }
    }

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
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        return PluginOpisPolaczenia::imap('mcs.play');
    }

    /**
     * @return array{wiadomosci: int, ddos: int, importy: int, wiersze: int, fallback_ostatnie: bool}
     */
    private function importujZImap(ImapKlientInterface $klient): array
    {
        $fallback = false;
        $uidy     = $klient->listujUid(true);
        if ($uidy === []) {
            $limit     = max(1, \App\Libraries\OpcjeMcs::int('mcs.play.imapRecentLimit'));
            $wszystkie = $klient->listujUid(false);
            rsort($wszystkie, SORT_NUMERIC);
            $uidy     = array_slice($wszystkie, 0, $limit);
            $fallback = true;
        }

        $sumaWierszy  = 0;
        $sumaImportow = 0;
        $sumaDdos     = 0;

        foreach ($uidy as $uid) {
            $wiadomosc = $klient->pobierzWiadomosc($uid);
            $from      = strtolower((string) ($wiadomosc['from'] ?? ''));
            $subject   = (string) ($wiadomosc['subject'] ?? '');
            $tresc     = (string) ($wiadomosc['tresc'] ?? '');

            // Sam temat lub podciąg domeny nie wystarcza: tylko dokładny nadawca alertów.
            $wygladaNaDdos = Parser::czyOdNadawcy($from)
                && $this->parser->toPowiadomienieDdos($subject, $tresc);

            if (! $wygladaNaDdos) {
                continue;
            }

            $sumaDdos++;
            $checksum = hash('sha256', $subject . "\n" . $tresc);
            $liczba   = $this->importujPowiadomienie(
                $subject,
                $tresc,
                $wiadomosc['date'] ?? null,
                $checksum,
                $wiadomosc['message_id'],
                [
                    'tryb'    => 'imap',
                    'uid'     => $uid,
                    'from'    => $from,
                    'subject' => $subject,
                ],
            );
            if ($liczba > 0) {
                $sumaWierszy += $liczba;
                $sumaImportow++;
                $klient->oznaczPrzeczytane($uid);
            }
        }

        return [
            'wiadomosci'        => count($uidy),
            'ddos'              => $sumaDdos,
            'importy'           => $sumaImportow,
            'wiersze'           => $sumaWierszy,
            'fallback_ostatnie' => $fallback,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function importujPowiadomienie(
        string $subject,
        string $tresc,
        ?string $dateNaglowka,
        string $checksum,
        ?string $idWiadomosci,
        array $meta,
    ): int {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, $idWiadomosci, $meta);
        if ($importId === null) {
            return 0;
        }

        $db = Database::connect();

        try {
            $wiersze = $this->parser->parsujPowiadomienie($subject, $tresc, $dateNaglowka);
            if ($wiersze === []) {
                $this->koperta->zakonczKwarantanna($importId, 'Brak rozpoznanego powiadomienia DDoS w treści/temacie');

                return 0;
            }

            $licznik = 0;
            foreach ($wiersze as $w) {
                $kategoria = $this->parser->kategoriaDlaWiersza($w);
                $wynikWagi = $this->waga->wylicz($kategoria, [
                    'message_type' => $w['message_type'],
                ]);

                $db->table('mcs_play_wiersze')->insert([
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $w['timestamp'],
                    'ip'               => $w['ip'],
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'payload_json'     => json_encode($w, JSON_UNESCAPED_UNICODE),
                ]);
                $idWZrodle = (string) $db->insertID();

                $tytul = 'Play Scrubbing DDoS — ' . $w['message_type'];
                if (! empty($w['event_id'])) {
                    $tytul .= ' #' . $w['event_id'];
                }

                $this->zapis->zapisz([
                    'zrodlo'           => $this->zrodlo(),
                    'klucz_dedupu'     => $w['klucz_dedupu'],
                    'podmiot_typ'      => $w['podmiot_typ'],
                    'podmiot'          => $w['podmiot'],
                    'zaobserwowano_at' => $w['timestamp'],
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => $kategoria,
                    'tytul'            => $tytul,
                    'import_id'        => $importId,
                    'id_w_zrodle'      => $idWZrodle,
                    'payload'          => [
                        'message_type'  => $w['message_type'],
                        'event_id'      => $w['event_id'],
                        'policy'        => $w['policy'],
                        'tid'           => $w['tid'],
                        'mitigation_id' => $w['mitigation_id'],
                        'cidr'          => $w['cidr'],
                        'waga_meta'     => $wynikWagi,
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

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
        ]);
    }
}
