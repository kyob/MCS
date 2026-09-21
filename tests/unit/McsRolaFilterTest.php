<?php

use App\Filters\McsRolaFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * @internal
 */
final class McsRolaFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testSuperadminPrzechodziDoUzytkownikow(): void
    {
        session()->set('mcs_user_rola', 'superadmin');

        $wynik = (new McsRolaFilter())->before($this->zrobRequest('/admin/uzytkownicy'), ['superadmin']);

        $this->assertNull($wynik);
    }

    public function testAdminDostaje403NaUzytkownikow(): void
    {
        session()->set('mcs_user_rola', 'admin');

        $wynik = (new McsRolaFilter())->before($this->zrobRequest('/admin/uzytkownicy'), ['superadmin']);

        $this->assertInstanceOf(Response::class, $wynik);
        $this->assertSame(403, $wynik->getStatusCode());
    }

    public function testOperatorDostaje403NaWtyczki(): void
    {
        session()->set('mcs_user_rola', 'operator');

        $wynik = (new McsRolaFilter())->before(
            $this->zrobRequest('/admin/pluginy'),
            ['admin', 'superadmin'],
        );

        $this->assertInstanceOf(Response::class, $wynik);
        $this->assertSame(403, $wynik->getStatusCode());
    }

    public function testAdminPrzechodziDoWtyczek(): void
    {
        session()->set('mcs_user_rola', 'admin');

        $wynik = (new McsRolaFilter())->before(
            $this->zrobRequest('/admin/pluginy'),
            ['admin', 'superadmin'],
        );

        $this->assertNull($wynik);
    }

    private function zrobRequest(string $path): IncomingRequest
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';

        $uri = new SiteURI($config, ltrim($path, '/'));

        return new IncomingRequest($config, $uri, 'php://input', new \CodeIgniter\HTTP\UserAgent());
    }
}
