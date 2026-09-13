<?php

use App\Libraries\EtykietyUi;
use App\Libraries\KontekstIp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class KontekstIpTest extends CIUnitTestCase
{
    public function testBudujeChipyIZdanie(): void
    {
        $k = new KontekstIp(new EtykietyUi());
        $out = $k->zbuduj([
            'ip'                => '198.51.100.10',
            'liczba_incydentow' => 3,
            'liczba_zrodel'     => 2,
            'zrodla'            => ['cert_n6', 'shadowserver'],
            'kategorie'         => ['exposure', 'malware', 'other'],
            'max_waga'          => 'high',
        ]);

        $this->assertSame('Szczegóły', $out['cta']);
        $this->assertStringContainsString('ekspozycja/malware', $out['zdanie']);
        $this->assertStringContainsString('N6+Shadowserver', $out['zdanie']);
        $this->assertStringContainsString('waga high', $out['zdanie']);

        $teksty = array_column($out['chipy'], 'tekst');
        $this->assertContains('HIGH', $teksty);
        $this->assertContains('3 incydenty', $teksty);
        $this->assertContains('N6', $teksty);
        $this->assertContains('Shadowserver', $teksty);
        $this->assertContains('ekspozycja', $teksty);
        $this->assertNotContains('inne', $teksty);
    }

    public function testPustyAgregat(): void
    {
        $out = (new KontekstIp())->zbuduj([]);
        $this->assertSame('Brak widocznych zgłoszeń w oknie.', $out['zdanie']);
        $this->assertSame([], $out['chipy']);
    }
}
