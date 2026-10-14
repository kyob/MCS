<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Widok skanera DNSBL na /dashboard/dnsbl (kafelki, tabela per strefa, filtry, Request removal).
 *
 * @internal
 */
final class DnsblSkanerViewTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $skaner
     */
    private function render(array $skaner): string
    {
        return view('dashboard/dnsbl', [
            'title' => 'DNSBL AS — MCS', 'navAktywny' => 'dnsbl', 'email' => 'op@example.com', 'rola' => 'operator',
            'flashBlad' => null, 'flashOk' => null, 'blad' => null,
            'kpi' => ['listed' => 0, 'listy_uniq' => 0], 'wiersze' => [], 'lista' => '', 'cidr' => '',
            'skaner' => $skaner,
        ]);
    }

    public function testZakonczonySkanPokazujeKafelkiStrefyIFiltry(): void
    {
        $skan = [
            'id' => 7, 'zakres' => '91.231.70.0/31', 'strefa_filtr' => null, 'status' => 'zakonczony', 'uzytkownik' => 'op@example.com',
            'koniec_at' => '2026-10-14 08:00:00+00', 'liczba_ip' => 2, 'czyste' => 1, 'listowane' => 1, 'bledy' => 0, 'czas_ms' => 1500,
            'zapytan_plan' => 2, 'zapytan_wykonano' => 2,
        ];
        $html = $this->render([
            'blad' => null, 'skan' => $skan, 'ostatnie' => [$skan], 'filtr' => 'listed', 'strona' => 1, 'naStrone' => 50,
            'prefiksy' => ['91.231.70.0/23'],
            'strefy' => [[
                'kod' => 'spamcop', 'nazwa' => 'SpamCop', 'strefa' => 'bl.spamcop.net', 'informacyjna' => false, 'status' => 'ok', 'powod' => '',
                'listed' => 1, 'bledy' => 0, 'url' => 'https://www.spamcop.net/bl.shtml?{ip}',
                'trafienia' => [['ip' => '91.231.70.1', 'strefa_kod' => 'spamcop', 'kody' => '127.0.0.2', 'txt' => 'Blocked']],
            ]],
            'szczegoly' => [
                'razem' => 1, 'liczniki' => ['listed' => 1, 'clean' => 1, 'bledy' => 0, 'all' => 2],
                'wiersze' => [['ip' => '91.231.70.1', 'status' => 'listed', 'bledy' => [], 'listy' => [
                    ['kod' => 'spamcop', 'kody' => '127.0.0.2', 'info' => false, 'url' => 'https://www.spamcop.net/bl.shtml?91.231.70.1'],
                ]]],
            ],
        ]);

        foreach (['Total (IP)', 'Clean', 'Blacklisted', 'Duration', 'Błędy', 'Skanuj ponownie', 'Request removal 91.231.70.1', 'Listed (1)', 'All (2)', 'Clean (1)', 'Błędy (0)'] as $t) {
            $this->assertStringContainsString($t, $html);
        }
        $this->assertStringContainsString('bl.shtml&#x3F;91.231.70.1', $html);
        $this->assertStringNotContainsString('dnsbl-postep-bar', $html);
    }

    public function testLinkCsvTrendIFala(): void
    {
        $skan = [
            'id' => 9, 'zakres' => '91.231.70.0/23', 'strefa_filtr' => null, 'status' => 'zakonczony', 'uzytkownik' => 'auto', 'tryb' => 'noc',
            'koniec_at' => '2026-10-15 03:40:00+00', 'liczba_ip' => 512, 'czyste' => 450, 'listowane' => 62, 'bledy' => 0, 'czas_ms' => 1500,
            'zapytan_plan' => 2, 'zapytan_wykonano' => 2, 'nowe' => 62, 'zdjete' => 0, 'incydenty' => 0, 'fala' => true,
            'komunikat' => 'Możliwy błąd skanu: 62 nowych listingów (> 50). Incydenty pominięte.',
        ];
        $html = $this->render([
            'blad' => null, 'skan' => $skan, 'ostatnie' => [$skan], 'filtr' => 'listed', 'strona' => 1, 'naStrone' => 50, 'prefiksy' => [],
            'strefy' => [], 'szczegoly' => null,
            'trend' => [
                ['id' => 8, 'koniec_at' => '2026-10-14 03:40:00+00', 'listowane' => 7, 'tryb' => 'noc'],
                ['id' => 9, 'koniec_at' => '2026-10-15 03:40:00+00', 'listowane' => 62, 'tryb' => 'noc'],
            ],
        ]);
        $this->assertStringContainsString('dashboard/dnsbl/skan/9/csv', $html);
        $this->assertStringContainsString('id="dnsbl-trend"', $html);
        $this->assertStringContainsString('Skan #9 (noc)', $html);
        $this->assertStringContainsString('id="dnsbl-fala"', $html);
    }

    public function testSkanWTokuPokazujePostepIPolling(): void
    {
        $html = $this->render([
            'blad' => null, 'strefy' => [], 'szczegoly' => null, 'ostatnie' => [], 'filtr' => 'listed', 'strona' => 1, 'naStrone' => 50, 'prefiksy' => [],
            'skan' => ['id' => 8, 'zakres' => '91.231.80.0/22', 'strefa_filtr' => null, 'status' => 'w_toku', 'uzytkownik' => '', 'koniec_at' => null,
                'zapytan_plan' => 100, 'zapytan_wykonano' => 50, 'strefy' => []],
        ]);
        $this->assertStringContainsString('dnsbl-postep-bar', $html);
        $this->assertStringContainsString('dashboard/dnsbl/status/8', $html);
        $this->assertStringContainsString('setInterval(tick, 2000)', $html);
        $this->assertStringNotContainsString('Skanuj ponownie', $html);
    }
}
