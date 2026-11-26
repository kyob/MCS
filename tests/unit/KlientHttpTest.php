<?php

use App\Libraries\Siec\KlientHttp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class KlientHttpTest extends CIUnitTestCase
{
    /** @var resource|null */
    private static $serwer;

    private static string $baza = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $gniazdo = @stream_socket_server('tcp://127.0.0.1:0');
        if ($gniazdo === false) {
            return;
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($gniazdo, false), ':'), 1);
        fclose($gniazdo);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, SUPPORTPATH . 'http_router.php'];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rury);
        if (! is_resource($proc)) {
            return;
        }
        self::$serwer = $proc;

        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $nr, $opis, 0.1);
            if ($s !== false) {
                fclose($s);
                self::$baza = 'http://127.0.0.1:' . $port;

                return;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serwer)) {
            proc_terminate(self::$serwer);
            proc_close(self::$serwer);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$baza === '') {
            $this->markTestSkipped('Nie udało się uruchomić lokalnego serwera HTTP.');
        }
    }

    public function testGetZwracaTrescKodINaglowki(): void
    {
        $res = KlientHttp::wykonaj(self::$baza . '/ok', ['timeout' => 5]);

        $this->assertTrue($res['ok']);
        $this->assertSame(200, $res['http_code']);
        $this->assertSame('hello', $res['body']);
        $this->assertSame(7, KlientHttp::retryAfter($res['naglowki']));
    }

    public function testKod404ToUdanyTransfer(): void
    {
        $res = KlientHttp::wykonaj(self::$baza . '/nie-ma', ['timeout' => 5]);

        $this->assertTrue($res['ok']);
        $this->assertSame(404, $res['http_code']);
    }

    public function testPostZTrescIBasicAuth(): void
    {
        $res = KlientHttp::wykonaj(self::$baza . '/echo', [
            'metoda'  => 'POST',
            'body'    => '{"a":1}',
            'basic'   => ['ops', 'tajne'],
            'timeout' => 5,
        ]);

        $this->assertSame('POST:{"a":1}:ops', $res['body']);
    }

    public function testZastojPrzerywaTransferPrzedLimitemCalkowitym(): void
    {
        $start = microtime(true);
        $res   = KlientHttp::wykonaj(self::$baza . '/wolno', ['timeout' => 30, 'zastoj' => 1]);

        $this->assertFalse($res['ok']);
        $this->assertNotSame('', $res['error']);
        $this->assertGreaterThan(0, $res['errno']);
        $this->assertLessThan(4, microtime(true) - $start);
    }

    public function testBezZastojuWolnyTransferKonczySie(): void
    {
        $res = KlientHttp::wykonaj(self::$baza . '/wolno', ['timeout' => 10]);

        $this->assertTrue($res['ok']);
        $this->assertSame('xyyyyyyyyy', $res['body']);
    }

    public function testBladPolaczeniaZwracaSurowyKomunikat(): void
    {
        $res = KlientHttp::wykonaj('http://127.0.0.1:1/', ['timeout' => 2]);

        $this->assertFalse($res['ok']);
        $this->assertStringNotContainsString('cURL:', $res['error']);
        $this->assertNotSame('', $res['error']);
    }
}
