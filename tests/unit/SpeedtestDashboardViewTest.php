<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SpeedtestDashboardViewTest extends CIUnitTestCase
{
    public function testWidokRenderujeKpiTabeleITrend(): void
    {
        $html = view('dashboard/speedtest', [
            'title'      => 'Speedtest — MCS',
            'navAktywny' => 'speedtest',
            'email'      => 'op@example.com',
            'rola'       => 'operator',
            'flashBlad'  => null,
            'flashOk'    => null,
            'filtry'     => [
                'okno'              => 7,
                'min_download_mbps' => 500,
                'min_upload_mbps'   => 500,
                'cidr'              => '',
                'sort'              => 'download',
                'top_n'             => 20,
                'tylko_as'          => true,
                'wszystkie'         => false,
            ],
            'kpi'        => [
                'okno_dni'          => 7,
                'liczba_testow'     => 12,
                'liczba_cidr'       => 3,
                'avg_download_kbps' => 50000.0,
                'p50_download_kbps' => 48000,
                'avg_upload_kbps'   => 12000.0,
                'p50_upload_kbps'   => 11000,
                'avg_latency_ms'    => 18.5,
                'p50_latency_ms'    => 16.0,
            ],
            'anomalie' => [
                'liczba_powyzej'    => 2,
                'liczba_pasujacych' => 2,
                'top_testy'         => [
                    [
                        'test_id'          => 'sat-1',
                        'cidr'             => '91.231.80.0/24',
                        'download_kbps'    => 915930,
                        'upload_kbps'      => 100000,
                        'latency_ms'       => 12.0,
                        'zaobserwowano_at' => '2026-09-05 12:00:00',
                    ],
                ],
                'cidr_peaki' => [
                    [
                        'cidr'                 => '91.231.80.0/24',
                        'liczba_testow'        => 8,
                        'max_download_kbps'    => 915930,
                        'max_upload_kbps'      => 4330000,
                        'avg_download_kbps'    => 52000.0,
                        'avg_upload_kbps'      => 13000.0,
                        'peak_download_ratio'  => 17.6,
                        'peak_upload_ratio'    => 333.0,
                        'liczba_powyzej'       => 2,
                        'ostatni_test_at'      => '2026-09-16 12:00:00+00:00',
                    ],
                ],
            ],
            'agregaty' => [
                [
                    'cidr'                 => '91.231.80.0/24',
                    'liczba_testow'        => 8,
                    'avg_download_kbps'    => 52000.0,
                    'p50_download_kbps'    => 50000,
                    'avg_upload_kbps'      => 13000.0,
                    'p50_upload_kbps'      => 12000,
                    'avg_latency_ms'       => 15.0,
                    'p50_latency_ms'       => 14.0,
                    'max_download_kbps'    => 915930,
                    'max_upload_kbps'      => 4330000,
                    'peak_download_ratio'  => 17.6,
                    'peak_upload_ratio'    => 333.0,
                    'ostatni_test_at'      => '2026-09-16 12:00:00+00:00',
                ],
            ],
            'dzienne' => [
                [
                    'dzien'             => '2026-09-16',
                    'liczba_testow'     => 5,
                    'avg_download_kbps' => 51000.0,
                    'avg_upload_kbps'   => 12500.0,
                    'avg_latency_ms'    => 14.5,
                ],
            ],
        ]);

        $this->assertStringContainsString('Speedtest', $html);
        $this->assertStringContainsString('Agregaty /24', $html);
        $this->assertStringContainsString('91.231.80.0/24', $html);
        $this->assertStringContainsString('Unikalne /24·/48', $html);
        $this->assertStringContainsString('Liczba testów', $html);
        $this->assertStringContainsString('Trend dzienny', $html);
        $this->assertStringContainsString('2026-09-16', $html);
        $this->assertStringContainsString('dashboard/speedtest', $html);
        $this->assertStringContainsString('name="okno"', $html);
        $this->assertStringContainsString('name="min_download_mbps"', $html);
        $this->assertStringContainsString('name="min_upload_mbps"', $html);
        $this->assertStringContainsString('name="cidr"', $html);
        $this->assertStringContainsString('name="sort"', $html);
        $this->assertStringContainsString('name="tylko_as"', $html);
        $this->assertStringContainsString('name="wszystkie"', $html);
        $this->assertStringContainsString('Pokaż wszystkie pomiary', $html);
        $this->assertStringContainsString('AS198050', $html);
        $this->assertStringContainsString('Wysycenie portu', $html);
        $this->assertStringContainsString('Najcięższe testy', $html);
        $this->assertStringContainsString('sat-1', $html);
        $this->assertStringContainsString('Sieci /24 z peakami', $html);
        $this->assertStringContainsString('Ookla anonimizuje', $html);
        $this->assertStringContainsString('Servers reporting', $html);
        $this->assertStringContainsString('IPv6', $html);
        $this->assertStringContainsString('Down max', $html);
        $this->assertStringNotContainsString('min_upload_mbbs', $html);
        $this->assertStringNotContainsString('panel-speedtest', $html);
    }

    public function testWidokWszystkiePomiaryZmieniaEtykieteTabeli(): void
    {
        $html = view('dashboard/speedtest', [
            'title'      => 'Speedtest — MCS',
            'navAktywny' => 'speedtest',
            'email'      => 'op@example.com',
            'rola'       => 'operator',
            'flashBlad'  => null,
            'flashOk'    => null,
            'filtry'     => [
                'okno'              => 0,
                'min_download_mbps' => 500,
                'min_upload_mbps'   => 500,
                'cidr'              => '',
                'sort'              => 'data',
                'top_n'             => 100,
                'tylko_as'          => true,
                'wszystkie'         => true,
            ],
            'kpi' => [
                'okno_dni'          => 0,
                'liczba_testow'     => 3,
                'liczba_cidr'       => 1,
                'avg_download_kbps' => 10000.0,
                'p50_download_kbps' => 10000,
                'avg_upload_kbps'   => 5000.0,
                'p50_upload_kbps'   => 5000,
                'avg_latency_ms'    => 10.0,
                'p50_latency_ms'    => 10.0,
            ],
            'anomalie' => [
                'liczba_powyzej'    => 1,
                'liczba_pasujacych' => 3,
                'top_testy'         => [
                    [
                        'test_id'          => 'low-1',
                        'cidr'             => '91.231.80.0/24',
                        'download_kbps'    => 5000,
                        'upload_kbps'      => 2000,
                        'latency_ms'       => 20.0,
                        'zaobserwowano_at' => '2026-09-05 13:00:00',
                    ],
                ],
                'cidr_peaki' => [],
            ],
            'agregaty' => [],
            'dzienne'  => [],
        ]);

        $this->assertStringContainsString('Pomiary', $html);
        $this->assertStringNotContainsString('Najcięższe testy', $html);
        $this->assertStringContainsString('id="filtr-wszystkie"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function testWidokPustyStan(): void
    {
        $html = view('dashboard/speedtest', [
            'title'      => 'Speedtest — MCS',
            'navAktywny' => 'speedtest',
            'email'      => 'op@example.com',
            'rola'       => 'operator',
            'flashBlad'  => null,
            'flashOk'    => null,
            'filtry'     => [
                'okno'              => 0,
                'min_download_mbps' => 500,
                'min_upload_mbps'   => 500,
                'cidr'              => '',
                'sort'              => 'download',
                'top_n'             => 20,
                'tylko_as'          => true,
                'wszystkie'         => false,
            ],
            'kpi'        => [
                'okno_dni'          => 0,
                'liczba_testow'     => 0,
                'liczba_cidr'       => 0,
                'avg_download_kbps' => null,
                'p50_download_kbps' => null,
                'avg_upload_kbps'   => null,
                'p50_upload_kbps'   => null,
                'avg_latency_ms'    => null,
                'p50_latency_ms'    => null,
            ],
            'anomalie' => [
                'liczba_powyzej'    => 0,
                'liczba_pasujacych' => 0,
                'top_testy'         => [],
                'cidr_peaki'        => [],
            ],
            'agregaty' => [],
            'dzienne'  => [],
        ]);

        $this->assertStringContainsString('Brak testów Speedtest', $html);
        $this->assertStringContainsString('Cała historia', $html);
        $this->assertStringContainsString('Brak testów powyżej progów', $html);
    }
}
