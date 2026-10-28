<?php

use App\Filters\McsBadgeTokenFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Mcs;

/**
 * @internal
 */
final class McsBadgeTokenFilterTest extends CIUnitTestCase
{
    private string $token = 'test-badge-token-nie-prod-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(Mcs::class)->badgeToken = $this->token;
    }

    public function testBezTokenuZwraca401(): void
    {
        $wynik = (new McsBadgeTokenFilter())->before($this->zrobRequest());

        $this->assertInstanceOf(Response::class, $wynik);
        $this->assertSame(401, $wynik->getStatusCode());
    }

    public function testZlyTokenZwraca401(): void
    {
        $request = $this->zrobRequest();
        $request->setHeader('Authorization', 'Bearer zly-token');

        $wynik = (new McsBadgeTokenFilter())->before($request);

        $this->assertInstanceOf(Response::class, $wynik);
        $this->assertSame(401, $wynik->getStatusCode());
    }

    public function testBearerPrzepuszcza(): void
    {
        $request = $this->zrobRequest();
        $request->setHeader('Authorization', 'Bearer ' . $this->token);

        $this->assertNull((new McsBadgeTokenFilter())->before($request));
    }

    public function testNaglowekXMcsBadgeTokenPrzepuszcza(): void
    {
        $request = $this->zrobRequest();
        $request->setHeader('X-Mcs-Badge-Token', $this->token);

        $this->assertNull((new McsBadgeTokenFilter())->before($request));
    }

    public function testPustyTokenWKonfiguracjiZawsze401(): void
    {
        config(Mcs::class)->badgeToken = '';

        $request = $this->zrobRequest();
        $request->setHeader('Authorization', 'Bearer cokolwiek');

        $wynik = (new McsBadgeTokenFilter())->before($request);

        $this->assertInstanceOf(Response::class, $wynik);
        $this->assertSame(401, $wynik->getStatusCode());
    }

    private function zrobRequest(): IncomingRequest
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';

        $uri = new SiteURI($config, 'api/badge');

        return new IncomingRequest($config, $uri, 'php://input', new \CodeIgniter\HTTP\UserAgent());
    }

    public function testTokenPrzykladowyIKrotkiNieOtwieraApi(): void
    {
        foreach (['ZMIEN_TOKEN', 'zmien_token', 'krotki-token', str_repeat('a', 31)] as $slaby) {
            $this->assertFalse(McsBadgeTokenFilter::tokenDopuszczalny($slaby), $slaby);

            config(Mcs::class)->badgeToken = $slaby;
            $request = $this->zrobRequest();
            $request->setHeader('Authorization', 'Bearer ' . $slaby);
            $wynik = (new McsBadgeTokenFilter())->before($request);

            $this->assertInstanceOf(Response::class, $wynik);
            $this->assertSame(401, $wynik->getStatusCode());
        }

        $this->assertTrue(McsBadgeTokenFilter::tokenDopuszczalny(str_repeat('a', 32)));
    }
}
