<?php

use App\Libraries\ImportHarmonogram;
use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\blocklist_de\Klient;
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

    public function testStabilnaTrescPrzyNiezgodnymMd5JestPrzyjete(): void
    {
        $pobraniaTxt = 0;
        $klient      = new Klient(function (string $url) use (&$pobraniaTxt): array {
            if (str_ends_with($url, '.txt.md5')) {
                return $this->http(str_repeat('a', 32));
            }
            $pobraniaTxt++;

            return $this->http("91.231.80.9\n");
        });

        $wynik = $klient->pobierz(['ssh']);

        $this->assertTrue($wynik['ok'], $wynik['blad']);
        $this->assertSame(['91.231.80.9'], $wynik['listy']['ssh']);
        $this->assertSame(['ssh'], $wynik['md5_stare']);
        $this->assertSame(2, $pobraniaTxt);
    }

    public function testZmieniajacaSieTrescINiezgodneMd5JestBledem(): void
    {
        $n      = 0;
        $klient = new Klient(function (string $url) use (&$n): array {
            if (str_ends_with($url, '.txt.md5')) {
                return $this->http(str_repeat('b', 32));
            }
            $n++;

            return $this->http($n . '.2.3.4' . "\n");
        });

        $wynik = $klient->pobierz(['mail']);

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('mail.txt.md5', $wynik['blad']);
        $this->assertSame([], $wynik['md5_stare']);
        $this->assertSame(3, $n);
    }

    public function testNiepoprawnyFormatMd5JestBledem(): void
    {
        $pobraniaTxt = 0;
        $klient      = new Klient(function (string $url) use (&$pobraniaTxt): array {
            if (str_ends_with($url, '.txt.md5')) {
                return $this->http('to-nie-jest-md5');
            }
            $pobraniaTxt++;

            return $this->http("91.231.80.9\n");
        });

        $wynik = $klient->pobierz(['strongips']);

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('strongips.txt.md5', $wynik['blad']);
        $this->assertSame(1, $pobraniaTxt);
    }

    public function testDrugiePobranieZgadzaSieZMd5(): void
    {
        $tresc  = "91.231.80.9\n";
        $n      = 0;
        $klient = new Klient(function (string $url) use ($tresc, &$n): array {
            if (str_ends_with($url, '.txt.md5')) {
                return $this->http(md5($tresc));
            }
            $n++;

            return $this->http($n === 1 ? "1.1.1.1\n" : $tresc);
        });

        $wynik = $klient->pobierz(['bots']);

        $this->assertTrue($wynik['ok'], $wynik['blad']);
        $this->assertSame(['91.231.80.9'], $wynik['listy']['bots']);
        $this->assertSame([], $wynik['md5_stare']);
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

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    private function http(string $body): array
    {
        return ['ok' => true, 'http_code' => 200, 'body' => $body, 'error' => ''];
    }
}
