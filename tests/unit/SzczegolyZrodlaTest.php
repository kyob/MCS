<?php

use App\Libraries\SzczegolyZrodla;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SzczegolyZrodlaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_shadowserver_wiersze');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
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
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_shadowserver_wiersze'));
        parent::tearDown();
    }

    public function testMapujeZrodloNaTabele(): void
    {
        $svc = new SzczegolyZrodla($this->db);
        $this->assertSame('mcs_shadowserver_wiersze', $svc->tabelaDla('shadowserver'));
        $this->assertSame('mcs_cert_n6_zdarzenia', $svc->tabelaDla('cert_n6'));
        $this->assertNull($svc->tabelaDla('nieznane'));
    }

    public function testZnajdzWierszPoIdWZrodle(): void
    {
        $this->db->table('mcs_shadowserver_wiersze')->insert([
            'zaobserwowano_at' => '2026-09-11 10:00:00+00',
            'ip'               => '198.51.100.10',
            'port'             => 22,
            'protokol'         => 'tcp',
            'tag'              => 'open',
            'severity'         => 'high',
            'klucz_dedupu'     => 'k1',
            'payload_json'     => '{"raw":true}',
        ]);
        $id = (string) $this->db->insertID();

        $svc    = new SzczegolyZrodla($this->db);
        $wiersz = $svc->znajdz('shadowserver', $id);

        $this->assertNotNull($wiersz);
        $this->assertSame('198.51.100.10', $wiersz['ip']);
        $this->assertSame('open', $wiersz['tag']);
        $this->assertIsArray($wiersz['payload'] ?? null);
        $this->assertTrue($wiersz['payload']['raw'] ?? false);
    }

    public function testZnajdzBrakWierszaLubZrodla(): void
    {
        $svc = new SzczegolyZrodla($this->db);
        $this->assertNull($svc->znajdz('shadowserver', '999'));
        $this->assertNull($svc->znajdz('nieznane', '1'));
        $this->assertNull($svc->znajdz('shadowserver', ''));
    }
}
