<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\urlhaus\Klient;
use McsPluginy\urlhaus\Serwis;

/**
 * @internal
 */
final class UrlhausSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected $refresh = false;

    protected $namespace = '';

    private const KLUCZ = 'SUPERSECRETKEY99';

    protected function setUp(): void
    {
        parent::setUp();
        \App\Libraries\OpcjeMcs::resetuj();
        $p = fn (string $t): string => $this->db->prefixTable($t);
        foreach (['mcs_ustawienia', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje'] as $tabela) {
            $this->db->query('DROP TABLE IF EXISTS ' . $p($tabela));
        }
        $this->db->query("CREATE TABLE {$p('mcs_ustawienia')} (klucz VARCHAR(191) PRIMARY KEY, wartosc TEXT NULL, updated_at TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            klucz_dedupu VARCHAR(255) NOT NULL, podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip', podmiot VARCHAR(255) NOT NULL,
            pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga VARCHAR(20) NOT NULL, wersja_wagi VARCHAR(20) NULL,
            kategoria VARCHAR(100) NOT NULL, tytul VARCHAR(255) NOT NULL, port INTEGER NULL, protokol VARCHAR(20) NULL,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");
        $this->db->table('mcs_ustawienia')->insertBatch([
            ['klucz' => 'as198050_wlaczone', 'wartosc' => '1', 'updated_at' => '2026-01-01T00:00:00+00:00'],
            ['klucz' => 'as198050_cidry', 'wartosc' => "91.231.80.0/22\n2a0f:1700::/29", 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $_ENV['mcs.urlhaus.authKey'] = self::KLUCZ;
        $_SERVER['mcs.urlhaus.authKey'] = self::KLUCZ;
    }

    protected function tearDown(): void
    {
        unset($_ENV['mcs.urlhaus.authKey'], $_SERVER['mcs.urlhaus.authKey']);
        \App\Libraries\OpcjeMcs::resetuj();
        foreach (['mcs_ustawienia', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje'] as $tabela) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($tabela));
        }
        parent::tearDown();
    }

    public function testIncydentDlaNaszegoIpIDomenaWKomunikacie(): void
    {
        $csv = "id,dateadded,url,url_status,threat,tags,urlhaus_link\n"
            . "9,2026-01-01,http://91.231.80.9/a.exe,online,malware_download,exe,https://urlhaus.abuse.ch/url/9/\n"
            . "8,2026-01-01,http://evil.example/b,online,malware_download,exe,https://urlhaus.abuse.ch/url/8/\n";
        $wynik = $this->serwis($csv)->uruchom();

        $this->assertSame('ok', $wynik['status'], $wynik['msg']);
        $this->assertSame(1, $wynik['trafienia']);
        $this->assertStringContainsString('pominięte domeny 1', $wynik['msg']);
        $this->assertStringNotContainsString(self::KLUCZ, $wynik['msg']);
        $wiersz = $this->db->table('mcs_incydenty')->get()->getRowArray();
        $this->assertIsArray($wiersz);
        $this->assertSame('urlhaus|91.231.80.9', $wiersz['klucz_dedupu']);
        $this->assertSame('critical', $wiersz['waga']);
    }

    public function testBrakKlucza(): void
    {
        unset($_ENV['mcs.urlhaus.authKey'], $_SERVER['mcs.urlhaus.authKey']);
        $wynik = $this->serwis('id,url\n')->uruchom();

        $this->assertSame('blad', $wynik['status']);
        $this->assertSame('Brak klucza URLhaus. Wtyczki → URLhaus → Klucz.', $wynik['msg']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    private function serwis(string $csv): Serwis
    {
        $klient = new Klient(static function (string $url) use ($csv): array {
            return ['ok' => true, 'http_code' => 200, 'body' => $csv, 'error' => ''];
        });

        return new Serwis($klient, null, null, null, null, 1_700_000_000);
    }
}
