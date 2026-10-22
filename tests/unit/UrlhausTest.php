<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\urlhaus\Klient;
use McsPluginy\urlhaus\Parser;
use McsPluginy\urlhaus\Skladacz;

/**
 * @internal
 */
final class UrlhausTest extends CIUnitTestCase
{
    public function testHostIpDomenaIOffline(): void
    {
        $csv = <<<CSV
# komentarz
id,dateadded,url,url_status,last_online,threat,tags,urlhaus_link,reporter
1,2026-01-01 00:00:00,http://91.231.80.9/a.exe,online,2026-01-02,malware_download,exe,https://urlhaus.abuse.ch/url/1/,x
2,2026-01-01 00:00:00,http://evil.example/b,online,2026-01-02,malware_download,exe,https://urlhaus.abuse.ch/url/2/,x
3,2026-01-01 00:00:00,http://91.231.80.9/c,offline,2026-01-02,malware_download,exe,https://urlhaus.abuse.ch/url/3/,x
4,2026-01-01 00:00:00,http://[2a0f:1700::5]/d,online,2026-01-02,malware_download,exe,https://urlhaus.abuse.ch/url/4/,x
CSV;
        $wynik = (new Parser())->parsuj($csv);

        $this->assertTrue($wynik['ok']);
        $this->assertSame(1, $wynik['pominiete_domeny']);
        $this->assertCount(2, $wynik['wiersze']);
        $this->assertSame('91.231.80.9', $wynik['wiersze'][0]['ip']);
        $this->assertSame('2a0f:1700::5', $wynik['wiersze'][1]['ip']);
    }

    public function testZipIBrakKolumny(): void
    {
        $this->assertFalse((new Parser())->parsuj("PK\x03\x04zip")['ok']);
        $this->assertStringContainsString('url', (new Parser())->parsuj("id,dateadded\n1,2026\n")['blad']);
    }

    public function testSufitDwudziestuUrl(): void
    {
        $wiersze = [];
        for ($i = 1; $i <= 21; $i++) {
            $wiersze[] = [
                'id' => (string) $i, 'dateadded' => '2026-01-01', 'url' => 'http://91.231.80.9/' . $i,
                'threat' => 'malware_download', 'tags' => 'exe', 'urlhaus_link' => 'https://urlhaus.abuse.ch/url/' . $i . '/',
                'ip' => '91.231.80.9',
            ];
        }
        $inc = (new Skladacz())->incydenty($wiersze, static fn (string $ip): bool => true);

        $this->assertCount(1, $inc);
        $this->assertSame('critical', $inc[0]['waga']);
        $this->assertSame('malware', $inc[0]['kategoria']);
        $this->assertSame(21, $inc[0]['payload']['liczba']);
        $this->assertCount(20, $inc[0]['payload']['url']);
        $this->assertSame('URLhaus: 21 aktywnych URL', $inc[0]['tytul']);
    }

    public function testBladHttpNieZwracaKlucza(): void
    {
        $klucz  = 'SUPERSECRETKEY99';
        $klient = new Klient(static function (string $url) use ($klucz): array {
            return ['ok' => false, 'http_code' => 500, 'body' => '', 'error' => 'fail ' . $url];
        });
        $wynik = $klient->pobierz(Klient::DOMYSLNY_SZABLON, $klucz);

        $this->assertFalse($wynik['ok']);
        $this->assertStringNotContainsString($klucz, $wynik['blad']);
        $this->assertStringContainsString('[klucz]', $wynik['blad']);
    }
}
