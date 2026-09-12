<?php

use App\Libraries\Enrichment\AbuseipdbClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AbuseipdbClientTest extends CIUnitTestCase
{
    public function testBezKluczaNieWolaHttp(): void
    {
        $wywolano = false;
        $client   = new AbuseipdbClient('', 'https://api.abuseipdb.com/api/v2', 90, true, static function () use (&$wywolano) {
            $wywolano = true;

            return ['ok' => true, 'http_code' => 200, 'data' => [], 'error' => '', 'raw_body' => ''];
        });

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('apiKey', $wynik['error']);
        $this->assertFalse($wywolano);
    }

    public function testCheckIpPrzekazujeKeyIQuery(): void
    {
        $zlapanyUrl     = null;
        $zlapaneHeaders = null;

        $client = new AbuseipdbClient('test-key', 'https://api.abuseipdb.com/api/v2', 90, true, static function (string $url, array $headers) use (&$zlapanyUrl, &$zlapaneHeaders) {
            $zlapanyUrl     = $url;
            $zlapaneHeaders = $headers;

            return [
                'ok'        => true,
                'http_code' => 200,
                'data'      => [
                    'data' => [
                        'ipAddress'            => '198.51.100.10',
                        'abuseConfidenceScore' => 0,
                        'totalReports'         => 0,
                    ],
                ],
                'error'     => '',
                'raw_body'  => '{}',
            ];
        });

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertNotNull($zlapanyUrl);
        $this->assertStringContainsString('ipAddress=198.51.100.10', (string) $zlapanyUrl);
        $this->assertStringContainsString('maxAgeInDays=90', (string) $zlapanyUrl);
        $this->assertSame('test-key', $zlapaneHeaders['Key'] ?? null);
        $this->assertSame('application/json', $zlapaneHeaders['Accept'] ?? null);
    }

    public function testNieprawidlowyIp(): void
    {
        $client = new AbuseipdbClient('k', 'https://example.test', 30, false, static function () {
            return ['ok' => true, 'http_code' => 200, 'data' => [], 'error' => '', 'raw_body' => ''];
        });

        $wynik = $client->checkIp('not-an-ip');
        $this->assertFalse($wynik['ok']);
        $this->assertSame('invalid ip', $wynik['error']);
    }
}
