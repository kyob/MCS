<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\wanguard\ApiClient;
use McsPluginy\wanguard\Parser;

/**
 * @internal
 */
final class WanguardParserTest extends CIUnitTestCase
{
    public function testMapujePrefixIAnomalyIdJakoKluczDedupu(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujAnomalie([
            'anomaly_id'     => 900001,
            'status'         => 'Finished',
            'anomaly'        => 'UDP pkts/s > 75.0 k',
            'direction'      => 'Incoming',
            'prefix'         => '198.51.100.10/32',
            'severity'       => '102.1',
            'link_severity'  => '23',
            'mitigated'      => false,
            'duration'       => 60,
            'pkts/s'         => '76560',
            'bits/s'         => '886202922',
            'from'           => ['unixtime' => '1700000000'],
            'until'          => ['unixtime' => '1700000060'],
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame(900001, $mapped['anomaly_id']);
        $this->assertSame('900001', $mapped['klucz_dedupu']);
        $this->assertSame('198.51.100.10', $mapped['ip']);
        $this->assertSame('amplification', $mapped['kategoria']);
        $this->assertSame('high', $mapped['severity_sygnal']);
        $this->assertSame(1700000000, $mapped['from_unix']);
        $this->assertSame(1700000060, $mapped['until_unix']);
        $this->assertSame(60, $mapped['duration_seconds']);
        $this->assertSame(0, $mapped['mitigated']);
        $this->assertSame('76560', $mapped['peak_pkts_s']);
    }

    public function testPomijaBezIpLubBezId(): void
    {
        $parser = new Parser();
        $this->assertNull($parser->mapujAnomalie([
            'anomaly' => 'UDP',
            'prefix'  => '198.51.100.1/32',
        ]));
        $this->assertNull($parser->mapujAnomalie([
            'anomaly_id' => 1,
            'prefix'     => '',
        ]));
        $this->assertNull($parser->mapujAnomalie([
            'href'   => '/wanguard-api/v1/anomalies/12',
            'prefix' => 'not-an-ip/32',
        ]));
    }

    public function testCidrAllowlistFiltruje(): void
    {
        $parser = new Parser();
        $ok = $parser->mapujAnomalie([
            'anomaly_id' => 1,
            'anomaly'    => 'UDP',
            'prefix'     => '198.51.100.10/32',
        ], ['198.51.100.0/24']);
        $this->assertNotNull($ok);

        $no = $parser->mapujAnomalie([
            'anomaly_id' => 2,
            'anomaly'    => 'UDP',
            'prefix'     => '203.0.113.9/32',
        ], ['198.51.100.0/24']);
        $this->assertNull($no);
    }

    public function testKategoriaISeverityMap(): void
    {
        $p = new Parser();
        $this->assertSame('amplification', $p->kategoriaDlaAnomalii('UDP pkts/s > 75.0 k'));
        $this->assertSame('amplification', $p->kategoriaDlaAnomalii('TCP SYN flood'));
        $this->assertSame('other', $p->kategoriaDlaAnomalii('weird-unknown'));
        $this->assertSame('low', $p->severityNaSygnal('10'));
        $this->assertSame('medium', $p->severityNaSygnal('50'));
        $this->assertSame('high', $p->severityNaSygnal('102.1'));
        $this->assertSame('critical', $p->severityNaSygnal('200'));
        $this->assertNull($p->severityNaSygnal('-'));
    }

    public function testParsujFixtureINormalizujeUrl(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/wanguard_anomalies_sample.json';
        $this->assertFileExists($sciezka);
        $parser = new Parser();
        $rows   = $parser->parsujFixtureJson((string) file_get_contents($sciezka));
        $this->assertCount(3, $rows);
        $this->assertSame(900001, $parser->extractAnomalyId($rows[0]));
        $this->assertSame('198.51.100.10', $parser->ipZPrefix($rows[0]['prefix']));
        $this->assertNull($parser->mapujAnomalie($rows[2])); // pusty prefix

        $this->assertSame(
            'https://wanguard.example/wanguard-api/v1/',
            ApiClient::normalizeApiBaseUrl('https://wanguard.example/wanguard-api/v1/anomalies?status=Finished'),
        );
    }

    public function testExtractIdZHrefWzglednego(): void
    {
        $parser = new Parser();
        $this->assertSame(3837, $parser->extractAnomalyId([
            'href' => '/wanguard-api/v1/anomalies/3837',
        ]));
    }
}
