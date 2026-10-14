<?php

use App\Libraries\Dnsbl\LimitySkanuWww;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class LimitySkanuWwwTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $plik;

    protected function setUp(): void
    {
        parent::setUp();
        $t = $this->db->prefixTable('mcs_dnsbl_skany');
        $this->db->query("DROP TABLE IF EXISTS {$t}");
        $this->db->query("CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTOINCREMENT, zakres TEXT, tryb TEXT DEFAULT 'cli',
            status TEXT DEFAULT 'oczekuje', utworzono_at TEXT NOT NULL, pary TEXT NULL)");
        $this->plik = sys_get_temp_dir() . '/mcs-limity-test-' . getmypid() . '.lock';
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_dnsbl_skany'));
        @unlink($this->plik);
        parent::tearDown();
    }

    public function testDomyslneLimity(): void
    {
        $this->assertSame(4096, LimitySkanuWww::maksIp());
        $this->assertSame(20, LimitySkanuWww::limitDoba());
    }

    public function testLimitLiczbyIp(): void
    {
        $l = new LimitySkanuWww($this->db, $this->plik);
        $this->assertSame('', $l->bladLiczbyIp(4096, 4096));
        $this->assertStringContainsString('4096', $l->bladLiczbyIp(4097, 4096));
    }

    public function testLimitDobyLiczyTylkoWwwZOstatnich24h(): void
    {
        $teraz = 1_800_000_000;
        $l     = new LimitySkanuWww($this->db, $this->plik);
        $b     = $this->db->table('mcs_dnsbl_skany');
        $b->insert(['zakres' => 'a', 'tryb' => 'web', 'utworzono_at' => gmdate('Y-m-d H:i:sP', $teraz - 3600)]);
        $b->insert(['zakres' => 'b', 'tryb' => 'web', 'utworzono_at' => gmdate('Y-m-d H:i:sP', $teraz - 7200)]);
        $b->insert(['zakres' => 'c', 'tryb' => 'web', 'utworzono_at' => gmdate('Y-m-d H:i:sP', $teraz - 90000)]);
        $b->insert(['zakres' => 'd', 'tryb' => 'noc', 'utworzono_at' => gmdate('Y-m-d H:i:sP', $teraz - 60)]);

        $this->assertSame(2, $l->liczbaDoba($teraz));
        $this->assertSame('', $l->bladLimituDoby(3, $teraz));
        $this->assertNotSame('', $l->bladLimituDoby(2, $teraz));
    }

    public function testSekcjaKrytycznaJestWylaczna(): void
    {
        $l = new LimitySkanuWww($this->db, $this->plik);

        $wynik = $l->wSekcjiKrytycznej(function () {
            // Drugi uchwyt (jak drugi proces PHP-FPM) nie dostaje blokady.
            $fh = fopen($this->plik, 'c');
            $ok = flock($fh, LOCK_EX | LOCK_NB);
            fclose($fh);

            return $ok;
        });

        $this->assertFalse($wynik);

        // Po wyjściu blokada jest zwolniona.
        $fh = fopen($this->plik, 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
