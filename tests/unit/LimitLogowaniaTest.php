<?php

use App\Controllers\Auth;
use App\Libraries\LimitLogowania;
use App\Models\UserModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;

/**
 * @internal
 */
final class LimitLogowaniaTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private Throttler $throttler;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION        = [];
        $this->throttler = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_800_000_000);
        Services::injectMock('throttler', $this->throttler);

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
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    public function testPiecProbPotemBlokadaPoEmailu(): void
    {
        $limit = new LimitLogowania($this->throttler);

        for ($i = 0; $i < LimitLogowania::PROBY; $i++) {
            // Różne IP: liczy się kubełek e-maila.
            $this->assertTrue($limit->pozwol('198.51.100.' . $i, 'Ofiara@example.net'));
        }
        $this->assertFalse($limit->pozwol('198.51.100.99', 'ofiara@example.net'));
        $this->assertTrue($limit->pozwol('198.51.100.99', 'inny@example.net'));
    }

    public function testPiecProbPotemBlokadaPoIp(): void
    {
        $limit = new LimitLogowania($this->throttler);

        for ($i = 0; $i < LimitLogowania::PROBY_IP; $i++) {
            $this->assertTrue($limit->pozwol('203.0.113.7', 'u' . $i . '@example.net'));
        }
        $this->assertFalse($limit->pozwol('203.0.113.7', 'nowy@example.net'));
        $this->assertTrue($limit->pozwol('203.0.113.8', 'nowy@example.net'));
    }

    public function testTokenWracaPoOknie(): void
    {
        $limit = new LimitLogowania($this->throttler);
        for ($i = 0; $i < LimitLogowania::PROBY; $i++) {
            $limit->pozwol('203.0.113.9', 'x@example.net');
        }
        $this->assertFalse($limit->pozwol('203.0.113.9', 'x@example.net'));

        $this->throttler->setTestTime(1_800_000_000 + LimitLogowania::OKNO);
        $this->assertTrue($limit->pozwol('203.0.113.9', 'x@example.net'));
    }

    public function testKluczBezZnakowZastrzezonych(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', LimitLogowania::klucz('em', 'a@b:c/(d)'));
    }

    public function testTenSamKomunikatDlaBrakuKontaIZlegoHasla(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        $this->zaloguj('brak@example.net', 'cokolwiek1');
        $brak = session()->getFlashdata('blad');
        $this->zaloguj('jest@example.net', 'zlehaslo11');
        $zle = session()->getFlashdata('blad');

        $this->assertSame('Nieprawidłowy e-mail lub hasło.', $brak);
        $this->assertSame($brak, $zle);
    }

    public function testSzostaProbaZablokowanaNawetZDobrymHaslem(): void
    {
        model(UserModel::class)->insert([
            'email'         => 'jest@example.net',
            'password_hash' => password_hash('dobrehaslo1', PASSWORD_DEFAULT),
            'rola'          => 'operator',
            'aktywny'       => 1,
        ]);

        for ($i = 0; $i < LimitLogowania::PROBY; $i++) {
            $this->zaloguj('jest@example.net', 'zlehaslo11');
        }
        $this->zaloguj('jest@example.net', 'dobrehaslo1');

        $this->assertSame(Auth::KOMUNIKAT_LIMIT, session()->getFlashdata('blad'));
        $this->assertNull(session()->get('mcs_user_id'));
    }

    private function zaloguj(string $email, string $haslo): void
    {
        $_SESSION = [];
        $request = service('incomingrequest', null, false);
        $request->setMethod('post');
        $request->setGlobal('post', ['email' => $email, 'haslo' => $haslo]);

        $this->withRequest($request)->controller(Auth::class)->execute('loginSubmit');
    }
}
