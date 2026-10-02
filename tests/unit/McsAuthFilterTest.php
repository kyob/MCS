<?php

use App\Filters\McsAuthFilter;
use App\Models\UserModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;

/**
 * @internal
 */
final class McsAuthFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = false;
    protected $refresh     = false;
    protected $namespace   = '';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
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

    public function testBezSesjiPrzekierowujeNaLogin(): void
    {
        $request = $this->zrobRequest('/ip/198.51.100.10');
        $wynik   = (new McsAuthFilter())->before($request);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $wynik);
        $this->assertSame('/ip/198.51.100.10', session()->get('mcs_po_logowaniu'));
    }

    public function testZeSesjaPrzepuszczaIOdswiezaRole(): void
    {
        $id = (int) model(UserModel::class)->insert([
            'email'         => 'sa@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'superadmin',
            'aktywny'       => 1,
        ], true);
        session()->set([
            'mcs_user_id'    => $id,
            'mcs_user_email' => 'stary@example.local',
            'mcs_user_rola'  => 'admin',
        ]);

        $request = $this->zrobRequest('/admin/pluginy');
        $wynik   = (new McsAuthFilter())->before($request);

        $this->assertNull($wynik);
        $this->assertSame('superadmin', session()->get('mcs_user_rola'));
        $this->assertSame('sa@example.local', session()->get('mcs_user_email'));
    }

    public function testWylaczoneKontoWylogowuje(): void
    {
        $id = (int) model(UserModel::class)->insert([
            'email'         => 'ops@example.local',
            'password_hash' => password_hash('sekretne1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 0,
        ], true);
        session()->set('mcs_user_id', $id);

        $wynik = (new McsAuthFilter())->before($this->zrobRequest('/'));

        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $wynik);
        $this->assertNull(session()->get('mcs_user_id'));
    }

    private function zrobRequest(string $path): IncomingRequest
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';

        $uri = new SiteURI($config, ltrim($path, '/'));

        return new IncomingRequest($config, $uri, 'php://input', new \CodeIgniter\HTTP\UserAgent());
    }
}
