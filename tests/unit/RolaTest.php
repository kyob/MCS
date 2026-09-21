<?php

use App\Libraries\Rola;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RolaTest extends CIUnitTestCase
{
    public function testEtykietyPoPolsku(): void
    {
        $this->assertSame('Superadmin', Rola::etykieta('superadmin'));
        $this->assertSame('Admin', Rola::etykieta('admin'));
        $this->assertSame('Operator', Rola::etykieta('operator'));
    }

    public function testDozwoloneRole(): void
    {
        $this->assertTrue(Rola::jestDozwolona('superadmin'));
        $this->assertTrue(Rola::jestDozwolona('admin'));
        $this->assertTrue(Rola::jestDozwolona('operator'));
        $this->assertFalse(Rola::jestDozwolona('admin '));
        $this->assertFalse(Rola::jestDozwolona('root'));
    }

    public function testPanelAdminDlaSuperadminaIAdmina(): void
    {
        $this->assertTrue(Rola::mozePanelAdmin('superadmin'));
        $this->assertTrue(Rola::mozePanelAdmin('admin'));
        $this->assertFalse(Rola::mozePanelAdmin('operator'));
        $this->assertFalse(Rola::mozePanelAdmin(''));
    }

    public function testUzytkownicyTylkoSuperadmin(): void
    {
        $this->assertTrue(Rola::mozeUzytkownicy('superadmin'));
        $this->assertFalse(Rola::mozeUzytkownicy('admin'));
        $this->assertFalse(Rola::mozeUzytkownicy('operator'));
    }
}
