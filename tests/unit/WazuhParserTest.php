<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\wazuh\ApiClient;
use McsPluginy\wazuh\Parser;

/**
 * @internal
 */
final class WazuhParserTest extends CIUnitTestCase
{
    private function wiersz(array $nadpisz = []): array
    {
        return $nadpisz + [
            'ip'              => '198.51.100.7',
            'dzien'           => '2026-09-28',
            'kategoria_wazuh' => 'smtp-auth',
            'liczba'          => 31,
            'poziom_max'      => 10,
            'reguly'          => [['id' => '3357', 'opis' => 'Postfix: Multiple SASL authentication failures.', 'poziom' => 10, 'liczba' => 6]],
            'hosty_docelowe'  => ['new-smtp'],
            'pierwsze'        => '2026-09-28T06:12:03.123Z',
            'ostatnie'        => '2026-09-28T11:40:55.456Z',
        ];
    }

    public function testParsujeEksportWersja1(): void
    {
        $e = (new Parser())->parsujEksport(json_encode([
            'wersja' => 1, 'wygenerowano' => '2026-09-28T12:00:00Z', 'okno_od' => 'a', 'okno_do' => 'b',
            'wiersze' => [$this->wiersz(), 'smiec'],
        ]));

        $this->assertCount(1, $e['wiersze']);
        $this->assertSame('2026-09-28T12:00:00Z', $e['wygenerowano']);
    }

    public function testOdrzucaInnaWersje(): void
    {
        $p = new Parser();
        $this->expectException(RuntimeException::class);
        $p->parsujEksport(json_encode(['wersja' => 2, 'wiersze' => []]));
    }

    public function testOdrzucaZlyJson(): void
    {
        $this->expectException(RuntimeException::class);
        (new Parser())->parsujEksport('nie-json');
    }

    public function testMapujeWierszIKluczDedupu(): void
    {
        $m = (new Parser())->mapujWiersz($this->wiersz());

        $this->assertNotNull($m);
        $this->assertSame('wazuh|198.51.100.7|smtp-auth|2026-09-28', $m['klucz_dedupu']);
        $this->assertSame(31, $m['liczba']);
        $this->assertSame('2026-09-28 11:40:55+00:00', $m['ostatnie_at']);
        $this->assertSame(['new-smtp'], $m['hosty_docelowe']);
        $this->assertSame('Wazuh: 31× smtp-auth na new-smtp', (new Parser())->tytul($m));
    }

    public function testPomijaNiepoprawneWiersze(): void
    {
        $p = new Parser();
        $this->assertNull($p->mapujWiersz($this->wiersz(['ip' => '999.1.1.1'])));
        $this->assertNull($p->mapujWiersz($this->wiersz(['dzien' => '28-09-2026'])));
        $this->assertNull($p->mapujWiersz($this->wiersz(['kategoria_wazuh' => 'nieznana'])));
        $this->assertNull($p->mapujWiersz($this->wiersz(['liczba' => 0])));
        $this->assertNull($p->mapujWiersz($this->wiersz(['ostatnie' => ''])));
    }

    public function testApiClientBudujeKomendeSshZOknem(): void
    {
        $zlapane = null;
        $c = new ApiClient('192.0.2.10', 'mcs-export', __FILE__, '/tmp/kh', 48, 30,
            static function (array $cmd, int $t) use (&$zlapane): array {
                $zlapane = $cmd;

                return ['ok' => true, 'stdout' => '{}', 'error' => ''];
            });

        $this->assertTrue($c->gotowy());
        $this->assertTrue($c->pobierzEksport()['ok']);
        $this->assertSame('mcs-export@192.0.2.10', $zlapane[count($zlapane) - 2]);
        $this->assertSame('48', $zlapane[count($zlapane) - 1]);
        $this->assertContains('StrictHostKeyChecking=yes', $zlapane);
    }
}
