<?php

use App\Filters\McsSecureHeaders;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cookie;
use Config\Filters;

/**
 * @internal
 */
final class BezpieczenstwoHttpTest extends CIUnitTestCase
{
    public function testNaglowkiBezpieczenstwa(): void
    {
        $response = new Response(new App());
        $request  = service('incomingrequest', null, false);

        (new McsSecureHeaders())->after($request, $response);

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testFiltrNaglowkowJestGlobalny(): void
    {
        $f = new Filters();
        $this->assertContains('secureheaders', $f->globals['after']);
        $this->assertSame(McsSecureHeaders::class, $f->aliases['secureheaders']);
    }

    public function testCiasteczkoSecureDomyslnie(): void
    {
        $r = new ReflectionClass(Cookie::class);
        $this->assertTrue($r->getDefaultProperties()['secure']);
    }

    public function testWylogowanieTylkoPost(): void
    {
        /** @var RouteCollection $routes */
        $routes = service('routes', false);
        $routes->resetRoutes();
        require APPPATH . 'Config/Routes.php';

        $this->assertArrayHasKey('logout', $routes->getRoutes('POST'));
        $this->assertArrayNotHasKey('logout', $routes->getRoutes('GET'));
    }

    public function testLayoutMaFormularzWylogowaniaISri(): void
    {
        $html = view('watchlista/index', [
            'title'      => 'Watchlista IP — MCS',
            'navAktywny' => 'watchlista',
            'email'      => 'a@example.net',
            'rola'       => 'operator',
            'komunikat'  => null,
            'blad'       => null,
            'asAsn'      => 198050,
            'lista'      => [],
        ]);

        $this->assertMatchesRegularExpression('#<form method="post" action="[^"]*logout"#', $html);
        $this->assertStringContainsString('name="csrf_test_name"', $html);
        $this->assertStringContainsString('sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+', $html);
    }

    public function testCookieSecureAutoDlaHttp(): void
    {
        $config         = config(Cookie::class);
        $config->secure = true;
        $request        = service('incomingrequest', null, false);

        (new \App\Filters\McsCookieSecureFilter())->before($request);

        $this->assertFalse(config(Cookie::class)->secure, 'HTTP bez proxy: brak flagi Secure zamiast HTTP 500');
        $config->secure = true;
    }

    public function testCookieSecureZostajeDlaHttps(): void
    {
        $config         = config(Cookie::class);
        $config->secure = true;
        $_SERVER['HTTPS'] = 'on';
        service('superglobals')->setServer('HTTPS', 'on');
        $request = service('incomingrequest', null, false);

        (new \App\Filters\McsCookieSecureFilter())->before($request);

        unset($_SERVER['HTTPS']);
        service('superglobals')->unsetServer('HTTPS');
        $this->assertTrue(config(Cookie::class)->secure);
    }

    public function testFiltrCookieSecurePrzedCsrf(): void
    {
        $g = (new Filters())->globals['before'];
        $k = array_map(static fn ($k, $v) => is_int($k) ? $v : $k, array_keys($g), $g);
        $this->assertLessThan(array_search('csrf', $k, true), array_search('mcsCookieSecure', $k, true));
    }
}
