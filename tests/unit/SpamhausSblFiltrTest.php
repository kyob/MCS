<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\spamhaus_sbl\Filtr;
use McsPluginy\spamhaus_sbl\Naglowki;
use McsPluginy\spamhaus_sbl\Parser;

/**
 * Filtr nadawcy / Authentication-Results i parser treści Spamhaus SBL (bez DB, bez sieci).
 *
 * @internal
 */
final class SpamhausSblFiltrTest extends CIUnitTestCase
{
    private Filtr $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new Filtr();
    }

    public function testAdresZFromParsujeAdresNieNazwe(): void
    {
        $this->assertSame('sbl@spamhaus.org', $this->f->adresZFrom('Spamhaus <SBL@Spamhaus.org>'));
        $this->assertSame('sbl@spamhaus.org', $this->f->adresZFrom('sbl@spamhaus.org'));
        $this->assertSame('x@evil.example', $this->f->adresZFrom('"sbl@spamhaus.org" <x@evil.example>'));
        $this->assertSame('x@evil.example', $this->f->adresZFrom('sbl@spamhaus.org <x@evil.example>'));
        $this->assertSame('x@evil.example', $this->f->adresZFrom('(sbl@spamhaus.org) x@evil.example'));
        $this->assertNull($this->f->adresZFrom('a@spamhaus.org, b@evil.example'));
        $this->assertNull($this->f->adresZFrom('<a@spamhaus.org> <b@evil.example>'));
        $this->assertNull($this->f->adresZFrom(''));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function domeny(): iterable
    {
        yield 'dokladnie' => ['a@spamhaus.org', true];
        yield 'subdomena' => ['a@mail.spamhaus.org', true];
        yield 'wielkie litery' => ['a@SPAMHAUS.ORG', true];
        yield 'sufiks obcy' => ['a@spamhaus.org.evil.com', false];
        yield 'prefiks bez kropki' => ['a@notspamhaus.org', false];
        yield 'cyrylica a' => ["a@spamh\u{0430}us.org", false];
        yield 'fullwidth dot' => ["a@spamhaus\u{FF0E}org", false];
        yield 'kropka na koncu' => ['a@spamhaus.org.', false];
        yield 'punycode lookalike' => ['a@xn--spamhus-9za.org', false];
        yield 'pusta etykieta' => ['a@.spamhaus.org', false];
        yield 'inna tld' => ['a@spamhaus.com', false];
    }

    /**
     * @dataProvider domeny
     */
    public function testDomenaNadawcy(string $adres, bool $ok): void
    {
        $this->assertSame($ok, $this->f->nadawcaZaufanejDomeny($adres));
    }

    public function testTematSblBezWielkosciLiter(): void
    {
        $this->assertTrue($this->f->tematSbl('Re: sbl123 listing'));
        $this->assertTrue($this->f->tematSbl('SBL'));
        $this->assertFalse($this->f->tematSbl('Spamhaus newsletter'));
    }

    public function testAuthBrakNaglowka(): void
    {
        $n = new Naglowki("From: a@spamhaus.org\nSubject: SBL1\n");
        $this->assertSame(Filtr::AUTH_BRAK, $this->f->uwierzytelnienie($n)['wynik']);
    }

    public function testAuthDkimPassSpamhaus(): void
    {
        $n = new Naglowki("Authentication-Results: mx.example.net;\n dkim=pass (ok) header.d=spamhaus.org;\n spf=none\n");
        $w = $this->f->uwierzytelnienie($n);
        $this->assertSame(Filtr::AUTH_PASS, $w['wynik']);
        $this->assertSame('dkim', $w['metoda']);
    }

    public function testAuthSpfPassMailfrom(): void
    {
        $n = new Naglowki("Authentication-Results: mx.example.net; dkim=none; spf=pass smtp.mailfrom=bounce@mail.spamhaus.org\n");
        $w = $this->f->uwierzytelnienie($n);
        $this->assertSame(Filtr::AUTH_PASS, $w['wynik']);
        $this->assertSame('spf', $w['metoda']);
    }

    public function testAuthDkimPassObcejDomenyToFail(): void
    {
        $n = new Naglowki("Authentication-Results: mx.example.net; dkim=pass header.d=spamhaus.org.evil.com; spf=pass smtp.mailfrom=x@evil.com\n");
        $this->assertSame(Filtr::AUTH_FAIL, $this->f->uwierzytelnienie($n)['wynik']);
    }

    public function testAuthKomentarzNieUdajePass(): void
    {
        $n = new Naglowki("Authentication-Results: mx.example.net; dkim=fail (dkim=pass header.d=spamhaus.org) header.d=spamhaus.org\n");
        $this->assertSame(Filtr::AUTH_FAIL, $this->f->uwierzytelnienie($n)['wynik']);
    }

    public function testAuthUfaTylkoNajwyzszemuNaglowkowi(): void
    {
        $n = new Naglowki(
            "Authentication-Results: mx.example.net; dkim=fail header.d=spamhaus.org\n"
            . "Authentication-Results: evil.example; dkim=pass header.d=spamhaus.org\n",
        );
        $this->assertSame(Filtr::AUTH_FAIL, $this->f->uwierzytelnienie($n)['wynik']);
    }

    public function testAuthservIdFiltrujeObce(): void
    {
        $f = new Filtr('mx.example.net');
        $n = new Naglowki(
            "Authentication-Results: evil.example; dkim=pass header.d=spamhaus.org\n"
            . "Authentication-Results: mx.example.net; spf=pass smtp.mailfrom=spamhaus.org\n",
        );
        $this->assertSame('spf', $f->uwierzytelnienie($n)['metoda']);

        $tylkoObce = new Naglowki("Authentication-Results: evil.example; dkim=pass header.d=spamhaus.org\n");
        $this->assertSame(Filtr::AUTH_BRAK, $f->uwierzytelnienie($tylkoObce)['wynik']);
    }

    public function testParserNumerIAdresy(): void
    {
        $p = new Parser();
        $this->assertSame('SBL654321', $p->numerSbl('SBL654321 listing', ''));
        $this->assertSame('SBL42', $p->numerSbl('notice', 'Ref: sbl-042'));
        $this->assertNull($p->numerSbl('SBL listing', 'brak numeru'));

        $a = $p->adresy('', "IP: 91.231.81.7\nRange 91.231.81.77/24\nbad 300.1.1.1 and 10.0.0.0/4");
        $this->assertSame([
            ['ip' => '91.231.81.7', 'cidr' => null],
            ['ip' => '91.231.81.0', 'cidr' => '91.231.81.0/24'],
        ], $a);
    }

    public function testParserCzysciHtmlILimit(): void
    {
        $p = new Parser();
        $powod = $p->powod('Reason: <img src=x onerror=alert(1)><b>spam</b> ' . str_repeat('A', 1000));
        $this->assertStringNotContainsString('<', $powod);
        $this->assertStringNotContainsString('onerror', $powod);
        $this->assertLessThanOrEqual(Parser::MAX_POWOD, mb_strlen($powod));
        $this->assertSame('', $p->link('https://evil.example/x https://spamhaus.org.evil.com/y'));
        $this->assertSame('https://check.spamhaus.org/sbl/query/SBL1', $p->link('see https://check.spamhaus.org/sbl/query/SBL1'));
    }

    public function testLinkPrzegladuTylkoDlaPoprawnegoNumeru(): void
    {
        $this->assertSame('https://check.spamhaus.org/sbl/query/SBL123', Parser::linkPrzegladu('SBL123'));
        $this->assertNull(Parser::linkPrzegladu('SBL123"><script>'));
        $this->assertNull(Parser::linkPrzegladu(''));
    }
}
