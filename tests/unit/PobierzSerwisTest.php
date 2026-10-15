<?php

use App\Libraries\PluginRegistry;
use App\Libraries\PobierzSerwis;
use App\Models\PluginModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PobierzSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $katalogPluginow;
    private string $katalogBlokad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->katalogPluginow = sys_get_temp_dir() . '/mcs-pobierz-plugins-' . uniqid('', true);
        $this->katalogBlokad   = sys_get_temp_dir() . '/mcs-pobierz-locks-' . uniqid('', true);
        mkdir($this->katalogPluginow, 0777, true);
        mkdir($this->katalogBlokad, 0777, true);

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
            'zrodlo'            => 'demo',
            'nazwa'             => 'Demo',
            'import_wstrzymany' => 0,
            'ukryty_w_ui'       => 0,
            'health_msg'        => null,
        ]);
        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'wstrzymany',
            'nazwa'             => 'Wstrzymany',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
            'health_msg'        => null,
        ]);
        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'bladny',
            'nazwa'             => 'Błędny',
            'import_wstrzymany' => 0,
            'ukryty_w_ui'       => 0,
            'health_msg'        => null,
        ]);

        $this->zapiszPlugin('demo', false);
        $this->zapiszPlugin('wstrzymany', false);
        $this->zapiszPlugin('bladny', true);
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->usunDrzewo($this->katalogPluginow);
        $this->usunDrzewo($this->katalogBlokad);
        parent::tearDown();
    }

    public function testUruchomPomijaWstrzymanyImport(): void
    {
        $serwis = $this->serwis();
        $wyniki = $serwis->uruchom('wstrzymany');

        $this->assertCount(1, $wyniki);
        $this->assertSame('wstrzymany', $wyniki[0]['zrodlo']);
        $this->assertSame('pominiety', $wyniki[0]['status']);
        $this->assertStringContainsString('import_wstrzymany', $wyniki[0]['msg']);
    }

    public function testUruchomOkDlaAktywnegoZrodla(): void
    {
        $serwis = $this->serwis();
        $wyniki = $serwis->uruchom('demo');

        $this->assertCount(1, $wyniki);
        $this->assertSame('demo', $wyniki[0]['zrodlo']);
        $this->assertSame('ok', $wyniki[0]['status']);
        $this->assertStringContainsString('demo-ok', $wyniki[0]['msg']);
    }

    public function testBrakKoduWtyczki(): void
    {
        $serwis = $this->serwis();
        $wyniki = $serwis->uruchom('nieistnieje');

        $this->assertCount(1, $wyniki);
        $this->assertSame('blad', $wyniki[0]['status']);
        $this->assertStringContainsString('Brak kodu wtyczki', $wyniki[0]['msg']);
    }

    public function testZajetaBlokadaNieUruchamiaDrugiegoPobierania(): void
    {
        $plik = $this->katalogBlokad . '/mcs-pobierz-demo.lock';
        $fh   = fopen($plik, 'c+');
        $this->assertNotFalse($fh);
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));

        try {
            $wyniki = $this->serwis()->uruchom('demo');
            $this->assertCount(1, $wyniki);
            $this->assertSame('zajety', $wyniki[0]['status']);
            $this->assertStringContainsString('już trwa', $wyniki[0]['msg']);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function testAllUruchamiaTylkoAktywne(): void
    {
        $wyniki = $this->serwis()->uruchom('all');
        $mapa   = [];
        foreach ($wyniki as $w) {
            $mapa[$w['zrodlo']] = $w['status'];
        }

        $this->assertSame('ok', $mapa['demo']);
        $this->assertSame('pominiety', $mapa['wstrzymany']);
    }

    public function testUruchomZwracaBladGdyPluginRzuca(): void
    {
        $wyniki = $this->serwis()->uruchom('bladny');

        $this->assertCount(1, $wyniki);
        $this->assertSame('blad', $wyniki[0]['status']);
        $this->assertSame('boom', $wyniki[0]['msg']);
    }

    public function testPominieciePrzezHarmonogramPokazujeKomunikatZamiastHealth(): void
    {
        $this->db->table('mcs_pluginy')->insert(['zrodlo' => 'harm', 'nazwa' => 'Harm', 'import_wstrzymany' => 0, 'ukryty_w_ui' => 0]);
        $this->zapiszPlugin('harm', false, "    public function komunikatPominiecia(): ?string\n    {\n        return 'pominięto (następne po 2026-10-15 03:12 UTC)';\n    }\n");

        $wyniki = $this->serwis()->uruchom('harm');

        $this->assertSame('pominiety', $wyniki[0]['status']);
        $this->assertSame('pominięto (następne po 2026-10-15 03:12 UTC)', $wyniki[0]['msg']);
    }

    public function testZlecWTleZlecaZarejestrowaneZrodlo(): void
    {
        $s = $this->serwisTlo();
        $w = $s->zlecWTle('demo');

        $this->assertSame('zlecono', $w['status']);
        $this->assertCount(1, $s->polecenia);
        $this->assertStringContainsString('/usr/bin/setsid -f', $s->polecenia[0]);
        $this->assertStringContainsString("mcs:pobierz 'demo'", $s->polecenia[0]);
    }

    public function testZlecWTleOdrzucaNieznaneZrodloIWstrzykniecie(): void
    {
        $s = $this->serwisTlo();
        foreach (['nieistnieje', 'demo; rm -rf /', '$(id)', 'all', '../demo', ''] as $zle) {
            $w = $s->zlecWTle($zle);
            $this->assertSame('blad', $w['status'], $zle);
        }
        $this->assertSame([], $s->polecenia);
    }

    public function testZlecWTlePomijaWstrzymanyIZajety(): void
    {
        $s = $this->serwisTlo();
        $this->assertSame('pominiety', $s->zlecWTle('wstrzymany')['status']);

        $fh = fopen($this->katalogBlokad . '/mcs-pobierz-demo.lock', 'c+');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));

        try {
            $this->assertSame('zajety', $s->zlecWTle('demo')['status']);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->assertSame([], $s->polecenia);
    }

    private function serwisTlo(): PobierzSerwis
    {
        return new class (new PluginRegistry($this->katalogPluginow), model(PluginModel::class), $this->katalogBlokad) extends PobierzSerwis {
            /** @var list<string> */
            public array $polecenia = [];

            protected function wykonajPolecenie(string $cmd): int
            {
                $this->polecenia[] = $cmd;

                return 0;
            }
        };
    }

    private function serwis(): PobierzSerwis
    {
        return new PobierzSerwis(
            new PluginRegistry($this->katalogPluginow),
            model(PluginModel::class),
            $this->katalogBlokad,
        );
    }

    private function zapiszPlugin(string $zrodlo, bool $rzucBlad, string $dodatek = ''): void
    {
        $dir = $this->katalogPluginow . '/' . $zrodlo;
        mkdir($dir, 0777, true);
        $cialo = $rzucBlad
            ? "throw new \\RuntimeException('boom');"
            : '// ok';

        file_put_contents($dir . '/Plugin.php', <<<PHP
<?php

namespace McsPluginy\\{$zrodlo};

use App\\Libraries\\SourcePluginInterface;

class Plugin implements SourcePluginInterface
{
    public function zrodlo(): string
    {
        return '{$zrodlo}';
    }

    public function pobierz(): void
    {
        {$cialo}
    }

    public function health(): array
    {
        return ['ok' => true, 'msg' => '{$zrodlo}-ok'];
    }

{$dodatek}
    public function opisPolaczenia(): array
    {
        return [
            'skrot'         => 'brak konfiguracji',
            'tryb_aktywny'  => 'brak',
            'tryb_etykieta' => 'brak konfiguracji',
            'pozycje'       => [],
        ];
    }
}
PHP);
    }

    private function usunDrzewo(string $sciezka): void
    {
        if (! is_dir($sciezka)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sciezka, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $plik) {
            $plik->isDir() ? rmdir($plik->getPathname()) : unlink($plik->getPathname());
        }

        rmdir($sciezka);
    }
}
