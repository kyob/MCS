<?php

use App\Libraries\Enrichment\DnsblClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DnsblClientTest extends CIUnitTestCase
{
    public function testBudujeFqdnZOdwróconymIp(): void
    {
        $zapytania = [];
        $client    = new DnsblClient(
            ['spamcop' => 'bl.spamcop.net'],
            static function (string $fqdn) use (&$zapytania) {
                $zapytania[] = $fqdn;

                return [];
            },
        );

        $wynik = $client->checkIp('192.168.1.1');

        $this->assertTrue($wynik['ok']);
        $this->assertSame(['1.1.168.192.bl.spamcop.net'], $zapytania);
        $this->assertFalse($wynik['data']['listed_any']);
    }

    public function testMapujeListedNaUceprotectISpamcop(): void
    {
        $client = new DnsblClient(
            [
                'uceprotect1' => 'dnsbl-1.uceprotect.net',
                'spamcop'     => 'bl.spamcop.net',
            ],
            static function (string $fqdn) {
                if (str_contains($fqdn, 'uceprotect')) {
                    return ['127.0.0.2'];
                }

                return [];
            },
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertTrue($wynik['data']['listed_any']);
        $this->assertTrue($wynik['data']['listy']['uceprotect1']['listed']);
        $this->assertFalse($wynik['data']['listy']['spamcop']['listed']);
        $this->assertSame(['127.0.0.2'], $wynik['data']['listy']['uceprotect1']['kody']);
    }

    public function testSpamhausZZonesPomijany(): void
    {
        putenv('mcs.dnsbl.zones=spamcop=bl.spamcop.net,spamhaus=zen.spamhaus.org');
        $_ENV['mcs.dnsbl.zones'] = 'spamcop=bl.spamcop.net,spamhaus=zen.spamhaus.org';
        try {
            $strefy = DnsblClient::strefyZOpcji();
        } finally {
            putenv('mcs.dnsbl.zones');
            unset($_ENV['mcs.dnsbl.zones']);
        }

        $this->assertArrayNotHasKey('spamhaus', $strefy);
    }

    public function testKodBledu127255ToErrorNieListed(): void
    {
        $client = new DnsblClient(
            ['test' => 'dnsbl.example.net'],
            static fn () => ['127.255.255.254'],
        );

        $wynik = $client->checkIp('198.51.100.10');

        // Jedyna strefa zwróciła błąd → ok=false
        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('DNSBL', $wynik['error']);
    }

    public function testIpv6Odrzucony(): void
    {
        $wywolano = false;
        $client   = new DnsblClient(
            ['spamcop' => 'bl.spamcop.net'],
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

    public function testParsujStrefy(): void
    {
        $strefy = DnsblClient::parsujStrefy(
            'uceprotect1=dnsbl-1.uceprotect.net,spamcop=bl.spamcop.net,dronebl=dnsbl.dronebl.org',
        );

        $this->assertSame('dnsbl-1.uceprotect.net', $strefy['uceprotect1']);
        $this->assertSame('bl.spamcop.net', $strefy['spamcop']);
        $this->assertSame('dnsbl.dronebl.org', $strefy['dronebl']);
    }

    public function testDroneblWDomyslnychStrefach(): void
    {
        $strefy = DnsblClient::domyslneStrefy();

        $this->assertSame('dnsbl.dronebl.org', $strefy['dronebl']);
        $this->assertSame('dronebl', DnsblClient::idZeStrefy('dnsbl.dronebl.org'));
    }

    public function testPusteStrefyBezDomyslnych(): void
    {
        $client = new DnsblClient([], static fn () => ['127.0.0.2'], 25, false);
        $wynik  = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['data']['listed_any']);
        $this->assertSame([], $wynik['data']['listy']);
    }

    public function testNieoczekiwanyKodPoza127(): void
    {
        $client = new DnsblClient(
            ['spamcop' => 'bl.spamcop.net'],
            static fn () => ['8.8.8.8'],
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
    }

    public function testBladResolveraToBladNieCzysto(): void
    {
        // false z resolvera = SERVFAIL/timeout (jak dns_get_record()).
        $client = new DnsblClient(
            ['spamcop' => 'bl.spamcop.net', 'dronebl' => 'dnsbl.dronebl.org'],
            static fn (string $fqdn) => str_contains($fqdn, 'spamcop') ? false : [],
            25,
            false,
        );

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['data']['listy']['spamcop']['listed']);
        $this->assertNotSame('', $wynik['data']['listy']['spamcop']['error']);
        $this->assertSame('', $wynik['data']['listy']['dronebl']['error']);
    }

    public function testWszystkieStrefyBladToOkFalse(): void
    {
        $client = new DnsblClient(['spamcop' => 'bl.spamcop.net'], static fn () => false, 25, false);

        $wynik = $client->checkIp('198.51.100.10');

        $this->assertFalse($wynik['ok']);
        $this->assertNull($wynik['data']);
    }
}
