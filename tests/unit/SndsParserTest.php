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

    public function testParsujeFixtureDataKeyPozycyjnie13i14(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/snds_datakey_sample.csv';
        $this->assertFileExists($sciezka);

        $wiersze = (new Parser())->parsujCsv((string) file_get_contents($sciezka));

        $this->assertCount(4, $wiersze);
        $this->assertSame('176.97.26.28', $wiersze[0]['ip']);
        $this->assertSame('YELLOW', $wiersze[0]['filtr']);
        $this->assertSame(3, $wiersze[0]['trap_hits']);
        $this->assertSame('176.97.26.28|yellow|2025-10-12', $wiersze[0]['klucz_dedupu']);
        $this->assertArrayHasKey('activity_period_start', $wiersze[0]['raw']);
        $this->assertArrayHasKey('jmr_p1_sender', $wiersze[0]['raw']);

        // 14 kolumn z comments
        $this->assertSame('RED', $wiersze[2]['filtr']);
        $this->assertSame('sample comment', $wiersze[2]['raw']['comments']);
        $this->assertSame(7, $wiersze[2]['trap_hits']);

        $this->assertSame('GREEN', $wiersze[3]['filtr']);
        $this->assertSame(0, $wiersze[3]['trap_hits']);
    }

    public function testPozycyjnyOdrzucaZlaLiczbeKolumn(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parsujCsv("1.2.3.4,a,b,c\n");
    }

    public function testKategoriaZawszeReputation(): void
    {
        $p = new Parser();
        $this->assertSame('reputation', $p->kategoriaDlaWiersza(['filtr' => 'RED']));
        $this->assertSame('reputation', $p->kategoriaDlaWiersza([]));
    }

    public function testZbudujDataUrlSubstrateILegacy(): void
    {
        $p = new Plugin();
        $this->assertSame(
            'https://substrate.office.com/ip-domain-management-snds/SNDS/DataKey?Key=uuid-test&Date=101225',
            $p->zbudujDataUrl('uuid-test', '101225', '', false),
        );
        $this->assertSame(
            'https://sendersupport.olc.protection.outlook.com/snds/data/?key=uuid-test&date=101225',
            $p->zbudujDataUrl('uuid-test', '101225', '', true),
        );
        $this->assertSame(
            'https://example.test/x?Key=uuid-test&Date=101225',
            $p->zbudujDataUrl('uuid-test', '101225', 'https://example.test/x?Key=%s&Date=%s'),
        );
    }

    public function testDatyLookbackJakLms(): void
    {
        $p     = new Plugin();
        $teraz = new DateTimeImmutable('2026-09-11 12:00:00', new DateTimeZone('UTC'));
        $this->assertSame(['091026', '090926', '090826'], $p->datyLookback(3, $teraz));
    }

    public function testZbudujUrlDoklejaKeyKompatybilnosc(): void
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
