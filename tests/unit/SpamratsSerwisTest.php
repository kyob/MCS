<?php

use App\Libraries\Enrichment\SpamratsClient;
use App\Libraries\Enrichment\SpamratsSerwis;
use App\Models\EnrichmentSpamratsModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SpamratsSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/spamrats_check_sample.json';
        $tabela        = $this->db->prefixTable('mcs_enrichment_spamrats');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                ip VARCHAR(45) PRIMARY KEY,
                listed INTEGER NULL,
                listed_dyna INTEGER NULL,
                listed_noptr INTEGER NULL,
                listed_spam INTEGER NULL,
                listed_auth INTEGER NULL,
                kody_json TEXT NULL,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'dns',
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_enrichment_spamrats');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testFixtureLookupZapisujeCache(): void
    {
        $serwis = new SpamratsSerwis(
            new SpamratsClient(),
            model(EnrichmentSpamratsModel::class),
            86400,
            $this->fixture,
        );

        $this->assertSame('fixture', $serwis->tryb());
        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertTrue((bool) ($wynik['wynik']['listed'] ?? false));
        $this->assertTrue((bool) ($wynik['wynik']['listed_dyna'] ?? false));
        $this->assertTrue((bool) ($wynik['wynik']['listed_spam'] ?? false));
        $this->assertFalse((bool) ($wynik['wynik']['listed_noptr'] ?? true));

        $ponownie = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($ponownie['z_cache']);
    }

    public function testBrakKlucza(): void
    {
        $serwis = new SpamratsSerwis(
            new SpamratsClient('', 'all.spamrats.com'),
            model(EnrichmentSpamratsModel::class),
            86400,
            '',
        );
        $wynik = $serwis->sprawdz('198.51.100.10', true);
        $this->assertFalse($wynik['ok']);
        $this->assertSame('brak_klucza', $wynik['tryb']);
        $this->assertStringContainsString('apiKey', (string) $wynik['blad']);
    }

    public function testIpv6Odrzucony(): void
    {
        $serwis = new SpamratsSerwis(
            new SpamratsClient('KEY', 'all.spamrats.com', static fn () => []),
            model(EnrichmentSpamratsModel::class),
            86400,
            '',
        );
        $wynik = $serwis->sprawdz('2001:db8::1', true);
        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('IPv4', (string) $wynik['blad']);
    }

    public function testDnsLookupZapisujeCache(): void
    {
        $client = new SpamratsClient(
            'KEY',
            'all.spamrats.com',
            static fn () => ['127.0.0.43'],
        );
        $serwis = new SpamratsSerwis(
            $client,
            model(EnrichmentSpamratsModel::class),
            86400,
            '',
        );

        $wynik = $serwis->sprawdz('203.0.113.50', true);
        $this->assertTrue($wynik['ok']);
        $this->assertSame('dns', $wynik['tryb']);
        $this->assertTrue((bool) ($wynik['wynik']['listed'] ?? false));
        $this->assertTrue((bool) ($wynik['wynik']['listed_auth'] ?? false));
    }
}
