<?php

use App\Libraries\DashboardFiltry;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DashboardFiltryTest extends CIUnitTestCase
{
    public function testOkno2JestDozwolone(): void
    {
        $this->assertContains(2, DashboardFiltry::OKNA);
        $f = (new DashboardFiltry())->rozstrzygnij(['okno' => '2', 'waga' => 'high']);
        $this->assertSame(2, $f['okno']);
        $this->assertSame('high', $f['minWaga']);
    }

    public function testDomyslneBezUstawien(): void
    {
        $filtry = $this->nowe([]);
        $d      = $filtry->domyslne();

        $this->assertSame(7, $d['okno']);
        $this->assertNull($d['zrodlo']);
        $this->assertNull($d['ip']);
        $this->assertNull($d['minWaga']);
        $this->assertSame(1, $d['minZrodel']);
        $this->assertNull($d['kategoria']);
        $this->assertNull($d['port']);
        $this->assertFalse($d['bladIp']);
    }

    public function testDomyslneZUstawien(): void
    {
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_OKNO       => '30',
            DashboardFiltry::KLUCZ_ZRODLO     => 'shadowserver',
            DashboardFiltry::KLUCZ_KATEGORIA  => 'malware',
            DashboardFiltry::KLUCZ_PORT       => '443',
            DashboardFiltry::KLUCZ_WAGA       => 'high',
            DashboardFiltry::KLUCZ_MIN_ZRODEL => '2',
        ]);
        $d = $filtry->domyslne();

        $this->assertSame(30, $d['okno']);
        $this->assertSame('shadowserver', $d['zrodlo']);
        $this->assertSame('malware', $d['kategoria']);
        $this->assertSame(443, $d['port']);
        $this->assertSame('high', $d['minWaga']);
        $this->assertSame(2, $d['minZrodel']);
        $this->assertNull($d['ip']);
    }

    public function testDomyslneOdrzucaNiepoprawneWartosci(): void
    {
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_OKNO       => '11',
            DashboardFiltry::KLUCZ_PORT       => '99999',
            DashboardFiltry::KLUCZ_WAGA       => 'ultra',
            DashboardFiltry::KLUCZ_MIN_ZRODEL => '0',
        ]);
        $d = $filtry->domyslne();

        $this->assertSame(7, $d['okno']);
        $this->assertNull($d['port']);
        $this->assertNull($d['minWaga']);
        $this->assertSame(1, $d['minZrodel']);
    }

    public function testRozstrzygnijGetNadpisujeDomyslneIZapamietuje(): void
    {
        $pamiec = [];
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_OKNO => '7',
            DashboardFiltry::KLUCZ_WAGA => 'low',
        ], $pamiec);

        $wynik = $filtry->rozstrzygnij([
            'okno'       => '14',
            'zrodlo'     => '',
            'ip'         => '198.51.100.10',
            'waga'       => 'high',
            'min_zrodel' => '3',
            'kategoria'  => 'exposure',
            'port'       => '22',
        ]);

        $this->assertSame(14, $wynik['okno']);
        $this->assertNull($wynik['zrodlo']);
        $this->assertSame('198.51.100.10', $wynik['ip']);
        $this->assertSame('high', $wynik['minWaga']);
        $this->assertSame(3, $wynik['minZrodel']);
        $this->assertSame('exposure', $wynik['kategoria']);
        $this->assertSame(22, $wynik['port']);
        $this->assertArrayHasKey(DashboardFiltry::SESJA_KLUCZ, $pamiec);
    }

    public function testPustyGetUzywaZapamietanychFiltrow(): void
    {
        $pamiec = [];
        $filtry = $this->nowe([], $pamiec);

        $filtry->rozstrzygnij(['okno' => '90', 'min_zrodel' => '4']);
        $ponownie = $filtry->rozstrzygnij([]);

        $this->assertSame(90, $ponownie['okno']);
        $this->assertSame(4, $ponownie['minZrodel']);
    }

    public function testPustyGetBezSesjiUzywaDomyslnychZUstawien(): void
    {
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_OKNO       => '1',
            DashboardFiltry::KLUCZ_MIN_ZRODEL => '5',
        ]);

        $wynik = $filtry->rozstrzygnij([]);

        $this->assertSame(1, $wynik['okno']);
        $this->assertSame(5, $wynik['minZrodel']);
    }

    public function testPusteZrodloWGetNieWracaDoDomyslnegoZrodla(): void
    {
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_ZRODLO => 'shadowserver',
        ]);

        $wynik = $filtry->rozstrzygnij([
            'okno'   => '7',
            'zrodlo' => '',
        ]);

        $this->assertNull($wynik['zrodlo']);
    }

    public function testWyczyscZapominaSesjeIWracaDoDomyslnych(): void
    {
        $pamiec = [];
        $filtry = $this->nowe([
            DashboardFiltry::KLUCZ_OKNO => '30',
        ], $pamiec);

        $filtry->rozstrzygnij(['okno' => '1', 'waga' => 'critical']);
        $poWyczyszczeniu = $filtry->rozstrzygnij(['wyczysc' => '1']);

        $this->assertSame(30, $poWyczyszczeniu['okno']);
        $this->assertNull($poWyczyszczeniu['minWaga']);
        $this->assertArrayNotHasKey(DashboardFiltry::SESJA_KLUCZ, $pamiec);

        $kolejnaWizyta = $filtry->rozstrzygnij([]);
        $this->assertSame(30, $kolejnaWizyta['okno']);
        $this->assertNull($kolejnaWizyta['minWaga']);
    }

    public function testNiepoprawneIpUstawiaBlad(): void
    {
        $wynik = $this->nowe([])->rozstrzygnij([
            'okno' => '7',
            'ip'   => 'nie-ip',
        ]);

        $this->assertTrue($wynik['bladIp']);
        $this->assertSame('nie-ip', $wynik['ipSurowy']);
        $this->assertNull($wynik['ip']);
    }

    public function testZFormularzaUstawienNormalizuje(): void
    {
        $d = $this->nowe([])->zFormularzaUstawien([
            'dashboard_okno'       => '14',
            'dashboard_zrodlo'     => ' cert_n6 ',
            'dashboard_kategoria'  => '',
            'dashboard_port'       => '80',
            'dashboard_waga'       => 'medium',
            'dashboard_min_zrodel' => '2',
        ]);

        $this->assertSame(14, $d['okno']);
        $this->assertSame('cert_n6', $d['zrodlo']);
        $this->assertNull($d['kategoria']);
        $this->assertSame(80, $d['port']);
        $this->assertSame('medium', $d['minWaga']);
        $this->assertSame(2, $d['minZrodel']);
        $this->assertNull($d['ip']);
    }

    public function testDoQuery(): void
    {
        $q = $this->nowe([])->doQuery([
            'okno'      => 14,
            'zrodlo'    => 'shadowserver',
            'ip'        => null,
            'minWaga'   => 'high',
            'minZrodel' => 2,
            'kategoria' => 'malware',
            'port'      => 443,
        ]);

        parse_str($q, $params);
        $this->assertSame('14', $params['okno']);
        $this->assertSame('shadowserver', $params['zrodlo']);
        $this->assertSame('high', $params['waga']);
        $this->assertSame('2', $params['min_zrodel']);
        $this->assertSame('malware', $params['kategoria']);
        $this->assertSame('443', $params['port']);
        $this->assertArrayNotHasKey('ip', $params);
        $this->assertArrayNotHasKey('cidr', $params);
    }

    public function testPodsiecZSelecta(): void
    {
        $wynik = $this->nowe([])->rozstrzygnij([
            'okno'    => '7',
            'podsiec' => '91.231.80.0/22',
            'cidr'    => '',
        ]);

        $this->assertSame('91.231.80.0/22', $wynik['cidr']);
        $this->assertFalse($wynik['bladCidr']);
        $this->assertSame('91.231.80.0/22', $wynik['cidrSurowy']);
    }

    public function testCidrNadpisujePodsiec(): void
    {
        $wynik = $this->nowe([])->rozstrzygnij([
            'okno'    => '7',
            'podsiec' => '91.231.80.0/22',
            'cidr'    => '91.231.80.0/24',
        ]);

        $this->assertSame('91.231.80.0/24', $wynik['cidr']);
        $this->assertFalse($wynik['bladCidr']);
    }

    public function testRecznyCidrIpv6(): void
    {
        $wynik = $this->nowe([])->rozstrzygnij([
            'okno' => '7',
            'cidr' => '2a0f:1700::/29',
        ]);

        $this->assertSame('2a0f:1700::/29', $wynik['cidr']);
        $this->assertFalse($wynik['bladCidr']);
    }

    public function testNiepoprawnyCidrUstawiaBlad(): void
    {
        $wynik = $this->nowe([])->rozstrzygnij([
            'okno' => '7',
            'cidr' => 'nie-cidr',
        ]);

        $this->assertTrue($wynik['bladCidr']);
        $this->assertSame('nie-cidr', $wynik['cidrSurowy']);
        $this->assertNull($wynik['cidr']);
    }

    public function testDoQueryZCidr(): void
    {
        $q = $this->nowe([])->doQuery([
            'okno'      => 7,
            'zrodlo'    => null,
            'ip'        => null,
            'minWaga'   => null,
            'minZrodel' => 1,
            'kategoria' => null,
            'port'      => null,
            'cidr'      => '176.97.24.0/21',
        ]);

        parse_str($q, $params);
        $this->assertSame('176.97.24.0/21', $params['cidr']);
    }

    public function testSesjaPamietaCidr(): void
    {
        $pamiec = [];
        $filtry = $this->nowe([], $pamiec);
        $filtry->rozstrzygnij(['okno' => '7', 'podsiec' => '64.190.43.0/24', 'cidr' => '']);
        $ponownie = $filtry->rozstrzygnij([]);

        $this->assertSame('64.190.43.0/24', $ponownie['cidr']);
    }

    public function testDoFormularzaSelectVsPole(): void
    {
        $lib   = $this->nowe([]);
        $lista = ['91.231.80.0/22', '176.97.24.0/21'];

        $zSelecta = $lib->doFormularza([
            'cidr'       => '91.231.80.0/22',
            'cidrSurowy' => '91.231.80.0/22',
            'bladCidr'   => false,
        ], $lista);
        $this->assertSame('91.231.80.0/22', $zSelecta['podsiec']);
        $this->assertSame('', $zSelecta['cidrPole']);

        $reczny = $lib->doFormularza([
            'cidr'       => '91.231.80.0/24',
            'cidrSurowy' => '91.231.80.0/24',
            'bladCidr'   => false,
        ], $lista);
        $this->assertNull($reczny['podsiec']);
        $this->assertSame('91.231.80.0/24', $reczny['cidrPole']);

        $blad = $lib->doFormularza([
            'cidr'       => null,
            'cidrSurowy' => 'nie-cidr',
            'bladCidr'   => true,
        ], $lista);
        $this->assertNull($blad['podsiec']);
        $this->assertSame('nie-cidr', $blad['cidrPole']);
    }

    /**
     * @param array<string, string> $ustawienia
     * @param array<string, mixed>  $pamiec
     */
    private function nowe(array $ustawienia = [], ?array &$pamiec = null): DashboardFiltry
    {
        if ($pamiec === null) {
            $pamiec = [];
        }

        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(
            static function (string $klucz, ?string $domyslnie = null) use ($ustawienia): ?string {
                return $ustawienia[$klucz] ?? $domyslnie;
            },
        );

        return new DashboardFiltry($model, [
            'get' => static function (string $klucz) use (&$pamiec): mixed {
                return $pamiec[$klucz] ?? null;
            },
            'set' => static function (string $klucz, mixed $wartosc) use (&$pamiec): void {
                $pamiec[$klucz] = $wartosc;
            },
            'forget' => static function (string $klucz) use (&$pamiec): void {
                unset($pamiec[$klucz]);
            },
        ]);
    }
}
