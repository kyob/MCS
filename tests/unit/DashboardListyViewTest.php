<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DashboardListyViewTest extends CIUnitTestCase
{
    public function testProblematyczneIpRenderujeTabeleICsv(): void
    {
        $html = view('dashboard/problematyczne_ip', [
            'title'           => 'Problematyczne IP — MCS',
            'navAktywny'      => 'problematyczne-ip',
            'email'           => 'test@example.com',
            'rola'            => 'admin',
            'flashBlad'       => null,
            'flashOk'         => null,
            'blad'            => null,
            'okno'            => 7,
            'filtrZrodlo'     => null,
            'filtrIp'         => null,
            'filtrMinWaga'    => null,
            'filtrMinZrodel'  => 1,
            'filtrKategoria'  => null,
            'filtrPort'       => null,
            'zrodla'          => ['shadowserver'],
            'kategorie'       => ['exposure'],
            'queryEksport'    => 'okno=7',
            'formAkcja'       => 'http://example.com/dashboard/problematyczne-ip',
            'wyczyscHref'     => 'http://example.com/dashboard/problematyczne-ip?wyczysc=1',
            'asMatchWlaczone' => false,
            'revDnsWlaczone'  => true,
            'pager'           => null,
            'listaOd'         => 1,
            'listaDo'         => 1,
            'listaTotal'      => 1,
            'topIp'           => [
                [
                    'ip'                => '198.51.100.10',
                    'liczba_incydentow' => 4,
                    'liczba_zrodel'     => 2,
                    'zrodla'            => ['cert_n6', 'shadowserver'],
                    'kategorie'         => ['exposure', 'malware'],
                    'max_waga'          => 'high',
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                    'rev_dns'           => 'ptr.example.test',
                    'kontekst'          => [
                        'zdanie' => '',
                        'chipy'  => [
                            ['typ' => 'waga-high', 'tekst' => 'HIGH'],
                            ['typ' => 'zrodlo', 'tekst' => 'N6'],
                            ['typ' => 'kategoria', 'tekst' => 'ekspozycja'],
                        ],
                        'cta' => 'Szczegóły',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Problematyczne IP', $html);
        $this->assertStringContainsString('198.51.100.10', $html);
        $this->assertStringContainsString('ptr.example.test', $html);
        $this->assertStringContainsString('N6', $html);
        $this->assertStringContainsString('Szczegóły', $html);
        $this->assertStringContainsString('ekspozycja', $html);
        $this->assertStringContainsString('Eksport CSV', $html);
        $this->assertStringContainsString('dashboard/eksport.csv', $html);
        $this->assertStringContainsString('name="kategoria"', $html);
        $this->assertStringContainsString('dashboard/problematyczne-ip?wyczysc=1', $html);
        $this->assertMatchesRegularExpression('/action="[^"]*dashboard\/problematyczne-ip"/', $html);
        $this->assertStringContainsString('dropdown-item active', $html);
        $this->assertStringNotContainsString('Sprawdź szczegóły IP', $html);
    }

    public function testKolejkaRenderujeNaglowekIPreset(): void
    {
        $html = view('dashboard/kolejka', [
            'title'           => 'Kolejka dnia — MCS',
            'navAktywny'      => 'kolejka',
            'email'           => 'test@example.com',
            'rola'            => 'operator',
            'flashBlad'       => null,
            'flashOk'         => null,
            'blad'            => null,
            'okno'            => 2,
            'filtrZrodlo'     => null,
            'filtrIp'         => null,
            'filtrMinWaga'    => 'high',
            'filtrMinZrodel'  => 1,
            'filtrKategoria'  => null,
            'filtrPort'       => null,
            'zrodla'          => [],
            'kategorie'       => [],
            'queryEksport'    => 'okno=2&waga=high',
            'formAkcja'       => 'http://example.com/dashboard/kolejka',
            'wyczyscHref'     => 'http://example.com/dashboard/kolejka',
            'asMatchWlaczone' => true,
            'revDnsWlaczone'  => false,
            'pager'           => null,
            'listaOd'         => 0,
            'listaDo'         => 0,
            'listaTotal'      => 0,
            'topIp'           => [],
            'podsieciAs'      => [],
            'filtrCidr'       => null,
            'filtrCidrEfekt'  => null,
            'filtrPodsiec'    => null,
        ]);

        $this->assertStringContainsString('Kolejka dnia', $html);
        $this->assertStringContainsString('48h', $html);
        $this->assertStringContainsString('high', $html);
    }

    public function testProblematyczneIpMaFiltrPodsieciICidr(): void
    {
        $html = view('dashboard/problematyczne_ip', [
            'title'           => 'Problematyczne IP — MCS',
            'navAktywny'      => 'problematyczne-ip',
            'email'           => 'test@example.com',
            'rola'            => 'admin',
            'flashBlad'       => null,
            'flashOk'         => null,
            'blad'            => null,
            'okno'            => 90,
            'filtrZrodlo'     => 'snds',
            'filtrIp'         => null,
            'filtrMinWaga'    => null,
            'filtrMinZrodel'  => 1,
            'filtrKategoria'  => null,
            'filtrPort'       => null,
            'filtrPodsiec'    => '91.231.80.0/22',
            'filtrCidr'       => '',
            'filtrCidrEfekt'  => '91.231.80.0/22',
            'podsieciAs'      => ['91.231.80.0/22', '176.97.24.0/21'],
            'zrodla'          => ['snds'],
            'kategorie'       => ['reputacja'],
            'queryEksport'    => 'okno=90&zrodlo=snds&cidr=91.231.80.0%2F22',
            'formAkcja'       => 'http://example.com/dashboard/problematyczne-ip',
            'wyczyscHref'     => 'http://example.com/dashboard/problematyczne-ip?wyczysc=1',
            'asMatchWlaczone' => true,
            'revDnsWlaczone'  => false,
            'pager'           => null,
            'listaOd'         => 0,
            'listaDo'         => 0,
            'listaTotal'      => 0,
            'topIp'           => [],
        ]);

        $this->assertStringContainsString('name="podsiec"', $html);
        $this->assertStringContainsString('name="cidr"', $html);
        $this->assertStringContainsString('Podsieć', $html);
        $this->assertStringContainsString('id="filtr-cidr"', $html);
        $this->assertStringContainsString('91.231.80.0/22', $html);
        $this->assertStringContainsString('176.97.24.0/21', $html);
        $this->assertStringContainsString('selected', $html);
        $this->assertStringNotContainsString('Sieć (AS', $html);
        $this->assertStringContainsString('cidr=91.231.80.0%2F22', $html);
    }

    public function testOstatnieIncydentyRenderujeTabele(): void
    {
        $html = view('dashboard/incydenty', [
            'title'           => 'Ostatnie incydenty — MCS',
            'navAktywny'      => 'incydenty',
            'email'           => 'test@example.com',
            'rola'            => 'admin',
            'flashBlad'       => null,
            'flashOk'         => null,
            'blad'            => null,
            'okno'            => 7,
            'filtrZrodlo'     => null,
            'filtrIp'         => null,
            'filtrMinWaga'    => null,
            'filtrMinZrodel'  => 1,
            'filtrKategoria'  => null,
            'filtrPort'       => null,
            'zrodla'          => ['shadowserver'],
            'kategorie'       => ['exposure'],
            'queryEksport'    => 'okno=7',
            'formAkcja'       => 'http://example.com/dashboard/incydenty',
            'wyczyscHref'     => 'http://example.com/dashboard/incydenty?wyczysc=1',
            'asMatchWlaczone' => false,
            'pager'           => null,
            'listaOd'         => 1,
            'listaDo'         => 1,
            'listaTotal'      => 1,
            'ostatnie'        => [
                [
                    'id'                => 42,
                    'podmiot'           => '198.51.100.10',
                    'waga'              => 'high',
                    'zrodlo'            => 'shadowserver',
                    'tytul'             => 'Test skrót',
                    'kategoria'         => 'exposure',
                    'port'              => 22,
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                ],
            ],
        ]);

        $this->assertStringContainsString('Ostatnie incydenty', $html);
        $this->assertStringContainsString('Najnowsze skróty z indeksu', $html);
        $this->assertStringContainsString('198.51.100.10', $html);
        $this->assertStringContainsString('Test skrót', $html);
        $this->assertStringContainsString('ip/198.51.100.10/incydent/42', $html);
        $this->assertStringContainsString('name="port"', $html);
        $this->assertStringContainsString('dashboard/incydenty?wyczysc=1', $html);
        $this->assertMatchesRegularExpression('/action="[^"]*dashboard\/incydenty"/', $html);
        $this->assertStringContainsString('dropdown-item active', $html);
        $this->assertStringNotContainsString('Eksport CSV', $html);
    }
}
