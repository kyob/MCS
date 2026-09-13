<?php

use App\Libraries\ProblemyIp;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ProblemyIpTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();
        helper('url');

        foreach (['mcs_obserwacje', 'mcs_incydenty'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }

        $inc = $this->db->prefixTable('mcs_incydenty');
        $obs = $this->db->prefixTable('mcs_obserwacje');

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
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testGrupujeIdentyczneTytulPortZrodlo(): void
    {
        $incydenty = [
            [
                'id'                => 1,
                'zrodlo'            => 'cert_n6',
                'waga'              => 'high',
                'kategoria'         => 'vulnerability',
                'tytul'             => 'CERT N6 — vulnerable: ftp, clear text pass',
                'port'              => 21,
                'protokol'          => 'tcp',
                'pierwsze_widziane' => '2026-09-01 10:00:00',
                'ostatnio_widziane' => '2026-09-10 10:00:00',
            ],
            [
                'id'                => 2,
                'zrodlo'            => 'cert_n6',
                'waga'              => 'medium',
                'kategoria'         => 'vulnerability',
                'tytul'             => 'CERT N6 — vulnerable: ftp, clear text pass',
                'port'              => 21,
                'protokol'          => 'tcp',
                'pierwsze_widziane' => '2026-09-05 10:00:00',
                'ostatnio_widziane' => '2026-09-12 10:00:00',
            ],
            [
                'id'                => 3,
                'zrodlo'            => 'shadowserver',
                'waga'              => 'low',
                'kategoria'         => 'exposure',
                'tytul'             => 'Shadowserver — open',
                'port'              => 22,
                'protokol'          => 'tcp',
                'pierwsze_widziane' => '2026-09-11 10:00:00',
                'ostatnio_widziane' => '2026-09-11 10:00:00',
            ],
        ];

        $svc = new ProblemyIp($this->db);
        $out = $svc->zbuduj('64.46.124.39', $incydenty);

        $this->assertSame(2, $out['status']['liczba_problemow']);
        $this->assertSame('high', $out['status']['max_waga']);
        $this->assertCount(2, $out['problemy']);
        $this->assertSame(2, $out['problemy'][0]['liczba_zdarzen']);
        $this->assertSame('high', $out['problemy'][0]['waga']);
        $this->assertSame('2026-09-12 10:00:00', $out['problemy'][0]['ostatnio_widziane']);
        $this->assertCount(14, $out['problemy'][0]['timeline']);
        $this->assertStringContainsString(
            'CERT N6 — vulnerable: ftp, clear text pass',
            $out['status']['top_tytuly'][0] ?? '',
        );
    }

    public function testKluczGrupyRoznoport(): void
    {
        $svc = new ProblemyIp($this->db);
        $a   = $svc->kluczGrupy([
            'zrodlo' => 'cert_n6', 'tytul' => 'x', 'port' => 21, 'protokol' => 'tcp',
        ]);
        $b = $svc->kluczGrupy([
            'zrodlo' => 'cert_n6', 'tytul' => 'x', 'port' => 22, 'protokol' => 'tcp',
        ]);
        $this->assertNotSame($a, $b);
    }
}
