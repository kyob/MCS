<?php

use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\EnrichmentSpeedtestModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\speedtest\Parser;
use McsPluginy\speedtest\PortalClient;

/**
 * @internal
 */
final class SpeedtestSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = SUPPORTPATH . 'fixtures/speedtest_sample.csv';
        $tabela        = $this->db->prefixTable('mcs_enrichment_speedtest');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                test_id VARCHAR(64) PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                zaobserwowano_at TEXT NOT NULL,
                download_kbps INTEGER NULL,
                upload_kbps INTEGER NULL,
                latency_ms REAL NULL,
                isp VARCHAR(255) NULL,
                platform VARCHAR(128) NULL,
                server_name VARCHAR(255) NULL,
                raw_json TEXT NULL,
                zrodlo_danych VARCHAR(32) NOT NULL DEFAULT 'csv',
                created_at TEXT NOT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_enrichment_speedtest');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testSyncFixtureZapisujeTylkoNaszeIp(): void
    {
        $serwis = $this->serwis($this->fixture, '');

        $this->assertSame('fixture', $serwis->tryb());
        $wynik = $serwis->sync();
        $this->assertTrue($wynik['ok']);
        $this->assertSame('fixture', $wynik['tryb']);
        $this->assertSame(2, $wynik['zapisano']);
        $this->assertSame(1, $wynik['pominieto_obce']);
        $this->assertSame(1, $wynik['pliki']);

        $historia = $serwis->historiaDlaIp('91.231.80.10');
        $this->assertCount(1, $historia);
        $this->assertSame('st-1001', $historia[0]['test_id']);
        $this->assertSame(94000, (int) $historia[0]['download_kbps']);

        $this->assertSame([], $serwis->historiaDlaIp('8.8.8.8'));
        $this->assertCount(1, $serwis->historiaDlaIp('91.231.81.20'));
    }

    public function testUpsertPoTestIdAktualizujePomiar(): void
    {
        $serwis = $this->serwis('', '');
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "st-1,2026-09-16 10:00:00,91.231.80.10,1000,500,10\n";
        $this->assertTrue($serwis->importujTresc($csv, 'csv')['ok']);
        $this->assertSame(1000, (int) $serwis->historiaDlaIp('91.231.80.10')[0]['download_kbps']);

        $csv2 = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "st-1,2026-09-16 11:00:00,91.231.80.10,8000,500,10\n";
        $this->assertTrue($serwis->importujTresc($csv2, 'csv')['ok']);
        $this->assertCount(1, $serwis->historiaDlaIp('91.231.80.10'));
        $this->assertSame(8000, (int) $serwis->historiaDlaIp('91.231.80.10')[0]['download_kbps']);
    }

    public function testBrakKonfiguracji(): void
    {
        $serwis = $this->serwis('', '');
        $this->assertSame('brak_konfiguracji', $serwis->tryb());
        $wynik = $serwis->sync();
        $this->assertFalse($wynik['ok']);
        $this->assertStringContainsString('mcs.speedtest', (string) $wynik['blad']);
    }

    public function testCsvDirIOpisPolaczenia(): void
    {
        $dir = sys_get_temp_dir() . '/mcs-speedtest-' . uniqid('', true);
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/a.csv',
            "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "dir-1,2026-09-16 10:00:00,91.231.80.10,2000,500,4\n",
        );

        try {
            $serwis = $this->serwis('', $dir);
            $this->assertSame('csv', $serwis->tryb());
            $opis = $serwis->opisPolaczenia();
            $this->assertSame('katalog_csv', $opis['tryb_aktywny']);
            $this->assertStringContainsString('katalog CSV', $opis['skrot']);
            $json = json_encode($opis, JSON_UNESCAPED_SLASHES);
            $this->assertIsString($json);
            $this->assertDoesNotMatchRegularExpression('/tryb=fixture/', $json);

            $wynik = $serwis->sync();
            $this->assertTrue($wynik['ok']);
            $this->assertSame(1, $wynik['zapisano']);
            $this->assertSame('dir-1', $serwis->historiaDlaIp('91.231.80.10')[0]['test_id']);
        } finally {
            unlink($dir . '/a.csv');
            rmdir($dir);
        }
    }

    public function testHistoriaLimitIKolejnosc(): void
    {
        $serwis = $this->serwis('', '');
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "h-1,2026-09-16 10:00:00,91.231.80.10,1000,500,10\n"
            . "h-2,2026-09-16 12:00:00,91.231.80.10,2000,500,10\n"
            . "h-3,2026-09-16 11:00:00,91.231.80.10,3000,500,10\n";
        $serwis->importujTresc($csv, 'csv');

        $dwa = $serwis->historiaDlaIp('91.231.80.10', 2);
        $this->assertCount(2, $dwa);
        $this->assertSame('h-2', $dwa[0]['test_id']);
        $this->assertSame('h-3', $dwa[1]['test_id']);
    }

    public function testCidrZIpDlaIpv4IIpv6(): void
    {
        $this->assertSame('91.231.80.0/24', SpeedtestSerwis::cidrZIp('91.231.80.10'));
        $this->assertSame('91.231.80.0/24', SpeedtestSerwis::cidrZIp('91.231.80.0'));
        $this->assertSame('2001:db8:abcd::/48', SpeedtestSerwis::cidrZIp('2001:db8:abcd::1'));
        $this->assertSame('2a0f:1700:2::/48', SpeedtestSerwis::cidrZIp('2a0f:1700:2::'));
        $this->assertNull(SpeedtestSerwis::cidrZIp('not-an-ip'));
        $this->assertNull(SpeedtestSerwis::cidrZIp(''));
    }

    public function testAgregatyHistoriaDlaCidrIDzienne(): void
    {
        $serwis = $this->serwis('', '');
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "a-1,2026-09-16 10:00:00,91.231.80.0,1000,400,10\n"
            . "a-2,2026-09-16 12:00:00,91.231.80.0,3000,600,20\n"
            . "a-3,2026-09-15 09:00:00,91.231.81.0,5000,800,30\n"
            . "a-4,2026-09-10 08:00:00,91.231.80.0,9000,100,5\n";
        $serwis->importujTresc($csv, 'csv');

        $historia = $serwis->historiaDlaCidr('91.231.80.0/24', 10);
        $this->assertCount(3, $historia);
        $this->assertSame('a-2', $historia[0]['test_id']);

        $hostHistoria = $serwis->historiaDlaCidrZIp('91.231.80.42', 10);
        $this->assertCount(3, $hostHistoria);

        $agregaty = $serwis->agregaty(0);
        $this->assertCount(2, $agregaty);
        $this->assertSame('91.231.80.0/24', $agregaty[0]['cidr']);
        $this->assertSame(3, (int) $agregaty[0]['liczba_testow']);
        $this->assertSame(4333, (int) round((float) $agregaty[0]['avg_download_kbps']));
        $this->assertSame(3000, (int) $agregaty[0]['p50_download_kbps']);
        $this->assertSame(367, (int) round((float) $agregaty[0]['avg_upload_kbps']));
        $this->assertSame(400, (int) $agregaty[0]['p50_upload_kbps']);
        $this->assertEqualsWithDelta(11.67, (float) $agregaty[0]['avg_latency_ms'], 0.1);
        $this->assertSame(10.0, (float) $agregaty[0]['p50_latency_ms']);
        $this->assertStringStartsWith('2026-09-16', (string) $agregaty[0]['ostatni_test_at']);

        $kpi = $serwis->kpi(0);
        $this->assertSame(4, (int) $kpi['liczba_testow']);
        $this->assertSame(2, (int) $kpi['liczba_cidr']);
        $this->assertSame(4500, (int) round((float) $kpi['avg_download_kbps']));
        $this->assertSame(3000, (int) $kpi['p50_download_kbps']);

        $dzienne = $serwis->agregatyDzienne(0);
        $this->assertGreaterThanOrEqual(2, count($dzienne));
        $this->assertSame('2026-09-16', $dzienne[0]['dzien']);
        $this->assertSame(2, (int) $dzienne[0]['liczba_testow']);
        $this->assertSame(2000, (int) round((float) $dzienne[0]['avg_download_kbps']));
    }

    public function testAgregatyOknoDniFiltrujeStare(): void
    {
        $serwis = $this->serwis('', '');
        $stary  = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $swiezy = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "old-1,{$stary},91.231.80.0,1000,400,10\n"
            . "new-1,{$swiezy},91.231.80.0,8000,400,10\n";
        $serwis->importujTresc($csv, 'csv');

        $kpi7 = $serwis->kpi(7);
        $this->assertSame(1, (int) $kpi7['liczba_testow']);
        $this->assertSame(8000, (int) round((float) $kpi7['avg_download_kbps']));

        $kpiAll = $serwis->kpi(0);
        $this->assertSame(2, (int) $kpiAll['liczba_testow']);
    }

    public function testParsujFiltryDomyslneINormalizacja(): void
    {
        $d = SpeedtestSerwis::parsujFiltry([]);
        $this->assertSame(7, $d['okno']);
        $this->assertSame(500, $d['min_download_mbps']);
        $this->assertSame(500, $d['min_upload_mbps']);
        $this->assertSame('', $d['cidr']);
        $this->assertSame('download', $d['sort']);
        $this->assertSame(20, $d['top_n']);
        $this->assertTrue($d['tylko_as']);
        $this->assertFalse($d['wszystkie']);

        $f = SpeedtestSerwis::parsujFiltry([
            'okno'              => '30',
            'min_download_mbps' => '900',
            'min_upload_mbps'   => '0',
            'cidr'              => ' 91.231.80 ',
            'sort'              => 'upload',
            'top_n'             => '5',
            'tylko_as'          => '0',
            'wszystkie'         => '1',
        ]);
        $this->assertSame(30, $f['okno']);
        $this->assertSame(900, $f['min_download_mbps']);
        $this->assertSame(0, $f['min_upload_mbps']);
        $this->assertSame('91.231.80', $f['cidr']);
        $this->assertSame('upload', $f['sort']);
        $this->assertSame(5, $f['top_n']);
        $this->assertFalse($f['tylko_as']);
        $this->assertTrue($f['wszystkie']);

        $zle = SpeedtestSerwis::parsujFiltry(['okno' => '99', 'sort' => 'hack', 'min_download_mbps' => '-10']);
        $this->assertSame(7, $zle['okno']);
        $this->assertSame('download', $zle['sort']);
        $this->assertSame(0, $zle['min_download_mbps']);

        // Oba progi 0 ⇒ tryb „wszystkie”; literówka mbbs → mbps
        $zero = SpeedtestSerwis::parsujFiltry([
            'min_download_mbps' => '0',
            'min_upload_mbps'   => '0',
        ]);
        $this->assertTrue($zero['wszystkie']);
        $this->assertSame(0, $zero['min_download_mbps']);
        $this->assertSame(0, $zero['min_upload_mbps']);

        $typo = SpeedtestSerwis::parsujFiltry([
            'min_download_mbps' => '400',
            'min_upload_mbbs'   => '300',
        ]);
        $this->assertSame(300, $typo['min_upload_mbps']);
    }

    public function testAnomalieWszystkiePomiaryZachowujaKpiProgow(): void
    {
        $serwis = $this->serwis('', '');
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "sat-1,2026-09-05 12:00:00,91.231.80.0,915930,100000,12\n"
            . "low-1,2026-09-05 13:00:00,91.231.80.0,5000,2000,20\n"
            . "sat-2,2026-09-06 10:00:00,91.231.81.0,800000,600000,15\n";
        $serwis->importujTresc($csv, 'csv');

        $filtry = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'wszystkie'         => '1',
            'sort'              => 'download',
            'top_n'             => '50',
        ]);
        $this->assertTrue($filtry['wszystkie']);
        $anom = $serwis->anomalie($filtry);

        $this->assertSame(2, (int) $anom['liczba_powyzej']);
        $this->assertSame(3, (int) $anom['liczba_pasujacych']);
        $this->assertCount(3, $anom['top_testy']);
        $ids = array_column($anom['top_testy'], 'test_id');
        $this->assertContains('low-1', $ids);
        $this->assertContains('sat-1', $ids);
    }

    public function testAnomalieTylkoAsFiltrujeObceIp(): void
    {
        $serwis = $this->serwis('', '');
        $model  = model(EnrichmentSpeedtestModel::class);
        $model->upsertPoTestId('obce-1', [
            'ip'               => '8.8.8.0',
            'zaobserwowano_at' => '2026-09-05 14:00:00',
            'download_kbps'    => 900000,
            'upload_kbps'      => 900000,
            'latency_ms'       => 5.0,
            'isp'              => null,
            'platform'         => null,
            'server_name'      => null,
            'raw_json'         => null,
            'zrodlo_danych'    => 'csv',
        ]);
        $model->upsertPoTestId('nasze-1', [
            'ip'               => '91.231.80.0',
            'zaobserwowano_at' => '2026-09-05 15:00:00',
            'download_kbps'    => 700000,
            'upload_kbps'      => 100000,
            'latency_ms'       => 8.0,
            'isp'              => null,
            'platform'         => null,
            'server_name'      => null,
            'raw_json'         => null,
            'zrodlo_danych'    => 'csv',
        ]);

        $tylkoAs = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'tylko_as'          => '1',
            'wszystkie'         => '1',
            'top_n'             => '20',
        ]);
        $anomAs = $serwis->anomalie($tylkoAs);
        $this->assertSame(1, (int) $anomAs['liczba_pasujacych']);
        $this->assertSame('nasze-1', $anomAs['top_testy'][0]['test_id']);
        $this->assertSame(1, $serwis->kpi(0, '', true)['liczba_testow']);

        $wszystkieIp = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'tylko_as'          => '0',
            'wszystkie'         => '1',
            'top_n'             => '20',
        ]);
        $anomAll = $serwis->anomalie($wszystkieIp);
        $this->assertSame(2, (int) $anomAll['liczba_pasujacych']);
        $this->assertSame(2, $serwis->kpi(0, '', false)['liczba_testow']);
    }

    public function testAnomalieProgiTopTestyICidrPeaki(): void
    {
        $serwis = $this->serwis('', '');
        // 900 Mbps down ≈ 900000 kbps; 4.3 Gbps up ≈ 4300000 kbps — jak spike LibreNMS
        $csv = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "sat-1,2026-09-05 12:00:00,91.231.80.0,915930,100000,12\n"
            . "sat-2,2026-09-05 12:05:00,91.231.80.0,50000,4330000,8\n"
            . "low-1,2026-09-05 13:00:00,91.231.80.0,5000,2000,20\n"
            . "sat-3,2026-09-06 10:00:00,91.231.81.0,800000,600000,15\n"
            . "other-1,2026-09-06 11:00:00,91.231.90.0,10000,10000,30\n";
        $serwis->importujTresc($csv, 'csv');

        $filtry = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'cidr'              => '',
            'sort'              => 'download',
            'top_n'             => '10',
        ]);
        $anom = $serwis->anomalie($filtry);

        $this->assertSame(3, (int) $anom['liczba_powyzej']);
        $this->assertCount(3, $anom['top_testy']);
        $this->assertSame('sat-1', $anom['top_testy'][0]['test_id']);
        $this->assertSame('91.231.80.0/24', $anom['top_testy'][0]['cidr']);
        $this->assertSame(915930, (int) $anom['top_testy'][0]['download_kbps']);

        $filtryUp = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '0',
            'min_upload_mbps'   => '1000',
            'sort'              => 'upload',
            'top_n'             => '5',
        ]);
        $anomUp = $serwis->anomalie($filtryUp);
        $this->assertSame(1, (int) $anomUp['liczba_powyzej']);
        $this->assertSame('sat-2', $anomUp['top_testy'][0]['test_id']);

        $filtryCidr = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'cidr'              => '91.231.80',
            'sort'              => 'download',
        ]);
        $anomCidr = $serwis->anomalie($filtryCidr);
        $this->assertSame(2, (int) $anomCidr['liczba_powyzej']);

        $this->assertNotEmpty($anom['cidr_peaki']);
        $peak80 = null;
        foreach ($anom['cidr_peaki'] as $p) {
            if ($p['cidr'] === '91.231.80.0/24') {
                $peak80 = $p;
                break;
            }
        }
        $this->assertNotNull($peak80);
        $this->assertSame(915930, (int) $peak80['max_download_kbps']);
        $this->assertSame(4330000, (int) $peak80['max_upload_kbps']);
        $this->assertGreaterThan(1.0, (float) $peak80['peak_download_ratio']);
    }

    public function testAgregatyMaxSortICidrContains(): void
    {
        $serwis = $this->serwis('', '');
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "m-1,2026-09-16 10:00:00,91.231.80.0,1000,400,10\n"
            . "m-2,2026-09-16 12:00:00,91.231.80.0,900000,600,20\n"
            . "m-3,2026-09-15 09:00:00,91.231.81.0,5000,800000,30\n";
        $serwis->importujTresc($csv, 'csv');

        $ag = $serwis->agregaty(0, '', 'download');
        $this->assertSame('91.231.80.0/24', $ag[0]['cidr']);
        $this->assertSame(900000, (int) $ag[0]['max_download_kbps']);
        $this->assertSame(600, (int) $ag[0]['max_upload_kbps']);
        $this->assertEqualsWithDelta(2.0, (float) $ag[0]['peak_download_ratio'], 0.2);

        $agUp = $serwis->agregaty(0, '', 'upload');
        $this->assertSame('91.231.81.0/24', $agUp[0]['cidr']);
        $this->assertSame(800000, (int) $agUp[0]['max_upload_kbps']);

        $agCidr = $serwis->agregaty(0, '91.231.81', 'testy');
        $this->assertCount(1, $agCidr);
        $this->assertSame('91.231.81.0/24', $agCidr[0]['cidr']);
    }

    public function testPortalPriorytetNadCsvIOpisMaskujeHaslo(): void
    {
        $dir = sys_get_temp_dir() . '/mcs-speedtest-' . uniqid('', true);
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/a.csv',
            "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "dir-1,2026-09-16 10:00:00,91.231.80.10,2000,500,4\n",
        );

        $portalCsv = "testId,ipAddress,resultDate,download (Kbps),upload (Kbps),ping (ms),ispName,userAgent,serverName\n"
            . "portal-1,91.231.80.10,2026-09-16T10:00:00.000Z,7777,500,4,Alfa,Chrome,Knurów\n";

        $portal = new PortalClient('mcs@example.test', 'sekret-haslo', null, static function (string $url) use ($portalCsv) {
            if (str_contains($url, 'oauth/token')) {
                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'body'      => '{"access_token":"tok","token_type":"Bearer","expires_in":3600}',
                    'error'     => '',
                    'headers'   => [],
                ];
            }

            return [
                'ok'        => true,
                'http_code' => 200,
                'body'      => $portalCsv,
                'error'     => '',
                'headers'   => ['content-type' => 'text/csv'],
            ];
        });

        try {
            $serwis = $this->serwis('', $dir, $portal);
            $this->assertSame('portal', $serwis->tryb());

            $opis = $serwis->opisPolaczenia();
            $this->assertSame('portal', $opis['tryb_aktywny']);
            $this->assertStringContainsString('mcs@example.test', $opis['skrot']);
            $json = json_encode($opis, JSON_UNESCAPED_SLASHES);
            $this->assertIsString($json);
            $this->assertStringContainsString('ustawiony', $json);
            $this->assertStringNotContainsString('sekret-haslo', $json);

            $checksum = $serwis->checksumZrodel();
            $this->assertNotNull($checksum);
            $this->assertSame(hash('sha256', $portalCsv), $checksum);

            $wynik = $serwis->sync();
            $this->assertTrue($wynik['ok']);
            $this->assertSame('portal', $wynik['tryb']);
            $this->assertSame(1, $wynik['zapisano']);
            $this->assertSame('portal-1', $serwis->historiaDlaIp('91.231.80.10')[0]['test_id']);
            $this->assertSame(7777, (int) $serwis->historiaDlaIp('91.231.80.10')[0]['download_kbps']);
        } finally {
            unlink($dir . '/a.csv');
            rmdir($dir);
        }
    }

    private function serwis(string $fixture, string $csvDir, ?PortalClient $portal = null): SpeedtestSerwis
    {
        $matcher = new As198050Matcher(new CidrMatcher(), null);

        return new SpeedtestSerwis(
            new Parser(),
            model(EnrichmentSpeedtestModel::class),
            $matcher,
            $fixture,
            $csvDir,
            $portal ?? new PortalClient('', ''),
        );
    }
}
