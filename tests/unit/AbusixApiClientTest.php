<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\abusix\ApiClient;
use McsPluginy\abusix\Plugin;

/**
 * @internal
 */
final class AbusixApiClientTest extends CIUnitTestCase
{
    public function testBezTokenaBrakAuth(): void
    {
        $this->assertFalse((new ApiClient('https://api.abusix.com', ''))->hasAuth());
    }

    public function testListCasesWolaDokumentowanyEndpointZBeareremICursorem(): void
    {
        $wolane = [];
        $client = new ApiClient('https://api.abusix.com/', 'tok', 30, static function (string $url, array $meta) use (&$wolane): array {
            $wolane[] = [$url, $meta];

            return ['ok' => true, 'http_code' => 200, 'body' => '{"items":[{"id":"C-1","state":"NEW"}],"next_cursor":"abc"}', 'error' => ''];
        });

        $res = $client->listCases(50, 'cur1');

        $this->assertTrue($res['ok']);
        $this->assertSame('C-1', $res['data']['items'][0]['id']);
        $this->assertSame('https://api.abusix.com/beta/guardian-ops/cases?limit=50&cursor=cur1', $wolane[0][0]);
        $this->assertSame('tok', $wolane[0][1]['token']);
    }

    public function testBladHttpZwracaError(): void
    {
        $client = new ApiClient('https://api.abusix.com', 'tok', 30, static fn (): array => ['ok' => false, 'http_code' => 401, 'body' => '', 'error' => 'HTTP 401']);

        $res = $client->listCustomers();

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['rate_limited']);
        $this->assertSame('HTTP 401', $res['error']);
    }

    public function test429ZwracaRetryAfterBezPonowien(): void
    {
        $licznik = 0;
        $client  = new ApiClient('https://api.abusix.com', 'tok', 30, static function () use (&$licznik): array {
            $licznik++;

            return ['ok' => false, 'http_code' => 429, 'body' => '', 'error' => 'HTTP 429', 'retry_after' => 120];
        });

        $res = $client->listEvents();

        $this->assertTrue($res['rate_limited']);
        $this->assertSame(120, $res['retry_after']);
        $this->assertSame(1, $licznik);
    }

    public function testMapujZdarzenie(): void
    {
        $m = Plugin::mapujZdarzenie(['event_id' => 'E-1', 'event_type' => 'spam', 'event_date' => '2026-10-08T10:00:00Z', 'ip' => '192.0.2.1', 'sender_email' => 'a@b.pl']);

        $this->assertSame('event:E-1', $m['klucz_dedupu']);
        $this->assertSame('192.0.2.1', $m['ip']);
        $this->assertStringStartsWith('2026-10-08 10:00:00', $m['zaobserwowano_at']);
        $this->assertSame('', Plugin::mapujZdarzenie(['event_id' => 'E-2', 'event_date' => '2026-10-08T10:00:00Z', 'ip' => 'zly'])['ip']);
        $this->assertNull(Plugin::mapujZdarzenie(['event_type' => 'x']));
    }
}
