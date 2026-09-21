<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\speedtest\Parser;

/**
 * @internal
 */
final class SpeedtestParserTest extends CIUnitTestCase
{
    public function testParsujeFixtureIPomijaDuplikatOrazZleWiersze(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/speedtest_sample.csv';
        $this->assertFileExists($sciezka);

        $wiersze = (new Parser())->parsujCsv((string) file_get_contents($sciezka));

        $this->assertCount(3, $wiersze);
        $this->assertSame('st-1001', $wiersze[0]['test_id']);
        $this->assertSame('91.231.80.10', $wiersze[0]['ip']);
        $this->assertSame(94000, $wiersze[0]['download_kbps']);
        $this->assertSame(22000, $wiersze[0]['upload_kbps']);
        $this->assertSame(8.5, $wiersze[0]['latency_ms']);
        $this->assertSame('Alfa-System', $wiersze[0]['isp']);
        $this->assertSame('Android', $wiersze[0]['platform']);
        $this->assertSame('Olsztyn', $wiersze[0]['server_name']);
        $this->assertSame('2026-09-16 10:15:00+00:00', $wiersze[0]['zaobserwowano_at']);

        $this->assertSame('8.8.8.8', $wiersze[1]['ip']);
        $this->assertSame('st-1003', $wiersze[2]['test_id']);
        $this->assertSame('91.231.81.20', $wiersze[2]['ip']);
    }

    public function testAliasKolumnIKonwersjaMbps(): void
    {
        $csv = "Test ID,Timestamp,Client IP,Download (Mbps),Upload (Mbps),Ping,isp_name,Platform,Server Name\n"
            . "abc-9,2026-09-01 08:00:00,91.231.80.1,94.5,22.0,9 ms,Alfa,Web,Host-1\n";

        $wiersze = (new Parser())->parsujCsv($csv);
        $this->assertCount(1, $wiersze);
        $this->assertSame('abc-9', $wiersze[0]['test_id']);
        $this->assertSame('91.231.80.1', $wiersze[0]['ip']);
        $this->assertSame(94500, $wiersze[0]['download_kbps']);
        $this->assertSame(22000, $wiersze[0]['upload_kbps']);
        $this->assertSame(9.0, $wiersze[0]['latency_ms']);
        $this->assertSame('Alfa', $wiersze[0]['isp']);
        $this->assertSame('Web', $wiersze[0]['platform']);
        $this->assertSame('Host-1', $wiersze[0]['server_name']);
    }

    public function testSrednikBomIPustyCsv(): void
    {
        $parser = new Parser();
        $this->assertSame([], $parser->parsujCsv(''));

        $csv = "\xEF\xBB\xBFResult ID;Date;IP Address;Download;Upload;Latency\n"
            . "id-1;2026-09-10 09:00:00;91.231.80.2;1000;500;3\n";
        $wiersze = $parser->parsujCsv($csv);
        $this->assertCount(1, $wiersze);
        $this->assertSame('id-1', $wiersze[0]['test_id']);
        $this->assertSame('91.231.80.2', $wiersze[0]['ip']);
        $this->assertSame(1000, $wiersze[0]['download_kbps']);
    }

    public function testNaglowkiApiOoklaServersReports(): void
    {
        $csv = '"resultDate","ipAddress","ipv6 address","country","serverId","serverName","userAgent",'
            . '"ispName","download (Kbps)","upload (Kbps)","ping (ms)","jitter","testId"' . "\n"
            . '"2026-09-17T08:48:19.000Z","91.231.80.10",,"Poland",75038,"Knurów","Chrome",'
            . '"Alfa-System","19132.00","21994.00",23,112,"19682771-356a-bb9g-ic7h-1dfgg5dbh7c8"' . "\n";

        $wiersze = (new Parser())->parsujCsv($csv);
        $this->assertCount(1, $wiersze);
        $this->assertSame('19682771-356a-bb9g-ic7h-1dfgg5dbh7c8', $wiersze[0]['test_id']);
        $this->assertSame('91.231.80.10', $wiersze[0]['ip']);
        $this->assertSame(19132, $wiersze[0]['download_kbps']);
        $this->assertSame(21994, $wiersze[0]['upload_kbps']);
        $this->assertSame(23.0, $wiersze[0]['latency_ms']);
        $this->assertSame('Alfa-System', $wiersze[0]['isp']);
        $this->assertSame('Chrome', $wiersze[0]['platform']);
        $this->assertSame('Knurów', $wiersze[0]['server_name']);
        $this->assertSame('2026-09-17 08:48:19+00:00', $wiersze[0]['zaobserwowano_at']);
    }

    public function testIpv6GdyIpAddressPuste(): void
    {
        $csv = '"resultDate","ipAddress","ipv6 address","country","serverId","serverName","userAgent",'
            . '"ispName","download (Kbps)","upload (Kbps)","ping (ms)","jitter","testId"' . "\n"
            . '"2026-08-25T08:49:00.000Z",,"2a0f:1700:2::","Poland",75038,"Knurów","Chrome",'
            . '"Alfa-System","924300.00","78900.00",12,4,"19595656-182a-bb9f-jf6f-6bicc8bgf6f9"' . "\n"
            . '"2026-08-25T09:00:00.000Z","91.231.80.0","2a0f:1700:2::","Poland",75038,"Knurów","Chrome",'
            . '"Alfa-System","1000.00","500.00",10,1,"prefers-ipv4-when-both"' . "\n";

        $wiersze = (new Parser())->parsujCsv($csv);
        $this->assertCount(2, $wiersze);
        $this->assertSame('19595656-182a-bb9f-jf6f-6bicc8bgf6f9', $wiersze[0]['test_id']);
        $this->assertSame('2a0f:1700:2::', $wiersze[0]['ip']);
        $this->assertSame(924300, $wiersze[0]['download_kbps']);
        $this->assertSame(78900, $wiersze[0]['upload_kbps']);
        $this->assertSame('91.231.80.0', $wiersze[1]['ip']);
    }
}
