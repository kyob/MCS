<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DashboardViewTest extends CIUnitTestCase
{
    public function testWidokPrzegladuRenderujeSekcje(): void
    {
        $html = view('dashboard/index', [
            'title'          => 'Przegląd — MCS',
            'navAktywny'     => 'dashboard',
            'email'          => 'test@example.com',
            'rola'           => 'admin',
            'flashBlad'      => null,
            'blad'           => null,
            'okno'           => 7,
            'filtrZrodlo'    => null,
            'filtrIp'        => null,
            'filtrMinWaga'   => null,
            'filtrMinZrodel' => 2,
            'zrodla'         => ['shadowserver', 'cert_n6'],
            'kpi'            => [
                'okno_dni'          => 7,
                'liczba_incydentow' => 12,
                'liczba_ip'         => 5,
                'ip_wielozrodlowe'  => 2,
                'critical_high'     => 3,
                'max_waga'          => 'critical',
            ],
            'topIp' => [
                [
                    'ip'                => '198.51.100.10',
                    'liczba_incydentow' => 4,
                    'liczba_zrodel'     => 2,
                    'zrodla'            => ['cert_n6', 'shadowserver'],
                    'max_waga'          => 'high',
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                ],
            ],
            'ostatnie' => [
                [
                    'podmiot'           => '198.51.100.10',
                    'waga'              => 'high',
                    'zrodlo'            => 'shadowserver',
                    'tytul'             => 'Test skrót',
                    'kategoria'         => 'exposure',
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                ],
            ],
        ]);

        $this->assertStringContainsString('Przegląd zagrożeń', $html);
        $this->assertStringContainsString('Problematyczne IP', $html);
        $this->assertStringContainsString('198.51.100.10', $html);
        $this->assertStringContainsString('Ostatnie incydenty', $html);
        $this->assertStringContainsString('cert_n6', $html);
    }
}
