<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IpShowViewTest extends CIUnitTestCase
{
    public function testWidokMaStatusProblemyIPanelAbuseipdb(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => [
                'max_waga'         => 'high',
                'liczba_problemow' => 1,
                'top_tytuly'       => ['Test'],
            ],
            'problemy'        => [
                [
                    'klucz'              => 'k1',
                    'zrodlo'             => 'shadowserver',
                    'zrodlo_etykieta'    => 'Shadowserver',
                    'waga'               => 'high',
                    'kategoria'          => 'exposure',
                    'kategoria_etykieta' => 'ekspozycja',
                    'tytul'              => 'Test',
                    'port_tekst'         => '22/tcp',
                    'liczba_zdarzen'     => 2,
                    'pierwsze_widziane'  => '2026-09-01 10:00:00',
                    'ostatnio_widziane'  => '2026-09-11 10:00:00',
                    'timeline'           => [
                        ['dzien' => '2026-09-11', 'aktywny' => true],
                        ['dzien' => '2026-09-12', 'aktywny' => false],
                    ],
                    'czlonkowie'         => [
                        [
                            'id'                => 42,
                            'waga'              => 'high',
                            'ostatnio_widziane' => '2026-09-11 10:00:00',
                            'url'               => 'http://example.test/ip/198.51.100.10/incydent/42',
                        ],
                    ],
                ],
            ],
            'zrodla'             => ['shadowserver'],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo fixturePath.',
        ]);

        $this->assertStringContainsString('1 problem', $html);
        $this->assertStringContainsString('Problemy', $html);
        $this->assertStringContainsString('Shadowserver', $html);
        $this->assertStringContainsString('ekspozycja', $html);
        $this->assertStringContainsString('mini-timeline', $html);
        $this->assertStringContainsString('incydent', $html);
        $this->assertStringContainsString('#42', $html);
        $this->assertStringContainsString('Timeline i pełne dane', $html);
        $this->assertStringNotContainsString('Karty źródeł', $html);
        $this->assertStringNotContainsString('Skróty incydentów', $html);
        $this->assertStringContainsString('AbuseIPDB', $html);
        $this->assertStringContainsString('mcs.abuseipdb.apiKey', $html);
        $this->assertStringContainsString('Sprawdź AbuseIPDB', $html);
    }

    public function testWidokPokazujeCacheAbuseipdb(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'statusIp'        => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'        => [],
            'zrodla'          => [],
            'filtrZrodlo'     => null,
            'asMatchWlaczone' => false,
            'naszeIp'         => false,
            'email'           => 'op@example.com',
            'rola'            => 'operator',
            'abuseipdbTryb'   => 'cache',
            'abuseipdbCache'  => [
                'abuse_confidence_score' => 42,
                'total_reports'          => 3,
                'country_code'           => 'PL',
                'isp'                    => 'Example ISP',
                'checked_at'             => '2026-09-11 12:00:00+00',
                'zrodlo_danych'          => 'fixture',
            ],
            'abuseipdbKomunikat' => null,
        ]);

        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('Example ISP', $html);
        $this->assertStringContainsString('PL', $html);
    }
}
