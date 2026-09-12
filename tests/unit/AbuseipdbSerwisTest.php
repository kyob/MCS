<?php

use App\Libraries\Enrichment\AbuseipdbClient;
use App\Libraries\Enrichment\AbuseipdbSerwis;
use App\Models\EnrichmentAbuseipdbModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AbuseipdbSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/abuseipdb_check_sample.json';
        $tabela        = $this->db->prefixTable('mcs_enrichment_abuseipdb');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                ip VARCHAR(45) PRIMARY KEY,
                abuse_confidence_score INTEGER NULL,
                total_reports INTEGER NULL,
                last_reported_at TEXT NULL,
                country_code VARCHAR(8) NULL,
                isp VARCHAR(255) NULL,
                usage_type VARCHAR(64) NULL,
                is_whitelisted INTEGER NULL,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'api',
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
        );
    }

    public function testFixtureZapisujeCacheBezApi(): void
    {
        $serwis = new AbuseipdbSerwis(
            new AbuseipdbClient(''),
            model(EnrichmentAbuseipdbModel::class),
            86400,
            $this->fixture,
        );

        $this->assertSame('fixture', $serwis->tryb());

        $wynik = $serwis->sprawdz('203.0.113.50');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertFalse($wynik['z_cache']);
        $this->assertSame(42, $wynik['wynik']['abuse_confidence_score'] ?? null);
        $this->assertSame('203.0.113.50', $wynik['wynik']['ip'] ?? null);

        $ponownie = $serwis->sprawdz('203.0.113.50');
        $this->assertTrue($ponownie['ok']);
        $this->assertTrue($ponownie['z_cache']);
        $this->assertSame('cache', $ponownie['tryb']);
    }

    public function testBezKluczaIBezFixture(): void
    {
        $serwis = new AbuseipdbSerwis(
            new AbuseipdbClient(''),
            model(EnrichmentAbuseipdbModel::class),
            86400,
            '',
        );

        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertFalse($wynik['ok']);
        $this->assertSame('brak_klucza', $wynik['tryb']);
        $this->assertStringContainsString('mcs.abuseipdb.apiKey', (string) $wynik['blad']);
    }

    public function testApiMockUpsert(): void
    {
        $client = new AbuseipdbClient('k', 'https://example.test', 90, false, static function () {
            return [
                'ok'        => true,
                'http_code' => 200,
                'data'      => [
                    'data' => [
                        'ipAddress'            => '198.51.100.10',
                        'abuseConfidenceScore' => 7,
                        'totalReports'         => 1,
                        'countryCode'          => 'DE',
                        'isp'                  => 'Mock ISP',
                        'usageType'            => 'Data Center',
                        'isWhitelisted'        => false,
                        'lastReportedAt'       => '2026-08-15T10:00:00+00:00',
                    ],
                ],
                'error'     => '',
                'raw_body'  => '{}',
            ];
        });

        $serwis = new AbuseipdbSerwis(
            $client,
            model(EnrichmentAbuseipdbModel::class),
            86400,
            '',
        );

        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('api', $wynik['tryb']);
        $this->assertSame(7, $wynik['wynik']['abuse_confidence_score'] ?? null);
        $this->assertSame('DE', $wynik['wynik']['country_code'] ?? null);
    }
}
