<?php

use App\Libraries\OpcjeMcs;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AbusixOpcjeTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::ustawModel(null);
    }

    protected function tearDown(): void
    {
        OpcjeMcs::resetuj();
        parent::tearDown();
    }

    public function testDomyslneWartosci(): void
    {
        $this->assertSame(100, OpcjeMcs::int('mcs.abusix.pageLimit'));
        $this->assertSame(2, OpcjeMcs::int('mcs.abusix.maxPages'));
    }

    public function testWalidacjaZakresow(): void
    {
        $this->assertFalse(OpcjeMcs::waliduj('mcs.abusix.pageLimit', '0')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.abusix.pageLimit', '501')['ok']);
        $this->assertTrue(OpcjeMcs::waliduj('mcs.abusix.pageLimit', '500')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.abusix.maxPages', '6')['ok']);
        $this->assertTrue(OpcjeMcs::waliduj('mcs.abusix.maxPages', '5')['ok']);
    }

    public function testTokenFormatIPominiecieWFormularzuOpcji(): void
    {
        $this->assertTrue(OpcjeMcs::waliduj('mcs.abusix.apiKey', 'sk_abcdefghijklmnopqrstuvwxyz0123')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.abusix.apiKey', 'krotki')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.abusix.apiKey', "zly token ze spacja 1234567890")['ok']);
        $this->assertArrayNotHasKey('mcs.abusix.apiKey', OpcjeMcs::doWidoku()['Abusix']);
        $this->assertArrayHasKey('mcs.abusix.pageLimit', OpcjeMcs::doWidoku()['Abusix']);
    }
}
