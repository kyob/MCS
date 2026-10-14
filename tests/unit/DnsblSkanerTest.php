<?php

use App\Libraries\Dnsbl\DnsblNarzedzia as N;
use App\Libraries\Dnsbl\DnsPakiet;
use App\Libraries\Dnsbl\SilnikZapytan;
use App\Libraries\Dnsbl\ZakresSkanu;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Skaner DNSBL (plugin dnsbl, E1): CIDR, odwracanie oktetów, klasyfikacja odpowiedzi, diff, pakiety DNS.
 *
 * @internal
 */
final class DnsblSkanerTest extends CIUnitTestCase
{
    private const AS = ['5.253.230.0/24', '64.46.124.0/24', '91.231.70.0/23', '91.231.80.0/22', '176.97.24.0/21'];

    public function testOdwracaOktety(): void
    {
        $this->assertSame('187.70.231.91', N::odwrocOktety('91.231.70.187'));
        $this->assertSame('2.0.0.127', N::odwrocOktety('127.0.0.2'));
    }

    public function testOdwracanieOdrzucaIpv6(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        N::odwrocOktety('2a0f:1700::1');
    }

    public function testRozwijaCidr(): void
    {
        $this->assertSame(['91.231.70.187'], N::rozwinCidr('91.231.70.187'));
        $this->assertSame(['91.231.70.186', '91.231.70.187'], N::rozwinCidr('91.231.70.187/31'));
        $l = N::rozwinCidr('91.231.70.0/23');
        $this->assertCount(512, $l);
        $this->assertSame('91.231.70.0', $l[0]);
        $this->assertSame('91.231.71.255', $l[511]);
        $this->assertCount(2048, N::rozwinCidr('176.97.24.0/21'));
    }

    public function testNormalizujeCidrDoAdresuSieci(): void
    {
        $this->assertSame('91.231.70.0/23', N::normalizujCidr('91.231.71.9/23'));
        $this->assertSame('91.231.70.5/32', N::normalizujCidr('91.231.70.5'));
        $this->assertNull(N::normalizujCidr('91.231.70.0/33'));
        $this->assertNull(N::normalizujCidr('abc'));
    }

    public function testZakresTylkoZAsIMaska21do32(): void
    {
        $ok = ZakresSkanu::waliduj('91.231.70.0/23, 91.231.80.10', self::AS);
        $this->assertTrue($ok['ok']);
        $this->assertSame(['91.231.70.0/23', '91.231.80.10/32'], $ok['cidry']);

        $this->assertFalse(ZakresSkanu::waliduj('8.8.8.8', self::AS)['ok']);
        $this->assertFalse(ZakresSkanu::waliduj('91.231.0.0/16', self::AS)['ok']);
        $this->assertFalse(ZakresSkanu::waliduj('91.231.70.0/20', self::AS)['ok']);
        $this->assertFalse(ZakresSkanu::waliduj('91.231.70.0/22', self::AS)['ok'], '/22 wychodzi poza 91.231.70.0/23');
        $this->assertFalse(ZakresSkanu::waliduj('2a0f:1700::/48', self::AS)['ok']);
        $this->assertFalse(ZakresSkanu::waliduj('', self::AS)['ok']);
        $this->assertTrue(ZakresSkanu::waliduj('176.97.24.0/21', self::AS)['ok']);
        $this->assertTrue(ZakresSkanu::waliduj('64.46.124.7', self::AS)['ok']);
    }

    public function testKlasyfikacjaNxdomainToCzysty(): void
    {
        $k = N::klasyfikujA(SilnikZapytan::wynik('odp', DnsPakiet::RCODE_NXDOMAIN, [], [], ''));
        $this->assertSame(N::CZYSTY, $k['status']);
    }

    public function testKlasyfikacjaListed(): void
    {
        $k = N::klasyfikujA(SilnikZapytan::wynik('odp', 0, ['127.0.0.2', '127.0.0.10'], [], ''));
        $this->assertSame(N::LISTED, $k['status']);
        $this->assertSame(['127.0.0.10', '127.0.0.2'], $k['kody']);
    }

