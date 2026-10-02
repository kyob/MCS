<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class IncydentViewTest extends CIUnitTestCase
{
    public function testWidokSzczegolowRenderujeTimelineIZrodlo(): void
    {
        $html = view('ip/incydent', [
            'title'      => 'Incydent #7 — MCS',
            'navAktywny' => 'ip',
            'ipNav'      => '198.51.100.10',
            'szukajIp'   => '198.51.100.10',
            'ip'         => '198.51.100.10',
            'email'      => 'op@example.com',
            'rola'       => 'operator',
            'blad'       => null,
            'flashOk'    => null,
            'flashBlad'  => null,
            'szczegoly'  => [
                'incydent' => [
                    'id'                => 7,
                    'zrodlo'            => 'shadowserver',
                    'tytul'             => 'Shadowserver — open',
                    'waga'              => 'high',
                    'kategoria'         => 'exposure',
                    'port'              => 22,
                    'protokol'          => 'tcp',
                    'pierwsze_widziane' => '2026-09-10 08:00:00',
                    'ostatnio_widziane' => '2026-09-11 10:00:00',
                    'klucz_dedupu'      => 'k1',
                ],
                'obserwacje' => [
                    [
                        'id'               => 2,
                        'zaobserwowano_at' => '2026-09-11 10:00:00',
                        'id_w_zrodle'      => '55',
                        'payload'          => ['dzień' => 2],
                    ],
                    [
                        'id'               => 1,
                        'zaobserwowano_at' => '2026-09-10 08:00:00',
                        'id_w_zrodle'      => '50',
                        'payload'          => ['dzień' => 1],
                    ],
                ],
                'zrodlo_tabela' => 'mcs_shadowserver_wiersze',
                'zrodlo_wiersz' => [
                    'id'  => 55,
                    'ip'  => '198.51.100.10',
                    'tag' => 'open',
                    'port'=> 22,
                ],
            ],
        ]);

        $this->assertStringContainsString('Shadowserver — open', $html);
        $this->assertStringContainsString('Historia obserwacji', $html);
        $this->assertStringContainsString('2026-09-11 10:00:00', $html);
        $this->assertStringContainsString('mcs_shadowserver_wiersze', $html);
        $this->assertStringContainsString('open', $html);
        $this->assertStringContainsString('ip/198.51.100.10', $html);
        $this->assertStringContainsString('Ukryj w UI', $html);
        $this->assertStringContainsString('incydent/7/ukryj', $html);
        $this->assertStringContainsString('csrf_test_name', $html);
    }
}
