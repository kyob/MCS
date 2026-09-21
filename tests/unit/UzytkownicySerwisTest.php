<?php

use App\Libraries\UzytkownicySerwis;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class UzytkownicySerwisTest extends CIUnitTestCase
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

    public function testTworzyUzytkownika(): void
    {
        $serwis = new UzytkownicySerwis(model(UserModel::class));
        $wynik  = $serwis->utworz('ops@example.local', 'sekretne1', 'operator');

        $this->assertTrue($wynik['ok']);
        $this->assertNotNull(model(UserModel::class)->znajdzAktywnegoPoEmailu('ops@example.local'));
    }

    public function testOdrzucaZlyEmailHasloIRole(): void
    {
        $serwis = new UzytkownicySerwis(model(UserModel::class));

        $this->assertFalse($serwis->utworz('nie-email', 'sekretne1', 'operator')['ok']);
        $this->assertFalse($serwis->utworz('ok@example.local', 'krotkie', 'operator')['ok']);
        $this->assertFalse($serwis->utworz('ok@example.local', 'sekretne1', 'root')['ok']);
    }

    public function testOdrzucaDuplikatEmaila(): void
    {
        $serwis = new UzytkownicySerwis(model(UserModel::class));
        $this->assertTrue($serwis->utworz('ops@example.local', 'sekretne1', 'operator')['ok']);
        $dup = $serwis->utworz('OPS@example.local', 'sekretne1', 'admin');
        $this->assertFalse($dup['ok']);
        $this->assertNotSame('', $dup['blad'] ?? '');
    }

    public function testNieDegradujeOstatniegoSuperadmina(): void
    {
        $model = model(UserModel::class);
        $id    = (int) $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $wynik  = $serwis->zmienRole($id, 'admin');

        $this->assertFalse($wynik['ok']);
        $this->assertSame('superadmin', $model->find($id)['rola']);
    }

    public function testNieWylaczaOstatniegoSuperadmina(): void
    {
        $model = model(UserModel::class);
        $id    = (int) $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $wynik  = $serwis->ustawAktywny($id, false);

        $this->assertFalse($wynik['ok']);
        $this->assertTrue((bool) $model->find($id)['aktywny']);
    }

    public function testDrugiSuperadminMoznaZdegradowacIWylaczyc(): void
    {
        $model = model(UserModel::class);
        $id1   = (int) $model->insert([
            'email'         => 'sa1@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);
        $id2 = (int) $model->insert([
            'email'         => 'sa2@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $this->assertTrue($serwis->zmienRole($id2, 'admin')['ok']);
        $this->assertSame('admin', $model->find($id2)['rola']);

        $this->assertTrue($serwis->zmienRole($id2, 'superadmin')['ok']);
        $this->assertTrue($serwis->ustawAktywny($id2, false)['ok']);
        $this->assertFalse((bool) $model->find($id2)['aktywny']);
        $this->assertSame('superadmin', $model->find($id1)['rola']);
    }

    public function testUstawiaHaslo(): void
    {
        $model = model(UserModel::class);
        $id    = (int) $model->insert([
            'email'         => 'ops@example.local',
            'password_hash' => password_hash('starehaslo', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $this->assertTrue($serwis->ustawHaslo($id, 'nowehaslo1')['ok']);
        $user = $model->find($id);
        $this->assertTrue(password_verify('nowehaslo1', (string) $user['password_hash']));
        $this->assertFalse($serwis->ustawHaslo($id, 'krotkie')['ok']);
    }

    public function testListaZwracaKont(): void
    {
        $model = model(UserModel::class);
        $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ]);

        $lista = (new UzytkownicySerwis($model))->lista();
        $this->assertCount(1, $lista);
        $this->assertSame('sa@example.local', $lista[0]['email']);
        $this->assertArrayNotHasKey('password_hash', $lista[0]);
    }

    public function testUsuwaZwykleKonto(): void
    {
        $model = model(UserModel::class);
        $sa = (int) $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);
        $ops = (int) $model->insert([
            'email'         => 'ops@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $wynik  = $serwis->usun($ops, $sa);

        $this->assertTrue($wynik['ok']);
        $this->assertNull($model->find($ops));
        $this->assertNotNull($model->find($sa));
    }

    public function testNieUsuwaOstatniegoSuperadmina(): void
    {
        $model = model(UserModel::class);
        $id    = (int) $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $wynik  = $serwis->usun($id, 999);

        $this->assertFalse($wynik['ok']);
        $this->assertSame('Nie można usunąć ostatniego superadmina.', $wynik['blad']);
        $this->assertNotNull($model->find($id));
    }

    public function testNieUsuwaWlasnegoKonta(): void
    {
        $model = model(UserModel::class);
        $sa = (int) $model->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);
        $admin = (int) $model->insert([
            'email'         => 'admin@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'admin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $wynik  = $serwis->usun($admin, $admin);

        $this->assertFalse($wynik['ok']);
        $this->assertSame('Nie można usunąć własnego konta.', $wynik['blad']);
        $this->assertNotNull($model->find($admin));
    }

    public function testDrugiSuperadminMoznaUsunac(): void
    {
        $model = model(UserModel::class);
        $id1 = (int) $model->insert([
            'email'         => 'sa1@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);
        $id2 = (int) $model->insert([
            'email'         => 'sa2@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);

        $serwis = new UzytkownicySerwis($model);
        $this->assertTrue($serwis->usun($id2, $id1)['ok']);
        $this->assertNull($model->find($id2));
        $this->assertFalse($serwis->usun($id1, $id1)['ok']);
        $this->assertNotNull($model->find($id1));
    }
}
