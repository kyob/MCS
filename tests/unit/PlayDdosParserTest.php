<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\play\Parser;

/**
 * @internal
 */
final class PlayDdosParserTest extends CIUnitTestCase
{
    public function testParsujeEventFinishedBezIp(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/play_ddos_event_finished.txt';
        $raw     = (string) file_get_contents($sciezka);
        preg_match('/^Subject:\s*(.+)$/mi', $raw, $m);
        $subject = trim($m[1]);
        $tresc   = (string) preg_replace('/^Subject:\s*.+$/mi', '', $raw, 1);

        $wiersze = (new Parser())->parsujPowiadomienie($subject, $tresc);
        $this->assertCount(1, $wiersze);
        $this->assertSame('Event finished', $wiersze[0]['message_type']);
        $this->assertSame('93570', $wiersze[0]['event_id']);
        $this->assertSame('siec', $wiersze[0]['podmiot_typ']);
        $this->assertSame('B2B_AS31242_SPM-1-4', $wiersze[0]['podmiot']);
        $this->assertNull($wiersze[0]['ip']);
        $this->assertStringContainsString('ddos|Event finished|93570', $wiersze[0]['klucz_dedupu']);
    }

    public function testParsujeCidrUpdate(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/play_ddos_cidr_update.txt';
        $raw     = (string) file_get_contents($sciezka);
        preg_match('/^Subject:\s*(.+)$/mi', $raw, $m);
        $subject = trim($m[1]);
        $tresc   = (string) preg_replace('/^Subject:\s*.+$/mi', '', $raw, 1);

        $wiersze = (new Parser())->parsujPowiadomienie($subject, $tresc);
        $this->assertCount(2, $wiersze);
        $this->assertSame('cidr', $wiersze[0]['podmiot_typ']);
        $this->assertSame('198.51.100.0/24', $wiersze[0]['podmiot']);
        $this->assertSame('203.0.113.64/28', $wiersze[1]['podmiot']);
    }

    public function testOdrzucaMailTestowy(): void
    {
        $w = (new Parser())->parsujPowiadomienie('test', 'Witamy w skrzynce');
        $this->assertSame([], $w);
    }

    public function testCzyOdNadawcyWymagaDokladnegoAdresu(): void
    {
        $this->assertTrue(Parser::czyOdNadawcy('antyddos@play.pl'));
        $this->assertTrue(Parser::czyOdNadawcy(' AntyDDoS@Play.pl '));
        $this->assertFalse(Parser::czyOdNadawcy('x@play.pl.evil.com'));
        $this->assertFalse(Parser::czyOdNadawcy('antyddos@play.pl.evil.com'));
        $this->assertFalse(Parser::czyOdNadawcy('inny@play.pl'));
        $this->assertFalse(Parser::czyOdNadawcy(''));
    }
}
