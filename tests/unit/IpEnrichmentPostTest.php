<?php

use App\Controllers\Ip;
use CodeIgniter\Config\Services;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Filters;

/**
 * GET ip/{ip}/enrichment/* czyta tylko cache; zapytanie do API tylko POST z CSRF.
 *
 * @internal
 */
final class IpEnrichmentPostTest extends CIUnitTestCase
{
    private function atrapa(): object
    {
        return new class () {
            public int $sprawdz = 0;
            public int $podglad = 0;
            public ?array $cache = null;
            public ?bool $ostatniForce = null;

            public function podgladCache(string $ip): ?array
            {
                $this->podglad++;

                return $this->cache;
            }

            public function sprawdz(string $ip, bool $force = false): array
            {
                $this->sprawdz++;
                $this->ostatniForce = $force;

                return ['ok' => true, 'z_cache' => false, 'tryb' => 'api', 'blad' => null, 'ip' => $ip, 'wynik' => ['x' => 1]];
            }
        };
    }

    private function kontroler(object $atrapa, string $metoda, array $post = []): Ip
    {
        $kontroler = new class ($atrapa) extends Ip {
            public function __construct(private object $atrapa)
            {
            }

            protected function nowySerwis(string $klasa): object
            {
                return $this->atrapa;
            }
        };

        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';
        $request         = new IncomingRequest($config, new SiteURI($config, 'ip/198.51.100.10/enrichment/abuseipdb'), 'php://input', new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod($metoda);
        foreach ($post as $k => $v) {
            $request->setGlobal('post', array_merge($request->getPost() ?? [], [$k => $v]));
        }
        $kontroler->initController($request, Services::response(), Services::logger());

        return $kontroler;
    }

    public function testGetNieWywolujeKlientaApiPrzyBrakuCache(): void
    {
        $a   = $this->atrapa();
        $res = $this->kontroler($a, 'GET')->abuseipdb('198.51.100.10');

        $this->assertSame(0, $a->sprawdz);
        $this->assertSame(1, $a->podglad);
        $this->assertSame(404, $res->getStatusCode());
        $this->assertFalse(json_decode($res->getBody(), true)['ok']);
    }

    public function testGetZForceNieWywolujeKlientaApi(): void
    {
        $a   = $this->atrapa();
        $res = $this->kontroler($a, 'GET')->ismalicious('198.51.100.10');

        $this->assertSame(0, $a->sprawdz);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGetZwracaCacheBezApi(): void
    {
        $a        = $this->atrapa();
        $a->cache = ['abuse_confidence_score' => 5];
        $res      = $this->kontroler($a, 'GET')->abuseipdb('198.51.100.10');
        $j        = json_decode($res->getBody(), true);

        $this->assertSame(0, $a->sprawdz);
        $this->assertTrue($j['ok']);
        $this->assertTrue($j['z_cache']);
        $this->assertSame(5, $j['wynik']['abuse_confidence_score']);
    }

    public function testGetNieWywolujeApiDlaWszystkichZrodel(): void
    {
        foreach (['abuseipdb', 'ismalicious', 'spamrats', 'dnsbl', 'lms', 'greynoise'] as $akcja) {
            $a = $this->atrapa();
            $this->kontroler($a, 'GET')->{$akcja}('198.51.100.10');
            $this->assertSame(0, $a->sprawdz, $akcja);
        }
    }

    public function testPostWywolujeSprawdzBezForce(): void
    {
        $a   = $this->atrapa();
        $res = $this->kontroler($a, 'POST')->abuseipdb('198.51.100.10');

        $this->assertSame(1, $a->sprawdz);
        $this->assertFalse($a->ostatniForce);
        $this->assertTrue(json_decode($res->getBody(), true)['ok']);
        $this->assertTrue($res->hasHeader('X-Mcs-Csrf'));
    }

    public function testPostZForceWywolujeSprawdzZForce(): void
    {
        $a = $this->atrapa();
        $this->kontroler($a, 'POST', ['force' => '1'])->abuseipdb('198.51.100.10');

        $this->assertTrue($a->ostatniForce);
    }

    public function testPostBezTokenuCsrfJestOdrzucony(): void
    {
        $config          = config(App::class);
        $config->baseURL = 'http://example.com/';
        $request         = new IncomingRequest($config, new SiteURI($config, 'ip/198.51.100.10/enrichment/abuseipdb'), 'php://input', new \CodeIgniter\HTTP\UserAgent());
        $request->setMethod('POST');

        $this->expectException(SecurityException::class);
        (new CSRF())->before($request);
    }

    public function testFiltrCsrfObejmujeTrasyEnrichment(): void
    {
        $f = new Filters();
        $this->assertArrayHasKey('csrf', $f->globals['before']);
        $wyjatki = $f->globals['before']['csrf']['except'];
        foreach ($wyjatki as $w) {
            $this->assertStringStartsNotWith('ip', $w);
        }
        $this->assertSame(['api/*'], $wyjatki);
    }
}
