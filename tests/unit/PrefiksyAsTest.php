<?php

use App\Controllers\Admin\Ustawienia;
use App\Database\Migrations\CreateMcsUstawienia;
use App\Database\Migrations\ZmienKluczeAsNaNeutralne;
use App\Libraries\OpcjeMcs;
use App\Libraries\Siec\AsMatcher;
use App\Models\UstawieniaModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once APPPATH . 'Database/Migrations/2026-09-11-220001_CreateMcsUstawienia.php';
require_once APPPATH . 'Database/Migrations/2026-11-24-150001_ZmienKluczeAsNaNeutralne.php';

/** @internal */
final class PrefiksyAsTest extends CIUnitTestCase
{
    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $_ENV;
        foreach (['mcs.as.asn', 'mcs.as198050.asn', 'mcs.as.cidry', 'mcs.as198050.cidry'] as $klucz) {
            $_ENV[$klucz] = 'null';
        }
        OpcjeMcs::ustawModel(null);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        OpcjeMcs::ustawModel(null);
        parent::tearDown();
    }

    public static function konfiguracje(): array
    {
        $stare = ['mcs.as198050.cidry' => '192.0.2.0/24'];
        $nowe = ['mcs.as.cidry' => '2001:db8::/48'];

        return [
            'domyślny ASN dokumentacyjny' => [[], [], []],
            'wbudowane prefiksy AS198050' => [['mcs.as.asn' => '198050'], [], AsMatcher::DOMYSLNE_CIDRY],
            'alternatywny ASN bez prefiksów' => [['mcs.as.asn' => '64512'], [], []],
            'dawny klucz CIDR' => [['mcs.as.asn' => '198050'] + $stare, [], ['192.0.2.0/24']],
            'dawny klucz ASN, obce CIDR' => [['mcs.as198050.asn' => '64512'] + $stare, ['as198050_cidry' => '203.0.113.0/24'], []],
            'pierwszeństwo nowych kluczy' => [['mcs.as.asn' => '64512', 'mcs.as198050.asn' => '198050'] + $nowe + $stare, [], ['2001:db8::/48']],
            'dawny wiersz DB' => [['mcs.as.asn' => '198050'], ['as198050_cidry' => '203.0.113.0/24'], ['203.0.113.0/24']],
            'jawnie pusta lista DB' => [$nowe, ['as_cidry' => '', 'as198050_cidry' => '203.0.113.0/24'], []],
            'własne prefiksy DB' => [['mcs.as.asn' => '64512'] + $nowe, ['as_cidry' => '198.51.100.0/24'], ['198.51.100.0/24']],
        ];
    }

    #[DataProvider('konfiguracje')]
    public function testPrefiksy(array $env, array $db, array $oczekiwane): void
    {
        $_ENV = $env + $_ENV;
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static fn (string $klucz) => $db[$klucz] ?? null);
        $this->assertSame($oczekiwane, (new AsMatcher(null, $model))->cidry());
    }

    public function testMigracjaNiePrzypisujeStarychPrefiksowInnemuAs(): void
    {
        $tabela = new CreateMcsUstawienia();
        $tabela->up();
        try {
            $model = new UstawieniaModel();
            $this->assertNull($model->pobierz('as198050_cidry'));
            $model->ustaw('as198050_cidry', '192.0.2.0/24');
            $model->ustaw('mcs.as198050.asn', '64512');
            (new ZmienKluczeAsNaNeutralne())->up();
            OpcjeMcs::ustawModel($model);
            $this->assertSame(64512, AsMatcher::asn());
            $this->assertSame([], (new AsMatcher(null, $model))->cidry());
            $this->assertSame('192.0.2.0/24', $model->pobierz('as198050_cidry'));
            $this->assertSame('1', $model->pobierz('as_wlaczone'));
        } finally {
            $tabela->down();
        }
    }

    public function testAsnPluginowZPrzykladowymEnv(): void
    {
        $_ENV['mcs.as.asn'] = '64512';
        $env = file_get_contents(ROOTPATH . '.env.example');
        $client = $this->createMock(\McsPluginy\cloudflare_botnet\ApiClient::class);
        $client->expects($this->never())->method('listRegisteredAsns');
        $pluginy = [
            'spoofer' => new \McsPluginy\spoofer\Plugin(),
            'cloudflare_botnet' => new \McsPluginy\cloudflare_botnet\Plugin(apiClient: $client),
        ];
        foreach ($pluginy as $nazwa => $plugin) {
            $klucz = 'mcs.' . $nazwa . '.asn';
            $this->assertSame(1, preg_match("/^" . preg_quote($klucz, '/') . " = '([^']*)'$/m", $env, $m));
            $_ENV[$klucz] = $m[1];
            $this->assertSame(64512, $plugin->resolveAsn());
            $_ENV[$klucz] = '64513';
            $this->assertSame(64513, $plugin->resolveAsn());
        }
    }

    public function testCofniecieMigracjiPrzywracaAktualneUstawienia(): void
    {
        $tabela = new CreateMcsUstawienia();
        $tabela->up();
        try {
            $model = new UstawieniaModel();
            $migracja = new ZmienKluczeAsNaNeutralne();
            $model->ustaw('as198050_cidry', '192.0.2.0/24');
            $model->ustaw('mcs.as198050.asn', '198050');
            $model->ustaw('mcs.as.asn', '64512');
            $migracja->up();
            $model->ustaw('as_cidry', '198.51.100.0/24');
            $migracja->down();
            $this->assertSame('198.51.100.0/24', $model->pobierz('as198050_cidry'));
            $this->assertSame('64512', $model->pobierz('mcs.as198050.asn'));
            $migracja->up();
            $this->assertSame('198.51.100.0/24', $model->pobierz('as_cidry'));
            $model->ustaw('as_cidry', '');
            $migracja->down();
            $this->assertSame('', $model->pobierz('as198050_cidry'));
        } finally {
            $tabela->down();
        }
    }

    public function testPrzywrocenieAs198050(): void
    {
        $_ENV['mcs.as.asn'] = '198050';
        $model = $this->createMock(UstawieniaModel::class);
        $model->expects($this->once())->method('ustaw')
            ->with('as_cidry', implode("\n", AsMatcher::DOMYSLNE_CIDRY))->willReturn(true);
        Factories::injectMock('models', UstawieniaModel::class, $model);
        (new Ustawienia())->przywrocDomyslne();
        $this->assertStringContainsString('AS198050', session()->getFlashdata('komunikat'));
    }

    public function testPrzywrocenieAlternatywnegoAsZachowujeUstawienia(): void
    {
        $_ENV['mcs.as.asn'] = '64512';
        $model = $this->createMock(UstawieniaModel::class);
        $model->expects($this->never())->method('ustaw');
        Factories::injectMock('models', UstawieniaModel::class, $model);
        (new Ustawienia())->przywrocDomyslne();
        $this->assertStringContainsString('Brak domyślnych prefiksów dla AS64512', session()->getFlashdata('blad'));
    }
}
