<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DashboardViewTest extends CIUnitTestCase
{
    public function testWidokPrzegladuRenderujeSekcjeBezTabelList(): void
    {
        $html = view('dashboard/index', $this->danePrzegladu());

        $this->assertStringContainsString('Przegląd zagrożeń', $html);
        $this->assertStringContainsString('Trendy dzienne z obserwacji', $html);
        $this->assertStringContainsString('name="kategoria"', $html);
        $this->assertStringContainsString('name="port"', $html);
        $this->assertStringContainsString('name="podsiec"', $html);
        $this->assertStringContainsString('name="cidr"', $html);
        $this->assertStringContainsString('selected', $html);
        $this->assertStringContainsString('malware', $html);
        $this->assertStringContainsString('wyczysc=1', $html);
        $this->assertStringContainsString('Kolejka dnia', $html);
        $this->assertStringContainsString('dashboard/kolejka', $html);
        $this->assertStringContainsString('dashboard/problematyczne-ip', $html);
        $this->assertStringContainsString('dashboard/incydenty', $html);
        $this->assertStringContainsString('Problematyczne IP', $html);
        $this->assertStringContainsString('Ostatnie incydenty', $html);
        $this->assertStringNotContainsString('Sort: źródła → waga', $html);
        $this->assertStringNotContainsString('Najnowsze skróty z indeksu', $html);
        $this->assertStringNotContainsString('Eksport CSV', $html);
        $this->assertStringNotContainsString('dashboard/eksport.csv', $html);
    }

    public function testWidokBezObserwacjiNiePokazujePustegoWykresu(): void
    {
        $html = view('dashboard/index', $this->danePrzegladu([
            'kpi' => [
                'okno_dni'          => 7,
                'liczba_incydentow' => 0,
                'liczba_ip'         => 0,
                'ip_wielozrodlowe'  => 0,
                'critical_high'     => 0,
                'max_waga'          => null,
            ],
            'trendy' => [
                'okno_dni'        => 7,
                'suma_obserwacji' => 0,
                'max_obserwacje'  => 0,
                'zrodla_suma'     => [],
                'dni'             => [],
            ],
        ]));

        $this->assertStringNotContainsString('Trendy dzienne z obserwacji', $html);
        $this->assertStringContainsString('dashboard/problematyczne-ip', $html);
    }

    /**
     * @param array<string, mixed> $nadpisz
     *
     * @return array<string, mixed>
     */
    private function danePrzegladu(array $nadpisz = []): array
    {
        return array_merge([
            'title'           => 'Przegląd — MCS',
            'navAktywny'      => 'dashboard',
            'email'           => 'test@example.com',
            'rola'            => 'admin',
            'flashBlad'       => null,
            'flashOk'         => null,
            'blad'            => null,
            'okno'            => 7,
            'filtrZrodlo'     => null,
            'filtrIp'         => null,
            'filtrMinWaga'    => null,
            'filtrMinZrodel'  => 2,
            'filtrKategoria'  => 'malware',
            'filtrPort'       => 443,
            'zrodla'          => ['shadowserver', 'cert_n6'],
            'kategorie'       => ['exposure', 'malware'],
            'queryEksport'    => 'okno=7&kategoria=malware&port=443',
            'asMatchWlaczone' => false,
            'kpi'             => [
                'okno_dni'          => 7,
                'liczba_incydentow' => 12,
                'liczba_ip'         => 5,
                'ip_wielozrodlowe'  => 2,
                'critical_high'     => 3,
                'max_waga'          => 'critical',
            ],
            'trendy' => [
                'okno_dni'        => 7,
                'suma_obserwacji' => 5,
                'max_obserwacje'  => 3,
                'zrodla_suma'     => ['shadowserver' => 3, 'cert_n6' => 2],
                'dni'             => [
                    [
                        'dzien'        => '2026-09-12',
                        'obserwacje'   => 3,
                        'nowe'         => 1,
                        'powtarzajace' => 2,
                        'zrodla'       => ['shadowserver' => 3],
                    ],
                ],
            ],
        ], $nadpisz);
    }
}
