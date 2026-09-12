<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WatchlistaViewTest extends CIUnitTestCase
{
    public function testWidokWatchlistyRenderujeSekcje(): void
    {
        $html = view('watchlista/index', [
            'title'      => 'Watchlista IP — MCS',
            'navAktywny' => 'watchlista',
            'email'      => 'test@example.com',
            'rola'       => 'admin',
            'komunikat'  => null,
            'blad'       => null,
            'asAsn'      => 198050,
            'lista'      => [
                [
                    'ip'           => '198.51.100.10',
                    'notatka'      => 'VIP',
                    'dodano_at'    => '2026-09-11 20:00:00',
                    'dodano_przez' => 'test@example.com',
                    'nasze_ip'     => false,
                ],
            ],
        ]);

        $this->assertStringContainsString('Watchlista IP', $html);
        $this->assertStringContainsString('198.51.100.10', $html);
        $this->assertStringContainsString('mcs:digest', $html);
        $this->assertStringContainsString('nie blokuje ani nie karze', $html);
        $this->assertStringContainsString('Dodaj do watchlisty', $html);
    }
}
