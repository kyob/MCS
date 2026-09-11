<?php

use App\Filters\McsAuthFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * @internal
 */
final class McsAuthFilterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testBezSesjiPrzekierowujeNaLogin(): void
    {
        $request = $this->zrobRequest('/ip/198.51.100.10');
        $wynik   = (new McsAuthFilter())->before($request);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $wynik);
        $this->assertSame('/ip/198.51.100.10', session()->get('mcs_po_logowaniu'));
    }

    public function testZeSesjaPrzepuszcza(): void
    {
        session()->set('mcs_user_id', 1);

        $request = $this->zrobRequest('/admin/pluginy');
        $wynik   = (new McsAuthFilter())->before($request);

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
