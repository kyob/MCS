<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\cert_n6\Parser;

/**
 * @internal
 */
final class CertN6ParserTest extends CIUnitTestCase
{
    public function testMapujePierwszyAddressIIdJakoKluczDedupu(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujZdarzenie([
            'id'         => 'abc123',
            'category'   => 'bots',
            'confidence' => 'medium',
            'name'       => 'android.badbox2',
            'time'       => '2026-04-12T21:52:19Z',
            'source'     => 'hidden.179',
            'dport'      => 80,
            'sport'      => 51114,
            'fqdn'       => 'cbphe.example',
            'proto'      => 'tcp',
            'origin'     => 'sinkhole',
            'restriction'=> 'need-to-know',
            'modified'   => '2026-04-13T05:00:59Z',
            'adip'       => 'x.x.70.38',
            'address'    => [
                ['ip' => '198.51.100.10', 'asn' => 64500, 'cc' => 'PL'],
                ['ip' => '203.0.113.9', 'asn' => 1, 'cc' => 'US'],
            ],
            'name_details' => [
                'phrase' => [
                    'android.badbox2' => [
                        'n6kb' => ['https://n6portal.cert.pl/knowledge_base/articles/1020#n6kb-android_2ebadbox2'],
                    ],
                ],
            ],
        ]);

        $this->assertNotNull($mapped);
        $this->assertSame('abc123', $mapped['klucz_dedupu']);
        $this->assertSame('abc123', $mapped['n6_id']);
        $this->assertSame('198.51.100.10', $mapped['ip']);
        $this->assertSame(64500, $mapped['asn']);
        $this->assertSame('PL', $mapped['cc']);
        $this->assertSame('malware', $mapped['kategoria']);
        $this->assertSame('medium', $mapped['confidence']);
        $this->assertStringContainsString('n6kb-android', $mapped['n6kb_url']);
        $this->assertSame(80, $mapped['dport']);
        $this->assertSame('tcp', $mapped['proto']);
    }

    public function testPomijaBezIdLubBezIp(): void
    {
        $parser = new Parser();
        $this->assertNull($parser->mapujZdarzenie([
            'category' => 'bots',
            'address'  => [['ip' => '198.51.100.1']],
        ]));
        $this->assertNull($parser->mapujZdarzenie([
            'id'      => 'x',
            'address' => [['ip' => '']],
        ]));
        $this->assertNull($parser->mapujZdarzenie([
            'id' => 'x',
        ]));
    }

    public function testMapujeKategorieN6NaMcs(): void
    {
        $p = new Parser();
        $this->assertSame('malware', $p->kategoriaDlaN6('bots'));
        $this->assertSame('vulnerability', $p->kategoriaDlaN6('vulnerable'));
        $this->assertSame('amplification', $p->kategoriaDlaN6('amplifier'));
        $this->assertSame('vulnerability', $p->kategoriaDlaN6('server-exploit'));
        $this->assertSame('abuse', $p->kategoriaDlaN6('spam'));
        $this->assertSame('abuse', $p->kategoriaDlaN6('phish'));
        $this->assertSame('other', $p->kategoriaDlaN6('weird-unknown'));
    }

    public function testParsujeFixtureIPomijaNiekompletne(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/cert_n6_report_inside_sample.json';
        $this->assertFileExists($sciezka);

        $parser  = new Parser();
        $events  = $parser->parsujReportJson((string) file_get_contents($sciezka));
        $this->assertCount(6, $events);

        $ok = [];
        foreach ($events as $item) {
            $m = $parser->mapujZdarzenie($item);
            if ($m !== null) {
                $ok[] = $m;
            }
        }

        $this->assertCount(4, $ok);
        $this->assertSame('malware', $ok[0]['kategoria']);
        $this->assertSame('vulnerability', $ok[1]['kategoria']);
        $this->assertSame('amplification', $ok[2]['kategoria']);
        $this->assertSame('vulnerability', $ok[3]['kategoria']);
        $this->assertSame('198.51.100.10', $ok[0]['ip']);
    }

    public function testBuildReportUrlWymagaTimeMin(): void
    {
        $url = Parser::buildReportUrl(
            'https://n6api.cert.pl',
            'report/inside',
            '2026-04-01T00:00:00Z',
            null,
            null,
        );
        $this->assertSame(
            'https://n6api.cert.pl/report/inside.json?time.min=2026-04-01T00%3A00%3A00Z',
            $url,
        );
    }
}
