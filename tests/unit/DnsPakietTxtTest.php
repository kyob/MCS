<?php

use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Dnsbl\SkanyWidok;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DnsPakietTxtTest extends CIUnitTestCase
{
    public function testTxtZNiepoprawnymUtf8INulJestBezpieczny(): void
    {
        $q    = DnsPakiet::zapytanie(0x0101, '2.0.0.127.bl.spamcop.net', DnsPakiet::TYP_TXT);
        $pyt  = substr($q, 12);
        $dane = "ok\xC3\x28\x00\nx";
        $odp  = pack('nnnnnn', 0x0101, 0x8180, 1, 1, 0, 0) . $pyt
            . "\xC0\x0C" . pack('nnNn', 16, 1, 60, strlen($dane) + 1) . chr(strlen($dane)) . $dane;

        $p = DnsPakiet::parsuj($odp);

        $this->assertCount(1, $p['txt']);
        $this->assertTrue(mb_check_encoding($p['txt'][0], 'UTF-8'));
        $this->assertStringNotContainsString("\x00", $p['txt'][0]);
        $this->assertStringNotContainsString("\n", $p['txt'][0]);
        $this->assertStringStartsWith('ok', $p['txt'][0]);
    }

    public function testCsvPrefiksujeFormuly(): void
    {
        $csv = SkanyWidok::csv([
            ['ip' => '192.0.2.1', 'strefa' => 'bl.example', 'kody' => '127.0.0.2', 'txt' => '=HYPERLINK("http://x")', 'status' => 'listed', 'blad' => ''],
            ['ip' => '192.0.2.2', 'strefa' => 'bl.example', 'kody' => '', 'txt' => '@SUM(1)', 'status' => 'blad', 'blad' => '-1+1'],
        ]);

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
        $this->assertStringContainsString("'-1+1", $csv);
    }
}
