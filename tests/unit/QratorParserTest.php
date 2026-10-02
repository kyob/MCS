<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\qrator\ApiClient;
use McsPluginy\qrator\Parser;

/**
 * @internal
 */
final class QratorParserTest extends CIUnitTestCase
{
    public function testMapujeHijackAffectedNaCidr(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujWpis([
            'feed' => 'hijacks_affected',
            'raw'  => [
                'hijacker' => [
                    'asn'    => 64496,
                    'prefix' => '91.231.80.0/24',
                ],
                'affected' => [
                    'asn'    => 198050,
                    'prefix' => '91.231.80.0/22',
                ],
                'first_seen' => '2024-05-05T12:00:00+00:00',
                'severity'   => 'high',
            ],
        ], 198050);

        $this->assertNotNull($mapped);
        $this->assertSame('hijack_affected', $mapped['event_typ']);
        $this->assertSame('cidr', $mapped['podmiot_typ']);
        $this->assertSame('91.231.80.0/22', $mapped['podmiot']);
        $this->assertSame('abuse', $mapped['kategoria']);
        $this->assertSame('high', $mapped['severity_sygnal']);
        $this->assertSame('bgp-hijack', $mapped['tag']);
        $this->assertStringContainsString('hijack_affected|91.231.80.0/22|64496|', $mapped['klucz_dedupu']);
    }

    public function testMapujeAmplifierNaIp(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujWpis([
            'feed' => 'amplifiers',
            'raw'  => [
                'ip'       => '198.51.100.10',
                'port'     => 53,
                'protocol' => 'udp',
                'type'     => 'dns',
            ],
        ], 198050);

        $this->assertNotNull($mapped);
        $this->assertSame('ip', $mapped['podmiot_typ']);
        $this->assertSame('198.51.100.10', $mapped['podmiot']);
        $this->assertSame('amplification', $mapped['kategoria']);
        $this->assertSame('amplifier|198.51.100.10|53|udp|dns', $mapped['klucz_dedupu']);
    }

    public function testPomijaNieznanyFeed(): void
    {
        $parser = new Parser();
        $this->assertNull($parser->mapujWpis([
            'feed' => 'neighbors',
            'raw'  => ['asn' => 1],
        ], 198050));
    }

    public function testParsujFixtureJson(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/qrator_sample.json';
        $this->assertFileExists($sciezka);
        $parser = new Parser();
        $parsed = $parser->parsujJson((string) file_get_contents($sciezka));
        $this->assertSame(198050, $parsed['asn']);
        $this->assertCount(3, $parsed['events']);

        $mapped = $parser->mapujListe($parsed['events'], 198050);
        $this->assertCount(3, $mapped);
        $this->assertSame('hijack_affected', $mapped[0]['event_typ']);
        $this->assertSame('leak_created', $mapped[1]['event_typ']);
        $this->assertSame('amplifier', $mapped[2]['event_typ']);
    }

    public function testApiClientParsujFeedsIIpVersion(): void
    {
        $this->assertSame(
            ApiClient::DEFAULT_FEEDS,
            ApiClient::parsujFeeds(''),
        );
        $this->assertSame(
            ['hijacks_affected', 'amplifiers'],
            ApiClient::parsujFeeds('hijacks_affected, amplifiers, unknown'),
        );

        $client = new ApiClient('k');
        $this->assertSame('4', $client->normalizeIpVersion('ipv4'));
        $this->assertSame('6', $client->normalizeIpVersion('6'));
        $this->assertSame('all', $client->normalizeIpVersion(''));
    }

    public function testApiClientFetchFeedsZMockiem(): void
    {
        $hijackBody = json_encode([
            [
                'hijacker'   => ['asn' => 1, 'prefix' => '10.0.0.0/24'],
                'affected'   => ['asn' => 198050, 'prefix' => '91.231.80.0/22'],
                'first_seen' => '2024-01-01T00:00:00+00:00',
                'severity'   => 'low',
                'info'       => [0, 0],
            ],
        ], JSON_THROW_ON_ERROR);

        $seen   = [];
        $client = new ApiClient(
            'test-key',
            'https://api.radar.qrator.net',
            true,
            static function (string $url, array $meta) use ($hijackBody, &$seen): array {
                $seen[] = ['url' => $url, 'key' => $meta['api_key'] ?? null];

                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'body'      => str_contains($url, 'hijacks/affected') ? $hijackBody : '[]',
                    'error'     => '',
                ];
            },
        );

        $res = $client->fetchFeeds(198050, 'all', false, [
            ApiClient::FEED_HIJACKS_AFFECTED,
            ApiClient::FEED_LEAKS_CREATED,
        ]);

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $res['events']);
        $this->assertSame('hijacks_affected', $res['events'][0]['feed']);
        $this->assertCount(2, $seen);
        $this->assertSame('test-key', $seen[0]['key']);
        $this->assertStringContainsString('/v2/hijacks/affected/asn/198050', $seen[0]['url']);
        $this->assertStringContainsString('ip=all', $seen[0]['url']);
    }
}
