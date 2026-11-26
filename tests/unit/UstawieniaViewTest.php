<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class UstawieniaViewTest extends CIUnitTestCase
{
    public function testWidokMaPrzelacznikRevDns(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'           => 'Ustawienia — MCS',
            'navAktywny'      => 'ustawienia',
            'email'           => 'admin@example.com',
            'rola'            => 'admin',
            'komunikat'       => null,
            'blad'            => null,
            'asWlaczone'      => true,
            'asCidryTekst'    => "91.231.80.0/22\n",
            'asAsn'           => 198050,
            'asDomyslneCidry' => ['91.231.80.0/22'],
            'revDnsWlaczone'  => false,
        ]);

        $this->assertStringContainsString('name="pokazuj_rev_dns"', $html);
        $this->assertStringContainsString('Pokazuj reverse DNS', $html);
        $this->assertStringContainsString('PTR', $html);
        $this->assertStringContainsString('csrf', $html);
    }

    public function testWidokZaznaczaRevDnsGdyWlaczone(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'           => 'Ustawienia — MCS',
            'navAktywny'      => 'ustawienia',
            'email'           => 'admin@example.com',
            'rola'            => 'admin',
            'komunikat'       => null,
            'blad'            => null,
            'asWlaczone'      => false,
            'asCidryTekst'    => '',
            'asAsn'           => 198050,
            'asDomyslneCidry' => [],
            'revDnsWlaczone'  => true,
        ]);

        $this->assertMatchesRegularExpression(
            '/name="pokazuj_rev_dns"[^>]*checked|checked[^>]*name="pokazuj_rev_dns"/',
            $html,
        );
    }

    public function testWidokMaDomyslneFiltryPrzegladu(): void
    {
        $html = view('admin/ustawienia/index', [
            'title'              => 'Ustawienia — MCS',
            'navAktywny'         => 'ustawienia',
            'email'              => 'admin@example.com',
            'rola'               => 'admin',
            'komunikat'          => null,
            'blad'               => null,
            'asWlaczone'         => false,
            'asCidryTekst'       => '',
            'asAsn'              => 198050,
            'asDomyslneCidry'    => [],
            'revDnsWlaczone'     => false,
            'dashboardOkno'      => 14,
            'dashboardZrodlo'    => 'shadowserver',
            'dashboardKategoria' => 'malware',
            'dashboardPort'      => 22,
            'dashboardWaga'      => 'high',
            'dashboardMinZrodel' => 2,
            'zrodla'             => ['shadowserver', 'cert_n6'],
            'kategorie'          => ['exposure', 'malware'],
        ]);

        $this->assertStringContainsString('Domyślne filtry przeglądu', $html);
        $this->assertStringContainsString('name="dashboard_okno"', $html);
        $this->assertStringContainsString('name="dashboard_zrodlo"', $html);
        $this->assertStringContainsString('name="dashboard_kategoria"', $html);
        $this->assertStringContainsString('name="dashboard_port"', $html);
        $this->assertStringContainsString('name="dashboard_waga"', $html);
        $this->assertStringContainsString('name="dashboard_min_zrodel"', $html);
        $this->assertMatchesRegularExpression('/<option value="14"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="shadowserver"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="high"[^>]*selected/', $html);
    }

    /**
     * #434: po błędnym CIDR (redirect z withInput) textarea pokazuje wklejoną listę (old), nie wartość z DB.
     */
    public function testTextareaCidryPokazujeOldInputZamiastBazy(): void
    {
        $sesjaPrzed = $_SESSION ?? null;
        $_SESSION = [];
        session()->set('_ci_old_input', ['post' => ['as_cidry' => "91.231.80.0/22\nzly-cidr-123\n176.97.24.0/21"]]);

        try {
            $html = view('admin/ustawienia/index', [
                'title'           => 'Ustawienia — MCS',
                'navAktywny'      => 'ustawienia',
                'email'           => 'admin@example.com',
                'rola'            => 'admin',
                'komunikat'       => null,
                'blad'            => 'Niepoprawne CIDR: zly-cidr-123',
                'asWlaczone'      => true,
                'asCidryTekst'    => '10.0.0.0/8', // wartość z DB — ma być zastąpiona przez old
                'asAsn'           => 198050,
                'asDomyslneCidry' => ['91.231.80.0/22'],
                'revDnsWlaczone'  => false,
            ]);
        } finally {
            $_SESSION = $sesjaPrzed ?? [];
        }

        $this->assertStringContainsString('zly-cidr-123', $html);
        $this->assertStringContainsString('91.231.80.0/22', $html);
        // textarea nie pokazuje starej wartości z DB
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="as_cidry"[^>]*>(?:(?!10\.0\.0\.0\/8).)*<\/textarea>/s',
            $html,
        );
    }

    public function testTextareaCidryBezOldInputPokazujeBaze(): void
    {
        $sesjaPrzed = $_SESSION ?? null;
        $_SESSION = [];

        try {
            $html = view('admin/ustawienia/index', [
                'title'           => 'Ustawienia — MCS',
                'navAktywny'      => 'ustawienia',
                'email'           => 'admin@example.com',
                'rola'            => 'admin',
                'komunikat'       => null,
                'blad'            => null,
                'asWlaczone'      => true,
                'asCidryTekst'    => "10.0.0.0/8\n",
                'asAsn'           => 198050,
                'asDomyslneCidry' => [],
                'revDnsWlaczone'  => false,
            ]);
        } finally {
            $_SESSION = $sesjaPrzed ?? [];
        }

        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="as_cidry"[^>]*>10\.0\.0\.0\/8/s',
            $html,
        );
    }

    /**
     * #434: opcje po błędzie walidacji pokazują wklejone wartości (old), nie wartości z DB.
     */
    public function testOpcjePokazujaOldInputPoBledzie(): void
    {
        $sesjaPrzed = $_SESSION ?? null;
        $_SESSION = [];
        session()->set('_ci_old_input', ['post' => ['opcja' => ['mcs.test.int' => '42', 'mcs.test.enum' => 'b']]]);

        try {
            $html = view('admin/ustawienia/opcje', [
                'title'      => 'Opcje strojenia — MCS',
                'navAktywny' => 'ustawienia',
                'email'      => 'admin@example.com',
                'rola'       => 'admin',
                'komunikat'  => null,
                'blad'       => 'Nie zapisano opcji — popraw błędy.',
                'bledy'      => ['mcs.test.int' => 'poza zakresem'],
                'grupy'      => [
                    'Test' => [
                        'mcs.test.int'  => ['etykieta' => 'Test int', 'zrodlo' => 'db', 'typ' => 'int',
                            'wartosc' => '10', 'domyslna' => '5', 'min' => 1, 'max' => 100],
                        'mcs.test.enum' => ['etykieta' => 'Test enum', 'zrodlo' => 'env', 'typ' => 'enum',
                            'wartosc' => 'a', 'domyslna' => 'a', 'opcje' => ['a', 'b']],
                    ],
                ],
            ]);
        } finally {
            $_SESSION = $sesjaPrzed ?? [];
        }

        // input int: old 42 zamiast wartości z DB (10)
        $this->assertMatchesRegularExpression('/name="opcja\[mcs\.test\.int\]"[^>]*value="42"|value="42"[^>]*name="opcja\[mcs\.test\.int\]"/s', $html);
        $this->assertStringNotContainsString('value="10"', $html);
        // select enum: old 'b' zaznaczone zamiast 'a'
        $this->assertMatchesRegularExpression('/<option value="b" selected>/', $html);
    }

    /**
     * #437: każde błędne pole ma is-invalid + aria-invalid + aria-describedby
     * i własny widoczny komunikat invalid-feedback (role=alert); alert zbiorczy zostaje.
     */
    public function testOpcjeBlednePoleMaInvalidFeedbackIAria(): void
    {
        $html = view('admin/ustawienia/opcje', [
            'title'      => 'Opcje strojenia — MCS',
            'navAktywny' => 'ustawienia',
            'email'      => 'admin@example.com',
            'rola'       => 'admin',
            'komunikat'  => null,
            'blad'       => 'Nie zapisano opcji — popraw błędy.',
            'bledy'      => [
                'mcs.test.int'  => 'poza zakresem',
                'mcs.test.enum' => 'zła wartość',
            ],
            'grupy'      => [
                'Test' => [
                    'mcs.test.int'  => ['etykieta' => 'Test int', 'zrodlo' => 'db', 'typ' => 'int',
                        'wartosc' => '10', 'domyslna' => '5', 'min' => 1, 'max' => 100],
                    'mcs.test.enum' => ['etykieta' => 'Test enum', 'zrodlo' => 'env', 'typ' => 'enum',
                        'wartosc' => 'a', 'domyslna' => 'a', 'opcje' => ['a', 'b']],
                    'mcs.test.ok'   => ['etykieta' => 'Test ok', 'zrodlo' => 'domyslna', 'typ' => 'text',
                        'wartosc' => 'x', 'domyslna' => 'x'],
                ],
            ],
        ]);

        // input int: is-invalid + ARIA + komunikat powiązany aria-describedby
        $this->assertMatchesRegularExpression(
            '/<input[^>]*class="form-control is-invalid"[^>]*aria-invalid="true" aria-describedby="opcja_mcs_test_int_blad"[^>]*>/s',
            $html,
        );
        $this->assertStringContainsString(
            '<div class="invalid-feedback" id="opcja_mcs_test_int_blad" role="alert">poza zakresem</div>',
            $html,
        );
        // select enum: to samo
        $this->assertMatchesRegularExpression(
            '/<select[^>]*class="form-select is-invalid"[^>]*aria-invalid="true" aria-describedby="opcja_mcs_test_enum_blad"[^>]*>/s',
            $html,
        );
        $this->assertStringContainsString(
            '<div class="invalid-feedback" id="opcja_mcs_test_enum_blad" role="alert">zła wartość</div>',
            $html,
        );
        // pole bez błędu: bez is-invalid i bez aria-invalid
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="opcja_mcs_test_ok"(?![^>]*aria-invalid)[^>]*>/s',
            $html,
        );
        $this->assertStringNotContainsString('opcja_mcs_test_ok_blad', $html);
        // alert zbiorczy nadal podsumowuje błędy
        $this->assertMatchesRegularExpression('/<div class="alert alert-danger[^"]*" role="alert">.*poza zakresem.*zła wartość.*<\/div>/s', $html);
    }
}
