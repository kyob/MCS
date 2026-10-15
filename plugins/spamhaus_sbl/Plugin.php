<?php

namespace McsPluginy\spamhaus_sbl;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\OpcjeMcs;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Plugin źródła Spamhaus SBL — powiadomienia e-mail o wpisach SBL (IMAP).
 *
 * Filtr nadawcy/tematu/uwierzytelnienia: {@see Filtr}. Parser treści: {@see Parser}.
 * Tylko adresy z prefiksów AS (as198050_cidry) dają incydent; reszta = licznik pominięć.
 * Żadnych zapytań sieciowych poza IMAP (linki z treści nie są odwiedzane).
 *
 * Idempotentność: tabela mcs_spamhaus_sbl_wiadomosci (unikat po Message-ID / sha256)
 * + ImportKoperta (zrodlo, checksum / id_wiadomosci).
 */
class Plugin implements SourcePluginInterface
{
    public const ZRODLO = 'spamhaus_sbl';

    public const TAB_WIADOMOSCI = 'mcs_spamhaus_sbl_wiadomosci';

    public const TAB_WIERSZE = 'mcs_spamhaus_sbl_wiersze';

    public const KATEGORIA = 'spam';

    public const STATUS_PRZYJETA = 'przyjeta';

    private ?string $pominiecie = null;

    private Parser $parser;

    private ?Filtr $filtr;

    private ?ImportKoperta $koperta;

    private ?IncydentZapis $zapis;

    private WagaV1 $waga;

    private ?As198050Matcher $matcher;

    private ?BaseConnection $db;

    public function __construct(
        ?Parser $parser = null,
        ?Filtr $filtr = null,
        ?ImportKoperta $koperta = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?As198050Matcher $matcher = null,
        private readonly ?ImapKlientFabryka $imapFabryka = null,
        ?BaseConnection $db = null,
    ) {
        $this->parser  = $parser ?? new Parser();
        $this->filtr   = $filtr;
        $this->koperta = $koperta;
        $this->zapis   = $zapis;
        $this->waga    = $waga ?? new WagaV1();
        $this->matcher = $matcher;
        $this->db      = $db;
    }

    public function zrodlo(): string
    {
        return self::ZRODLO;
    }

    /**
     * Gdy ostatni pobierz() nic nie zrobił (brak konfiguracji) — komunikat dla PobierzSerwis.
     */
    public function komunikatPominiecia(): ?string
    {
        return $this->pominiecie;
    }

