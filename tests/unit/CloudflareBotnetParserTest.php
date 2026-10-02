<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\cloudflare_botnet\ApiClient;
use McsPluginy\cloudflare_botnet\Parser;

/**
 * @internal
 */
final class CloudflareBotnetParserTest extends CIUnitTestCase
{
    public function testMapujeCidrDateJakoKluczDedupu(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujWpis([
            'cidr'          => '198.51.100.10/32',
            'date'          => '2024-05-05T00:00:00Z',
            'offense_count' => 12500,
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame('198.51.100.10', $mapped['ip']);
        $this->assertSame('198.51.100.10/32|2024-05-05', $mapped['klucz_dedupu']);
        $this->assertSame('malware', $mapped['kategoria']);
        $this->assertSame('critical', $mapped['severity_sygnal']);
        $this->assertSame('botnet-ddos', $mapped['tag']);
        $this->assertSame(12500, $mapped['offense_count']);
    }

    public function testPomijaBezIpLubDaty(): void
    {
        $parser = new Parser();
        $this->assertNull($parser->mapujWpis([
            'date'          => '2024-05-05T00:00:00Z',
            'offense_count' => 1,
        ]));
        $this->assertNull($parser->mapujWpis([
            'cidr'          => '198.51.100.1/32',
            'offense_count' => 1,
        ]));
        $this->assertNull($parser->mapujWpis([
            'cidr'          => 'not-an-ip/32',
            'date'          => '2024-05-05T00:00:00Z',
            'offense_count' => 1,
        ]));
    }

    public function testCidrAllowlistFiltruje(): void
    {
        $parser = new Parser();
        $ok = $parser->mapujWpis([
            'cidr'          => '198.51.100.10/32',
            'date'          => '2024-05-05T00:00:00Z',
            'offense_count' => 10,
        ], ['198.51.100.0/24']);
        $this->assertNotNull($ok);

        $no = $parser->mapujWpis([
            'cidr'          => '203.0.113.9/32',
            'date'          => '2024-05-05T00:00:00Z',
            'offense_count' => 10,
        ], ['198.51.100.0/24']);
        $this->assertNull($no);
    }

    public function testParsujFixtureJson(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/cloudflare_botnet_sample.json';
        $this->assertFileExists($sciezka);
        $parser  = new Parser();
        $entries = $parser->parsujJson((string) file_get_contents($sciezka));
        $this->assertCount(3, $entries);

        $mapped = $parser->mapujListe($entries);
        $this->assertCount(2, $mapped);
        $this->assertSame('198.51.100.10/32|2024-05-05', $mapped[0]['klucz_dedupu']);
    }

    public function testApiClientNormalizujePusteResultIAsn(): void
    {
        $client = new ApiClient('tok', 'acc');
        $this->assertSame([], $client->normalizeEntries(null));
        $this->assertSame([], $client->normalizeEntries(0));
        $this->assertSame([], $client->normalizeEntries([]));
        $this->assertSame([198050], $client->normalizeAsnList([198050]));
        $this->assertSame([13335], $client->normalizeAsnList(['asn' => 13335]));
    }

    public function testApiClientFetchFullReportZMockiem(): void
    {
        $body = json_encode([
            'success' => true,
            'errors'  => [],
            'result'  => [
                [
                    'cidr'          => '198.51.100.1/32',
                    'date'          => '2024-05-01T00:00:00Z',
                    'offense_count' => 9,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $seenUrl   = null;
        $seenToken = null;
        $client    = new ApiClient(
            'test-token',
            'acc123',
            'https://api.cloudflare.com/client/v4',
            true,
            static function (string $url, array $meta) use ($body, &$seenUrl, &$seenToken): array {
                $seenUrl   = $url;
                $seenToken = $meta['token'] ?? null;

                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'body'      => $body,
                    'error'     => '',
                ];
            },
        );

        $res = $client->fetchFullReport(198050);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['entries']);
        $this->assertSame('198.51.100.1/32', $res['entries'][0]['cidr']);
        $this->assertIsString($seenUrl);
        $this->assertStringContainsString('/botnet_feed/asn/198050/full_report', (string) $seenUrl);
        $this->assertSame('test-token', $seenToken);
    }
}
