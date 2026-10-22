<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PluginyViewTest extends CIUnitTestCase
{
    public function testWidokPokazujePobierzTerazIHistorie(): void
    {
        $html = view('admin/pluginy/index', [
            'title'      => 'Wtyczki źródeł — MCS',
            'navAktywny' => 'pluginy',
            'email'      => 'ops@example.com',
            'rola'       => 'operator',
            'komunikat'  => null,
            'blad'       => null,
            'pluginy'    => [
                [
                    'zrodlo'            => 'shadowserver',
                    'nazwa'             => 'Shadowserver',
                    'import_wstrzymany' => false,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => true,
                    'health_msg'        => 'import IMAP OK',
                    'health_at'         => '2026-09-11 20:00:00+00',
                ],
                [
                    'zrodlo'            => 'play',
                    'nazwa'             => 'Play',
                    'opis'              => 'Play Ochrona Anty-DDoS — MCS importuje alerty e-mail (IMAP) jako incydenty.',
                    'import_wstrzymany' => true,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => null,
                    'health_msg'        => '',
                    'health_at'         => '',
                ],
                [
                    'zrodlo'            => 'snds',
                    'nazwa'             => 'SNDS',
                    'import_wstrzymany' => false,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => false,
                    'health_msg'        => 'SNDS HTTP 404',
                    'health_at'         => '2026-10-06 07:15:00+00',
                ],
                [
                    'zrodlo'            => 'greynoise',
                    'nazwa'             => 'GreyNoise',
                    'import_wstrzymany' => true,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => null,
                    'health_msg'        => '',
                    'health_at'         => '',
                ],
                [
                    'zrodlo'            => 'urlhaus',
                    'nazwa'             => 'URLhaus',
                    'import_wstrzymany' => true,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => null,
                    'health_msg'        => '',
                    'health_at'         => '',
                ],
            ],
            'sndsKlucz' => [
                'ustawiony' => true,
                'zrodlo'    => 'env',
                'skrot'     => 'a1b2…7890',
            ],
            'kluczeZrodel' => [
                'greynoise' => ['ustawiony' => false, 'zrodlo' => 'domyslna', 'skrot' => ''],
                'urlhaus'   => ['ustawiony' => true, 'zrodlo' => 'db', 'skrot' => 'ab12…99zz'],
            ],
            'importy' => [
                [
                    'id'            => 9,
                    'zrodlo'        => 'shadowserver',
                    'status'        => 'ok',
                    'rozpoczeto_at' => '2026-09-11 19:00:00+00',
                    'zakonczono_at' => '2026-09-11 19:01:00+00',
                    'blad_text'     => null,
                    'checksum'      => 'abcdef0123456789ffff',
                    'id_wiadomosci' => null,
                ],
                [
                    'id'            => 8,
                    'zrodlo'        => 'snds',
                    'status'        => 'kwarantanna',
                    'rozpoczeto_at' => '2026-09-11 18:00:00+00',
                    'zakonczono_at' => '2026-09-11 18:00:30+00',
                    'blad_text'     => 'zły CSV',
                    'checksum'      => null,
                    'id_wiadomosci' => null,
                ],
            ],
        ]);

        $this->assertStringContainsString('Pobierz teraz', $html);
        $this->assertStringContainsString('admin/pluginy/pobierz', $html);
        $this->assertStringContainsString('Historia importów', $html);
        $this->assertStringContainsString('Pełna historia importów', $html);
        $this->assertStringContainsString('admin/pluginy/historia-importow', $html);
        $this->assertStringContainsString('kwarantanna', $html);
        $this->assertStringContainsString('zły CSV', $html);
        $this->assertStringContainsString('shadowserver', $html);
        $this->assertStringContainsString('Play Ochrona Anty-DDoS', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('bi-key me-1', $html);
        $this->assertStringContainsString('admin/pluginy/snds-klucz', $html);
        $this->assertStringContainsString('name="mcs_snds_key"', $html);
        $this->assertStringContainsString('a1b2…7890', $html);
        $this->assertStringContainsString('modalSndsKlucz', $html);
        $this->assertStringContainsString('Zapisz klucz', $html);
        $this->assertStringContainsString('Pobierz klucz z portalu Auto', $html);
        $this->assertStringContainsString('https://substrate.office.com/ip-domain-management-snds/SNDS/Auto', $html);
        // Przycisk Klucz przy SNDS jest osobny od GreyNoise i URLhaus.
        $this->assertSame(1, substr_count($html, 'data-bs-target="#modalSndsKlucz"'));
        $this->assertStringContainsString('data-bs-target="#modalGreynoiseKlucz"', $html);
        $this->assertStringContainsString('data-bs-target="#modalUrlhausKlucz"', $html);
        $this->assertStringContainsString('name="mcs_greynoise_key"', $html);
        $this->assertStringContainsString('name="mcs_urlhaus_key"', $html);
        $this->assertStringContainsString('admin/pluginy/klucz', $html);
        $this->assertStringContainsString('https://viz.greynoise.io/account', $html);
        $this->assertStringContainsString('https://auth.abuse.ch/', $html);
        $this->assertStringContainsString('brak klucza', $html);
        $this->assertStringContainsString('ab12…99zz', $html);
        $this->assertStringContainsString('z panelu', $html);

        $this->assertStringContainsString('>Lp.</th>', $html);
        $this->assertStringContainsString('>Nazwa</th>', $html);
        $this->assertStringContainsString('>Import</th>', $html);
        $this->assertStringContainsString('>UI</th>', $html);
        $this->assertStringContainsString('>Ostatni update</th>', $html);
        $this->assertStringContainsString('>Status</th>', $html);
        $this->assertStringContainsString('>Akcje</th>', $html);
        $this->assertStringContainsString('>aktywny</button>', $html);
        $this->assertStringContainsString('>nieaktywny</button>', $html);
        $this->assertStringContainsString('>widoczny</button>', $html);
        $this->assertStringContainsString('d-inline-flex', $html);
        $this->assertStringContainsString('bi-check-circle-fill', $html);
        $this->assertStringContainsString('temu', $html);
        $this->assertStringNotContainsString('Wstrzymaj import', $html);
        $this->assertStringNotContainsString('Ukryj w UI / badge', $html);
        $this->assertStringNotContainsString('>Zapisz</button>', $html);
    }

    public function testWidokPokazujeSkrotPolaczeniaIPelnyHealth(): void
    {
        $dlugiHealth = 'sync LMS OK (tryb=fixture, zapisano=12, trafionych=12, jeszcze trochę szczegółów ponad siedemdziesiąt znaków)';

        $html = view('admin/pluginy/index', [
            'title'      => 'Wtyczki źródeł — MCS',
            'navAktywny' => 'pluginy',
            'email'      => 'ops@example.com',
            'rola'       => 'admin',
            'komunikat'  => null,
            'blad'       => null,
            'importy'    => [],
            'pluginy'    => [
                [
                    'zrodlo'            => 'lms_mapowanie',
                    'nazwa'             => 'LMS mapowanie',
                    'opis'              => 'Mapowanie IP',
                    'import_wstrzymany' => false,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => true,
                    'health_msg'        => $dlugiHealth,
                    'health_at'         => '2026-09-15 07:45:05+00',
                    'opis_polaczenia'   => [
                        'skrot'         => 'plik testowy · lms_mapowanie_sample.json',
                        'tryb_aktywny'  => 'plik_testowy',
                        'tryb_etykieta' => 'plik testowy (JSON)',
                        'pozycje'       => [
                            [
                                'nazwa'     => 'Plik testowy (JSON)',
                                'wartosc'   => '/tmp/lms_mapowanie_sample.json',
                                'aktywna'   => true,
                                'ustawiona' => true,
                            ],
                            [
                                'nazwa'     => 'HTTP LMS',
                                'wartosc'   => 'http://192.0.2.60/plugins/LMSMcsMapowaniePlugin/api.php',
                                'aktywna'   => false,
                                'ustawiona' => true,
                            ],
                            [
                                'nazwa'     => 'Baza LMS (PostgreSQL)',
                                'wartosc'   => '192.0.2.50:5432/lms',
                                'aktywna'   => false,
                                'ustawiona' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('plik testowy · lms_mapowanie_sample.json', $html);
        $this->assertStringContainsString('Szczegóły połączenia', $html);
        $this->assertStringContainsString('192.0.2.60', $html);
        $this->assertStringContainsString('192.0.2.50', $html);
        $this->assertStringContainsString('data-bs-toggle="popover"', $html);
        $this->assertStringContainsString('Aktywny', $html);
        $this->assertStringContainsString('zapisano=12, trafionych=12, jeszcze trochę szczegółów', $html);
        $this->assertStringContainsString('tryb=plik testowy', $html);
        $this->assertStringNotContainsString('tryb=fixture', $html);
        $this->assertStringNotContainsString('healthSkrot', $html);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $html);
        $this->assertStringNotContainsString('d-block text-break', $html);
        $this->assertStringNotContainsString('Szczegóły połączenia</button>', $html);
    }

    public function testWidokHistoriiPokazujeFiltryIWiersze(): void
    {
        $html = view('admin/pluginy/historia_importow', [
            'title'       => 'Historia importów — MCS',
            'navAktywny'  => 'pluginy',
            'email'       => 'ops@example.com',
            'rola'        => 'operator',
            'zrodla'      => ['shadowserver', 'snds'],
            'filtrZrodlo' => 'snds',
            'filtrStatus' => 'kwarantanna',
            'filtrQ'      => 'CSV',
            'filtrOd'     => '2026-09-01',
            'filtrDo'     => '2026-09-30',
            'pager'       => null,
            'importy'     => [
                [
                    'id'            => 8,
                    'zrodlo'        => 'snds',
                    'status'        => 'kwarantanna',
                    'rozpoczeto_at' => '2026-09-11 18:00:00+00',
                    'zakonczono_at' => '2026-09-11 18:00:30+00',
                    'blad_text'     => 'zły CSV',
                    'checksum'      => null,
                    'id_wiadomosci' => null,
                ],
            ],
        ]);

        $this->assertStringContainsString('Historia importów', $html);
        $this->assertStringContainsString('Filtruj', $html);
        $this->assertStringContainsString('name="zrodlo"', $html);
        $this->assertStringContainsString('name="status"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('name="od"', $html);
        $this->assertStringContainsString('name="do"', $html);
        $this->assertStringContainsString('selected', $html);
        $this->assertStringContainsString('zły CSV', $html);
        $this->assertStringContainsString('admin/pluginy', $html);
        // UTC 18:00 → Europe/Warsaw CEST 20:00 (bez sufiksu +00).
        $this->assertStringContainsString('2026-09-11 20:00:00', $html);
        $this->assertStringContainsString('2026-09-11 20:00:30', $html);
        $this->assertStringNotContainsString('18:00:00+00', $html);
    }
}
