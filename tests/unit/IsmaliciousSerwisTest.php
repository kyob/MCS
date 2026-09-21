<?php

use App\Libraries\Enrichment\IsmaliciousClient;
use App\Libraries\Enrichment\IsmaliciousSerwis;
use App\Models\EnrichmentIsmaliciousModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IsmaliciousSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/ismalicious_check_sample.json';
        $tabela        = $this->db->prefixTable('mcs_enrichment_ismalicious');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                ip VARCHAR(45) PRIMARY KEY,
                malicious INTEGER NULL,
                risk_score REAL NULL,
                confidence REAL NULL,
                blocklist_hits INTEGER NULL,
                blocklist_listed INTEGER NULL,
                classification VARCHAR(128) NULL,
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
        $tabela = $this->db->prefixTable('mcs_enrichment_ismalicious');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testFixtureLookupZapisujeCache(): void
    {
        $serwis = new IsmaliciousSerwis(
            new IsmaliciousClient(),
            model(EnrichmentIsmaliciousModel::class),
            86400,
            $this->fixture,
        );

        $this->assertSame('fixture', $serwis->tryb());
        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertTrue((bool) ($wynik['wynik']['malicious'] ?? false));
        $this->assertSame(78.5, (float) ($wynik['wynik']['risk_score'] ?? 0));
        $this->assertSame('malware', (string) ($wynik['wynik']['classification'] ?? ''));

        $ponownie = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($ponownie['z_cache']);
    }

    public function testBrakKlucza(): void
    {
        $serwis = new IsmaliciousSerwis(
            new IsmaliciousClient('', '', '', 'https://api.ismalicious.com'),
            model(EnrichmentIsmaliciousModel::class),
            86400,
            '',
        );
        $wynik = $serwis->sprawdz('198.51.100.10', true);
        $this->assertFalse($wynik['ok']);
        $this->assertSame('brak_klucza', $wynik['tryb']);
        $this->assertStringContainsString('apiKey', (string) $wynik['blad']);
    }

    public function testClientBudujeBase64Header(): void
    {
        $zlapany = null;
        $client  = new IsmaliciousClient(
            'key-aaa',
            'secret-bbb',
            '',
            'https://api.ismalicious.com',
            'standard',
            static function (string $url, array $headers) use (&$zlapany) {
                $zlapany = $headers;

                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'data'      => [
                        'malicious'       => false,
                        'apiVersion'      => 'v2',
                        'enrichmentLevel' => 'standard',
                        'dataTrust'       => 'high',
                        'evidence'        => [],
                        'riskScore'       => 1.0,
                        'confidence'      => 0.5,
                        'blocklistHits'   => 0,
                        'blocklistListed' => false,
                    ],
                    'error'    => '',
                    'raw_body' => '{}',
                ];
            },
        );

        $resp = $client->checkIp('8.8.8.8');
        $this->assertTrue($resp['ok']);
        $this->assertSame(base64_encode('key-aaa:secret-bbb'), $zlapany['X-API-KEY'] ?? null);
    }
}
