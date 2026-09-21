<?php

use App\Libraries\KolejkaDnia;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class KolejkaDniaTest extends CIUnitTestCase
{
    public function testFiltryBazoweWymuszajaOkno2IWageHigh(): void
    {
        $f = (new KolejkaDnia())->filtryBazowe();

        $this->assertSame(2, $f['okno']);
        $this->assertSame('high', $f['minWaga']);
        $this->assertSame(1, $f['minZrodel']);
        $this->assertNull($f['zrodlo']);
        $this->assertNull($f['ip']);
        $this->assertNull($f['kategoria']);
        $this->assertNull($f['port']);
    }

    public function testDoQueryZawieraOknoIWage(): void
    {
        $qs = (new KolejkaDnia())->doQuery();

        $this->assertStringContainsString('okno=2', $qs);
        $this->assertStringContainsString('waga=high', $qs);
        $this->assertStringContainsString('min_zrodel=1', $qs);
    }

    /**
     * Kolejka czyści CIDR z sesji — akceptujIp musi być przebudowane z null,
     * inaczej wąski filtr CIDR z dashboardu nadal zawęża kolejkę.
     */
    public function testAkceptujIpPoWyczyszczeniuCidrNieWazySesyjnegoCidr(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return match ($klucz) {
                As198050Matcher::KLUCZ_WLACZONE => '1',
                As198050Matcher::KLUCZ_CIDRY    => "91.231.80.0/22\n176.97.24.0/21",
                default                         => null,
            };
        });

        $matcher   = new As198050Matcher(new CidrMatcher(), $model);
        $zSesji    = $matcher->filtrAkceptujIp('91.231.80.0/22');
        $dlaKolejki = $matcher->filtrAkceptujIp(null);

        $this->assertNotNull($zSesji);
        $this->assertNotNull($dlaKolejki);
        $this->assertFalse($zSesji('176.97.25.1'));
        $this->assertTrue($dlaKolejki('176.97.25.1'));
    }
}
