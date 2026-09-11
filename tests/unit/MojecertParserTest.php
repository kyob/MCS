<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\mojecert\Parser;
use McsPluginy\mojecert\SubnetHelper;

/**
 * @internal
 */
final class MojecertParserTest extends CIUnitTestCase
{
    public function testKluczDedupuJakWLms(): void
    {
        $p = new Parser();
        // Algorytm LMS: sha256('scan' . "\0" . implode("\0", parts))
        $oczekiwany = hash(
            'sha256',
            'scan' . "\0" . implode("\0", [
                'demo-org',
                '198.51.100.0/24',
                '198.51.100.10',
                '2026-03-11T23:31:18.674657',
                'https://host198-51-100-10.example.test:443/',
                'bad_certificate_names',
            ]),
        );

        $this->assertSame(
            $oczekiwany,
            $p->kluczDedupu(
                'demo-org',
                '198.51.100.0/24',
                '198.51.100.10',
                '2026-03-11T23:31:18.674657',
                'https://host198-51-100-10.example.test:443/',
                'bad_certificate_names',
            ),
        );
    }

    public function testParsujeFixtureIPomijaZleIp(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/mojecert_scan_sample.json';
        $this->assertFileExists($sciezka);

        $parser = new Parser();
        $pakiet = $parser->parsujFixtureJson((string) file_get_contents($sciezka));

        $this->assertSame('demo-org', $pakiet['org_slug']);
        $this->assertSame('198.51.100.0/24', $pakiet['subnet_cidr']);
        $this->assertCount(5, $pakiet['results']);

        $zmapowane = [];
        foreach ($pakiet['results'] as $row) {
            $m = $parser->mapujWiersz($row, $pakiet['org_slug'], $pakiet['subnet_cidr']);
            if ($m !== null) {
                $zmapowane[] = $m;
            }
        }

        $this->assertCount(4, $zmapowane);
        $this->assertSame('198.51.100.10', $zmapowane[0]['ip']);
        $this->assertSame('exposure', $zmapowane[0]['kategoria']);
        $this->assertSame('low', $zmapowane[0]['severity']);
        $this->assertSame('vulnerability', $zmapowane[3]['kategoria']);
        $this->assertSame('high', $zmapowane[3]['severity']);
        $this->assertNotSame('', $zmapowane[0]['detail_excerpt']);
    }

    public function testKategoriaVulnWNazwie(): void
    {
        $p = new Parser();
        $this->assertSame('vulnerability', $p->kategoriaDlaReportType('open_vuln_service'));
        $this->assertSame('vulnerability', $p->kategoriaDlaReportType('cve-2024-1234'));
        $this->assertSame('exposure', $p->kategoriaDlaReportType('bad_certificate_names'));
    }

    public function testSubnetHelperPreferujeCidrDoStorage(): void
    {
        $sn = [
            'subnet'   => ['slug' => 'net-a', 'cidr' => '203.0.113.0/24'],
            'verified' => true,
        ];
        $this->assertSame('net-a', SubnetHelper::apiPathFromSn($sn));
        $this->assertSame('203.0.113.0/24', SubnetHelper::storageLabelFromSn($sn));
    }

    public function testAcceptsHtmlIReportHtml(): void
    {
        $p = new Parser();
        $a = $p->mapujWiersz([
            'target_ip'   => '198.51.100.1',
            'timestamp'   => '2026-01-01T00:00:00Z',
            'target'      => 't',
            'report_type' => 'x',
            'html'        => '<b>AAA</b>',
        ], 'o', 's');
        $b = $p->mapujWiersz([
            'target_ip'    => '198.51.100.1',
            'timestamp'    => '2026-01-01T00:00:00Z',
            'target'       => 't',
            'report_type'  => 'x',
            'report_html'  => '<i>BBB</i>',
        ], 'o', 's');

        $this->assertSame('AAA', $a['detail_excerpt']);
        $this->assertSame('BBB', $b['detail_excerpt']);
    }
}
