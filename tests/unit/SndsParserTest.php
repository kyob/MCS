<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\snds\Parser;
use McsPluginy\snds\Plugin;

/**
 * @internal
 */
final class SndsParserTest extends CIUnitTestCase
{
    public function testParsujeFixturePoNaglowkach(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/snds_ip_status_sample.csv';
        $this->assertFileExists($sciezka);

        $wiersze = (new Parser())->parsujCsv((string) file_get_contents($sciezka));

        // 4. wiersz ma niepoprawne IP — pomijany
        $this->assertCount(3, $wiersze);
        $this->assertSame('198.51.100.10', $wiersze[0]['ip']);
        $this->assertSame('GREEN', $wiersze[0]['filtr']);
        $this->assertSame('198.51.100.10|green|2026-09-09', $wiersze[0]['klucz_dedupu']);

        $this->assertSame('YELLOW', $wiersze[1]['filtr']);
        $this->assertSame(2, $wiersze[1]['trap_hits']);

        $this->assertSame('RED', $wiersze[2]['filtr']);
        $this->assertSame('203.0.113.5|red|2026-09-10', $wiersze[2]['klucz_dedupu']);
    }

    public function testKategoriaZawszeReputation(): void
    {
        $p = new Parser();
        $this->assertSame('reputation', $p->kategoriaDlaWiersza(['filtr' => 'RED']));
        $this->assertSame('reputation', $p->kategoriaDlaWiersza([]));
    }

    public function testZbudujUrlDoklejaKey(): void
    {
        $p = new Plugin();
        $this->assertSame(
            'https://example.test/snds?key=sekret',
            $p->zbudujUrl('https://example.test/snds', 'sekret'),
        );
        $this->assertSame(
            'https://example.test/snds?date=1&key=sekret',
            $p->zbudujUrl('https://example.test/snds?date=1', 'sekret'),
        );
        $this->assertSame(
            'https://example.test/snds?key=juz-jest',
            $p->zbudujUrl('https://example.test/snds?key=juz-jest', 'inny'),
        );
        $this->assertSame(
            'https://example.test/snds',
            $p->zbudujUrl('https://example.test/snds', ''),
        );
    }

    public function testAlternatywneNazwyNaglowkow(): void
    {
        $csv = "ip,filter_result,date\n198.51.100.1,red,2026-09-11\n";
        $w   = (new Parser())->parsujCsv($csv);
        $this->assertCount(1, $w);
        $this->assertSame('RED', $w[0]['filtr']);
        $this->assertSame('198.51.100.1|red|2026-09-11', $w[0]['klucz_dedupu']);
    }
}
