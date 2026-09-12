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
                    'import_wstrzymany' => true,
                    'ukryty_w_ui'       => false,
                    'health_ok'         => null,
                    'health_msg'        => '',
                    'health_at'         => '',
                ],
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
        $this->assertStringContainsString('kwarantanna', $html);
        $this->assertStringContainsString('zły CSV', $html);
        $this->assertStringContainsString('shadowserver', $html);
        $this->assertStringContainsString('disabled', $html);
    }
}
