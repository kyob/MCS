<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class UstawieniaViewTest extends CIUnitTestCase
{
    public function testWidokMaPrzelacznikRevDns(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'           => 'Ustawienia — MCS',
            'navAktywny'      => 'ustawienia',
            'email'           => 'admin@example.com',
            'rola'            => 'admin',
            'komunikat'       => null,
            'blad'            => null,
            'asWlaczone'      => true,
            'asCidryTekst'    => "91.231.80.0/22\n",
            'asAsn'           => 198050,
            'asDomyslneCidry' => ['91.231.80.0/22'],
            'revDnsWlaczone'  => false,
        ]);

        $this->assertStringContainsString('name="pokazuj_rev_dns"', $html);
        $this->assertStringContainsString('Pokazuj reverse DNS', $html);
        $this->assertStringContainsString('PTR', $html);
        $this->assertStringContainsString('csrf', $html);
    }

    public function testWidokZaznaczaRevDnsGdyWlaczone(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'           => 'Ustawienia — MCS',
            'navAktywny'      => 'ustawienia',
            'email'           => 'admin@example.com',
            'rola'            => 'admin',
            'komunikat'       => null,
            'blad'            => null,
            'asWlaczone'      => false,
            'asCidryTekst'    => '',
            'asAsn'           => 198050,
            'asDomyslneCidry' => [],
            'revDnsWlaczone'  => true,
        ]);

        $this->assertMatchesRegularExpression(
            '/name="pokazuj_rev_dns"[^>]*checked|checked[^>]*name="pokazuj_rev_dns"/',
            $html,
        );
    }

    public function testWidokMaDomyslneFiltryPrzegladu(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'              => 'Ustawienia — MCS',
            'navAktywny'         => 'ustawienia',
            'email'              => 'admin@example.com',
            'rola'               => 'admin',
            'komunikat'          => null,
            'blad'               => null,
            'asWlaczone'         => false,
            'asCidryTekst'       => '',
            'asAsn'              => 198050,
            'asDomyslneCidry'    => [],
            'revDnsWlaczone'     => false,
            'dashboardOkno'      => 14,
            'dashboardZrodlo'    => 'shadowserver',
            'dashboardKategoria' => 'malware',
            'dashboardPort'      => 22,
            'dashboardWaga'      => 'high',
            'dashboardMinZrodel' => 2,
            'zrodla'             => ['shadowserver', 'cert_n6'],
            'kategorie'          => ['exposure', 'malware'],
        ]);

        $this->assertStringContainsString('Domyślne filtry przeglądu', $html);
        $this->assertStringContainsString('name="dashboard_okno"', $html);
        $this->assertStringContainsString('name="dashboard_zrodlo"', $html);
        $this->assertStringContainsString('name="dashboard_kategoria"', $html);
        $this->assertStringContainsString('name="dashboard_port"', $html);
        $this->assertStringContainsString('name="dashboard_waga"', $html);
        $this->assertStringContainsString('name="dashboard_min_zrodel"', $html);
        $this->assertMatchesRegularExpression('/<option value="14"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="shadowserver"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="high"[^>]*selected/', $html);
    }
}
