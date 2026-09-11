<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class UserModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = false;
    protected $refresh     = false;
    protected $namespace   = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tabela = $this->db->prefixTable('mcs_users');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                rola VARCHAR(20) NOT NULL,
                aktywny BOOLEAN NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_users');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testZnajdujeTylkoAktywnego(): void
    {
        $model = model(UserModel::class);
        $model->insert([
            'email'         => 'aktywny@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'admin',
            'aktywny'       => 1,
        ]);
        $model->insert([
            'email'         => 'wylaczony@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 0,
        ]);

        $this->assertNotNull($model->znajdzAktywnegoPoEmailu('aktywny@example.local'));
        $this->assertNotNull($model->znajdzAktywnegoPoEmailu('  Aktywny@Example.Local  '));
        $this->assertNull($model->znajdzAktywnegoPoEmailu('wylaczony@example.local'));
        $this->assertNull($model->znajdzAktywnegoPoEmailu('brak@example.local'));
    }
}
