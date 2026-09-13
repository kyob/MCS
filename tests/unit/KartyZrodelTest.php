<?php

use App\Libraries\KartyZrodel;
use App\Libraries\SzczegolyZrodla;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class KartyZrodelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();
        helper('url');

        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_shadowserver_wiersze'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }

        $inc = $this->db->prefixTable('mcs_incydenty');
        $obs = $this->db->prefixTable('mcs_obserwacje');
        $ss  = $this->db->prefixTable('mcs_shadowserver_wiersze');

        $this->db->query(
            "CREATE TABLE {$inc} (
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
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0
            )",
        );
        $this->db->query(
            "CREATE TABLE {$obs} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
            )",
        );
        $this->db->query(
            "CREATE TABLE {$ss} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                ip VARCHAR(45) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                tag VARCHAR(100) NULL,
                severity VARCHAR(40) NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                payload_json TEXT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_shadowserver_wiersze'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testBudujeKarteZPolamiZrodla(): void
    {
        $this->db->table('mcs_shadowserver_wiersze')->insert([
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'ip'               => '198.51.100.10',
            'port'             => 21,
            'protokol'         => 'tcp',
            'tag'              => 'ftp',
            'severity'         => 'high',
            'klucz_dedupu'     => 'k1',
            'payload_json'     => '{"src":1}',
        ]);
        $idZrodla = (string) $this->db->insertID();

        $incydenty = [[
            'id'                => 7,
            'zrodlo'            => 'shadowserver',
            'waga'              => 'high',
            'kategoria'         => 'exposure',
            'tytul'             => 'Shadowserver — ftp',
            'port'              => 21,
            'protokol'          => 'tcp',
            'ostatnio_widziane' => '2026-09-11 10:00:00',
        ]];

        // KartyZrodel czyta obserwacje po incydent_id — wstawiamy ręcznie pod id=7
        $this->db->table('mcs_obserwacje')->insert([
            'incydent_id'      => 7,
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'id_w_zrodle'      => $idZrodla,
            'payload_json'     => null,
        ]);

        $svc   = new KartyZrodel($this->db, new SzczegolyZrodla($this->db));
        $karty = $svc->dlaIncydentow('198.51.100.10', $incydenty);

        $this->assertCount(1, $karty);
        $this->assertSame('Shadowserver', $karty[0]['zrodlo_etykieta']);
        $this->assertSame('ekspozycja', $karty[0]['kategoria_etykieta']);
        $this->assertSame(1, $karty[0]['liczba_obserwacji']);
        $this->assertStringContainsString('ip/198.51.100.10/incydent/7', $karty[0]['url']);

        $mapa = [];
        foreach ($karty[0]['pola'] as $p) {
            $mapa[$p['klucz']] = $p['wartosc'];
        }
        $this->assertSame('ftp', $mapa['tag']);
        $this->assertSame('21', $mapa['port']);
        $this->assertSame('high', $mapa['severity']);
    }
}
