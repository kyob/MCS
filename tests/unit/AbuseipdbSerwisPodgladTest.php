<?php

use App\Libraries\Enrichment\AbuseipdbClient;
use App\Libraries\Enrichment\AbuseipdbSerwis;
use App\Models\EnrichmentAbuseipdbModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class AbuseipdbSerwisPodgladTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_enrichment_abuseipdb');
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

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_enrichment_abuseipdb'));
        parent::tearDown();
    }

    public function testPodgladCacheBezWywolaniaApi(): void
    {
        $model = model(EnrichmentAbuseipdbModel::class);
        $model->upsertDlaIp('198.51.100.10', [
            'abuse_confidence_score' => 11,
            'total_reports'          => 2,
            'country_code'           => 'PL',
            'isp'                    => 'ISP',
            'zrodlo_danych'          => 'api',
            'checked_at'             => gmdate('Y-m-d H:i:sP'),
            'raw_json'               => '{}',
        ]);

        $serwis = new AbuseipdbSerwis(
            new AbuseipdbClient(''),
            $model,
            86400,
            '',
        );

        $podglad = $serwis->podgladCache('198.51.100.10');
        $this->assertNotNull($podglad);
        $this->assertSame(11, $podglad['abuse_confidence_score']);
        $this->assertSame('brak_klucza', $serwis->tryb());
    }

    public function testPodgladCacheNullGdyBrakLubPrzeterminowany(): void
    {
        $serwis = new AbuseipdbSerwis(
            new AbuseipdbClient(''),
            model(EnrichmentAbuseipdbModel::class),
            86400,
            '',
        );
        $this->assertNull($serwis->podgladCache('198.51.100.99'));

        model(EnrichmentAbuseipdbModel::class)->upsertDlaIp('198.51.100.99', [
            'abuse_confidence_score' => 1,
            'zrodlo_danych'          => 'api',
            'checked_at'             => gmdate('Y-m-d H:i:sP', time() - 90000),
            'raw_json'               => '{}',
        ]);

        $serwisKrotkiTtl = new AbuseipdbSerwis(
            new AbuseipdbClient(''),
            model(EnrichmentAbuseipdbModel::class),
            60,
            '',
        );
        $this->assertNull($serwisKrotkiTtl->podgladCache('198.51.100.99'));
    }
}
