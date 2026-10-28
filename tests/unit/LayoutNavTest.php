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
        $this->assertStringContainsString('DNSBL', $html);
        $this->assertStringContainsString('dashboard/dnsbl', $html);
    }

    /**
     * @param array{status: string, opis: string}|null $pasek
     */
    private function html(string $rola, ?array $pasek = null): string
    {
        return view('watchlista/index', [
            'pasekZdrowia' => $pasek,
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

    public function testPasekZdrowiaPokazujeAwarieZLinkiemDlaAdmina(): void
    {
        $html = $this->html('admin', ['status' => 'CRITICAL', 'opis' => 'urlhaus: API 500']);

        $this->assertStringContainsString('id="pasek-zdrowia"', $html);
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('urlhaus: API 500', $html);
        $this->assertStringContainsString('Szczegóły', $html);
    }

    public function testPasekZdrowiaBezLinkuDlaOperatoraIEscapuje(): void
    {
        $html = $this->html('operator', ['status' => 'WARNING', 'opis' => '<script>x</script>']);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('admin/pluginy', $html);
    }

    public function testBezAwariiBrakPaska(): void
    {
        $html = $this->html('admin');

        $this->assertStringNotContainsString('id="pasek-zdrowia"', $html);
    }
}
