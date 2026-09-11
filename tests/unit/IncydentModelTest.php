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
            'ukryty_w_ui'       => $ukrytyWUi ? 1 : 0,
        ]);
    }
}
