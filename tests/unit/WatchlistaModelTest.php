<?php

use App\Models\WatchlistaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class WatchlistaModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_watchlista');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip VARCHAR(45) NOT NULL UNIQUE,
                notatka TEXT NULL,
                dodano_at TEXT NOT NULL,
                dodano_przez VARCHAR(255) NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_watchlista');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testDodajIListuj(): void
    {
        $model = model(WatchlistaModel::class);
        $this->assertTrue($model->dodaj('198.51.100.10', 'VIP klienta', 'op@example.com'));
        $this->assertFalse($model->dodaj('198.51.100.10')); // duplikat

        $lista = $model->wszystkie();
        $this->assertCount(1, $lista);
        $this->assertSame('198.51.100.10', $lista[0]['ip']);
        $this->assertSame('VIP klienta', $lista[0]['notatka']);
        $this->assertTrue($model->czyObserwowane('198.51.100.10'));
        $this->assertFalse($model->czyObserwowane('8.8.8.8'));
    }

    public function testOdrzucaNiepoprawneIp(): void
    {
        $model = model(WatchlistaModel::class);
        $this->assertFalse($model->dodaj('nie-ip'));
        $this->assertFalse($model->dodaj(''));
        $this->assertSame([], $model->wszystkie());
    }

    public function testUsun(): void
    {
        $model = model(WatchlistaModel::class);
        $model->dodaj('2001:db8::1');
        $this->assertTrue($model->usun('2001:db8::1'));
        $this->assertFalse($model->usun('2001:db8::1'));
        $this->assertSame([], $model->wszystkie());
    }

    public function testListaIp(): void
    {
        $model = model(WatchlistaModel::class);
        $model->dodaj('198.51.100.1');
        $model->dodaj('198.51.100.2');
        $ips = $model->listaIp();
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $ips);
    }
}
