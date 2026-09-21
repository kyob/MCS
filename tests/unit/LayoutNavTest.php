<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class LayoutNavTest extends CIUnitTestCase
{
    public function testSuperadminWidziUzytkownikowIPanelAdmin(): void
    {
        $html = $this->html('superadmin');

        $this->assertStringContainsString('admin/uzytkownicy', $html);
        $this->assertStringContainsString('Użytkownicy', $html);
        $this->assertStringContainsString('admin/pluginy', $html);
        $this->assertStringContainsString('admin/ustawienia', $html);
        $this->assertStringContainsString('Superadmin', $html);
    }

    public function testAdminWidziPanelBezUzytkownikow(): void
    {
        $html = $this->html('admin');

        $this->assertStringContainsString('admin/pluginy', $html);
        $this->assertStringContainsString('admin/ustawienia', $html);
        $this->assertStringNotContainsString('admin/uzytkownicy', $html);
        $this->assertStringContainsString(' · Admin', $html);
    }

    public function testOperatorNieWidziAdmina(): void
    {
        $html = $this->html('operator');

        $this->assertStringNotContainsString('admin/pluginy', $html);
        $this->assertStringNotContainsString('admin/ustawienia', $html);
        $this->assertStringNotContainsString('admin/uzytkownicy', $html);
        $this->assertStringContainsString('Operator', $html);
    }

    public function testDashboardMaSubmenuList(): void
    {
        $html = $this->html('operator');

        $this->assertStringContainsString('dropdown-toggle', $html);
        $this->assertStringContainsString('Przegląd', $html);
        $this->assertStringContainsString('Kolejka dnia', $html);
        $this->assertStringContainsString('dashboard/kolejka', $html);
        $this->assertStringContainsString('Problematyczne IP', $html);
        $this->assertStringContainsString('Ostatnie incydenty', $html);
        $this->assertStringContainsString('dashboard/problematyczne-ip', $html);
        $this->assertStringContainsString('dashboard/incydenty', $html);
        $this->assertStringContainsString('Speedtest', $html);
        $this->assertStringContainsString('dashboard/speedtest', $html);
    }

    private function html(string $rola): string
    {
        return view('watchlista/index', [
            'title'      => 'Watchlista IP — MCS',
            'navAktywny' => 'watchlista',
            'email'      => 'test@example.com',
            'rola'       => $rola,
            'komunikat'  => null,
            'blad'       => null,
            'asAsn'      => 198050,
            'lista'      => [],
        ]);
    }
}
