<?php

use App\Libraries\WagaV0;
use App\Libraries\WagaV1;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WagaV1Test extends CIUnitTestCase
{
    public function testWersjaZawszeV1(): void
    {
        $wynik = (new WagaV1())->wylicz('malware');

        $this->assertSame(WagaV1::WERSJA, $wynik['wersja']);
        $this->assertSame('v1', $wynik['wersja']);
    }

    public function testMapowanieKategoriiJakV0(): void
    {
        $w = new WagaV1();

        $this->assertSame('critical', $w->wylicz('malware')['waga']);
        $this->assertSame('high', $w->wylicz('amplification')['waga']);
        $this->assertSame('high', $w->wylicz('vulnerability')['waga']);
        $this->assertSame('high', $w->wylicz('abuse')['waga']);
        $this->assertSame('medium', $w->wylicz('reputation')['waga']);
        $this->assertSame('medium', $w->wylicz('exposure')['waga']);
        $this->assertSame('low', $w->wylicz('other')['waga']);
        $this->assertSame('low', $w->wylicz('')['waga']);
    }

    public function testSndSFiltrRedJestCritical(): void
    {
        $w = new WagaV1();

        $this->assertSame('critical', $w->wylicz('reputation', ['filtr' => 'RED'])['waga']);
        $this->assertSame('high', $w->wylicz('reputation', ['filtr' => 'YELLOW'])['waga']);
        $this->assertSame('low', $w->wylicz('reputation', ['filtr' => 'GREEN'])['waga']);
    }

    public function testSeverityNieObnizaMalware(): void
    {
        $wynik = (new WagaV1())->wylicz('malware', ['confidence' => 'low']);

        $this->assertSame('critical', $wynik['waga']);
        $this->assertSame('v1', $wynik['wersja']);
    }

    public function testSeverityPodbijaExposure(): void
    {
        $wynik = (new WagaV1())->wylicz('exposure', ['severity' => 'critical']);

        $this->assertSame('critical', $wynik['waga']);
    }

    public function testN6ConfidenceNaMiekkejKategorii(): void
    {
        $w = new WagaV1();

        $this->assertSame('high', $w->wylicz('other', ['confidence' => 'high'])['waga']);
        $this->assertSame('medium', $w->wylicz('other', ['confidence' => 'medium'])['waga']);
    }

    public function testWanguardSeverityMaxZBaza(): void
    {
        $w = new WagaV1();

        $this->assertSame('critical', $w->wylicz('amplification', ['severity' => 'critical'])['waga']);
        $this->assertSame('high', $w->wylicz('amplification', ['severity' => 'low'])['waga']);
    }

    public function testShadowserverTagBotnet(): void
    {
        $wynik = (new WagaV1())->wylicz('other', ['tag' => 'botnet-drone']);

        $this->assertSame('critical', $wynik['waga']);
    }

    public function testShadowserverTagAmplification(): void
    {
        $wynik = (new WagaV1())->wylicz('other', ['tag' => 'open-resolver']);

        $this->assertSame('high', $wynik['waga']);
    }

    public function testPlayMessageTypeMitigation(): void
    {
        $wynik = (new WagaV1())->wylicz('abuse', ['message_type' => 'Mitigation started']);

        $this->assertSame('critical', $wynik['waga']);
    }

    public function testPlayMessageTypeCidrNiePodbijaPonadMax(): void
    {
        // CIDR → low, ale max z bazą abuse=high zostawia high
        $wynik = (new WagaV1())->wylicz('abuse', ['message_type' => 'CIDR']);

        $this->assertSame('high', $wynik['waga']);
    }

    public function testMojecertReportTypeVuln(): void
    {
        $wynik = (new WagaV1())->wylicz('exposure', ['report_type' => 'ssl-cve-scan']);

        $this->assertSame('high', $wynik['waga']);
    }

    public function testV0NadalDostepneJakoFallback(): void
    {
        $v0 = (new WagaV0())->wylicz('reputation', ['filtr' => 'RED']);
        $v1 = (new WagaV1())->wylicz('reputation', ['filtr' => 'RED']);

        $this->assertSame('v0', $v0['wersja']);
        $this->assertSame('high', $v0['waga']);
        $this->assertSame('v1', $v1['wersja']);
        $this->assertSame('critical', $v1['waga']);
    }
}
