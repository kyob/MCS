<?php

use App\Controllers\Api\Badge;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;

/**
 * Kontrakt GET/POST /api/badge (single + batch) — bez FeatureTestTrait (filtr tokenu osobno).
 *
 * @internal
 */
final class BadgeApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pluginy   = $this->db->prefixTable('mcs_pluginy');
        $incydenty = $this->db->prefixTable('mcs_incydenty');

        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                health_at TEXT NULL,
                health_ok BOOLEAN NULL,
                health_msg TEXT NULL
            )",
        );

        $this->db->query(
            "CREATE TABLE {$incydenty} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                klucz_dedupu VARCHAR(255) NOT NULL,
                podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip',
                podmiot VARCHAR(255) NOT NULL,
                pierwsze_widziane TEXT NOT NULL,
                ostatnio_widziane TEXT NOT NULL,
                waga VARCHAR(20) NOT NULL,
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $incydenty = $this->db->prefixTable('mcs_incydenty');
        $pluginy   = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");
        parent::tearDown();
    }

    public function testSingleIpZachowany(): void
    {
        $ip     = '198.51.100.10';
        $teraz  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-1 days')
            ->format('Y-m-d H:i:s');
        $this->wstawIncydent('shadowserver', 's1', $ip, 'high', $teraz);

        $odp = $this->wywolajBadge('GET', ['ip' => $ip]);
        $this->assertSame(200, $odp->getStatusCode());
        $json = json_decode((string) $odp->getBody(), true);

        $this->assertSame($ip, $json['ip']);
        $this->assertSame(1, $json['liczba']);
        $this->assertSame('high', $json['max_waga']);
        $this->assertSame(['shadowserver'], $json['zrodla']);
        $this->assertSame(['other'], $json['kategorie']);
        $this->assertSame(['Test s1'], $json['przyklady']);
        $this->assertArrayNotHasKey('wyniki', $json);
    }

    public function testBatchGetIps(): void
    {
        $ipA = '198.51.100.10';
        $ipB = '203.0.113.1';
        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-1 days')
            ->format('Y-m-d H:i:s');
        $this->wstawIncydent('shadowserver', 's1', $ipA, 'critical', $teraz);

        $odp = $this->wywolajBadge('GET', ['ips' => $ipA . ',' . $ipB]);
        $this->assertSame(200, $odp->getStatusCode());
        $json = json_decode((string) $odp->getBody(), true);

        $this->assertSame(2, $json['liczba_ip']);
        $this->assertCount(2, $json['wyniki']);
        $this->assertSame($ipA, $json['wyniki'][0]['ip']);
        $this->assertSame(1, $json['wyniki'][0]['liczba']);
        $this->assertSame('critical', $json['wyniki'][0]['max_waga']);
        $this->assertSame($ipB, $json['wyniki'][1]['ip']);
        $this->assertSame(0, $json['wyniki'][1]['liczba']);
        $this->assertStringContainsString('/ip/' . rawurlencode($ipA), $json['wyniki'][0]['url']);
    }

    public function testBatchPostJson(): void
    {
        $ipA = '198.51.100.10';
        $ipB = '203.0.113.1';

        $odp = $this->wywolajBadge('POST', [], ['ips' => [$ipA, $ipB, $ipA]]);
        $this->assertSame(200, $odp->getStatusCode());
        $json = json_decode((string) $odp->getBody(), true);

        $this->assertSame(2, $json['liczba_ip']);
        $this->assertSame([$ipA, $ipB], array_column($json['wyniki'], 'ip'));
    }

    public function testBatchLimitINieprawidloweIp(): void
    {
        $odp = $this->wywolajBadge('GET', ['ips' => 'nie-ip,198.51.100.10']);
        $this->assertSame(400, $odp->getStatusCode());

        $zaDuzo = [];
        for ($i = 0; $i < Badge::MAX_IPS_BATCH + 1; $i++) {
            $a        = intdiv($i, 250);
            $b        = $i % 250;
            $zaDuzo[] = "198.{$a}.{$b}.1";
        }

        $odp = $this->wywolajBadge('POST', [], ['ips' => $zaDuzo]);
        $this->assertSame(400, $odp->getStatusCode());
        $json = json_decode((string) $odp->getBody(), true);
        $this->assertStringContainsString((string) Badge::MAX_IPS_BATCH, $json['blad']);
    }

    public function testBrakParametrow400(): void
    {
        $odp = $this->wywolajBadge('GET', []);
        $this->assertSame(400, $odp->getStatusCode());
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed>|null $jsonBody
     */
    private function wywolajBadge(string $method, array $get, ?array $jsonBody = null)
    {
        $query = $get === [] ? '' : ('?' . http_build_query($get));
        $uri   = new URI('http://example.test/api/badge' . $query);

        $config  = config(App::class);
        $request = new IncomingRequest($config, $uri, null, new UserAgent());
        $request->setMethod($method);
        $request->setGlobal('get', $get);

        if ($jsonBody !== null) {
            $raw = json_encode($jsonBody, JSON_THROW_ON_ERROR);
            $request = new IncomingRequest($config, $uri, $raw, new UserAgent());
            $request->setMethod($method);
            $request->setHeader('Content-Type', 'application/json');
            $request->setGlobal('get', $get);
        }

        $controller = new Badge();
        $controller->initController($request, service('response'), service('logger'));

        return $controller->index();
    }

    private function wstawIncydent(
        string $zrodlo,
        string $klucz,
        string $ip,
        string $waga,
        string $ostatnio,
    ): void {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $klucz,
            'podmiot_typ'       => 'ip',
            'podmiot'           => $ip,
            'pierwsze_widziane' => $ostatnio,
            'ostatnio_widziane' => $ostatnio,
            'waga'              => $waga,
            'kategoria'         => 'other',
            'tytul'             => "Test {$klucz}",
            'ukryty_w_ui'       => 0,
        ]);
    }
}
