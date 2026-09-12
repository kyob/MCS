<?php

use App\Libraries\IncydentSzczegoly;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IncydentSzczegolyTest extends CIUnitTestCase
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
        $ss         = $this->db->prefixTable('mcs_shadowserver_wiersze');

        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");
        $this->db->query("DROP TABLE IF EXISTS {$ss}");

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                health_at TEXT NULL,
                health_ok BOOLEAN NULL,
                health_msg TEXT NULL
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

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_pluginy', 'mcs_shadowserver_wiersze'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testZbieraIncydentTimelineIWierszZrodla(): void
    {
        $this->db->table('mcs_shadowserver_wiersze')->insert([
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'ip'               => '198.51.100.10',
            'port'             => 22,
            'tag'              => 'open',
            'klucz_dedupu'     => 'k1',
            'payload_json'     => '{"src":1}',
        ]);
        $idZrodla = (string) $this->db->insertID();

        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => 'k1',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.10',
            'pierwsze_widziane' => '2026-09-10 08:00:00+00',
            'ostatnio_widziane' => '2026-09-11 10:00:00+00',
            'waga'              => 'high',
            'kategoria'         => 'exposure',
            'tytul'             => 'Shadowserver — open',
            'port'              => 22,
            'protokol'          => 'tcp',
            'ukryty_w_ui'       => 0,
        ]);
        $incydentId = (int) $this->db->insertID();

        $this->db->table('mcs_obserwacje')->insertBatch([
            [
                'incydent_id'      => $incydentId,
                'zaobserwowano_at' => '2026-09-10 08:00:00+00',
                'payload_json'     => '{"dzień":1}',
                'id_w_zrodle'      => 'stary',
            ],
            [
                'incydent_id'      => $incydentId,
                'zaobserwowano_at' => '2026-09-11 10:00:00+00',
                'payload_json'     => '{"dzień":2}',
                'id_w_zrodle'      => $idZrodla,
            ],
        ]);

        $svc    = new IncydentSzczegoly($this->db);
        $wynik  = $svc->dlaIpIId('198.51.100.10', $incydentId);

        $this->assertNotNull($wynik);
        $this->assertSame('Shadowserver — open', $wynik['incydent']['tytul']);
        $this->assertCount(2, $wynik['obserwacje']);
        $this->assertSame($idZrodla, $wynik['obserwacje'][0]['id_w_zrodle']);
        $this->assertSame('mcs_shadowserver_wiersze', $wynik['zrodlo_tabela']);
        $this->assertSame('open', $wynik['zrodlo_wiersz']['tag'] ?? null);
    }

    public function testOdrzucaInneIpLubUkryty(): void
    {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => 'k2',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.10',
            'pierwsze_widziane' => '2026-09-10 08:00:00+00',
            'ostatnio_widziane' => '2026-09-11 10:00:00+00',
            'waga'              => 'low',
            'kategoria'         => 'other',
            'tytul'             => 'Ukryty',
            'ukryty_w_ui'       => 1,
        ]);
        $id = (int) $this->db->insertID();

        $svc = new IncydentSzczegoly($this->db);
        $this->assertNull($svc->dlaIpIId('198.51.100.10', $id));
        $this->assertNull($svc->dlaIpIId('203.0.113.1', $id));
        $this->assertNull($svc->dlaIpIId('198.51.100.10', 99999));
    }
}
