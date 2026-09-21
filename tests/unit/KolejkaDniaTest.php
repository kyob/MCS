<?php

use App\Libraries\KolejkaDnia;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class KolejkaDniaTest extends CIUnitTestCase
{
    public function testFiltryBazoweWymuszajaOkno2IWageHigh(): void
    {
        $f = (new KolejkaDnia())->filtryBazowe();

        $this->assertSame(2, $f['okno']);
        $this->assertSame('high', $f['minWaga']);
        $this->assertSame(1, $f['minZrodel']);
        $this->assertNull($f['zrodlo']);
        $this->assertNull($f['ip']);
        $this->assertNull($f['kategoria']);
        $this->assertNull($f['port']);
    }

    public function testDoQueryZawieraOknoIWage(): void
    {
        $qs = (new KolejkaDnia())->doQuery();

        $this->assertStringContainsString('okno=2', $qs);
        $this->assertStringContainsString('waga=high', $qs);
        $this->assertStringContainsString('min_zrodel=1', $qs);
    }
}
