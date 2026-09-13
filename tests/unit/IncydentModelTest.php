<?php

use App\Models\IncydentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IncydentModelTest extends CIUnitTestCase
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

        $this->db->table('mcs_pluginy')->insertBatch([
            [
                'zrodlo'            => 'shadowserver',
                'nazwa'             => 'Shadowserver',
                'import_wstrzymany' => 1,
                'ukryty_w_ui'       => 0,
            ],
            [
                'zrodlo'            => 'play',
                'nazwa'             => 'Play',
                'import_wstrzymany' => 1,
                'ukryty_w_ui'       => 0,
            ],
            [
                'zrodlo'            => 'snds',
                'nazwa'             => 'SNDS',
                'import_wstrzymany' => 1,
                'ukryty_w_ui'       => 1,
            ],
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

    public function testPustaListaDlaNieznanegoIp(): void
    {
        $lista = model(IncydentModel::class)->dlaPodmiotuIp('198.51.100.10');

        $this->assertSame([], $lista);
    }

    public function testZwracaIncydentyIpBezUkrytychZrodel(): void
    {
        $ip = '198.51.100.10';
        $this->wstawIncydent('shadowserver', 'a1', $ip, 'high', '2026-09-10 12:00:00');
        $this->wstawIncydent('play', 'b1', $ip, 'low', '2026-09-11 08:00:00');
        $this->wstawIncydent('snds', 'c1', $ip, 'critical', '2026-09-11 09:00:00'); // plugin ukryty
        $this->wstawIncydent('shadowserver', 'd1', '203.0.113.1', 'medium', '2026-09-11 10:00:00');
        $this->wstawIncydent('play', 'e1', $ip, 'medium', '2026-09-09 10:00:00', true); // ukryty_w_ui incydentu

        $lista = model(IncydentModel::class)->dlaPodmiotuIp($ip);

        $this->assertCount(2, $lista);
        $this->assertSame(['shadowserver', 'play'], array_column($lista, 'zrodlo'));
    }

    public function testFiltrZrodlo(): void
    {
        $ip = '198.51.100.10';
        $this->wstawIncydent('shadowserver', 'a1', $ip, 'high', '2026-09-10 12:00:00');
        $this->wstawIncydent('play', 'b1', $ip, 'low', '2026-09-11 08:00:00');

        $lista = model(IncydentModel::class)->dlaPodmiotuIp($ip, 'play');

        $this->assertCount(1, $lista);
        $this->assertSame('play', $lista[0]['zrodlo']);
    }

    public function testSortowanieWagaPotemCzas(): void
    {
        $ip = '198.51.100.10';
        $this->wstawIncydent('shadowserver', 'low-old', $ip, 'low', '2026-09-01 00:00:00');
        $this->wstawIncydent('shadowserver', 'high-new', $ip, 'high', '2026-09-10 00:00:00');
        $this->wstawIncydent('play', 'high-old', $ip, 'high', '2026-09-05 00:00:00');
        $this->wstawIncydent('play', 'critical', $ip, 'critical', '2026-09-02 00:00:00');

        $lista = model(IncydentModel::class)->dlaPodmiotuIp($ip);
        $klucze = array_column($lista, 'klucz_dedupu');

        $this->assertSame(['critical', 'high-new', 'high-old', 'low-old'], $klucze);
    }

    public function testAgregatBadgePustyDlaNieznanegoIp(): void
    {
        $agregat = model(IncydentModel::class)->agregatBadgeDlaIp('198.51.100.10');

        $this->assertSame(0, $agregat['liczba']);
        $this->assertNull($agregat['max_waga']);
        $this->assertSame(0, $agregat['liczba_zrodel']);
        $this->assertSame([], $agregat['zrodla']);
        $this->assertSame([], $agregat['kategorie']);
        $this->assertSame([], $agregat['przyklady']);
        $this->assertSame(7, $agregat['okno_dni']);
    }

    public function testAgregatBadgeLiczyOknoIUkrycia(): void
    {
        $ip     = '198.51.100.10';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-2 days')->format('Y-m-d H:i:s');
        $poza   = $teraz->modify('-10 days')->format('Y-m-d H:i:s');

        $this->wstawIncydent('shadowserver', 'a1', $ip, 'high', $wOknie, false, 'botnet', 'SS bot');
        $this->wstawIncydent('play', 'b1', $ip, 'low', $wOknie, false, 'spam', 'Play low');
        $this->wstawIncydent('play', 'b2', $ip, 'critical', $wOknie, false, 'botnet', 'Play crit'); // druga w tym samym źródle
        $this->wstawIncydent('snds', 'c1', $ip, 'critical', $wOknie); // plugin ukryty
        $this->wstawIncydent('shadowserver', 'd1', $ip, 'medium', $poza); // poza oknem 7 dni
        $this->wstawIncydent('play', 'e1', $ip, 'medium', $wOknie, true); // ukryty_w_ui
        $this->wstawIncydent('shadowserver', 'f1', '203.0.113.1', 'critical', $wOknie);

        $agregat = model(IncydentModel::class)->agregatBadgeDlaIp($ip);

        $this->assertSame(3, $agregat['liczba']);
        $this->assertSame('critical', $agregat['max_waga']);
        $this->assertSame(2, $agregat['liczba_zrodel']);
        $this->assertSame(['play', 'shadowserver'], $agregat['zrodla']);
        $this->assertSame(['botnet', 'spam'], $agregat['kategorie']);
        $this->assertSame(['Play crit', 'SS bot', 'Play low'], $agregat['przyklady']);
        $this->assertSame(7, $agregat['okno_dni']);
    }

    public function testAgregatBadgeBatchWhereIn(): void
    {
        $ipA    = '198.51.100.10';
        $ipB    = '203.0.113.1';
        $ipC    = '203.0.113.50';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-1 days')->format('Y-m-d H:i:s');
        $poza   = $teraz->modify('-12 days')->format('Y-m-d H:i:s');

        $this->wstawIncydent('shadowserver', 'a1', $ipA, 'high', $wOknie);
        $this->wstawIncydent('play', 'a2', $ipA, 'low', $wOknie);
        $this->wstawIncydent('snds', 'a3', $ipA, 'critical', $wOknie); // ukryte źródło
        $this->wstawIncydent('play', 'b1', $ipB, 'medium', $wOknie);
        $this->wstawIncydent('shadowserver', 'c-old', $ipC, 'critical', $poza);

        $mapa = model(IncydentModel::class)->agregatBadgeDlaIpLista([$ipA, $ipB, $ipC, $ipA]);

        $this->assertSame([$ipA, $ipB, $ipC], array_keys($mapa));
        $this->assertSame(2, $mapa[$ipA]['liczba']);
        $this->assertSame('high', $mapa[$ipA]['max_waga']);
        $this->assertSame(2, $mapa[$ipA]['liczba_zrodel']);
        $this->assertSame(1, $mapa[$ipB]['liczba']);
        $this->assertSame('medium', $mapa[$ipB]['max_waga']);
        $this->assertSame(0, $mapa[$ipC]['liczba']);
        $this->assertNull($mapa[$ipC]['max_waga']);
        $this->assertSame(0, $mapa[$ipC]['liczba_zrodel']);
        $this->assertSame([], $mapa[$ipC]['zrodla']);
        $this->assertSame([], $mapa[$ipC]['kategorie']);
        $this->assertSame([], $mapa[$ipC]['przyklady']);
    }

    public function testKpiITopWieloZrodlowe(): void
    {
        $ipA    = '198.51.100.10';
        $ipB    = '203.0.113.1';
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->modify('-1 days')->format('Y-m-d H:i:s');
        $poza   = $teraz->modify('-20 days')->format('Y-m-d H:i:s');

        $this->wstawIncydent('shadowserver', 'a1', $ipA, 'high', $wOknie, false, 'exposure');
        $this->wstawIncydent('play', 'a2', $ipA, 'critical', $wOknie, false, 'abuse');
        $this->wstawIncydent('snds', 'a3', $ipA, 'critical', $wOknie); // ukryte źródło
        $this->wstawIncydent('shadowserver', 'b1', $ipB, 'low', $wOknie);
        $this->wstawIncydent('play', 'old', $ipA, 'high', $poza);

        $model = model(IncydentModel::class);
        $kpi   = $model->kpiPrzegladu(7);

        $this->assertSame(3, $kpi['liczba_incydentow']);
        $this->assertSame(2, $kpi['liczba_ip']);
        $this->assertSame(1, $kpi['ip_wielozrodlowe']);
        $this->assertSame(2, $kpi['critical_high']);
        $this->assertSame('critical', $kpi['max_waga']);

        $top = $model->topProblematyczneIp(7, null, null, null, 2, 10);
        $this->assertCount(1, $top);
        $this->assertSame($ipA, $top[0]['ip']);
        $this->assertSame(2, $top[0]['liczba_zrodel']);
        $this->assertSame(['play', 'shadowserver'], $top[0]['zrodla']);
        $this->assertSame('critical', $top[0]['max_waga']);
        $this->assertContains('exposure', $top[0]['kategorie']);
        $this->assertContains('abuse', $top[0]['kategorie']);

        $ostatnie = $model->ostatnieIncydenty(7, null, $ipA, 'high', 10);
        $this->assertCount(2, $ostatnie);
        $this->assertSame(['critical', 'high'], array_column($ostatnie, 'waga'));
    }

    public function testWidoczneZrodlaPomijaUkryte(): void
    {
        $teraz  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $wOknie = $teraz->format('Y-m-d H:i:s');
        $this->wstawIncydent('shadowserver', 'a1', '198.51.100.10', 'low', $wOknie);
        $this->wstawIncydent('snds', 'c1', '198.51.100.10', 'critical', $wOknie);

        $zrodla = model(IncydentModel::class)->widoczneZrodla();

        $this->assertSame(['shadowserver'], $zrodla);
    }

    /**
     * @param bool $ukrytyWUi
     */
    private function wstawIncydent(
        string $zrodlo,
        string $klucz,
        string $ip,
        string $waga,
        string $ostatnio,
        bool $ukrytyWUi = false,
        string $kategoria = 'other',
        string $tytul = '',
    ): void {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $klucz,
            'podmiot_typ'       => 'ip',
            'podmiot'           => $ip,
            'pierwsze_widziane' => $ostatnio,
            'ostatnio_widziane' => $ostatnio,
            'waga'              => $waga,
            'kategoria'         => $kategoria,
            'tytul'             => $tytul !== '' ? $tytul : "Test {$klucz}",
            'ukryty_w_ui'       => $ukrytyWUi ? 1 : 0,
        ]);
    }
}
