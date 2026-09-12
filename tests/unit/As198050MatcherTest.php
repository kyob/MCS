<?php

use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class As198050MatcherTest extends CIUnitTestCase
{
    public function testCzyNaszeIpZWstrzyknietymModelem(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return match ($klucz) {
                As198050Matcher::KLUCZ_WLACZONE => '1',
                As198050Matcher::KLUCZ_CIDRY    => "91.231.80.0/22\n5.253.230.0/24",
                default                         => null,
            };
        });

        $matcher = new As198050Matcher(new CidrMatcher(), $model);
        $this->assertTrue($matcher->jestWlaczone());
        $this->assertTrue($matcher->czyNaszeIp('91.231.81.10'));
        $this->assertTrue($matcher->czyNaszeIp('5.253.230.1'));
        $this->assertFalse($matcher->czyNaszeIp('8.8.8.8'));
    }

    public function testWylaczoneNieOznacza(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return match ($klucz) {
                As198050Matcher::KLUCZ_WLACZONE => '0',
                As198050Matcher::KLUCZ_CIDRY    => '91.231.80.0/22',
                default                         => null,
            };
        });

        $matcher = new As198050Matcher(new CidrMatcher(), $model);
        $this->assertFalse($matcher->jestWlaczone());
        $this->assertFalse($matcher->czyNaszeIp('91.231.80.1'));
    }

    public function testDomyslneCidryZawierajaZnanePrefiksy(): void
    {
        $this->assertContains('91.231.80.0/22', As198050Matcher::DOMYSLNE_CIDRY);
        $this->assertContains('2a0f:1700::/29', As198050Matcher::DOMYSLNE_CIDRY);
        $this->assertSame(198050, As198050Matcher::ASN);
    }
}
