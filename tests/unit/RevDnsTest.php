<?php

use App\Libraries\Siec\RevDns;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RevDnsTest extends CIUnitTestCase
{
    public function testDomyslnieWylaczone(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn(null);

        $rev = new RevDns($model, static fn (): ?string => 'ptr.example.test', $this->pamiecCache());
        $this->assertFalse($rev->jestWlaczone());
        $this->assertNull($rev->dlaIp('198.51.100.10'));
    }

    public function testWlaczoneZwracaHostnameICacheuje(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return $klucz === RevDns::KLUCZ_WLACZONE ? '1' : null;
        });

        $wywolania = 0;
        $cache     = $this->pamiecCache();
        $rev       = new RevDns($model, static function () use (&$wywolania): ?string {
            $wywolania++;

            return 'host.example.test';
        }, $cache);

        $this->assertTrue($rev->jestWlaczone());
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame(1, $wywolania);
    }

    public function testTylkoCacheNieWykonujeLookupu(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $wywolania = 0;
        $rev       = new RevDns($model, static function () use (&$wywolania): ?string {
            $wywolania++;

            return 'host.example.test';
        }, $this->pamiecCache());

        $this->assertNull($rev->dlaIp('198.51.100.10', true));
        $this->assertSame(0, $wywolania);

        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10'));
        $this->assertSame('host.example.test', $rev->dlaIp('198.51.100.10', true));
        $this->assertSame(1, $wywolania);
    }

    public function testNiepoprawneIpZwracaNull(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturn('1');

        $rev = new RevDns($model, static fn (): ?string => 'x', $this->pamiecCache());
        $this->assertNull($rev->dlaIp('nie-ip'));
    }

    public function testKluczUstawienia(): void
    {
        $this->assertSame('pokazuj_rev_dns', RevDns::KLUCZ_WLACZONE);
    }

    /**
     * @return array{get:callable,set:callable}
     */
    private function pamiecCache(): array
    {
        $store = [];

        return [
            'get' => static function (string $klucz) use (&$store) {
                return $store[$klucz] ?? null;
            },
            'set' => static function (string $klucz, mixed $wartosc, int $ttl) use (&$store): bool {
                unset($ttl);
                $store[$klucz] = $wartosc;

                return true;
            },
        ];
    }
}
