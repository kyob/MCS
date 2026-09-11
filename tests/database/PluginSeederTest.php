<?php

use App\Libraries\PluginSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PluginSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;
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
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testZapisujeTrzyZrodlaZWstrzymanymImportem(): void
    {
        $wynik = (new PluginSeeder())->zapisz();

        $this->assertSame(['shadowserver', 'play', 'snds', 'mojecert'], $wynik['nowe']);
        $this->assertSame([], $wynik['juz_byly']);
        $this->seeNumRecords(4, 'mcs_pluginy', []);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'play',
            'nazwa'             => 'Play',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'snds',
            'nazwa'             => 'SNDS',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'mojecert',
            'nazwa'             => 'MojeCert',
            'import_wstrzymany' => 1,
        ]);
    }

    public function testPonownyZapisNieNadpisujeIstniejacych(): void
    {
        (new PluginSeeder())->zapisz();
        $this->db->table('mcs_pluginy')->where('zrodlo', 'play')->update(['import_wstrzymany' => 0]);

        $wynik = (new PluginSeeder())->zapisz();

        $this->assertSame([], $wynik['nowe']);
        $this->assertSame(['shadowserver', 'play', 'snds', 'mojecert'], $wynik['juz_byly']);
        $this->seeNumRecords(4, 'mcs_pluginy', []);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'play',
            'import_wstrzymany' => 0,
        ]);
    }
}
