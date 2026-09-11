<?php

use App\Models\PluginModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PluginModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                health_at TEXT NULL,
                health_ok BOOLEAN NULL,
                health_msg TEXT NULL
            )",
        );

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'play',
            'nazwa'             => 'Play',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
            'health_msg'        => 'seed',
        ]);
        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
            'health_msg'        => null,
        ]);
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testWszystkieSortujePoZrodle(): void
    {
        $lista = model(PluginModel::class)->wszystkie();

        $this->assertCount(2, $lista);
        $this->assertSame('play', $lista[0]['zrodlo']);
        $this->assertSame('shadowserver', $lista[1]['zrodlo']);
    }

    public function testUstawStanyZmieniaFlagiBezHealth(): void
    {
        $ok = model(PluginModel::class)->ustawStany('play', false, true);

        $this->assertTrue($ok);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'play',
            'import_wstrzymany' => 0,
            'ukryty_w_ui'       => 1,
            'health_msg'        => 'seed',
        ]);
    }

    public function testUstawStanyNieznanegoZrodlaZwracaFalse(): void
    {
        $ok = model(PluginModel::class)->ustawStany('nieistnieje', true, true);

        $this->assertFalse($ok);
        $this->seeNumRecords(2, 'mcs_pluginy', []);
    }
}
