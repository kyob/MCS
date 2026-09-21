<?php

use App\Libraries\Enrichment\LmsMapowanieClient;
use App\Libraries\Enrichment\LmsMapowanieDbClient;
use App\Libraries\Enrichment\LmsMapowanieSerwis;
use App\Models\EnrichmentLmsModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class LmsMapowanieSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/lms_mapowanie_sample.json';
        $tabela        = $this->db->prefixTable('mcs_enrichment_lms');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                ip VARCHAR(45) PRIMARY KEY,
                node_id INTEGER NULL,
                komputer VARCHAR(64) NULL,
                node_login VARCHAR(128) NULL,
                customer_id INTEGER NULL,
                abonent VARCHAR(255) NULL,
                customer_lastname VARCHAR(128) NULL,
                customer_name VARCHAR(128) NULL,
                netid INTEGER NULL,
                access SMALLINT NULL,
                trafiony INTEGER NOT NULL DEFAULT 0,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'api',
                checked_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
        );
    }

    public function testFixtureLookupICache(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(),
            null,
            model(EnrichmentLmsModel::class),
            86400,
            $this->fixture,
            'http://lms.test/?m=nodeinfo&id={id}',
        );

        $this->assertSame('fixture', $serwis->tryb());

        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertFalse($wynik['z_cache']);
        $this->assertTrue($wynik['wynik']['trafiony'] ?? false);
        $this->assertSame('Kochanowskiego-8', $wynik['wynik']['komputer'] ?? null);
        $this->assertSame('MICHALAK Łukasz', $wynik['wynik']['abonent'] ?? null);
        $this->assertSame(11106, $wynik['wynik']['node_id'] ?? null);
        $this->assertStringContainsString('11106', (string) ($wynik['wynik']['lms_url'] ?? ''));

        $ponownie = $serwis->sprawdz('198.51.100.10');
        $this->assertTrue($ponownie['ok']);
        $this->assertTrue($ponownie['z_cache']);
        $this->assertSame('cache', $ponownie['tryb']);
    }

    public function testBezKonfiguracji(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(),
            null,
            model(EnrichmentLmsModel::class),
            86400,
            '',
            '',
        );

        $wynik = $serwis->sprawdz('198.51.100.10');
        $this->assertFalse($wynik['ok']);
        $this->assertSame('brak_konfiguracji', $wynik['tryb']);
        $this->assertStringContainsString('mcs.lms', (string) $wynik['blad']);
    }

    public function testApiMockLookup(): void
    {
        $client = new LmsMapowanieClient(
            'http://lms.test',
            'tok',
            'plugins/LMSMcsMapowaniePlugin/api.php',
            5,
            true,
            static function () {
                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'data'      => [
                        'ok'    => true,
                        'items' => [
                            [
                                'ip'          => '198.51.100.99',
                                'node_id'     => 42,
                                'komputer'    => 'Test-Host',
                                'customer_id' => 7,
                                'abonent'     => 'Kowalski Jan',
                            ],
                        ],
                    ],
                    'error'     => '',
                    'raw_body'  => '{}',
                ];
            },
        );

        $serwis = new LmsMapowanieSerwis(
            $client,
            null,
            model(EnrichmentLmsModel::class),
            86400,
            '',
            '',
        );

        $wynik = $serwis->sprawdz('198.51.100.99');
        $this->assertTrue($wynik['ok']);
        $this->assertSame('api', $wynik['tryb']);
        $this->assertSame('Test-Host', $wynik['wynik']['komputer'] ?? null);
        $this->assertSame('Kowalski Jan', $wynik['wynik']['abonent'] ?? null);
    }

    public function testSyncFixture(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(),
            null,
            model(EnrichmentLmsModel::class),
            86400,
            $this->fixture,
            '',
        );

        $wynik = $serwis->sync();
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertSame(3, $wynik['zapisano']);

        $cache = model(EnrichmentLmsModel::class)->znajdzPoIp('198.51.100.20');
        $this->assertIsArray($cache);
        $this->assertSame('AP12-IIa16', $cache['komputer'] ?? null);
    }

    public function testFixtureLookupIp91MapujeNaNode9833(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(),
            null,
            model(EnrichmentLmsModel::class),
            86400,
            $this->fixture,
            'http://192.0.2.60/?m=nodeinfo&id={id}',
        );

        $wynik = $serwis->sprawdz('91.231.81.84', true);
        $this->assertTrue($wynik['ok']);
        $this->assertFalse($wynik['z_cache']);
        $this->assertTrue($wynik['wynik']['trafiony'] ?? false);
        $this->assertSame(9833, $wynik['wynik']['node_id'] ?? null);
        $this->assertSame('LETNIA-17', $wynik['wynik']['komputer'] ?? null);
        $this->assertSame('Misiura Jakub', $wynik['wynik']['abonent'] ?? null);
        $this->assertSame(1884, $wynik['wynik']['customer_id'] ?? null);
        $this->assertSame(
            'http://192.0.2.60/?m=nodeinfo&id=9833',
            $wynik['wynik']['lms_url'] ?? null,
        );
    }

    public function testFixtureNieRemapujeObcegoIpNaPierwszyWpis(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(),
            null,
            model(EnrichmentLmsModel::class),
            86400,
            $this->fixture,
            'http://lms.test/?m=nodeinfo&id={id}',
        );

        $wynik = $serwis->sprawdz('203.0.113.50', true);
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertFalse($wynik['wynik']['trafiony'] ?? true);
        $this->assertNull($wynik['wynik']['node_id']);
        $this->assertNull($wynik['wynik']['lms_url']);
    }

    public function testWybieraNizszyNodeIdPrzyWieluTrafieniach(): void
    {
        $client = new LmsMapowanieClient(
            'http://lms.test',
            'tok',
            'plugins/LMSMcsMapowaniePlugin/api.php',
            5,
            true,
            static function () {
                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'data'      => [
                        'ok'    => true,
                        'items' => [
                            [
                                'ip'       => '198.51.100.77',
                                'node_id'  => 11106,
                                'komputer' => 'zly-wysoki-id',
                                'access'   => 1,
                            ],
                            [
                                'ip'       => '198.51.100.77',
                                'node_id'  => 9833,
                                'komputer' => 'wlasciwy',
                                'access'   => 1,
                            ],
                        ],
                    ],
                    'error'     => '',
                    'raw_body'  => '{}',
                ];
            },
        );

        $serwis = new LmsMapowanieSerwis(
            $client,
            null,
            model(EnrichmentLmsModel::class),
            86400,
            '',
            'http://lms.test/?m=nodeinfo&id={id}',
        );

        $wynik = $serwis->sprawdz('198.51.100.77', true);
        $this->assertTrue($wynik['ok']);
        $this->assertSame(9833, $wynik['wynik']['node_id'] ?? null);
        $this->assertSame('wlasciwy', $wynik['wynik']['komputer'] ?? null);
        $this->assertStringContainsString('9833', (string) ($wynik['wynik']['lms_url'] ?? ''));
    }

    public function testOpisPolaczeniaPokazujeWszystkieKanalyIHostBezSekretu(): void
    {
        $serwis = new LmsMapowanieSerwis(
            new LmsMapowanieClient(
                'http://192.0.2.60',
                'tajny-token-lms',
                'plugins/LMSMcsMapowaniePlugin/api.php',
            ),
            new LmsMapowanieDbClient(
                'pgsql:host=192.0.2.50;port=5432;dbname=lms;sslmode=prefer',
                'lmsro',
                'haslo-bazy',
            ),
            model(EnrichmentLmsModel::class),
            86400,
            $this->fixture,
            '',
        );

        $opis = $serwis->opisPolaczenia();

        $this->assertSame('plik_testowy', $opis['tryb_aktywny']);
        $this->assertSame('plik testowy (JSON)', $opis['tryb_etykieta']);
        $this->assertStringContainsString('plik testowy', $opis['skrot']);
        $this->assertStringNotContainsString('fixture', $opis['skrot']);

        $poNazwie = [];
        foreach ($opis['pozycje'] as $p) {
            $poNazwie[$p['nazwa']] = $p;
        }

        $this->assertTrue($poNazwie['Plik testowy (JSON)']['aktywna']);
        $this->assertStringContainsString('lms_mapowanie_sample.json', $poNazwie['Plik testowy (JSON)']['wartosc']);

        $this->assertFalse($poNazwie['HTTP LMS']['aktywna']);
        $this->assertTrue($poNazwie['HTTP LMS']['ustawiona']);
        $this->assertStringContainsString('192.0.2.60', $poNazwie['HTTP LMS']['wartosc']);

        $this->assertFalse($poNazwie['Baza LMS (PostgreSQL)']['aktywna']);
        $this->assertTrue($poNazwie['Baza LMS (PostgreSQL)']['ustawiona']);
        $this->assertStringContainsString('192.0.2.50', $poNazwie['Baza LMS (PostgreSQL)']['wartosc']);
        $this->assertStringContainsString('lms', $poNazwie['Baza LMS (PostgreSQL)']['wartosc']);

        $json = json_encode($opis, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('tajny-token-lms', $json);
        $this->assertStringNotContainsString('haslo-bazy', $json);
        $this->assertSame('ustawiony', $poNazwie['Token mapowania']['wartosc']);
    }
}
