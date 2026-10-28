<?php

use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Dnsbl\HarmonogramSkanera as H;
use App\Libraries\Dnsbl\Skaner;
use App\Libraries\Dnsbl\SilnikZapytan;
use App\Libraries\Dnsbl\SkanyWidok;
use App\Libraries\Dnsbl\ZmianySkanu;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Skaner DNSBL, E3: diff nowy/zdjęty, zdarzenia, incydenty (dedup ze starym mechanizmem), fala,
 * tryb recheck (pary), CSV, retencja, harmonogram. SQLite :memory: + podstawiony silnik DNS.
 *
 * @internal
 */
final class DnsblSkanerE3Test extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = ['mcs_dnsbl_zdarzenia', 'mcs_dnsbl_listingi', 'mcs_dnsbl_wyniki', 'mcs_dnsbl_skany', 'mcs_dnsbl_strefy', 'mcs_obserwacje', 'mcs_incydenty'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_strefy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, kod TEXT UNIQUE, nazwa TEXT, strefa TEXT,
            typ TEXT DEFAULT 'ip', mapa_kodow TEXT NULL, url_delisting TEXT DEFAULT '', informacyjna BOOLEAN DEFAULT 0, aktywna BOOLEAN DEFAULT 1,
            wymaga_klucza TEXT DEFAULT '', test_ip TEXT DEFAULT '127.0.0.2', kolejnosc INTEGER DEFAULT 100, uwagi TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_skany')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zakres TEXT, strefa_filtr TEXT NULL, tryb TEXT DEFAULT 'cli',
            status TEXT DEFAULT 'oczekuje', uzytkownik TEXT DEFAULT '', utworzono_at TEXT NOT NULL, start_at TEXT NULL, koniec_at TEXT NULL, heartbeat_at TEXT NULL,
            liczba_ip INTEGER DEFAULT 0, zapytan_plan INTEGER DEFAULT 0, zapytan_wykonano INTEGER DEFAULT 0, czyste INTEGER DEFAULT 0, listowane INTEGER DEFAULT 0,
            bledy INTEGER DEFAULT 0, czas_ms INTEGER DEFAULT 0, strefy_json TEXT NULL, komunikat TEXT NULL,
            pary TEXT NULL, nowe INTEGER DEFAULT 0, zdjete INTEGER DEFAULT 0, incydenty INTEGER DEFAULT 0, fala BOOLEAN DEFAULT 0)");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_wyniki')} (id INTEGER PRIMARY KEY AUTOINCREMENT, skan_id INTEGER, ip TEXT, strefa_kod TEXT, status TEXT,
            kody TEXT DEFAULT '', txt TEXT NULL, blad TEXT DEFAULT '')");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_listingi')} (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, strefa_kod TEXT, kody TEXT DEFAULT '', txt TEXT NULL,
            pierwszy_raz TEXT NOT NULL, ostatni_raz TEXT NOT NULL, aktywny BOOLEAN DEFAULT 1, skan_id INTEGER NULL, UNIQUE (ip, strefa_kod))");
        $this->db->query("CREATE TABLE {$p('mcs_dnsbl_zdarzenia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, ip TEXT, strefa_kod TEXT, typ TEXT,
            skan_id INTEGER NULL, kody TEXT DEFAULT '', incydent_id INTEGER NULL, uwagi TEXT DEFAULT '')");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo TEXT NOT NULL, klucz_dedupu TEXT NOT NULL,
            podmiot_typ TEXT NOT NULL DEFAULT 'ip', podmiot TEXT NOT NULL, pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga TEXT NOT NULL,
            wersja_wagi TEXT NULL, kategoria TEXT NOT NULL, tytul TEXT NOT NULL, port INTEGER NULL, protokol TEXT NULL, ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
            UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL, import_id INTEGER NULL,
            zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");

        foreach ([
            ['kod' => 'spamcop', 'nazwa' => 'SpamCop', 'strefa' => 'bl.spamcop.net', 'typ' => 'ip', 'informacyjna' => 0, 'kolejnosc' => 10],
            ['kod' => 'mailspike', 'nazwa' => 'Mailspike', 'strefa' => 'bl.mailspike.net', 'typ' => 'ip', 'informacyjna' => 0, 'kolejnosc' => 20],
            ['kod' => 'uceprotect2', 'nazwa' => 'UCE L2', 'strefa' => 'dnsbl-2.uceprotect.net', 'typ' => 'siec24', 'informacyjna' => 1, 'kolejnosc' => 30],
        ] as $s) {
            $this->db->table('mcs_dnsbl_strefy')->insert($s + ['url_delisting' => 'https://x/?{ip}', 'mapa_kodow' => '{}', 'aktywna' => 1]);
        }
    }

    public function testStrefaPrzerwanaNieDajeCzystychIp(): void
    {
        // spamcop odpowiada kodem błędu strefy przy pierwszym IP: strefa przerwana, reszta IP nieodpytana.
        $silnik = new FakeSilnikPrzerywajacy(['spamcop|91.231.70.0' => ['127.255.255.254']]);
        $skaner = new Skaner($this->db, $silnik);

        $w = $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));

        $this->assertSame(4, $w['liczba_ip']);
        $this->assertSame(0, $w['czyste'], 'IP nieodpytane przez przerwaną strefę nie są czyste');
        $this->assertGreaterThan(0, $w['bledy']);
    }

    public function testBezListingowWszystkieIpCzyste(): void
    {
        $skaner = new Skaner($this->db, new FakeSilnik([]));

        $w = $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));

        $this->assertSame(4, $w['czyste']);
        $this->assertSame(0, $w['listowane']);
    }

    // --- diff nowy / zdjęty (pełny przebieg Skanera) ---

    public function testSkanZapisujeNowyIZdjetyListingOrazZdarzenia(): void
    {
        $this->listing('91.231.70.2', 'spamcop');
        $silnik = new FakeSilnik(['spamcop|91.231.70.1' => ['127.0.0.2']]);
        $skaner = new Skaner($this->db, $silnik);

        $id = $skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test');
        $w  = $skaner->wykonaj($id);

        $this->assertSame(1, $w['nowe']);
        $this->assertSame(1, $w['zdjete']);
        $this->assertSame(1, $w['incydenty'], 'spamcop = strefa o wysokiej wadze');
        $this->assertFalse($w['fala']);

        $akt = $this->db->table('mcs_dnsbl_listingi')->where('aktywny', 1)->get()->getResultArray();
        $this->assertSame(['91.231.70.1'], array_column($akt, 'ip'));

        $zd = $this->db->table('mcs_dnsbl_zdarzenia')->orderBy('typ')->get()->getResultArray();
        $this->assertSame(['nowy', 'zdjety'], array_column($zd, 'typ'));
        $this->assertSame(['91.231.70.1', '91.231.70.2'], array_column($zd, 'ip'));

        $inc = $this->db->table('mcs_incydenty')->get()->getResultArray();
        $this->assertCount(1, $inc);
        $this->assertSame('dnsbl', $inc[0]['zrodlo']);
        $this->assertSame('91.231.70.1|spamcop', $inc[0]['klucz_dedupu']);
        $this->assertSame('ip', $inc[0]['podmiot_typ']);

        $skan = $this->db->table('mcs_dnsbl_skany')->where('id', $id)->get()->getRowArray();
        $this->assertSame(1, (int) $skan['nowe']);
        $this->assertSame(1, (int) $skan['incydenty']);
    }

    public function testStrefaNiskiejWagiIInformacyjnaBezIncydentu(): void
    {
        $silnik = new FakeSilnik(['mailspike|91.231.70.1' => ['127.0.0.10'], 'uceprotect2|91.231.70.0/24' => ['127.0.0.2']]);
        $skaner = new Skaner($this->db, $silnik);
        $w      = $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));

        $this->assertSame(2, $w['nowe']);
        $this->assertSame(0, $w['incydenty']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(2, $this->db->table('mcs_dnsbl_zdarzenia')->where('typ', 'nowy')->countAllResults());
    }

    public function testUtrzymanyListingNieTworzyDrugiegoZdarzeniaAniIncydentu(): void
    {
        $silnik = new FakeSilnik(['spamcop|91.231.70.1' => ['127.0.0.2']]);
        $skaner = new Skaner($this->db, $silnik);
        $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));
        $w = $skaner->wykonaj($skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test'));

        $this->assertSame(0, $w['nowe']);
        $this->assertSame(1, $this->db->table('mcs_dnsbl_zdarzenia')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_obserwacje')->countAllResults());
    }

    // --- dedup ze starym mechanizmem (DnsblSerwis: zrodlo=dnsbl, klucz ip|lista) ---

    public function testDedupZIncydentemStaregoMechanizmu(): void
    {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo' => 'dnsbl', 'klucz_dedupu' => '91.231.70.1|spamcop', 'podmiot_typ' => 'ip', 'podmiot' => '91.231.70.1',
            'pierwsze_widziane' => '2026-10-01 00:00:00+00', 'ostatnio_widziane' => '2026-10-01 00:00:00+00', 'waga' => 'medium',
            'kategoria' => 'reputation', 'tytul' => 'DNSBL spamcop: 91.231.70.1',
        ]);
        $z = new ZmianySkanu($this->db);
        $w = $z->zapisz(5, [['kod' => 'spamcop', 'ip' => '91.231.70.1', 'kody' => '127.0.0.2', 'txt' => '']], []);

        $this->assertSame(0, $w['incydenty'], 'istniejący incydent — bez nowego');
        $this->assertSame(1, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_obserwacje')->countAllResults(), 'dopisana obserwacja');
        $zd = $this->db->table('mcs_dnsbl_zdarzenia')->get()->getRowArray();
        $this->assertSame(1, (int) $zd['incydent_id']);
    }

    public function testZdjetyListingAdnotujeIncydent(): void
    {
        $z = new ZmianySkanu($this->db);
        $z->zapisz(1, [['kod' => 'spamcop', 'ip' => '91.231.70.9', 'kody' => '127.0.0.2', 'txt' => '']], []);
        $z->zapisz(2, [], [['kod' => 'spamcop', 'ip' => '91.231.70.9']]);

        $zd = $this->db->table('mcs_dnsbl_zdarzenia')->where('typ', 'zdjety')->get()->getRowArray();
        $this->assertNotNull($zd['incydent_id']);
        $this->assertStringContainsString('zdjęty', (string) $zd['uwagi']);
    }

    // --- ochrona przed falą ---

    public function testFalaPowyzejProguBezIncydentow(): void
    {
        $nowe = [];
        for ($i = 1; $i <= ZmianySkanu::PROG_FALI + 1; $i++) {
            $nowe[] = ['kod' => 'spamcop', 'ip' => '91.231.80.' . $i, 'kody' => '127.0.0.2', 'txt' => ''];
        }
        $w = (new ZmianySkanu($this->db))->zapisz(3, $nowe, []);

        $this->assertTrue($w['fala']);
        $this->assertSame(0, $w['incydenty']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(ZmianySkanu::PROG_FALI + 1, $this->db->table('mcs_dnsbl_zdarzenia')->countAllResults());
    }

    public function testProgFaliDokladnie50ToJeszczeNieFala(): void
    {
        $nowe = array_fill(0, 50, ['kod' => 'spamcop', 'ip' => '91.231.80.1', 'kody' => '', 'txt' => '']);
        $this->assertFalse(ZmianySkanu::wybierzDoIncydentu($nowe)['fala']);
        $nowe[] = $nowe[0];
        $this->assertTrue(ZmianySkanu::wybierzDoIncydentu($nowe)['fala']);
    }

    public function testFalaWSkanieUstawiaKomunikat(): void
    {
        $odp = [];
        for ($i = 0; $i < 64; $i++) {
            $odp['spamcop|91.231.70.' . $i] = ['127.0.0.2'];
        }
        $skaner = new Skaner($this->db, new FakeSilnik($odp));
        $id     = $skaner->utworzSkan(['91.231.70.0/26'], null, 'noc', 'auto');
        $w      = $skaner->wykonaj($id);

        $this->assertTrue($w['fala']);
        $skan = $this->db->table('mcs_dnsbl_skany')->where('id', $id)->get()->getRowArray();
        $this->assertStringContainsString('Możliwy błąd skanu', (string) $skan['komunikat']);

        $stan = (new H($this->db, static fn () => null))->stanOstatniego();
        $this->assertTrue($stan['fala']);
        $this->assertStringContainsString('OSTRZEŻENIE: możliwy błąd skanu', $stan['msg']);
    }

    // --- recheck: tylko pary z aktywnych listingów ---

    public function testRecheckPytaTylkoOParyIZdejmujeListing(): void
    {
        $this->listing('91.231.70.7', 'spamcop');
        $this->listing('91.231.70.0/24', 'uceprotect2');
        $skaner = new Skaner($this->db, $silnik = new FakeSilnik(['uceprotect2|91.231.70.0/24' => ['127.0.0.2']]));
        $pary   = $skaner->aktywnePary();
        $this->assertCount(2, $pary);

        $id = $skaner->utworzSkanPar($pary, H::TRYB_RECHECK, 'auto');
        $w  = $skaner->wykonaj($id);

        $zapytania = array_values(array_filter($silnik->klucze, static fn ($k) => ! str_starts_with($k, 't')));
        sort($zapytania);
        $this->assertSame(['spamcop|91.231.70.7', 'uceprotect2|91.231.70.0/24'], $zapytania);
        $this->assertSame(1, $w['zdjete']);
        $this->assertSame(0, $w['nowe']);
        $akt = $this->db->table('mcs_dnsbl_listingi')->where('aktywny', 1)->get()->getResultArray();
        $this->assertSame(['91.231.70.0/24'], array_column($akt, 'ip'));
    }

    // --- CSV ---

    public function testCsvNaglowekIWierszeZOchronaFormul(): void
    {
        $csv = SkanyWidok::csv([
            ['ip' => '91.231.83.9', 'strefa_kod' => 'spamrats', 'strefa' => 'all.spamrats.com', 'kody' => '127.0.0.36,127.0.0.38', 'txt' => 'See: http://x', 'status' => 'listed', 'blad' => ''],
            ['ip' => '91.231.70.1', 'strefa_kod' => 'spamcop', 'strefa' => null, 'kody' => '', 'txt' => '=HYPERLINK("x")', 'status' => 'blad', 'blad' => 'timeout'],
        ]);
        $linie = explode("\n", trim($csv));
        $this->assertSame('ip,strefa,kod,txt,status,blad', $linie[0]);
        $this->assertSame('91.231.83.9,all.spamrats.com,"127.0.0.36,127.0.0.38","See: http://x",listed,', $linie[1]);
        $this->assertStringContainsString(",spamcop,,\"'=HYPERLINK(\"\"x\"\")\",blad,timeout", $linie[2]);
    }

    public function testWynikiCsvZBazy(): void
    {
        $skaner = new Skaner($this->db, new FakeSilnik(['spamcop|91.231.70.1' => ['127.0.0.2']]));
        $id     = $skaner->utworzSkan(['91.231.70.0/30'], null, 'cli', 'test');
        $skaner->wykonaj($id);
        $w = (new SkanyWidok($this->db))->wynikiCsv($id);
        $this->assertCount(1, $w);
        $this->assertSame('bl.spamcop.net', $w[0]['strefa']);
    }

    // --- retencja ---

    public function testRetencjaUsuwaTylkoStareWyniki(): void
    {
        $teraz = 1_800_000_000;
        foreach ([[1, $teraz - 91 * 86400], [2, $teraz - 10 * 86400]] as [$id, $ts]) {
            $this->db->table('mcs_dnsbl_skany')->insert(['id' => $id, 'zakres' => 'x', 'status' => 'zakonczony', 'utworzono_at' => gmdate('Y-m-d H:i:sP', $ts)]);
            $this->db->table('mcs_dnsbl_wyniki')->insert(['skan_id' => $id, 'ip' => '1.1.1.1', 'strefa_kod' => 'spamcop', 'status' => 'listed']);
        }
        $z = new ZmianySkanu($this->db);
        $this->assertSame(0, $z->czyscRetencje(0, $teraz), '0 = bez czyszczenia');
        $this->assertSame(1, $z->czyscRetencje(90, $teraz));
        $this->assertSame([2], array_map('intval', array_column($this->db->table('mcs_dnsbl_wyniki')->get()->getResultArray(), 'skan_id')));
        $this->assertSame(2, $this->db->table('mcs_dnsbl_skany')->countAllResults(), 'skany zostają (trend)');
    }

    // --- harmonogram ---

    public function testHarmonogramNocRazNaDobeWOknie(): void
    {
        $t0330 = (new DateTimeImmutable('2026-10-15 03:30:00', new DateTimeZone('Europe/Warsaw')))->getTimestamp();
        $this->assertSame(H::TRYB_NOC, H::decyzja($t0330, null, null, false, 0));
        $this->assertSame(H::TRYB_NOC, H::decyzja($t0330 + 3600, $t0330 - 86400, null, false, 0));
        $this->assertSame('', H::decyzja($t0330 + 900, $t0330, null, false, 0), 'już był dziś');
        $this->assertSame('', H::decyzja($t0330 - 60, null, null, false, 0), 'przed oknem');
        $this->assertSame('', H::decyzja($t0330 + 5400, null, null, false, 0), 'po oknie (05:00)');
        $this->assertSame('', H::decyzja($t0330, null, null, true, 5), 'trwa inny skan');
    }

    public function testHarmonogramRecheckCoGodzineTylkoGdySaListingi(): void
    {
        $t = (new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('Europe/Warsaw')))->getTimestamp();
        $this->assertSame('', H::decyzja($t, null, null, false, 0), 'brak aktywnych listingów');
        $this->assertSame(H::TRYB_RECHECK, H::decyzja($t, null, null, false, 3));
        $this->assertSame('', H::decyzja($t, null, $t - 1800, false, 3));
        $this->assertSame(H::TRYB_RECHECK, H::decyzja($t, null, $t - 3600, false, 3));
    }

    public function testKrokHarmonogramuZlecaRecheckWTle(): void
    {
        $this->listing('91.231.70.7', 'spamcop');
        $uruchomione = [];
        $h           = new H($this->db, static function (int $id) use (&$uruchomione): void {
            $uruchomione[] = $id;
        });
        $t   = (new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('Europe/Warsaw')))->getTimestamp();
        $msg = $h->krok($t);

        $this->assertCount(1, $uruchomione);
        $this->assertStringContainsString('zlecono recheck #' . $uruchomione[0], $msg);
        $skan = $this->db->table('mcs_dnsbl_skany')->where('id', $uruchomione[0])->get()->getRowArray();
        $this->assertSame('recheck', $skan['tryb']);
        $this->assertSame('oczekuje', $skan['status']);
        $this->assertSame([['kod' => 'spamcop', 'ip' => '91.231.70.7']], Skaner::dekodujPary($skan['pary']));

        $this->assertStringContainsString('trwa skan', $h->krok($t + 3600), 'jeden skan naraz');
        $this->assertCount(1, $uruchomione);
    }

    private function listing(string $ip, string $kod): void
    {
        $this->db->table('mcs_dnsbl_listingi')->insert([
            'ip' => $ip, 'strefa_kod' => $kod, 'kody' => '127.0.0.2', 'pierwszy_raz' => '2026-10-14 00:00:00+00',
            'ostatni_raz' => '2026-10-14 00:00:00+00', 'aktywny' => 1,
        ]);
    }
}

