<?php

use App\Libraries\Enrichment\DnsblClient;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\IncydentZapis;
use App\Libraries\Siec\AsMatcher;
use App\Libraries\Siec\CidrMatcher;
use App\Libraries\WagaV1;
use App\Models\DnsblWierszeModel;
use App\Models\EnrichmentDnsblModel;
use App\Models\EnrichmentLmsModel;
use App\Models\PluginModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Issue #458: skan DNSBL zakresu „as” robi jeden zbiorczy odczyt cache (WHERE ip IN (…) partiami
 * po 500, jak znajdzWiele) i zbiorczy zapis wyników (INSERT … ON CONFLICT multi-row per paczka 500)
 * zamiast per-IP SELECT/upsert w pętli.
 *
 * @internal
 */
final class DnsblSerwisSkanBatchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = ['mcs_obserwacje', 'mcs_incydenty', 'mcs_dnsbl_wiersze', 'mcs_enrichment_dnsbl', 'mcs_enrichment_lms'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p   = fn (string $t): string => $this->db->prefixTable($t);
        $teraz = gmdate('Y-m-d H:i:sP');
        $this->db->query(
            "CREATE TABLE {$p('mcs_enrichment_dnsbl')} (
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
            "CREATE TABLE {$p('mcs_dnsbl_wiersze')} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                import_id INTEGER NULL,
                ip VARCHAR(45) NOT NULL,
                lista VARCHAR(64) NOT NULL,
                kod_a VARCHAR(45) NULL,
                zakres_skanu VARCHAR(16) NOT NULL DEFAULT 'as',
                listed INTEGER NOT NULL DEFAULT 1,
                pierwszy_seen_at TEXT NOT NULL,
                ostatni_seen_at TEXT NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                payload_json TEXT NULL,
                UNIQUE (ip, lista)
            )",
        );
        $this->db->query(
            "CREATE TABLE {$p('mcs_enrichment_lms')} (
                ip VARCHAR(45) PRIMARY KEY,
                trafiony INTEGER NULL,
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'test'
            )",
        );
        $this->db->query(
            "CREATE TABLE {$p('mcs_incydenty')} (
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
            "CREATE TABLE {$p('mcs_obserwacje')} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
            )",
        );

        // mały zakres AS na potrzeby testu: 91.231.70.0/30 = 4 IP
        putenv('mcs.as.cidry=91.231.70.0/30');
        $_ENV['mcs.as.cidry'] = '91.231.70.0/30';
    }

    protected function tearDown(): void
    {
        putenv('mcs.as.cidry');
        unset($_ENV['mcs.as.cidry']);
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testSkanAsCzytaIZapisujeCacheBatchowo(): void
    {
        // tylko 91.231.70.1 listowany na uceprotect1
        $resolver = static function (string $fqdn): array {
            if (str_contains($fqdn, 'uceprotect') && str_starts_with($fqdn, '1.70.231.91.')) {
                return ['127.0.0.2'];
            }

            return [];
        };
        $serwis = $this->serwis($resolver);

        $zapytania = [];
        Events::on('DBQuery', static function ($q) use (&$zapytania): void {
            $zapytania[] = $q->getQuery();
        });
        try {
            $wynik = $serwis->skanuj(null, 'as');
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertTrue($wynik['ok']);
        $this->assertSame('as', $wynik['zakres']);
        $this->assertSame(4, $wynik['sprawdzono']);
        $this->assertSame(1, $wynik['listed']);

        $selectyEnr = array_filter($zapytania, static fn (string $sql) => (bool) preg_match('/^\s*SELECT/i', $sql) && str_contains($sql, 'mcs_enrichment_dnsbl'));
        $insertyEnr = array_filter($zapytania, static fn (string $sql) => (bool) preg_match('/^\s*INSERT/i', $sql) && str_contains($sql, 'mcs_enrichment_dnsbl'));

        $this->assertSame(1, count($selectyEnr), 'jeden zbiorczy SELECT cache (whereIn, znajdzWiele) zamiast per-IP SELECT w pętli');
        // upsert zostaje per-IP (robi go szkielet EnrichmentSerwisBaza) — batchowo jest tylko odczyt cache
        $this->assertSame(4, count($insertyEnr), '4 upserty per IP przez ON CONFLICT (zapis własnością klasy bazowej)');
        foreach ($insertyEnr as $sql) {
            $this->assertStringContainsString('ON CONFLICT', $sql);
        }

        // cache faktycznie zapisany dla wszystkich 4 IP
        $this->assertSame(4, $this->db->table('mcs_enrichment_dnsbl')->countAllResults());
        $cache = model(EnrichmentDnsblModel::class)->znajdzPoIp('91.231.70.1');
        $this->assertNotNull($cache);
        $this->assertTrue(PluginModel::jakoBool($cache['listed_any'] ?? false));
        $cacheCzysty = model(EnrichmentDnsblModel::class)->znajdzPoIp('91.231.70.2');
        $this->assertFalse(PluginModel::jakoBool($cacheCzysty['listed_any'] ?? true));
    }

    public function testSkanAsPonownieUzywaMapyCachePrzyPoprzednichListach(): void
    {
        // pierwszy skan: 91.231.70.1 listowany
        $serwis = $this->serwis(static fn (string $fqdn) => str_contains($fqdn, 'uceprotect') && str_starts_with($fqdn, '1.70.231.91.') ? ['127.0.0.2'] : []);
        $serwis->skanuj(null, 'as');

        // drugi skan: uceprotect daje SERVFAIL (false) — poprzedni listing musi zostać (z mapy batchowej)
        $zapytania = [];
        Events::on('DBQuery', static function ($q) use (&$zapytania): void {
            $zapytania[] = $q->getQuery();
        });
        try {
            $serwis2 = $this->serwis(static fn (string $fqdn) => str_contains($fqdn, 'uceprotect') ? false : []);
            $wynik   = $serwis2->skanuj(null, 'as');
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertTrue($wynik['ok']);
        $selectyEnr = array_filter($zapytania, static fn (string $sql) => (bool) preg_match('/^\s*SELECT/i', $sql) && str_contains($sql, 'mcs_enrichment_dnsbl'));
        // 1 zbiorczy SELECT (znajdzWiele) — poprzednieListy NIE robi osobnego SELECT per IP
        $this->assertSame(1, count($selectyEnr));

        $cache = model(EnrichmentDnsblModel::class)->znajdzPoIp('91.231.70.1');
        $listy = is_string($cache['listy_json']) ? json_decode($cache['listy_json'], true) : [];
        $this->assertTrue(PluginModel::jakoBool($listy['uceprotect1']['listed'] ?? false), 'błąd DNS nie kasuje znanego listingu');
        $this->assertNotSame('', (string) ($listy['uceprotect1']['error'] ?? ''));
    }

    /**
     * @param callable(string): list<string>|false $resolver
     */
    private function serwis(callable $resolver): DnsblSerwis
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
            new AsMatcher(new CidrMatcher()),
            new CidrMatcher(),
            new IncydentZapis($this->db),
            new WagaV1(),
            $this->db,
            0,
            '',
            0,
        );
    }
}