    public function testBledyNigdyNieSaCzyste(): void
    {
        foreach ([
            SilnikZapytan::wynik('timeout', null, [], [], 'timeout'),
            SilnikZapytan::wynik('blad', null, [], [], 'socket'),
            SilnikZapytan::wynik('odp', DnsPakiet::RCODE_SERVFAIL, [], [], ''),
            SilnikZapytan::wynik('odp', DnsPakiet::RCODE_REFUSED, [], [], ''),
            SilnikZapytan::wynik('odp', 0, [], [], ''),
            SilnikZapytan::wynik('odp', 0, ['10.0.0.1'], [], ''),
        ] as $odp) {
            $this->assertSame(N::BLAD, N::klasyfikujA($odp)['status'], json_encode($odp));
        }
    }

    public function testKod127_255_255ToBladStrefy(): void
    {
        $k = N::klasyfikujA(SilnikZapytan::wynik('odp', 0, ['127.255.255.254'], [], ''));
        $this->assertSame(N::BLAD_STREFY, $k['status']);
    }

    public function testTestKontrolny(): void
    {
        $listed = SilnikZapytan::wynik('odp', 0, ['127.0.0.2'], [], '');
        $nx     = SilnikZapytan::wynik('odp', 3, [], [], '');
        $to     = SilnikZapytan::wynik('timeout', null, [], [], 'timeout');
        $this->assertTrue(N::testKontrolny($listed, $nx)['ok']);
        $this->assertFalse(N::testKontrolny($nx, $nx)['ok']);
        $this->assertFalse(N::testKontrolny($listed, $listed)['ok']);
        $this->assertFalse(N::testKontrolny($to, $nx)['ok']);
    }

    public function testDiffListingow(): void
    {
        $d = N::diff(['1.1.1.1', '1.1.1.2', '1.1.1.3'], ['1.1.1.1', '1.1.1.9'], ['1.1.1.2']);
        $this->assertSame(['1.1.1.9'], $d['nowe']);
        $this->assertSame(['1.1.1.1'], $d['utrzymane']);
        // 1.1.1.3 bez odpowiedzi (błąd) — zostaje aktywny
        $this->assertSame(['1.1.1.2'], $d['zdjete']);
    }

    public function testUrlDelisting(): void
    {
        $this->assertSame('https://www.spamcop.net/bl.shtml?91.231.70.187', N::urlDelisting('https://www.spamcop.net/bl.shtml?{ip}', '91.231.70.187'));
        $this->assertSame('https://x/?ip=91.231.70.0', N::urlDelisting('https://x/?ip={ip}', '91.231.70.0/24'));
    }

    public function testPakietZapytaniaIParsowanieOdpowiedzi(): void
    {
        $q = DnsPakiet::zapytanie(0x1234, '2.0.0.127.bl.spamcop.net', DnsPakiet::TYP_A);
        $this->assertSame(0x1234, unpack('n', substr($q, 0, 2))[1]);

        // odpowiedź: ten sam nagłówek z QR=1, AN=2 (A + TXT), nazwy jako wskaźnik 0xC00C
        $pyt = substr($q, 12);
        $odp = pack('nnnnnn', 0x1234, 0x8180, 1, 2, 0, 0) . $pyt
            . "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . inet_pton('127.0.0.2')
            . "\xC0\x0C" . pack('nnNn', 16, 1, 60, 5) . "\x04test";
        $p = DnsPakiet::parsuj($odp);
        $this->assertSame(0x1234, $p['id']);
        $this->assertSame(0, $p['rcode']);
        $this->assertSame(['127.0.0.2'], $p['a']);
        $this->assertSame(['test'], $p['txt']);

        $nx = DnsPakiet::parsuj(pack('nnnnnn', 7, 0x8183, 1, 0, 0, 0) . $pyt);
        $this->assertSame(DnsPakiet::RCODE_NXDOMAIN, $nx['rcode']);
    }

    public function testSiec24(): void
    {
        $this->assertSame('91.231.71.0/24', N::siec24('91.231.71.200'));
        $this->assertTrue(N::cidrZawieraCidr('91.231.70.0/23', '91.231.71.0/24'));
        $this->assertFalse(N::cidrZawieraCidr('91.231.70.0/24', '91.231.70.0/23'));
    }
}
