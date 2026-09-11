<?php

use App\Libraries\WagaV0;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WagaV0Test extends CIUnitTestCase
{
    public function testWersjaZawszeV0(): void
    {
        $wynik = (new WagaV0())->wylicz('malware');

        $this->assertSame(WagaV0::WERSJA, $wynik['wersja']);
        $this->assertSame('v0', $wynik['wersja']);
    }

    public function testMapowanieKategorii(): void
    {
        $w = new WagaV0();

        $this->assertSame('critical', $w->wylicz('malware')['waga']);
        $this->assertSame('high', $w->wylicz('amplification')['waga']);
        $this->assertSame('high', $w->wylicz('vulnerability')['waga']);
        $this->assertSame('high', $w->wylicz('abuse')['waga']);
        $this->assertSame('medium', $w->wylicz('reputation')['waga']);
        $this->assertSame('medium', $w->wylicz('exposure')['waga']);
        $this->assertSame('low', $w->wylicz('other')['waga']);
        $this->assertSame('low', $w->wylicz('')['waga']);
    }

    public function testSygnalFiltrSndSNadpisuje(): void
    {
        $w = new WagaV0();

        $this->assertSame('high', $w->wylicz('reputation', ['filtr' => 'RED'])['waga']);
        $this->assertSame('medium', $w->wylicz('reputation', ['filtr' => 'YELLOW'])['waga']);
        $this->assertSame('low', $w->wylicz('reputation', ['filtr' => 'GREEN'])['waga']);
    }

    public function testJawnaWagaWSygnalach(): void
    {
        $wynik = (new WagaV0())->wylicz('exposure', ['severity' => 'critical']);

        $this->assertSame('critical', $wynik['waga']);
        $this->assertSame('v0', $wynik['wersja']);
    }
}
