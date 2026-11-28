<?php

use App\Libraries\Siec\AsMatcher;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\botnet_cl\Dopasowanie;
use McsPluginy\botnet_cl\Klient;
use McsPluginy\botnet_cl\Parser;
use McsPluginy\botnet_cl\Serwis;

/**
 * @internal
 */
final class BotnetClTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected $refresh = false;

    protected $namespace = '';

    protected function tearDown(): void
    {
        foreach (['mcs_ustawienia', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje'] as $tabela) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($tabela));
        }
        unset($_ENV['mcs.botnet_cl.asn'], $_SERVER['mcs.botnet_cl.asn']);
        parent::tearDown();
    }

    public function testParserCzytaWierszObserwacji(): void
    {
        $html = <<<'HTML'
        <section><h2>Recent observations</h2><ul>
        <li><a href="/attack/cc85f439-7a2b-4882-86c8-fa6f0fab2e38">
        <span><time dateTime="2026-11-20T19:28:09.628Z">2026-11-20 19:28:09 UTC</time></span>
        <span class="sr-only">Poland</span>
        <span>Potassium: <!-- -->UDP datagram flood, variant 7</span>
        <span>46.151.187.35/32</span>
        <span>UDP/53</span>
        <span>1m</span>
        </a></li>
        <li><a href="/attack/11111111-1111-1111-1111-111111111111">
        <time dateTime="2026-11-03T15:19:42Z">2026-11-03 15:19:42 UTC</time>
        <span>8.8.8.8/32</span>
        <span>TCP/80</span>
        <span>Other scan</span>
        </a></li>
        </ul></section>
        HTML;

        $wynik = (new Parser())->parsuj($html);

        $this->assertTrue($wynik['ok'], $wynik['blad']);
        $this->assertCount(2, $wynik['obserwacje']);
        $this->assertSame('cc85f439-7a2b-4882-86c8-fa6f0fab2e38', $wynik['obserwacje'][0]['id']);
        $this->assertSame('2026-11-20 19:28:09+00:00', $wynik['obserwacje'][0]['zaobserwowano_at']);
        $this->assertSame('Potassium: UDP datagram flood, variant 7', $wynik['obserwacje'][0]['metoda']);
        $this->assertSame('46.151.187.35/32', $wynik['obserwacje'][0]['cidr']);
        $this->assertSame('udp', $wynik['obserwacje'][0]['protokol']);
        $this->assertSame(53, $wynik['obserwacje'][0]['port']);
        $this->assertSame('Poland', $wynik['obserwacje'][0]['kraj']);
    }

    public function testParserOdrzucaStroneBezListy(): void
    {
        $wynik = (new Parser())->parsuj('<html><title>botnet</title></html>');
        $this->assertFalse($wynik['ok']);
        $this->assertSame([], $wynik['obserwacje']);
    }

    public function testDopasowanieZostawiaTylkoNaszePrefiksy(): void
    {
        $obs = (new Parser())->parsuj(<<<'HTML'
        <h2>Recent observations</h2><ul>
        <li><a href="/attack/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa">
        <time dateTime="2026-11-20T19:28:09Z"></time>
        <span>UDP flood</span><span>91.231.80.9/32</span><span>UDP/53</span><span>1m</span>
        </a></li>
        <li><a href="/attack/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb">
        <time dateTime="2026-11-20T19:28:09Z"></time>
        <span>UDP flood</span><span>1.2.3.4/32</span><span>UDP/53</span><span>1m</span>
        </a></li>
        <li><a href="/attack/cccccccc-cccc-cccc-cccc-cccccccccccc">
        <time dateTime="2026-11-08T23:13:07Z"></time>
        <span>UDP flood</span><span>91.231.81.0/24</span><span>UDP/53</span><span>1m</span>
        </a></li>
        </ul>
        HTML)['obserwacje'];

        $inc = (new Dopasowanie())->incydenty($obs, ['91.231.80.0/22']);

        $this->assertCount(2, $inc);
        $this->assertSame('91.231.80.9', $inc[0]['ip']);
        $this->assertSame('91.231.81.0', $inc[1]['ip']);
        $this->assertSame('high', $inc[0]['waga']);
        $this->assertSame('amplification', $inc[0]['kategoria']);
    }

    public function testPrzebiegZapisujeTrafienie(): void
    {
        $this->utworzTabele();
        $this->db->table('mcs_ustawienia')->insertBatch([
            ['klucz' => 'as_wlaczone', 'wartosc' => '1', 'updated_at' => '2026-01-01T00:00:00+00:00'],
            ['klucz' => 'as_cidry', 'wartosc' => '46.151.184.0/21', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $_ENV['mcs.botnet_cl.asn'] = (string) AsMatcher::DOMYSLNY_ASN;
        $_SERVER['mcs.botnet_cl.asn'] = (string) AsMatcher::DOMYSLNY_ASN;

        $html = <<<'HTML'
            <h2>Recent observations</h2><ul>
            <li><a href="/attack/cc85f439-7a2b-4882-86c8-fa6f0fab2e38">
            <time dateTime="2026-11-20T19:28:09.628Z"></time>
            <span class="sr-only">Poland</span>
            <span>Potassium: UDP datagram flood, variant 7</span>
            <span>46.151.187.35/32</span><span>UDP/53</span><span>1m</span>
            </a></li>
            </ul>
            HTML;

        $klient = new Klient(static fn (): array => ['ok' => true, 'http_code' => 200, 'body' => $html, 'error' => '']);
        $wynik  = (new Serwis($klient))->uruchom();

        $this->assertSame('ok', $wynik['status'], $wynik['msg']);
        $this->assertGreaterThan(0, $wynik['trafienia']);
        $wiersz = $this->db->table('mcs_incydenty')->where('podmiot', '46.151.187.35')->get()->getRowArray();
        $this->assertIsArray($wiersz);
        $this->assertSame('botnet_cl|cc85f439-7a2b-4882-86c8-fa6f0fab2e38', $wiersz['klucz_dedupu']);
        $this->assertSame(53, (int) $wiersz['port']);
        $this->assertSame('udp', $wiersz['protokol']);
    }

    private function utworzTabele(): void
    {
        $p = fn (string $t): string => $this->db->prefixTable($t);
        foreach (['mcs_ustawienia', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje'] as $tabela) {
            $this->db->query('DROP TABLE IF EXISTS ' . $p($tabela));
        }
        $this->db->query("CREATE TABLE {$p('mcs_ustawienia')} (klucz VARCHAR(191) PRIMARY KEY, wartosc TEXT NULL, updated_at TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            klucz_dedupu VARCHAR(255) NOT NULL, podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip', podmiot VARCHAR(255) NOT NULL,
            pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga VARCHAR(20) NOT NULL, wersja_wagi VARCHAR(20) NULL,
            kategoria VARCHAR(100) NOT NULL, tytul VARCHAR(255) NOT NULL, port INTEGER NULL, protokol VARCHAR(20) NULL,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");
    }
}
