<?php

use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\Siec\AsMatcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\EnrichmentSpeedtestModel;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\speedtest\Parser;
use McsPluginy\speedtest\PortalClient;

/**
 * Issue #448 + #450 (speedtest): agregaty/KPI/anomalie w SQL (bez limitu 10 000 wierszy
 * i bez agregacji w PHP), batch upsert ON CONFLICT przy imporcie, dlaPrefiksu24 jako zakres
 * na indeksie ip (zamiast LIKE 'prefiks%').
 *
 * @internal
 */
final class SpeedtestDbBottlenecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_enrichment_speedtest');
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
        $this->db->query("CREATE INDEX {$tabela}_ip_idx ON {$tabela} (ip)");
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_enrichment_speedtest'));
        parent::tearDown();
    }

    public function testDlaPrefiksu24UzywaZakresuZamiastLike(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        $model->upsertWiele([
            ['test_id' => 'p-1', 'ip' => '91.231.80.9', 'zaobserwowano_at' => '2026-09-16 10:00:00', 'download_kbps' => 1000],
            ['test_id' => 'p-2', 'ip' => '91.231.80.10', 'zaobserwowano_at' => '2026-09-16 11:00:00', 'download_kbps' => 2000],
            ['test_id' => 'p-3', 'ip' => '91.231.80.255', 'zaobserwowano_at' => '2026-09-16 12:00:00', 'download_kbps' => 3000],
            ['test_id' => 'p-4', 'ip' => '91.231.81.5', 'zaobserwowano_at' => '2026-09-16 13:00:00', 'download_kbps' => 4000],
            ['test_id' => 'p-5', 'ip' => '91.231.8.1', 'zaobserwowano_at' => '2026-09-16 14:00:00', 'download_kbps' => 5000],
            ['test_id' => 'p-6', 'ip' => '91.231.800.1', 'zaobserwowano_at' => '2026-09-16 15:00:00', 'download_kbps' => 6000],
        ]);

        $lista = $model->dlaPrefiksu24('91.231.80.', 50);
        $sql   = $this->db->getLastQuery()->getQuery();

        $this->assertStringContainsString('>=', $sql);
        $this->assertStringContainsString('<', $sql);
        $this->assertStringNotContainsStringIgnoringCase('LIKE', $sql, 'zakres zamiast LIKE (issue #450)');
        $this->assertSame(['p-3', 'p-2', 'p-1'], array_column($lista, 'test_id'), 'tylko /24, po czasie DESC; „91.231.800.1” i „91.231.8.1” wykluczone');

        $this->assertSame([], $model->dlaPrefiksu24('nie-ip.', 50));
        $this->assertSame([], $model->dlaPrefiksu24('', 50));
    }

    public function testDlaCidrIILiczbaDlaCidrDlaIpv6(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        $model->upsertWiele([
            ['test_id' => 'v6-1', 'ip' => '2a0f:1700:2::1', 'zaobserwowano_at' => '2026-09-16 10:00:00'],
            ['test_id' => 'v6-2', 'ip' => '2a0f:1700:2::ffff', 'zaobserwowano_at' => '2026-09-16 11:00:00'],
            ['test_id' => 'v6-3', 'ip' => '2a0f:1700:3::1', 'zaobserwowano_at' => '2026-09-16 12:00:00'],
            ['test_id' => 'v4-1', 'ip' => '91.231.80.10', 'zaobserwowano_at' => '2026-09-16 13:00:00'],
        ]);

        $lista = $model->dlaCidr('2a0f:1700:2::/48', 50);
        $this->assertSame(['v6-2', 'v6-1'], array_column($lista, 'test_id'));
        $this->assertSame(2, $model->liczbaDlaCidr('2a0f:1700:2::/48'));
        $this->assertSame(1, $model->liczbaDlaCidr('91.231.80.0/24'), 'dla /24 też działa przez wyrażenie CIDR');
        $this->assertSame(0, $model->liczbaDlaCidr(''));
    }

    public function testAgregatySqlRowneReferencyjnymPhp(): void
    {
        $serwis = $this->serwis();
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "a-1,2026-09-16 10:00:00,91.231.80.10,1000,400,10\n"
            . "a-2,2026-09-16 12:00:00,91.231.80.20,3000,600,20\n"
            . "a-3,2026-09-15 09:00:00,91.231.81.30,5000,800,30\n"
            . "a-4,2026-09-10 08:00:00,91.231.80.30,9000,100,5\n";
        $serwis->importujTresc($csv, 'csv');

        $ag = $serwis->agregaty(0);
        $this->assertCount(2, $ag);
        $g80 = null;
        foreach ($ag as $g) {
            if ($g['cidr'] === '91.231.80.0/24') {
                $g80 = $g;
            }
        }
        $this->assertNotNull($g80);
        $this->assertSame(3, $g80['liczba_testow']);
        $this->assertSame(4333, (int) round($g80['avg_download_kbps']));
        $this->assertSame(3000, $g80['p50_download_kbps'], 'dolna mediana (jak medianaInt): element (n+1)/2');
        $this->assertSame(400, $g80['p50_upload_kbps']);
        $this->assertEqualsWithDelta(11.67, $g80['avg_latency_ms'], 0.1);
        $this->assertSame(10.0, $g80['p50_latency_ms']);
        $this->assertSame(9000, $g80['max_download_kbps']);

        $kpi = $serwis->kpi(0, '', false);
        $this->assertSame(4, $kpi['liczba_testow']);
        $this->assertSame(2, $kpi['liczba_cidr']);
        $this->assertSame(3000, $kpi['p50_download_kbps'], 'KPI p50 dla [1000,3000,5000,9000] = 3000 (element 2., nie interpolacja)');

        $dzienne = $serwis->agregatyDzienne(0, '', false);
        $this->assertSame('2026-09-16', $dzienne[0]['dzien']);
        $this->assertSame(2, $dzienne[0]['liczba_testow']);
        $this->assertSame(2000, (int) round($dzienne[0]['avg_download_kbps']));
    }

    public function testAgregatySqlNullIIParzystaLiczba(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        $model->upsertWiele([
            ['test_id' => 'n-1', 'ip' => '91.231.80.10', 'zaobserwowano_at' => '2026-09-16 10:00:00', 'download_kbps' => 1000, 'upload_kbps' => 400],
            ['test_id' => 'n-2', 'ip' => '91.231.80.11', 'zaobserwowano_at' => '2026-09-16 11:00:00', 'download_kbps' => 3000, 'upload_kbps' => null],
            ['test_id' => 'n-3', 'ip' => '91.231.80.12', 'zaobserwowano_at' => '2026-09-16 12:00:00', 'download_kbps' => null, 'upload_kbps' => 600],
        ]);

        $ag = $model->agregatyPerCidr(null, null, '');
        $this->assertCount(1, $ag);
        $this->assertSame(3, (int) $ag[0]['liczba_testow'], 'liczba_testow liczy też wiersze z NULL metryką');
        $this->assertSame(2000.0, (float) $ag[0]['avg_download_kbps'], 'AVG ignoruje NULL');
        $this->assertSame(1000, (int) $ag[0]['p50_download_kbps'], 'p50 dla [1000,3000] = 1000 (dolna mediana, nie 2000)');
        $this->assertSame(500.0, (float) $ag[0]['avg_upload_kbps']);
        $this->assertSame(400, (int) $ag[0]['p50_upload_kbps'], 'p50 upload dla [400,600] = 400');
        $this->assertSame(600, (int) $ag[0]['max_upload_kbps']);
    }

    public function testKpiNieJestObcinaneDo10000Wierszy(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        $wiersze = [];
        for ($i = 0; $i < 10500; $i++) {
            $wiersze[] = [
                'test_id'          => 'big-' . $i,
                'ip'               => '91.231.80.' . ($i % 250),
                'zaobserwowano_at' => '2026-09-16 10:00:00',
                'download_kbps'    => 1000 + ($i % 100),
                'upload_kbps'      => 500,
                'latency_ms'       => 10.0,
            ];
        }
        $model->upsertWiele($wiersze);

        $kpi = $model->kpiWOkie(null, null, '');
        $this->assertSame(10500, (int) $kpi['liczba_testow'], 'wcześniej wszystkieOd() cicho ucinało do 10 000');
        $this->assertSame(1, (int) $kpi['liczba_cidr']);

        $ag = $model->agregatyPerCidr(null, null, '');
        $this->assertSame(10500, (int) $ag[0]['liczba_testow']);
    }

    public function testImportCsv600WierszyToDwaBatchUpserty(): void
    {
        $serwis = $this->serwis();
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n";
        for ($i = 0; $i < 600; $i++) {
            $csv .= 'b-' . $i . ",2026-09-16 10:00:00,91.231.80." . ($i % 250) . ',' . (1000 + $i) . ",500,10\n";
        }

        $inserty = 0;
        Events::on('DBQuery', static function ($q) use (&$inserty): void {
            $sql = $q->getQuery();
            if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'mcs_enrichment_speedtest') && str_contains($sql, 'ON CONFLICT')) {
                $inserty++;
            }
        });
        try {
            $wynik = $serwis->importujTresc($csv, 'csv');
        } finally {
            Events::removeAllListeners('DBQuery');
        }

        $this->assertSame(600, $wynik['zapisano']);
        $this->assertSame(2, $inserty, 'batch upsert partiami po 500: ceil(600/500) = 2 INSERTy zamiast 600');
        $this->assertSame(600, $this->db->table('mcs_enrichment_speedtest')->countAllResults());
    }

    public function testUpsertWieleDuplikatTestIdJedenWierszBezBledu(): void
    {
        $model = model(EnrichmentSpeedtestModel::class);
        // duplikat test_id w jednej partii — bez dedupu INSERT … ON CONFLICT rzuciłby „affect row twice”
        $model->upsertWiele([
            ['test_id' => 'd-1', 'ip' => '91.231.80.10', 'zaobserwowano_at' => '2026-09-16 10:00:00', 'download_kbps' => 1000],
            ['test_id' => 'd-1', 'ip' => '91.231.80.10', 'zaobserwowano_at' => '2026-09-16 11:00:00', 'download_kbps' => 8000],
        ]);

        $this->assertSame(1, $this->db->table('mcs_enrichment_speedtest')->countAllResults());
        $this->assertSame(8000, (int) $this->db->table('mcs_enrichment_speedtest')->where('test_id', 'd-1')->get()->getRowArray()['download_kbps'], 'ostatni wygrywa');
    }

    public function testAnomalieSqlZgadzaSieZProgiemIWycinaTopN(): void
    {
        $serwis = $this->serwis();
        $csv    = "Result ID,Date,IP Address,Download,Upload,Latency\n"
            . "s-1,2026-09-05 12:00:00,91.231.80.0,915930,100000,12\n"
            . "s-2,2026-09-05 12:05:00,91.231.80.0,50000,4330000,8\n"
            . "l-1,2026-09-05 13:00:00,91.231.80.0,5000,2000,20\n";
        $serwis->importujTresc($csv, 'csv');

        $filtry = SpeedtestSerwis::parsujFiltry([
            'okno'              => '0',
            'min_download_mbps' => '500',
            'min_upload_mbps'   => '500',
            'sort'              => 'download',
            'top_n'             => '10',
        ]);
        $anom = $serwis->anomalie($filtry);

        $this->assertSame(2, $anom['liczba_powyzej']);
        $this->assertSame(2, $anom['liczba_pasujacych']);
        $this->assertSame('s-1', $anom['top_testy'][0]['test_id']);
        $this->assertSame('91.231.80.0/24', $anom['top_testy'][0]['cidr']);
        $this->assertCount(1, $anom['cidr_peaki']);
        $this->assertSame(4330000, $anom['cidr_peaki'][0]['max_upload_kbps']);
        $this->assertSame(2, $anom['cidr_peaki'][0]['liczba_powyzej']);
    }

    /**
     * Issue #468: na Postgre agregatyDzienneSql nie może porównywać date do ''
     * (ERROR: invalid input syntax for type date). Test z mockiem połączenia PG.
     */
    public function testAgregatyDzienneNaPostgreBezPorownaniaDateDoPustegoStringa(): void
    {
        $model = new EnrichmentSpeedtestModel();

        $uchwycone = [];
        $wynik = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $wynik->method('getResultArray')->willReturn([]);
        $db = $this->createMock(\CodeIgniter\Database\BaseConnection::class);
        $db->method('getPlatform')->willReturn('Postgre');
        $db->method('prefixTable')->willReturnCallback(static fn (string $t): string => 'mcs_' . $t);
        $db->method('escape')->willReturnCallback(static fn ($v): string => "'" . $v . "'");
        $db->method('query')->willReturnCallback(static function (string $sql) use (&$uchwycone, $wynik) {
            $uchwycone[] = $sql;

            return $wynik;
        });

        $ref = new \ReflectionProperty($model, 'db');
        $ref->setAccessible(true);
        $ref->setValue($model, $db);

        $model->agregatyDzienneSql('2026-11-17 00:00:00', null, '');

        $this->assertNotEmpty($uchwycone);
        $this->assertStringContainsString('dzien IS NOT NULL', $uchwycone[0]);
        $this->assertStringNotContainsString("dzien <> ''", $uchwycone[0], 'date <> \'\' rzuca błąd na Postgre');
    }

    private function serwis(): SpeedtestSerwis
    {
        return new SpeedtestSerwis(
            new Parser(),
            model(EnrichmentSpeedtestModel::class),
            new AsMatcher(new CidrMatcher(), null),
            '',
            '',
            new PortalClient('', ''),
        );
    }
}
