<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\blocklist_de\Klient;
use McsPluginy\blocklist_de\Serwis;

/**
 * @internal
 */
final class BlocklistDeSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected $refresh = false;

    protected $namespace = '';

    private int $zapytania = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zapytania = 0;
        $this->utworzTabele();
        $this->db->table('mcs_ustawienia')->insertBatch([
            ['klucz' => 'as198050_wlaczone', 'wartosc' => '1', 'updated_at' => '2026-01-01T00:00:00+00:00'],
            ['klucz' => 'as198050_cidry', 'wartosc' => '91.231.80.0/22', 'updated_at' => '2026-01-01T00:00:00+00:00'],
        ]);
        $this->ustawEnv('mcs.blocklistDe.listy', 'ssh,strongips');
    }

    protected function tearDown(): void
    {
        $this->usunEnv('mcs.blocklistDe.listy');
        foreach (['mcs_ustawienia', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje'] as $tabela) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($tabela));
        }
        parent::tearDown();
    }

    public function testTrafienieWPrefiksie(): void
    {
        $wynik = $this->serwis([
            'ssh'       => "1.2.3.4\n91.231.80.9\n",
            'strongips' => "91.231.80.9\n",
        ])->uruchom();

        $this->assertSame('ok', $wynik['status'], $wynik['msg']);
        $this->assertSame(1, $wynik['trafienia']);
        $wiersz = $this->db->table('mcs_incydenty')->get()->getRowArray();
        $this->assertIsArray($wiersz);
        $this->assertSame('blocklist_de|91.231.80.9', $wiersz['klucz_dedupu']);
        $this->assertSame('critical', $wiersz['waga']);
        $this->assertSame('abuse', $wiersz['kategoria']);
        $this->assertStringContainsString('bez MD5', $wynik['msg']);
    }

    public function testZeroTrafienJestSukcesem(): void
    {
        $wynik = $this->serwis([
            'ssh'       => "1.2.3.4\n",
            'strongips' => '',
        ])->uruchom();

        $this->assertSame('ok', $wynik['status'], $wynik['msg']);
        $this->assertSame(0, $wynik['trafienia']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    public function testNiezgodneMd5NieZapisujeIncydentu(): void
    {
        $wynik = $this->serwis([
            'ssh'       => "91.231.80.9\n",
            'strongips' => "91.231.80.9\n",
        ], ['ssh' => str_repeat('a', 32)])->uruchom();

        $this->assertSame('blad', $wynik['status']);
        $this->assertStringContainsString('md5', strtolower($wynik['msg']));
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    public function testBrakPrefiksowNieSiegaPoHttp(): void
    {
        $this->db->table('mcs_ustawienia')->where('klucz', 'as198050_cidry')->update(['wartosc' => '']);
        $przed = $this->zapytania;
        $wynik = $this->serwis(['ssh' => "91.231.80.9\n", 'strongips' => ''])->uruchom();

        $this->assertSame('blad', $wynik['status']);
        $this->assertSame('brak prefiksów AS', $wynik['msg']);
        $this->assertSame($przed, $this->zapytania);
    }

    public function testDrugiPrzebiegWTejSamejGodzinieJestPominiety(): void
    {
        $tresc = ['ssh' => "91.231.80.9\n", 'strongips' => ''];
        $this->assertSame('ok', $this->serwis($tresc, [], 1_700_000_000)->uruchom()['status']);
        $po = $this->zapytania;
        $drugi = $this->serwis($tresc, [], 1_700_000_100)->uruchom();

        $this->assertSame('pominieto', $drugi['status']);
        $this->assertSame($po, $this->zapytania);
    }

    /**
     * @param array<string, string> $tresc
     * @param array<string, string> $md5
     */
    private function serwis(array $tresc, array $md5 = [], ?int $teraz = null): Serwis
    {
        return new Serwis($this->klient($tresc, $md5), null, null, null, null, $teraz ?? 1_700_000_000);
    }

    /**
     * @param array<string, string> $tresc
     * @param array<string, string> $md5
     */
    private function klient(array $tresc, array $md5): Klient
    {
        return new Klient(function (string $url) use ($tresc, $md5): array {
            $this->zapytania++;
            foreach ($tresc as $nazwa => $body) {
                if (str_ends_with($url, '/' . $nazwa . '.txt')) {
                    return ['ok' => true, 'http_code' => 200, 'body' => $body, 'error' => ''];
                }
                if (str_ends_with($url, '/' . $nazwa . '.txt.md5')) {
                    if (! array_key_exists($nazwa, $md5)) {
                        return ['ok' => false, 'http_code' => 404, 'body' => '', 'error' => 'HTTP 404'];
                    }

                    return ['ok' => true, 'http_code' => 200, 'body' => $md5[$nazwa], 'error' => ''];
                }
            }

            return ['ok' => false, 'http_code' => 404, 'body' => '', 'error' => 'HTTP 404'];
        });
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

    private function ustawEnv(string $klucz, string $wartosc): void
    {
        $_ENV[$klucz]    = $wartosc;
        $_SERVER[$klucz] = $wartosc;
    }

    private function usunEnv(string $klucz): void
    {
        unset($_ENV[$klucz], $_SERVER[$klucz]);
    }
}
