<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\abusix\ApiClient;

/**
 * @internal
 */
final class AbusixApiClientTest extends CIUnitTestCase
{
    public function testBezTokenaBrakAuth(): void
    {
        $this->assertFalse((new ApiClient('https://api.abusix.com', ''))->hasAuth());
    }

    public function testListCasesWolaDokumentowanyEndpointZBearerem(): void
    {
        $wolane = [];
        $client = new ApiClient('https://api.abusix.com/', 'tok', 30, static function (string $url, array $meta) use (&$wolane): array {
            $wolane[] = [$url, $meta];

            return ['ok' => true, 'http_code' => 200, 'body' => '{"items":[{"id":"C-1","state":"open"}]}', 'error' => ''];
        });

        $res = $client->listCases('open', 50);

        $this->assertTrue($res['ok']);
        $this->assertSame('C-1', $res['data']['items'][0]['id']);
        $this->assertSame('https://api.abusix.com/beta/guardian-ops/cases?limit=50&state=open', $wolane[0][0]);
        $this->assertSame('tok', $wolane[0][1]['token']);
    }

    public function testBladHttpZwracaError(): void
    {
        $client = new ApiClient('https://api.abusix.com', 'tok', 30, static fn (): array => ['ok' => false, 'http_code' => 401, 'body' => '', 'error' => 'HTTP 401']);

        $res = $client->listCustomers();

        $this->assertFalse($res['ok']);
        $this->assertSame('HTTP 401', $res['error']);
    }
}
