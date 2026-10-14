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
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => 'Brak konfiguracji LMS — ustaw mcs.lms.baseUrl+mapToken.',
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('1 problem', $html);
        $this->assertStringContainsString('Problemy', $html);
        $this->assertStringContainsString('Shadowserver', $html);
        $this->assertStringContainsString('ekspozycja', $html);
        $this->assertStringContainsString('mini-timeline', $html);
        $this->assertStringContainsString('incydent', $html);
        $this->assertStringContainsString('#42', $html);
        $this->assertStringContainsString('Timeline i pełne dane', $html);
        $this->assertStringContainsString('Ukryj w UI', $html);
        $this->assertStringContainsString('incydent/42/ukryj', $html);
        $this->assertStringNotContainsString('Karty źródeł', $html);
        $this->assertStringNotContainsString('Skróty incydentów', $html);
        $this->assertStringContainsString('LMS — komputer / abonent', $html);
        $this->assertStringContainsString('Sprawdź w LMS', $html);
        $this->assertStringContainsString('AbuseIPDB', $html);
        $this->assertStringContainsString('isMalicious', $html);
        $this->assertStringContainsString('SpamRats', $html);
        $this->assertStringContainsString('Sprawdź SpamRats', $html);
        $this->assertStringContainsString('DNSBL (AS)', $html);
        $this->assertStringContainsString('Sprawdź DNSBL', $html);
        $this->assertStringContainsString('mcs.abuseipdb.apiKey', $html);
        $this->assertStringContainsString('Sprawdź AbuseIPDB', $html);
        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringNotContainsString('brak testów Speedtest dla tego IP', $html);
        $this->assertStringNotContainsString('Sprawdź Speedtest', $html);
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
            'lmsTryb'         => 'cache',
            'lmsCache'        => [
                'trafiony'    => true,
                'komputer'    => 'Kochanowskiego-8',
                'abonent'     => 'MICHALAK Łukasz',
                'node_id'     => 11106,
                'customer_id' => 6603,
                'lms_url'     => 'http://lms.test/?m=nodeinfo&id=11106',
            ],
            'lmsKomunikat'    => null,
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
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('Example ISP', $html);
        $this->assertStringContainsString('PL', $html);
        $this->assertStringContainsString('Kochanowskiego-8', $html);
        $this->assertStringContainsString('MICHALAK Łukasz', $html);
        $this->assertStringContainsString('otwórz w LMS', $html);
    }

    public function testWidokPokazujeNotkeSpeedtestDla24(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 91.231.80.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '91.231.80.10',
            'szukajIp'           => '91.231.80.10',
            'ip'                 => '91.231.80.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => true,
            'naszeIp'            => true,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'speedtestCidr'        => '91.231.80.0/24',
            'speedtestLiczba24'    => 8,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringContainsString('testy w Twoim /24', $html);
        $this->assertStringContainsString('91.231.80.0/24', $html);
        $this->assertStringContainsString('dashboard/speedtest', $html);
        $this->assertStringNotContainsString('st-1001', $html);
        $this->assertStringNotContainsString('Sprawdź Speedtest', $html);
    }

    public function testWidokUkrywaNotkeSpeedtestGdyBrakDanych(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'speedtestCidr'        => null,
            'speedtestLiczba24'    => 0,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringNotContainsString('id="panel-speedtest"', $html);
        $this->assertStringNotContainsString('testy w Twoim /24', $html);
    }

    public function testWidokPokazujeRevDnsGdyWlaczone(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => true,
            'revDns'             => 'ptr.example.test',
        ]);

        $this->assertStringContainsString('ptr.example.test', $html);
        $this->assertStringContainsString('reverse DNS', $html);
    }

    public function testWidokUkrywaRevDnsGdyWylaczone(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => [
                'max_waga'         => '',
                'liczba_problemow' => 0,
                'top_tytuly'       => [],
            ],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => 'ptr.example.test',
        ]);

        $this->assertStringNotContainsString('ptr.example.test', $html);
    }

    public function testStatusPokazujeSkrotLmsGdyTrafiony(): void
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
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'dns',
            'dnsblCache'           => null,
            'dnsblKomunikat'       => null,
            'lmsTryb'      => 'cache',
            'lmsCache'     => [
                'trafiony'     => true,
                'komputer'     => 'Host-ABC',
                'abonent'      => 'Jan Kowalski',
                'node_id'      => 9910,
                'customer_id'  => 100,
                'lms_url'      => 'http://lms.test/node/9910',
            ],
            'lmsKomunikat' => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('Host-ABC', $html);
        $this->assertMatchesRegularExpression(
            '/Status IP[\s\S]*id="status-lms-skrot"[\s\S]*Host-ABC/',
            $html,
        );
    }

    public function testDnsblBladZapytaniaNieJestCzysty(): void
    {
        $html = view('ip/show', [
            'title'              => 'IP 198.51.100.10 — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => '198.51.100.10',
            'szukajIp'           => '198.51.100.10',
            'ip'                 => '198.51.100.10',
            'blad'               => null,
            'statusIp'           => ['max_waga' => '', 'liczba_problemow' => 0, 'top_tytuly' => []],
            'problemy'           => [],
            'zrodla'             => [],
            'filtrZrodlo'        => null,
            'asMatchWlaczone'    => false,
            'naszeIp'            => false,
            'email'              => 'op@example.com',
            'rola'               => 'operator',
            'lmsTryb'            => 'brak_konfiguracji',
            'lmsCache'           => null,
            'lmsKomunikat'       => null,
            'abuseipdbTryb'      => 'brak_klucza',
            'abuseipdbCache'     => null,
            'abuseipdbKomunikat' => null,
            'ismaliciousTryb'      => 'brak_klucza',
            'ismaliciousCache'     => null,
            'ismaliciousKomunikat' => null,
            'spamratsTryb'         => 'brak_klucza',
            'spamratsCache'        => null,
            'spamratsKomunikat'    => null,
            'dnsblTryb'            => 'cache',
            'dnsblCache'           => [
                'ip'         => '198.51.100.10',
                'listed_any' => false,
                'listy'      => [
                    'spamcop' => ['listed' => false, 'kody' => [], 'error' => 'błąd DNS'],
                    'dronebl' => ['listed' => false, 'kody' => [], 'error' => ''],
                ],
                'checked_at'    => '2026-10-14 10:00:00+00',
                'zrodlo_danych' => 'dns',
            ],
            'dnsblKomunikat'     => null,
            'flashOk'            => null,
            'flashBlad'          => null,
            'revDnsWlaczone'     => false,
            'revDns'             => null,
        ]);

        $this->assertStringContainsString('nieznane (błąd zapytania: spamcop)', $html);
        $this->assertStringContainsString('spamcop (błąd)', $html);
        $this->assertStringNotContainsString('id="dnsbl-listed">nie<', $html);
    }
}
