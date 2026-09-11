<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\shadowserver\Parser;

/**
 * @internal
 */
final class ShadowserverParserTest extends CIUnitTestCase
{
    public function testParsujeFixturePoNaglowkach(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/shadowserver_sample.csv';
        $this->assertFileExists($sciezka);

        $parser  = new Parser();
        $wiersze = $parser->parsujCsv((string) file_get_contents($sciezka));

        // 4. wiersz ma niepoprawne IP — pomijany
        $this->assertCount(3, $wiersze);
        $this->assertSame('198.51.100.10', $wiersze[0]['ip']);
        $this->assertSame(22, $wiersze[0]['port']);
        $this->assertSame('open-ssh', $wiersze[0]['tag']);
        $this->assertSame('198.51.100.10|open-ssh|22|tcp', $wiersze[0]['klucz_dedupu']);
    }

    public function testKategoriaZTagu(): void
    {
        $p = new Parser();
        $this->assertSame('amplification', $p->kategoriaDlaWiersza(['tag' => 'ntp-amplification']));
        $this->assertSame('exposure', $p->kategoriaDlaWiersza(['tag' => 'open-ssh']));
        $this->assertSame('malware', $p->kategoriaDlaWiersza(['tag' => 'botnet-c2']));
    }
}
