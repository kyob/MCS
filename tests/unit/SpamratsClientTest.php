<?php

use App\Libraries\Enrichment\SpamratsClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SpamratsClientTest extends CIUnitTestCase
{
    public function testBudujeFqdnZOdwróconymIpIKluczem(): void
    {
        $zapytany = null;
        $client   = new SpamratsClient(
            'MTHTESTKEY123',
            'all.spamrats.com',
            static function (string $fqdn) use (&$zapytany) {
                $zapytany = $fqdn;

                return [];
            },
        );

        $wynik = $client->checkIp('192.168.1.1');

        $this->assertTrue($wynik['ok']);
        $this->assertSame('1.1.168.192.MTHTESTKEY123.all.spamrats.com', $zapytany);
        $this->assertFalse($wynik['data']['listed']);
    }

    public function testMapujeKodyRatsAll(): void
    {
        $client = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static fn () => ['127.0.0.36', '127.0.0.38'],
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $data = $wynik['data'];
        $this->assertTrue($data['listed']);
        $this->assertTrue($data['listed_dyna']);
        $this->assertFalse($data['listed_noptr']);
        $this->assertTrue($data['listed_spam']);
        $this->assertFalse($data['listed_auth']);
        $this->assertSame(['127.0.0.36', '127.0.0.38'], $data['kody']);
    }

    public function testNxdomainToNieListed(): void
    {
        $client = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static fn () => [],
        );

        $wynik = $client->checkIp('203.0.113.1');

        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['data']['listed']);
        $this->assertSame([], $wynik['data']['kody']);
    }

    public function testNieznanyKodAToBlad(): void
    {
        $client = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static fn () => ['8.8.8.8'],
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('nieoczekiwany', $wynik['error']);
        $this->assertStringNotContainsString('KEY', $wynik['error']);
        $this->assertStringNotContainsString('all.spamrats.com', $wynik['error']);
    }

    public function testKod127_0_0_2JestAkceptowany(): void
    {
        $client = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static fn () => ['127.0.0.2'],
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertTrue($wynik['data']['listed']);
        $this->assertFalse($wynik['data']['listed_dyna']);
    }

    public function testIpv6Odrzucony(): void
    {
        $wywolano = false;
        $client   = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static function () use (&$wywolano) {
                $wywolano = true;

                return [];
            },
        );

        $wynik = $client->checkIp('2001:db8::1');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('IPv4', $wynik['error']);
        $this->assertFalse($wywolano);
    }

    public function testNieprawidlowyIp(): void
    {
        $client = new SpamratsClient('KEY', 'all.spamrats.com', static fn () => []);

        $wynik = $client->checkIp('not-an-ip');

        $this->assertFalse($wynik['ok']);
        $this->assertSame('invalid ip', $wynik['error']);
    }

    public function testNieprawidlowyKluczNieSkładaNazwy(): void
    {
        $wywolano = false;
        $client   = new SpamratsClient(
            'bad.key!',
            'all.spamrats.com',
            static function () use (&$wywolano) {
                $wywolano = true;

                return [];
            },
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('apiKey', $wynik['error']);
        $this->assertFalse($wywolano);
    }

    public function testBezKluczaNieWolaDns(): void
    {
        $wywolano = false;
        $client   = new SpamratsClient(
            '',
            'all.spamrats.com',
            static function () use (&$wywolano) {
                $wywolano = true;

                return [];
            },
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('apiKey', $wynik['error']);
        $this->assertFalse($wywolano);
    }

    public function testBladResolveraBezFqdnWKomunikacie(): void
    {
        $client = new SpamratsClient(
            'SECRETKEY',
            'all.spamrats.com',
            static function () {
                throw new RuntimeException('dns failed for SECRETKEY.all.spamrats.com');
            },
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringNotContainsString('SECRETKEY', $wynik['error']);
        $this->assertStringContainsString('198.51.100.10', $wynik['error']);
    }

    public function testAwariaResolweraNieJestCzysto(): void
    {
        $client = new SpamratsClient('KEY', 'all.spamrats.com', static fn () => false);

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('Błąd DNS', $wynik['error']);
    }

    public function testPustaOdpowiedzToNielistowany(): void
    {
        $client = new SpamratsClient('KEY', 'all.spamrats.com', static fn () => []);

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['data']['listed']);
    }
}
