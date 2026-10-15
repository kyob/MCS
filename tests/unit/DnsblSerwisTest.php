<?php

use App\Libraries\Enrichment\DnsblClient;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\IncydentZapis;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Libraries\WagaV1;
use App\Models\DnsblWierszeModel;
use App\Models\EnrichmentDnsblModel;
use App\Models\EnrichmentLmsModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class DnsblSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/dnsbl_check_sample.json';
        $this->utworzTabele();
    }

    protected function tearDown(): void
    {
        foreach ([
            'mcs_obserwacje',
            'mcs_incydenty',
            'mcs_dnsbl_wiersze',
            'mcs_enrichment_dnsbl',
            'mcs_enrichment_lms',
        ] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testWybierzZakresLmsIAs(): void
    {
        $serwis = $this->serwisFixture();
        $this->assertSame('lms', $serwis->wybierzZakres(3)); // środa
        $this->assertSame('as', $serwis->wybierzZakres(0));  // niedziela
    }

    public function testFixtureZapisujeEnrichmentIIncydent(): void
    {
        $serwis = $this->serwisFixture();
        $this->assertSame('fixture', $serwis->tryb());

        $wynik = $serwis->sprawdz('5.253.230.10', true, 'lms');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertTrue((bool) ($wynik['wynik']['listed_any'] ?? false));
        $this->assertGreaterThanOrEqual(1, (int) ($wynik['incydenty'] ?? 0));

        $cache = model(EnrichmentDnsblModel::class)->znajdzPoIp('5.253.230.10');
        $this->assertNotNull($cache);
        $this->assertTrue(PluginModelBool($cache['listed_any'] ?? false));

        $wiersz = model(DnsblWierszeModel::class)->znajdzPoIpLista('5.253.230.10', 'uceprotect1');
        $this->assertNotNull($wiersz);
        $this->assertTrue(PluginModelBool($wiersz['listed'] ?? false));

        $inc = $this->db->table('mcs_incydenty')
            ->where('zrodlo', 'dnsbl')
            ->where('klucz_dedupu', '5.253.230.10|uceprotect1')
            ->get()
            ->getRowArray();
        $this->assertIsArray($inc);
        $this->assertSame('reputation', $inc['kategoria']);
        $this->assertSame('medium', $inc['waga']);
    }

    public function testDelistNieTworzyIncydentu(): void
    {
        $resolver = static function (string $fqdn) {
            if (str_contains($fqdn, 'uceprotect')) {
                return ['127.0.0.2'];
            }

            return [];
        };
        $serwis = $this->serwisDns($resolver);
        $serwis->sprawdz('5.253.230.11', true, 'lms');

        $resolver2 = static fn () => [];
        $serwis2   = $this->serwisDns($resolver2);
        $wynik     = $serwis2->sprawdz('5.253.230.11', true, 'lms');

        $this->assertTrue($wynik['ok']);
        $this->assertFalse((bool) ($wynik['wynik']['listed_any'] ?? true));
        $this->assertSame(0, (int) ($wynik['incydenty'] ?? -1));

        $wiersz = model(DnsblWierszeModel::class)->znajdzPoIpLista('5.253.230.11', 'uceprotect1');
        $this->assertNotNull($wiersz);
        $this->assertFalse(PluginModelBool($wiersz['listed'] ?? true));
    }

    public function testIpZLmsFiltrujeAs(): void
    {
        $this->db->table('mcs_enrichment_lms')->insert([
            'ip' => '5.253.230.50', 'trafiony' => 1,
            'checked_at' => gmdate('Y-m-d H:i:sP'),
            'created_at' => gmdate('Y-m-d H:i:sP'),
            'updated_at' => gmdate('Y-m-d H:i:sP'),
            'zrodlo_danych' => 'test',
        ]);
        $this->db->table('mcs_enrichment_lms')->insert([
            'ip' => '8.8.8.8', 'trafiony' => 1,
            'checked_at' => gmdate('Y-m-d H:i:sP'),
            'created_at' => gmdate('Y-m-d H:i:sP'),
            'updated_at' => gmdate('Y-m-d H:i:sP'),
            'zrodlo_danych' => 'test',
        ]);

        $serwis = $this->serwisFixture();
        $ips    = $serwis->zbierzIpDoSkanu('lms');
        $this->assertContains('5.253.230.50', $ips);
        $this->assertNotContains('8.8.8.8', $ips);
    }

    public function testBladDnsNieKasujeListingu(): void
    {
        $serwis = $this->serwisDns(static fn (string $fqdn) => str_contains($fqdn, 'uceprotect') ? ['127.0.0.2'] : []);
        $serwis->sprawdz('5.253.230.12', true, 'lms');

        // Drugi przebieg: uceprotect SERVFAIL (false), spamcop czysto.
        $serwis2 = $this->serwisDns(static fn (string $fqdn) => str_contains($fqdn, 'uceprotect') ? false : []);
        $wynik   = $serwis2->sprawdz('5.253.230.12', true, 'lms');

        $this->assertTrue($wynik['ok']);
        $this->assertTrue((bool) $wynik['wynik']['listed_any']);
        $this->assertTrue((bool) $wynik['wynik']['listy']['uceprotect1']['listed']);
        $this->assertNotSame('', $wynik['wynik']['listy']['uceprotect1']['error']);

        $wiersz = model(DnsblWierszeModel::class)->znajdzPoIpLista('5.253.230.12', 'uceprotect1');
        $this->assertTrue(PluginModelBool($wiersz['listed'] ?? false));
    }

    public function testBladDnsBezHistoriiNieJestCzysty(): void
    {
        $serwis = $this->serwisDns(static fn (string $fqdn) => str_contains($fqdn, 'uceprotect') ? false : []);
        $wynik  = $serwis->sprawdz('5.253.230.13', true, 'lms');

        $this->assertTrue($wynik['ok']);
        $this->assertNotSame('', $wynik['wynik']['listy']['uceprotect1']['error']);
        $this->assertFalse((bool) $wynik['wynik']['listed_any']);
    }

    private function serwisFixture(): DnsblSerwis
    {
        return new DnsblSerwis(
            new DnsblClient(['spamcop' => 'bl.spamcop.net'], static fn () => []),
            model(EnrichmentDnsblModel::class),
            model(DnsblWierszeModel::class),
            model(EnrichmentLmsModel::class),
            new As198050Matcher(new CidrMatcher()),
            new CidrMatcher(),
            new IncydentZapis($this->db),
            new WagaV1(),
            $this->db,
            86400,
            $this->fixture,
            0,
        );
    }

    /**
     * @param callable(string): list<string> $resolver
     */
    private function serwisDns(callable $resolver): DnsblSerwis
    {
        return new DnsblSerwis(
            new DnsblClient(
                [
                    'uceprotect1' => 'dnsbl-1.uceprotect.net',
                    'spamcop'     => 'bl.spamcop.net',
                ],
                $resolver,
            ),
            model(EnrichmentDnsblModel::class),
            model(DnsblWierszeModel::class),
            model(EnrichmentLmsModel::class),
            new As198050Matcher(new CidrMatcher()),
            new CidrMatcher(),
            new IncydentZapis($this->db),
            new WagaV1(),
            $this->db,
            0,
            '',
            0,
        );
    }

    private function utworzTabele(): void
    {
        $enr = $this->db->prefixTable('mcs_enrichment_dnsbl');
        $wr  = $this->db->prefixTable('mcs_dnsbl_wiersze');
        $lms = $this->db->prefixTable('mcs_enrichment_lms');
        $inc = $this->db->prefixTable('mcs_incydenty');
        $obs = $this->db->prefixTable('mcs_obserwacje');

        foreach ([$obs, $inc, $wr, $enr, $lms] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->db->query(
            "CREATE TABLE {$enr} (
                ip VARCHAR(45) PRIMARY KEY,
                listed_any INTEGER NULL,
                listy_json TEXT NULL,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'dns',
                zakres_skanu VARCHAR(16) NULL,
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
        );
        $this->db->query(
            "CREATE TABLE {$wr} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                import_id INTEGER NULL,
                ip VARCHAR(45) NOT NULL,
                lista VARCHAR(64) NOT NULL,
                kod_a VARCHAR(45) NULL,
                zakres_skanu VARCHAR(16) NOT NULL DEFAULT 'lms',
                listed INTEGER NOT NULL DEFAULT 1,
                pierwszy_seen_at TEXT NOT NULL,
                ostatni_seen_at TEXT NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                payload_json TEXT NULL,
                UNIQUE (ip, lista)
            )",
        );
        $this->db->query(
            "CREATE TABLE {$lms} (
                ip VARCHAR(45) PRIMARY KEY,
                trafiony INTEGER NULL,
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'test'
            )",
        );
        $this->db->query(
            "CREATE TABLE {$inc} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip',
                podmiot VARCHAR(255) NOT NULL,
                pierwsze_widziane TEXT NOT NULL,
                ostatnio_widziane TEXT NOT NULL,
                waga VARCHAR(20) NOT NULL,
                wersja_wagi VARCHAR(20) NULL,
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );
        $this->db->query(
            "CREATE TABLE {$obs} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
            )",
        );
    }
}

/**
 * SQLite może zwrócić 0/1 zamiast bool.
 */
function PluginModelBool(mixed $v): bool
{
    return \App\Models\PluginModel::jakoBool($v);
}
