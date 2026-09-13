<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IpShowViewTest extends CIUnitTestCase
{
    public function testWidokMaLinkDoSzczegolowIPanelAbuseipdb(): void
    {
        $html = view('ip/show', [
            'title'           => 'IP 198.51.100.10 — MCS',
            'navAktywny'      => 'ip',
            'ipNav'           => '198.51.100.10',
            'szukajIp'        => '198.51.100.10',
            'ip'              => '198.51.100.10',
            'blad'            => null,
            'incydenty'       => [
                [
                    'id'                => 42,
                    'waga'              => 'high',
                    'zrodlo'            => 'shadowserver',
                    'tytul'             => 'Test',
                    'kategoria'         => 'exposure',
                    'port'              => 22,
                    'protokol'          => 'tcp',
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                ],
            ],
            'kartyZrodel'        => [
                [
                    'incydent_id'        => 42,
                    'zrodlo'             => 'shadowserver',
                    'zrodlo_etykieta'    => 'Shadowserver',
                    'waga'               => 'high',
                    'kategoria'          => 'exposure',
                    'kategoria_etykieta' => 'ekspozycja',
                    'tytul'              => 'Test',
                    'port_tekst'         => '22/tcp',
                    'ostatnio_widziane'  => '2026-09-11 10:00:00',
                    'url'                => 'http://example.test/ip/198.51.100.10/incydent/42',
                    'pola'               => [
                        ['klucz' => 'tag', 'wartosc' => 'open'],
                        ['klucz' => 'port', 'wartosc' => '22'],
                    ],
                    'liczba_obserwacji'  => 2,
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

        $this->assertStringContainsString('ip/198.51.100.10/incydent/42', $html);
        $this->assertStringContainsString('Karty źródeł', $html);
        $this->assertStringContainsString('Shadowserver', $html);
        $this->assertStringContainsString('ekspozycja', $html);
        $this->assertStringContainsString('open', $html);
        $this->assertStringContainsString('Timeline i pełne dane', $html);
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
            'incydenty'       => [],
            'kartyZrodel'     => [],
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