    public function pobierz(): void
    {
        $this->pominiecie = null;
        $fabryka = $this->imapFabryka ?? new ImapKlientFabryka();

        // Brak skrzynki / rozszerzenia = pominięcie, nie wyjątek (cron nie może się wywracać).
        try {
            $klient = $fabryka->dlaZrodla(self::ZRODLO);
        } catch (RuntimeException $e) {
            $msg = 'pominięto — brak konfiguracji IMAP (mcs.spamhaus_sbl.imap* w .env) albo rozszerzenia imap.';
            $this->pominiecie = $msg;
            $this->aktualizujHealth(false, $msg);

            return;
        }

        try {
            $s = $this->importujZImap($klient);
            $this->aktualizujHealth(
                $s['bledy'] === 0,
                sprintf(
                    'IMAP OK (wiadomosci=%d, przyjete=%d, pominiete=%d, nieuwierzytelnione=%d, incydenty=%d, duplikaty=%d, inne=%d, bledy=%d)',
                    $s['wiadomosci'],
                    $s['przyjete'],
                    $s['pominiete'],
                    $s['nieuwierzytelnione'],
                    $s['incydenty'],
                    $s['duplikaty'],
                    $s['inne'],
                    $s['bledy'],
                ),
            );
        } catch (\Throwable $e) {
            $this->aktualizujHealth(false, 'IMAP: ' . $e->getMessage());
            throw $e;
        } finally {
            $klient->zamknij();
        }
    }

    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find(self::ZRODLO);
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }
        $msg = (string) ($wiersz['health_msg'] ?? '');

        return [
            'ok'  => PluginModel::jakoBool($wiersz['health_ok'] ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
        ];
    }

    /**
     * @return array{skrot: string, tryb_aktywny: string, tryb_etykieta: string, pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>}
     */
    public function opisPolaczenia(): array
    {
        return PluginOpisPolaczenia::imap('mcs.' . self::ZRODLO);
    }

    /**
     * Przetwarza nieprzeczytane wiadomości ze skrzynki.
     *
     * @return array{wiadomosci: int, przyjete: int, pominiete: int, nieuwierzytelnione: int, incydenty: int, duplikaty: int, inne: int, bledy: int}
     */
    public function importujZImap(ImapKlientInterface $klient): array
    {
        $limit = max(1, OpcjeMcs::int('mcs.spamhaus_sbl.maxWiadomosci'));
        $uidy  = $klient->listujUid(true);
        sort($uidy, SORT_NUMERIC);
        $uidy = array_slice($uidy, 0, $limit);

        $s = ['wiadomosci' => count($uidy), 'przyjete' => 0, 'pominiete' => 0, 'nieuwierzytelnione' => 0,
            'incydenty' => 0, 'duplikaty' => 0, 'inne' => 0, 'bledy' => 0];

        foreach ($uidy as $uid) {
            try {
                $w = $this->przetworz($klient->pobierzWiadomosc($uid));
            } catch (\Throwable $e) {
                $s['bledy']++;
                log_message('warning', 'spamhaus_sbl: UID {uid}: {msg}', ['uid' => $uid, 'msg' => $e->getMessage()]);

                continue;
            }
            switch ($w['wynik']) {
                case 'inne':
                    $s['inne']++;
                    break;
                case 'duplikat':
                    $s['duplikaty']++;
                    break;
                case self::STATUS_PRZYJETA:
                    $s['przyjete']++;
                    $s['incydenty'] += $w['incydenty'];
                    if ($w['nieuwierzytelniony']) {
                        $s['nieuwierzytelnione']++;
                    }
                    $klient->oznaczPrzeczytane($uid);
                    break;
                default:
                    $s['pominiete']++;
            }
        }

        return $s;
    }

    /**
     * Decyzja i zapis dla jednej wiadomości (format jak ImapKlientInterface::pobierzWiadomosc).
     *
     * @param array<string, mixed> $wiad
     *
     * @return array{wynik: string, incydenty: int, nieuwierzytelniony: bool}
     */
    public function przetworz(array $wiad): array
    {
        $nic      = ['incydenty' => 0, 'nieuwierzytelniony' => false];
        $naglowki = new Naglowki((string) ($wiad['naglowki'] ?? ''));
        $filtr    = $this->filtr();

        $fromy = $naglowki->wszystkie('from');
        if (count($fromy) > 1) {
            $adres = null; // kilka nagłówków From = podejrzane
        } else {
            $adres = $filtr->adresZFrom($fromy[0] ?? (string) ($wiad['from'] ?? ''));
        }
        $nadawcaOk = $adres !== null && $filtr->nadawcaZaufanejDomeny($adres);
        $tematSur  = (string) ($wiad['subject'] ?? '');
        $tematOk   = $filtr->tematSbl($tematSur);

        if (! $nadawcaOk && ! $tematOk) {
            return ['wynik' => 'inne'] + $nic; // zwykła poczta w skrzynce — bez zapisu
        }

        $tresc   = Parser::tekstZTresci((string) ($wiad['tresc'] ?? ''));
        $temat   = Parser::czystyTekst($tematSur, Parser::MAX_TEMAT);
        $klucz   = $this->kluczWiadomosci($wiad, $tresc);
        $db      = $this->db();

        if ($db->table(self::TAB_WIADOMOSCI)->where('klucz', $klucz)->countAllResults() > 0) {
            return ['wynik' => 'duplikat'] + $nic;
        }

        $rekord = [
            'klucz'              => $klucz,
            'uid'                => isset($wiad['uid']) ? (int) $wiad['uid'] : null,
            'nadawca'            => Parser::czystyTekst((string) ($adres ?? ($fromy[0] ?? ($wiad['from'] ?? ''))), 255),
            'temat'              => $temat,
            'nieuwierzytelniony' => false,
            'uwierzytelnienie'   => '',
            'sbl'                => null,
            'ip'                 => null,
            'import_id'          => null,
            'przetworzono_at'    => $this->teraz(),
        ];

        if (! $nadawcaOk) {
            return $this->odrzuc($rekord, 'nadawca', 'nadawca spoza spamhaus.org (lub niejednoznaczny From)');
        }
        if (! $tematOk) {
            return $this->odrzuc($rekord, 'temat', 'temat bez „SBL”');
        }

        $auth = $filtr->uwierzytelnienie($naglowki);
        $rekord['uwierzytelnienie'] = $auth['wynik'] === Filtr::AUTH_PASS ? $auth['metoda'] . '=pass' : $auth['wynik'];
        if ($auth['wynik'] === Filtr::AUTH_FAIL) {
            return $this->odrzuc($rekord, 'auth_fail', Parser::czystyTekst($auth['opis'], 255));
        }
        $nieuw = $auth['wynik'] === Filtr::AUTH_BRAK;
        $rekord['nieuwierzytelniony'] = $nieuw;

        $sbl = $this->parser->numerSbl($tematSur, $tresc);
        $rekord['sbl'] = $sbl;
        if ($sbl === null) {
            return $this->odrzuc($rekord, 'brak_numeru', 'brak numeru SBL');
        }

        $adresy = $this->parser->adresy($tematSur, $tresc);
        if ($adresy === []) {
            return $this->odrzuc($rekord, 'brak_ip', 'brak adresu IPv4/CIDR');
        }

        $matcher = $this->matcher ?? new As198050Matcher();
        $nasze   = array_values(array_filter($adresy, static fn (array $a): bool => $matcher->czyNaszeIp($a['ip'])));
        if ($nasze === []) {
            $rekord['ip'] = $adresy[0]['cidr'] ?? $adresy[0]['ip'];

            return $this->odrzuc($rekord, 'obcy_ip', 'adres spoza prefiksów AS');
        }

        return $this->przyjmij($rekord, $wiad, $tresc, $sbl, $nasze, $nieuw, $auth);
    }

    /**
     * @param array<string, mixed>                    $rekord
     * @param array<string, mixed>                    $wiad
     * @param list<array{ip: string, cidr: ?string}>  $nasze
     * @param array{wynik: string, metoda: string, opis: string} $auth
     *
     * @return array{wynik: string, incydenty: int, nieuwierzytelniony: bool}
     */
    private function przyjmij(array $rekord, array $wiad, string $tresc, string $sbl, array $nasze, bool $nieuw, array $auth): array
    {
        $db       = $this->db();
        $koperta  = $this->koperta ?? new ImportKoperta(null, $db);
        $zapis    = $this->zapis ?? new IncydentZapis($db);
        $checksum = hash('sha256', $rekord['klucz'] . "\n" . $tresc);
        $importId = $koperta->rozpocznij(self::ZRODLO, $checksum, $rekord['klucz'], [
            'tryb' => 'imap',
            'uid'  => $rekord['uid'],
            'sbl'  => $sbl,
        ]);
        if ($importId === null) {
            return ['wynik' => 'duplikat', 'incydenty' => 0, 'nieuwierzytelniony' => false];
        }

        try {
            $czas  = $this->czasWiadomosci($wiad['date'] ?? null);
            $powod = $this->parser->powod($tresc);
            $link  = $this->parser->link($tresc);
            $wynikWagi = $this->waga->wylicz(self::KATEGORIA, ['waga_jawna' => $nieuw ? 'medium' : 'high']);
            $uwierz = $nieuw ? 'nieuwierzytelniony nadawca' : $auth['metoda'] . '=pass';
            $rekord['ip']        = $nasze[0]['cidr'] ?? $nasze[0]['ip'];
            $rekord['import_id'] = $importId;
            $licznik = 0;

            foreach (array_slice($nasze, 0, 16) as $a) {
                $kluczDedupu = 'sbl|' . $sbl . '|' . $a['ip'];
                $payload = [
                    'sbl'                => $sbl,
                    'cidr'               => $a['cidr'],
                    'uwierzytelnienie'   => $uwierz,
                    'nieuwierzytelniony' => $nieuw,
                    'powod'              => $powod,
                    'link_w_tresci'      => $link,
                    'temat'              => $rekord['temat'],
                    'nadawca'            => $rekord['nadawca'],
                    'waga_meta'          => $wynikWagi,
                ];
                $db->table(self::TAB_WIERSZE)->insert([
                    'import_id'        => $importId,
                    'zaobserwowano_at' => $czas,
                    'sbl'              => $sbl,
                    'ip'               => $a['ip'],
                    'cidr'             => $a['cidr'],
                    'uwierzytelnienie' => $uwierz,
                    'klucz_dedupu'     => $kluczDedupu,
                    'payload_json'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);
                $idWZrodle = (string) $db->insertID();

                $tytul = 'Spamhaus ' . $sbl . ' — ' . ($a['cidr'] ?? $a['ip']);
                if ($nieuw) {
                    $tytul .= ' (nieuwierzytelniony nadawca)';
                }

                $zapis->zapisz([
                    'zrodlo'           => self::ZRODLO,
                    'klucz_dedupu'     => $kluczDedupu,
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $a['ip'],
                    'zaobserwowano_at' => $czas,
                    'waga'             => $wynikWagi['waga'],
                    'wersja_wagi'      => $wynikWagi['wersja'],
                    'kategoria'        => self::KATEGORIA,
                    'tytul'            => mb_substr($tytul, 0, 255),
                    'ukryty_w_ui'      => false,
                    'import_id'        => $importId,
                    'id_w_zrodle'      => $idWZrodle,
                    'payload'          => $payload,
                ]);
                $licznik++;
            }

            $this->zapiszRekord($rekord, self::STATUS_PRZYJETA, '');
            $koperta->zakonczOk($importId, ['wierszy' => $licznik, 'nieuwierzytelniony' => $nieuw]);

            return ['wynik' => self::STATUS_PRZYJETA, 'incydenty' => $licznik, 'nieuwierzytelniony' => $nieuw];
        } catch (\Throwable $e) {
            $koperta->zakonczBlad($importId, mb_substr($e->getMessage(), 0, 500));
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $rekord
     *
     * @return array{wynik: string, incydenty: int, nieuwierzytelniony: bool}
     */
    private function odrzuc(array $rekord, string $status, string $powod): array
    {
        $this->zapiszRekord($rekord, $status, $powod);

        return ['wynik' => $status, 'incydenty' => 0, 'nieuwierzytelniony' => (bool) $rekord['nieuwierzytelniony']];
    }

    /**
     * @param array<string, mixed> $rekord
     */
    private function zapiszRekord(array $rekord, string $status, string $powod): void
    {
        $rekord['status'] = $status;
        $rekord['powod']  = mb_substr($powod, 0, 255);
        if (is_string($rekord['ip'])) {
            $rekord['ip'] = mb_substr($rekord['ip'], 0, 64);
        }
        $this->db()->table(self::TAB_WIADOMOSCI)->insert($rekord);
    }

    /**
     * Message-ID (gdy jest) albo sha256 nagłówków i treści.
     *
     * @param array<string, mixed> $wiad
     */
    private function kluczWiadomosci(array $wiad, string $tresc): string
    {
        $mid = Parser::czystyTekst((string) ($wiad['message_id'] ?? ''), 240);
        if ($mid !== '') {
            return 'mid:' . $mid;
        }

        return 'sha256:' . hash('sha256', (string) ($wiad['from'] ?? '') . "\n" . (string) ($wiad['subject'] ?? '')
            . "\n" . (string) ($wiad['date'] ?? '') . "\n" . $tresc);
    }

    private function czasWiadomosci(mixed $date): string
    {
        $teraz = time();
        $ts    = is_string($date) && $date !== '' ? strtotime($date) : false;
        if ($ts === false || $ts > $teraz + 86400 || $ts < $teraz - 365 * 86400) {
            $ts = $teraz;
        }

        return gmdate('Y-m-d H:i:s', $ts) . '+00:00';
    }

    private function teraz(): string
    {
        return gmdate('Y-m-d H:i:s') . '+00:00';
    }

    private function filtr(): Filtr
    {
        return $this->filtr ??= new Filtr(trim((string) env('mcs.spamhaus_sbl.authservId', '')));
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        try {
            model(PluginModel::class)->update(self::ZRODLO, [
                'health_at'  => $this->teraz(),
                'health_ok'  => $ok,
                'health_msg' => mb_substr($msg, 0, 500),
            ]);
        } catch (\Throwable) {
            // brak wiersza / DB — health tylko informacyjny
        }
    }
}
