<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\speedtest\PortalClient;

/**
 * @internal
 */
final class SpeedtestPortalClientTest extends CIUnitTestCase
{
    public function testBezCredentialsNieWolaHttp(): void
    {
        $wywolano = false;
        $client   = new PortalClient('', '', null, static function () use (&$wywolano) {
            $wywolano = true;

            return ['ok' => true, 'http_code' => 200, 'body' => '', 'error' => '', 'headers' => []];
        });

        $wynik = $client->pobierzCsvRaport();

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('email', $wynik['error']);
        $this->assertFalse($wywolano);
    }

    public function testLogowanieIPobranieCsv(): void
    {
        $wywolania = [];
        $client    = new PortalClient(
            'mcs@example.test',
            'sekret',
            null,
            static function (string $url, array $opts) use (&$wywolania) {
                $wywolania[] = ['url' => $url, 'opts' => $opts];
                $method      = strtoupper((string) ($opts['method'] ?? 'GET'));

                if (str_contains($url, 'oauth/token') && $method === 'POST') {
                    return [
                        'ok'        => true,
                        'http_code' => 200,
                        'body'      => json_encode([
                            'access_token' => 'tok-test',
                            'token_type'   => 'Bearer',
                            'expires_in'   => 3600,
                        ], JSON_THROW_ON_ERROR),
                        'error'     => '',
                        'headers'   => [],
                    ];
                }

                if (str_contains($url, '/api/servers/reports') && str_contains($url, 'format=csv')) {
                    return [
                        'ok'        => true,
                        'http_code' => 200,
                        'body'      => "testId,ipAddress,resultDate,download (Kbps)\n"
                            . "t-1,91.231.80.10,2026-09-16T10:00:00.000Z,1000\n",
                        'error'     => '',
                        'headers'   => [
                            'content-type'        => 'text/csv; charset=utf-8',
                            'content-disposition' => 'attachment; filename="speedtest-results.csv"',
                        ],
                    ];
                }

                return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'unexpected ' . $url, 'headers' => []];
            },
        );

        $wynik = $client->pobierzCsvRaport(
            new DateTimeImmutable('2026-08-17', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-19', new DateTimeZone('UTC')),
        );

        $this->assertTrue($wynik['ok'], $wynik['error'] ?? '');
        $this->assertStringContainsString('testId', $wynik['csv']);
        $this->assertStringContainsString('91.231.80.10', $wynik['csv']);
        $this->assertCount(2, $wywolania);

        $tokenBody = (string) ($wywolania[0]['opts']['body'] ?? '');
        $this->assertStringContainsString('mcs@example.test', $tokenBody);
        $this->assertStringContainsString('password-realm', $tokenBody);
        $this->assertStringContainsString('OOKLA-AUTH', $tokenBody);

        $auth = $wywolania[1]['opts']['headers']['Authorization']
            ?? $wywolania[1]['opts']['headers']['authorization']
            ?? '';
        $this->assertSame('Bearer tok-test', $auth);
        $this->assertStringContainsString('startDate=2026-08-17', $wywolania[1]['url']);
        $this->assertStringContainsString('endDate=2026-09-19', $wywolania[1]['url']);
    }

    public function testBladLogowaniaZwracaCzytelnyKomunikat(): void
    {
        $client = new PortalClient('a@b.c', 'x', null, static function () {
            return [
                'ok'        => true,
                'http_code' => 403,
                'body'      => json_encode(['error' => 'mfa_required', 'error_description' => 'Multifactor'], JSON_THROW_ON_ERROR),
                'error'     => '',
                'headers'   => [],
            ];
        });

        $wynik = $client->pobierzCsvRaport();
        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('mfa', strtolower($wynik['error']));
    }
}
