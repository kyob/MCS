<?php

use App\Controllers\Dashboard;
use App\Libraries\CsvBezpieczenstwo;
use App\Libraries\Dnsbl\SkanyWidok;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;

/**
 * Issue #429: CSV/formula injection — wszystkie pola eksportów CSV (dashboard + skany DNSBL)
 * przechodzą przez CsvBezpieczenstwo::pole() (OWASP: = + - @ \t \r → prefix ').
 *
 * @internal
 */
final class CsvBezpieczenstwoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $obserwacje = $this->db->prefixTable('mcs_obserwacje');
        $ustawienia = $this->db->prefixTable('mcs_ustawienia');

        foreach ([$obserwacje, $incydenty, $pluginy, $ustawienia] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0
            )",
        );
        $this->db->query(
            "CREATE TABLE {$incydenty} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip',
                podmiot VARCHAR(255) NOT NULL,
                pierwsze_widziane TEXT NOT NULL,
                ostatnio_widziane TEXT NOT NULL,
                waga VARCHAR(20) NOT NULL,
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );
        $this->db->query(
            "CREATE TABLE {$obserwacje} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
            )",
        );
        $this->db->query(
            "CREATE TABLE {$ustawienia} (
                klucz VARCHAR(100) PRIMARY KEY,
                wartosc TEXT NULL,
                updated_at TEXT NULL
            )",
        );

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo' => 'threatfox', 'nazwa' => 'ThreatFox', 'import_wstrzymany' => 0, 'ukryty_w_ui' => 0,
        ]);
        // matchowanie AS wyłączone — eksport bez filtra CIDR AS (domyślnie byłoby włączone)
        $this->db->table('mcs_ustawienia')->insert([
            'klucz' => 'as_wlaczone', 'wartosc' => '0', 'updated_at' => '2026-11-24 10:00:00',
        ]);

        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        // złośliwa kategoria prosto z feedu (jak w issue: =1+1) + normalne źródło
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo' => 'threatfox', 'klucz_dedupu' => 'evil-1', 'podmiot_typ' => 'ip',
            'podmiot' => '198.51.100.10', 'pierwsze_widziane' => $teraz, 'ostatnio_widziane' => $teraz,
            'waga' => 'high', 'kategoria' => '=1+1', 'tytul' => 'IOC =1+1', 'port' => 443,
            'protokol' => 'tcp', 'ukryty_w_ui' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_pluginy', 'mcs_ustawienia'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testPolePrefiksujeWszystkieZnakiFormuly(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-1+1', '@cmd', "\t=x", "\r=x"] as $zlosliwe) {
            $this->assertSame("'" . $zlosliwe, CsvBezpieczenstwo::pole($zlosliwe), 'pole: ' . json_encode($zlosliwe));
        }
    }

    public function testPoleZostawiaBezpieczneWartosci(): void
    {
        $this->assertSame('198.51.100.10', CsvBezpieczenstwo::pole('198.51.100.10'));
        $this->assertSame('2026-11-24 10:00:00', CsvBezpieczenstwo::pole('2026-11-24 10:00:00'));
        $this->assertSame('high', CsvBezpieczenstwo::pole('high'));
        $this->assertSame('0', CsvBezpieczenstwo::pole(0));
        $this->assertSame('', CsvBezpieczenstwo::pole(null));
        $this->assertSame('', CsvBezpieczenstwo::pole(''));
        // apostrof w środku pola jest bezpieczny — liczy się tylko pierwszy znak
        $this->assertSame("a'b", CsvBezpieczenstwo::pole("a'b"));
    }

    public function testEksportCsvDashboarduNeutralizujeKategorieZFeedu(): void
    {
        // izolacja od stanu sesji/response współdzielonych przez inne testy w pełnym suite
        $sesjaPrzed = $_SESSION ?? null;
        $_SESSION = [];

        try {
            $config  = config(App::class);
            $uri     = new URI('http://example.test/dashboard/eksport-csv');
            $request = new IncomingRequest($config, $uri, null, new UserAgent());
            $request->setMethod('GET');
            $request->setGlobal('get', []);

            $controller = new Dashboard();
            $controller->initController($request, new Response($config), service('logger'));

            $odp = $controller->eksportCsv();
            $this->assertSame(200, $odp->getStatusCode());
            $csv = (string) $odp->getBody();
        } finally {
            $_SESSION = $sesjaPrzed ?? [];
        }

        $linie = explode("\n", trim($csv));
        $this->assertSame('ip,liczba_zrodel,zrodla,kategorie,max_waga,liczba_incydentow,ostatnio_widziane', $linie[0]);
        // kategoria =1+1 z feedu musi wyjść jako '=1+1 (prefix apostrofu), a nie surowa formuła
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString(',=1+1,', $csv);
        $this->assertStringContainsString('198.51.100.10', $csv);
    }

    public function testSkanyWidokCsvNeutralizujePoleTxt(): void
    {
        $csv = SkanyWidok::csv([
            ['ip' => '198.51.100.10', 'strefa' => 'bl.example', 'strefa_kod' => 'bl',
                'kody' => '127.0.0.2', 'txt' => '=1+1', 'status' => 'listed', 'blad' => ''],
        ]);

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString(',"=1+1"', $csv);
    }
}