/**
 * Silnik DNS bez sieci: testy kontrolne OK, odpowiedzi A wg klucza „kod|ip”, reszta NXDOMAIN.
 */
final class FakeSilnik extends SilnikZapytan
{
    /** @var list<string> */
    public array $klucze = [];

    /**
     * @param array<string, list<string>> $listed
     */
    public function __construct(private array $listed)
    {
        parent::__construct();
    }

    public function wykonaj(array $zadania, ?callable $poOdpowiedzi = null): array
    {
        $out = [];
        foreach ($zadania as $z) {
            $k = $z['klucz'];
            if ($z['typ'] === DnsPakiet::TYP_TXT) {
                $w = SilnikZapytan::wynik('odp', 0, [], ['listed ' . $k], '');
            } elseif (str_starts_with($k, 't+|')) {
                $w = SilnikZapytan::wynik('odp', 0, ['127.0.0.2'], [], '');
            } elseif (str_starts_with($k, 't-|')) {
                $w = SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            } else {
                $this->klucze[] = $k;
                $w = isset($this->listed[$k])
                    ? SilnikZapytan::wynik('odp', 0, $this->listed[$k], [], '')
                    : SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            }
            if (str_starts_with($k, 't')) {
                $this->klucze[] = $k;
            }
            $out[$k] = $w;
            if ($poOdpowiedzi !== null) {
                $poOdpowiedzi($k, $w, $z);
            }
        }

        return $out;
    }
}

