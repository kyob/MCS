<?php

use App\Libraries\Enrichment\GreynoiseClient;
use App\Libraries\Enrichment\GreynoiseSerwis;
use App\Models\EnrichmentGreynoiseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\greynoise\Plugin;

/**
 * @internal
 */
final class GreynoiseSerwisTest extends CIUnitTestCase
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
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query('DROP TABLE IF EXISTS ' . $p('mcs_enrichment_greynoise'));
        $this->db->query('DROP TABLE IF EXISTS ' . $p('mcs_pluginy'));
        $this->db->query("CREATE TABLE {$p('mcs_enrichment_greynoise')} (
            ip VARCHAR(45) PRIMARY KEY,
            noise INTEGER NULL,
            riot INTEGER NULL,
            classification VARCHAR(64) NULL,
            name VARCHAR(255) NULL,
            link VARCHAR(512) NULL,
            last_seen VARCHAR(32) NULL,
            message TEXT NULL,
            http_status INTEGER NULL,
            raw_json TEXT NULL,
            zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'api',
            checked_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        $this->db->query("CREATE TABLE {$p('mcs_pluginy')} (
            zrodlo VARCHAR(50) PRIMARY KEY,
            nazwa VARCHAR(100) NOT NULL,
            opis TEXT NULL,
            import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
            health_at TEXT NULL,
            health_ok BOOLEAN NULL,
            health_msg TEXT NULL
        )");
    }

    protected function tearDown(): void
    {
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query('DROP TABLE IF EXISTS ' . $p('mcs_enrichment_greynoise'));
        $this->db->query('DROP TABLE IF EXISTS ' . $p('mcs_pluginy'));
        parent::tearDown();
    }

    public function testOdpowiedz200TrafiaDoCache(): void
    {
        $serwis = $this->serwis(200, [
            'ip' => '8.8.8.8', 'noise' => false, 'riot' => true, 'classification' => 'benign',
            'name' => 'Example DNS', 'link' => 'https://viz.greynoise.io/riot/8.8.8.8',
            'last_seen' => '2026-01-01', 'message' => 'Success',
        ]);
        $wynik = $serwis->sprawdz('8.8.8.8', true);

        $this->assertTrue($wynik['ok']);
        $this->assertSame('benign', $wynik['wynik']['classification']);
        $this->assertTrue($wynik['wynik']['riot']);
        $this->assertFalse($wynik['wynik']['noise']);
        $ponownie = $serwis->sprawdz('8.8.8.8');
        $this->assertTrue($ponownie['z_cache']);
        $this->assertSame(1, $this->zapytania);
    }

    public function test404JestWynikiem(): void
    {
        $serwis = $this->serwis(404, ['message' => 'IP not observed', 'noise' => false, 'riot' => false]);
        $wynik  = $serwis->sprawdz('198.51.100.10', true);

        $this->assertTrue($wynik['ok']);
        $this->assertSame(404, $wynik['wynik']['http_status']);
        $this->assertSame('IP not observed', $wynik['wynik']['message']);
        $this->assertNotNull($serwis->podgladCache('198.51.100.10'));
    }

    public function test429NieNadpisujeCache(): void
    {
        $pierwszy = $this->serwis(200, [
            'classification' => 'malicious', 'noise' => true, 'riot' => false, 'name' => 'Stary',
            'message' => 'Success',
        ]);
        $this->assertTrue($pierwszy->sprawdz('203.0.113.5', true)['ok']);

        $drugi = $this->serwis(429, ['message' => 'limit GreyNoise']);
        $wynik = $drugi->sprawdz('203.0.113.5', true);

        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('limit', (string) $wynik['blad']);
        $cache = $pierwszy->podgladCache('203.0.113.5');
        $this->assertSame('malicious', $cache['classification'] ?? null);
        $this->assertSame('Stary', $cache['name'] ?? null);
    }

    public function testPobierzNieWolaApi(): void
    {
        $this->db->table('mcs_pluginy')->insert([
            'zrodlo' => 'greynoise', 'nazwa' => 'GreyNoise', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0,
        ]);
        $serwis = $this->serwis(200, ['classification' => 'benign']);
        $plugin = new Plugin($serwis);
        $plugin->pobierz();

        $this->assertSame(0, $this->zapytania);
        $wiersz = $this->db->table('mcs_pluginy')->where('zrodlo', 'greynoise')->get()->getRowArray();
        $this->assertIsArray($wiersz);
        $this->assertContains($wiersz['health_ok'], [1, '1', true, 't'], true);
        $this->assertStringContainsString('/ip/{ip}', (string) $wiersz['health_msg']);
    }

    public function testOpisNieZawieraKlucza(): void
    {
        $klucz = 'TAJNYKLUCZGREYNOISE';
        $opis  = (new GreynoiseSerwis(new GreynoiseClient($klucz)))->opisPolaczenia();
        $json  = (string) json_encode($opis);

        $this->assertStringNotContainsString($klucz, $json);
        $this->assertStringContainsString('ustawiony', $json);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function serwis(int $kod, array $data): GreynoiseSerwis
    {
        $client = new GreynoiseClient('klucz-test', 'https://api.greynoise.io', 15, function (string $url, array $headers) use ($kod, $data): array {
            $this->zapytania++;
            $this->assertSame('klucz-test', $headers['key'] ?? null);
            $this->assertStringContainsString('/v3/community/', $url);

            return [
                'ok'        => $kod === 200 || $kod === 404,
                'http_code' => $kod,
                'data'      => $data,
                'error'     => $kod === 429 ? 'limit GreyNoise' : '',
                'raw_body'  => '',
            ];
        });

        return new GreynoiseSerwis($client, model(EnrichmentGreynoiseModel::class), 604800, '');
    }
}
