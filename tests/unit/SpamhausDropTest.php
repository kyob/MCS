<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\spamhaus\Dopasowanie;
use McsPluginy\spamhaus\DropParser;
use McsPluginy\spamhaus\Harmonogram;

/**
 * Czyste funkcje pluginu spamhaus (bez DB i sieci).
 *
 * @internal
 */
final class SpamhausDropTest extends CIUnitTestCase
{
    public function testNaszPrefiksWewnatrzWpisuDrop(): void
    {
        $t = Dopasowanie::dopasujCidry([['cidr' => '91.231.0.0/16', 'sblid' => 'SBL1']], ['91.231.80.0/22']);
        $this->assertCount(1, $t);
        $this->assertSame('91.231.80.0/22', $t[0]['zakres']);
        $this->assertSame('91.231.80.0', $t[0]['podmiot']);
        $this->assertSame('SBL1', $t[0]['sblid']);
    }

    public function testWpisDropWewnatrzNaszegoPrefiksu(): void
    {
        $t = Dopasowanie::dopasujCidry([['cidr' => '176.97.25.0/24', 'sblid' => 'SBL2']], ['176.97.24.0/21']);
        $this->assertCount(1, $t);
        $this->assertSame('176.97.25.0/24', $t[0]['zakres']);
        $this->assertSame('176.97.24.0/21', $t[0]['nasz_prefiks']);
    }

    public function testBrakNakladaniaIRozneRodziny(): void
    {
        $this->assertSame([], Dopasowanie::dopasujCidry([['cidr' => '91.231.84.0/22']], ['91.231.80.0/22']));
        $this->assertFalse(Dopasowanie::nakladajaSie('91.231.80.0/22', '2a0f:1700::/29'));
        $this->assertFalse(Dopasowanie::nakladajaSie('0.0.0.0/0', '::/0'));
        $this->assertSame([], Dopasowanie::dopasujCidry([['cidr' => 'bzdura']], ['91.231.80.0/22']));
    }

    public function testIpv6NakladanieWObieStrony(): void
    {
        $this->assertTrue(Dopasowanie::nakladajaSie('2a0f:1700:10::/48', '2a0f:1700::/29'));
        $this->assertTrue(Dopasowanie::nakladajaSie('2a0f::/16', '2a0f:1700::/29'));
        $this->assertFalse(Dopasowanie::nakladajaSie('2a0f:1708::/32', '2a0f:1700::/29'));
        $this->assertSame('2a0f:1700:10::/48', Dopasowanie::wezszy('2a0f:1700::/29', '2a0f:1700:10::/48'));
    }

    public function testNormalizacjaZerujeBityHosta(): void
    {
        $this->assertSame('91.231.80.0/22', Dopasowanie::normalizuj('91.231.81.7/22'));
        $this->assertSame('10.0.0.1/32', Dopasowanie::normalizuj('10.0.0.1'));
        $this->assertNull(Dopasowanie::normalizuj('10.0.0.0/33'));
    }

    public function testAsn(): void
    {
        $wpisy = [['asn' => 245], ['asn' => 198050, 'asname' => 'X'], ['asn' => 'AS4242']];
        $this->assertSame('X', Dopasowanie::dopasujAsn($wpisy, 198050)['asname'] ?? null);
        $this->assertNotNull(Dopasowanie::dopasujAsn($wpisy, 4242));
        $this->assertNull(Dopasowanie::dopasujAsn($wpisy, 19805));
    }

    public function testParserMetadane(): void
    {
        $tresc = "{\"cidr\":\"1.10.16.0/20\",\"sblid\":\"SBL1\",\"rir\":\"apnic\"}\n"
            . "{\"type\":\"metadata\",\"timestamp\":1791926042,\"records\":1,\"copyright\":\"(c) 2026 The Spamhaus Project SLU\"}\n";
        $p = (new DropParser())->parsuj($tresc, 'drop_v4');
        $this->assertCount(1, $p['wpisy']);
        $this->assertSame(1791926042, $p['meta']['timestamp']);
        $this->assertStringContainsString('Spamhaus', $p['meta']['copyright']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function zlePliki(): iterable
    {
        yield 'pusty' => [''];
        yield 'html' => ["<html>blocked</html>\n"];
        yield 'bez metadanych' => ["{\"cidr\":\"1.10.16.0/20\"}\n"];
        yield 'same metadane' => ["{\"type\":\"metadata\",\"timestamp\":1,\"records\":0}\n"];
        yield 'obcięty' => ["{\"cidr\":\"1.10.16.0/20\"}\n{\"type\":\"metadata\",\"timestamp\":1,\"records\":2}\n"];
    }

    /**
     * @dataProvider zlePliki
     */
    public function testParserOdrzucaNiekompletnyPlik(string $tresc): void
    {
        $this->expectException(RuntimeException::class);
        (new DropParser())->parsuj($tresc, 'drop_v4');
    }

    public function testHarmonogram(): void
    {
        $t = 1_800_000_000;
        $this->assertTrue(Harmonogram::czyPora($t, null, null));
        $this->assertFalse(Harmonogram::czyPora($t, null, $t - 1800), 'nigdy częściej niż 1/h');
        $this->assertFalse(Harmonogram::czyPora($t, $t + 60, $t - 7200));
        $this->assertTrue(Harmonogram::czyPora($t, $t - 1, $t - 3600));

        $po = Harmonogram::poSukcesie($t, 24, 37);
        $this->assertSame($t + 24 * 3600 + 37 * 60, $po);

        $this->assertSame($t + 3600, Harmonogram::poBledzie($t, 1));
        $this->assertSame($t + 7200, Harmonogram::poBledzie($t, 2));
        $this->assertSame($t + 86400, Harmonogram::poBledzie($t, 20));
        $this->assertSame($t + 10000, Harmonogram::poBledzie($t, 1, 10000), 'Retry-After dłuższy niż backoff');
    }
}