/**
 * Jak FakeSilnik, ale respektuje anulujGrupe(): zapytania anulowanej strefy nie dostają odpowiedzi.
 */
final class FakeSilnikPrzerywajacy extends SilnikZapytan
{
    /** @var array<string, true> */
    private array $anulowaneGrupy = [];

    /**
     * @param array<string, list<string>> $odpowiedzi klucz zapytania => kody A
     */
    public function __construct(private array $odpowiedzi)
    {
        parent::__construct();
    }

    public function anulujGrupe(string $grupa): void
    {
        $this->anulowaneGrupy[$grupa] = true;
        parent::anulujGrupe($grupa);
    }

    public function wykonaj(array $zadania, ?callable $poOdpowiedzi = null): array
    {
        $out = [];
        foreach ($zadania as $z) {
            $k      = $z['klucz'];
            $kontrol = str_starts_with($k, 't+|') || str_starts_with($k, 't-|');
            if (! $kontrol && isset($this->anulowaneGrupy[$z['grupa']])) {
                continue;
            }
            if ($z['typ'] === DnsPakiet::TYP_TXT) {
                $w = SilnikZapytan::wynik('odp', 0, [], ['listed ' . $k], '');
            } elseif (str_starts_with($k, 't+|')) {
                $w = SilnikZapytan::wynik('odp', 0, ['127.0.0.2'], [], '');
            } elseif (str_starts_with($k, 't-|')) {
                $w = SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            } else {
                $w = isset($this->odpowiedzi[$k])
                    ? SilnikZapytan::wynik('odp', 0, $this->odpowiedzi[$k], [], '')
                    : SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], '');
            }
            $out[$k] = $w;
            if ($poOdpowiedzi !== null) {
                $poOdpowiedzi($k, $w, $z);
            }
        }

        return $out;
    }
}
