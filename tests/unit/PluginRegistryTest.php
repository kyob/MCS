<?php

use App\Libraries\PluginRegistry;
use App\Libraries\SourcePluginInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PluginRegistryTest extends CIUnitTestCase
{
    private string $katalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->katalog = sys_get_temp_dir() . '/mcs-pluginy-' . uniqid('', true);
        mkdir($this->katalog, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->usunDrzewo($this->katalog);
        parent::tearDown();
    }

    public function testPustyKatalogNieLadujePluginow(): void
    {
        $rejestr = new PluginRegistry($this->katalog);

        $this->assertSame([], $rejestr->zaladuj());
    }

    public function testLadujePluginZKataloguZrodla(): void
    {
        $this->zapiszPlugin('przyklad');

        $rejestr = new PluginRegistry($this->katalog);
        $pluginy = $rejestr->zaladuj();

        $this->assertCount(1, $pluginy);
        $this->assertInstanceOf(SourcePluginInterface::class, $pluginy[0]);
        $this->assertSame('przyklad', $pluginy[0]->zrodlo());
        $this->assertSame(['ok' => false, 'msg' => 'brak importera'], $pluginy[0]->health());
    }

    public function testPobierzRzucaNotImplemented(): void
    {
        $this->zapiszPlugin('przyklad');

        $plugin = (new PluginRegistry($this->katalog))->zaladuj()[0];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not implemented');
        $plugin->pobierz();
    }

    public function testPomijaKatalogBezPlikuPlugin(): void
    {
        mkdir($this->katalog . '/cien', 0777, true);
        file_put_contents($this->katalog . '/README.md', 'nie plugin');

        $this->assertSame([], (new PluginRegistry($this->katalog))->zaladuj());
    }

    public function testProdukcyjnePluginyZwracajaOpisPolaczeniaBezSekretow(): void
    {
        $pluginy = (new PluginRegistry())->zaladuj();
        $this->assertNotEmpty($pluginy);

        $zrodla = [];
        foreach ($pluginy as $plugin) {
            $opis = $plugin->opisPolaczenia();
            $zrodla[] = $plugin->zrodlo();
            $this->assertArrayHasKey('skrot', $opis);
            $this->assertArrayHasKey('pozycje', $opis);
            $this->assertNotSame('', $opis['skrot']);
            $json = json_encode($opis, JSON_UNESCAPED_SLASHES);
            $this->assertIsString($json);
            $this->assertDoesNotMatchRegularExpression('/tryb=fixture/', $json);
        }

        $this->assertContains('lms_mapowanie', $zrodla);
        $this->assertContains('play', $zrodla);
        $this->assertContains('spoofer', $zrodla);
        $this->assertContains('speedtest', $zrodla);
    }

    private function zapiszPlugin(string $zrodlo): void
    {
        $dir = $this->katalog . '/' . $zrodlo;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/Plugin.php', <<<PHP
<?php

namespace McsPluginy\\{$zrodlo};

use App\\Libraries\\SourcePluginInterface;
use RuntimeException;

class Plugin implements SourcePluginInterface
{
    public function zrodlo(): string
    {
        return '{$zrodlo}';
    }

    public function pobierz(): void
    {
        throw new RuntimeException('not implemented');
    }

    public function health(): array
    {
        return ['ok' => false, 'msg' => 'brak importera'];
    }

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
