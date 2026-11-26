<?php

use App\Libraries\Dashboard\EksportCsv;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #464: eksport CSV rankingu problematycznych IP w klasie
 * App\Libraries\Dashboard\EksportCsv (kontroler Dashboard tylko deleguje).
 *
 * @internal
 */
final class EksportCsvTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private mixed $sesjaPrzed = null;

    protected function setUp(): void
    {
        parent::setUp();

        // izolacja od stanu sesji współdzielonego przez inne testy w pełnym suite
        $this->sesjaPrzed = $_SESSION ?? null;
        $_SESSION         = [];

        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $ustawienia = $this->db->prefixTable('mcs_ustawienia');

        foreach ([$incydenty, $pluginy, $ustawienia] as $t) {
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
            "CREATE TABLE {$ustawienia} (
                klucz VARCHAR(100) PRIMARY KEY,
                wartosc TEXT NULL,
                updated_at TEXT NULL
            )",
        );

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo' => 'threatfox', 'nazwa' => 'ThreatFox', 'import_wstrzymany' => 0, 'ukryty_w_ui' => 0,
        ]);
        // matchowanie AS wyłączone — grupyCidr bez prefiksów AS
        $this->db->table('mcs_ustawienia')->insert([
            'klucz' => 'as_wlaczone', 'wartosc' => '0', 'updated_at' => '2026-11-24 10:00:00',
        ]);

        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        // złośliwa kategoria prosto z feedu (formula injection) + IP poza CIDR filtra
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo' => 'threatfox', 'klucz_dedupu' => 'evil-1', 'podmiot_typ' => 'ip',
            'podmiot' => '203.0.113.7', 'pierwsze_widziane' => $teraz, 'ostatnio_widziane' => $teraz,
            'waga' => 'high', 'kategoria' => '=1+1', 'tytul' => 'IOC =1+1', 'port' => 443,
            'protokol' => 'tcp', 'ukryty_w_ui' => 0,
        ]);
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo' => 'threatfox', 'klucz_dedupu' => 'ok-1', 'podmiot_typ' => 'ip',
            'podmiot' => '198.51.100.10', 'pierwsze_widziane' => $teraz, 'ostatnio_widziane' => $teraz,
            'waga' => 'low', 'kategoria' => 'spam', 'tytul' => 'Zwykly', 'port' => 25,
            'protokol' => 'tcp', 'ukryty_w_ui' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sesjaPrzed ?? [];
        foreach (['mcs_ustawienia', 'mcs_incydenty', 'mcs_pluginy'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testCsvMaNaglowekIPoleNeutralizowanePrzezCsvBezpieczenstwo(): void
    {
        $eksport = new EksportCsv();
        $filtry  = $eksport->filtry([]);

        $this->assertNull($eksport->bladFiltrow($filtry));

        $csv = $eksport->csv($eksport->wiersze($filtry));

        $this->assertNotNull($csv);
        $linie = explode("\n", trim($csv));
        $this->assertSame('ip,liczba_zrodel,zrodla,kategorie,max_waga,liczba_incydentow,ostatnio_widziane', $linie[0]);
        $this->assertCount(3, $linie); // nagłówek + 2 IP
        // kategoria =1+1 z feedu wychodzi jako '=1+1 (prefix apostrofu), nie surowa formuła
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString(',=1+1,', $csv);
        $this->assertStringContainsString('203.0.113.7', $csv);
        $this->assertStringContainsString('198.51.100.10', $csv);
        $this->assertStringContainsString('threatfox', $csv);
    }

    public function testCsvPustejListyToSamNaglowek(): void
    {
        $csv = (new EksportCsv())->csv([]);

        $this->assertSame(
            "ip,liczba_zrodel,zrodla,kategorie,max_waga,liczba_incydentow,ostatnio_widziane\n",
            $csv,
        );
    }

    public function testBladFiltrowEksportu(): void
    {
        $eksport = new EksportCsv();

        $zlyIp = $eksport->filtry(['ip' => 'nie-ip']);
        $this->assertSame('Nieprawidłowy adres IP w filtrze eksportu.', $eksport->bladFiltrow($zlyIp));

        $zlyCidr = $eksport->filtry(['cidr' => '10.0.0.0/33']);
        $this->assertSame('Nieprawidłowy CIDR w filtrze eksportu.', $eksport->bladFiltrow($zlyCidr));

        $dobre = $eksport->filtry(['ip' => '203.0.113.7', 'okno' => '7']);
        $this->assertNull($eksport->bladFiltrow($dobre));
    }

    public function testGrupyCidrBezAsIZCidrFiltrem(): void
    {
        $eksport = new EksportCsv();

        // AS wyłączone w ustawieniach, bez CIDR — brak grup
        $this->assertSame([], $eksport->grupyCidr(null));
        $this->assertSame([], $eksport->grupyCidr('   '));
        $this->assertSame([['203.0.113.0/24']], $eksport->grupyCidr('203.0.113.0/24'));
    }

    public function testWierszeRespektujaCidrZGet(): void
    {
        $eksport = new EksportCsv();
        $filtry  = $eksport->filtry(['cidr' => '203.0.113.0/24']);

        $this->assertNull($eksport->bladFiltrow($filtry));

        $ipy = array_column($eksport->wiersze($filtry), 'ip');
        $this->assertSame(['203.0.113.7'], $ipy);
    }

    public function testNazwaPlikuMaWzorzecICzas(): void
    {
        $nazwa = (new EksportCsv())->nazwaPliku();

        $this->assertMatchesRegularExpression('/^mcs-problematyczne-ip-\d{8}-\d{6}\.csv$/', $nazwa);
    }

    public function testLimitEksportuTo1000(): void
    {
        $this->assertSame(1000, EksportCsv::LISTA_MAX);
    }
}
