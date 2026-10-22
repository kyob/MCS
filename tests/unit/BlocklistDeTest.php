<?php

use App\Libraries\ImportHarmonogram;
use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\blocklist_de\Listy;
use McsPluginy\blocklist_de\Parser;
use McsPluginy\blocklist_de\Skladacz;

/**
 * @internal
 */
final class BlocklistDeTest extends CIUnitTestCase
{
    public function testPustaListaIKomentarz(): void
    {
        $parser = new Parser();
        $this->assertSame([], $parser->parsuj("\n\n")['ips']);
        $this->assertTrue($parser->parsuj("\n\n")['ok']);
        $zly = $parser->parsuj("# tylko komentarz\n");
        $this->assertFalse($zly['ok']);
        $this->assertSame([], $zly['ips']);
    }

    public function testIpOrazSmieci(): void
    {
        $wynik = (new Parser())->parsuj("91.231.80.9\nnie-ip\n91.231.80.9\n");
        $this->assertTrue($wynik['ok']);
        $this->assertSame(['91.231.80.9'], $wynik['ips']);
    }

    public function testTrescBezAdresu(): void
    {
        $wynik = (new Parser())->parsuj("hello\n");
        $this->assertFalse($wynik['ok']);
    }

    public function testAllowlista(): void
    {
        $this->assertSame(Listy::DOZWOLONE, Listy::parsuj('')['listy']);
        $this->assertSame(Listy::DOZWOLONE, Listy::parsuj(null)['listy']);
        $this->assertSame(['ssh'], Listy::parsuj('ssh,ssh')['listy']);
        $this->assertFalse(Listy::parsuj('ssh,ftp')['ok']);
        $this->assertStringContainsString('nieznana lista ftp', Listy::parsuj('ssh,ftp')['blad']);
        $this->assertFalse(Listy::parsuj(',,')['ok']);
    }

    public function testSkladaczWagaIFiltr(): void
    {
        $inc = (new Skladacz())->incydenty([
            'mail'      => ['91.231.80.9', '1.2.3.4'],
            'strongips' => ['91.231.80.9'],
            'ssh'       => ['8.8.8.8'],
        ], static fn (string $ip): bool => $ip === '91.231.80.9');

        $this->assertCount(1, $inc);
        $this->assertSame(['mail', 'strongips'], $inc[0]['listy']);
        $this->assertSame('critical', $inc[0]['waga']);
        $this->assertSame('abuse', $inc[0]['kategoria']);
        $this->assertSame('blocklist.de: mail, strongips', $inc[0]['tytul']);
        $this->assertSame(2, $inc[0]['payload']['liczba_list']);
    }

    public function testSkladaczBezStrongipsJestHigh(): void
    {
        $inc = (new Skladacz())->incydenty([
            'ssh' => ['91.231.80.9'],
        ], static fn (string $ip): bool => true);

        $this->assertSame('high', $inc[0]['waga']);
    }

    public function testHarmonogramGodzinny(): void
    {
        $this->assertFalse(ImportHarmonogram::czyPora(4599, null, 1000));
        $this->assertTrue(ImportHarmonogram::czyPora(4600, null, 1000));
        $this->assertSame(4600, ImportHarmonogram::poSukcesie(1000));
        $this->assertSame(4600, ImportHarmonogram::poBledzie(1000, 1));
        $this->assertSame(8200, ImportHarmonogram::poBledzie(1000, 2));
        $this->assertFalse(ImportHarmonogram::czyPora(2000, 5000, null));
    }
}
