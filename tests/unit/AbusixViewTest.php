<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AbusixViewTest extends CIUnitTestCase
{
    /**
     * @param array<string,mixed> $d
     */
    private function render(array $d): string
    {
        return view('dashboard/abusix', [
            'title' => 'Abusix — MCS', 'navAktywny' => 'abusix', 'email' => 'op@example.com', 'rola' => 'operator',
            'flashBlad' => null, 'flashOk' => null, 'blad' => null, 'd' => $d,
        ]);
    }

    public function testStanPustyPokazujeBrakSprawIOstatniePobranie(): void
    {
        $html = $this->render([
            'ostatni_ok' => '2026-10-08 07:27:39+00', 'health_ok' => true, 'health_msg' => 'Abusix OK', 'health_at' => null,
            'kpi' => ['sprawy' => 0, 'otwarte' => 0, 'zdarzenia' => 0, 'klienci' => 0],
            'wg_stanu' => [], 'sprawy' => [], 'zdarzenia' => [], 'klienci' => [],
        ]);

        $this->assertStringContainsString('Brak spraw w Abusix.', $html);
        $this->assertStringContainsString('Ostatnie udane pobranie: 2026-10-08 07:27:39+00', $html);
        $this->assertStringNotContainsString('otwartych</span>', $html);
    }

    public function testListaPokazujeSprawyZdarzeniaKlientowIBadge(): void
    {
        $html = $this->render([
            'ostatni_ok' => null, 'health_ok' => true, 'health_msg' => 'Abusix OK', 'health_at' => null,
            'kpi' => ['sprawy' => 1, 'otwarte' => 1, 'zdarzenia' => 1, 'klienci' => 1],
            'wg_stanu' => ['NEW' => 1],
            'sprawy' => [['case_id' => 'C-1', 'state' => 'NEW', 'customer_id' => 'cust1', 'contract_id' => '', 'first_event_date' => '2026-10-01', 'last_event_date' => '2026-10-02']],
            'zdarzenia' => [['zaobserwowano_at' => '2026-10-02', 'ip' => '192.0.2.1', 'event_type' => 'spam', 'domain' => '', 'sender_email' => 'a@b.pl']],
            'klienci' => [['customer_id' => 'cust1', 'tenant_id' => 't1', 'widziano_at' => '2026-10-08']],
        ]);

        $this->assertStringContainsString('1 otwartych', $html);
        $this->assertStringContainsString('C-1', $html);
        $this->assertStringContainsString('192.0.2.1', $html);
        $this->assertStringContainsString('cust1', $html);
    }
}
