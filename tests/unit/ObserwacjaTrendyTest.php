<?php

use App\Models\ObserwacjaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ObserwacjaTrendyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $obserwacje = $this->db->prefixTable('mcs_obserwacje');

        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");

        $this->db->query(
            "CREATE TABLE {$pluginy} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0
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
        $this->db->query(
            "CREATE TABLE {$obserwacje} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                incydent_id INTEGER NOT NULL,
                import_id INTEGER NULL,
                zaobserwowano_at TEXT NOT NULL,
                payload_json TEXT NULL,
                id_w_zrodle TEXT NULL
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
                'zrodlo'            => 'snds',
                'nazwa'             => 'SNDS',
                'import_wstrzymany' => 1,
                'ukryty_w_ui'       => 1,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['mcs_obserwacje', 'mcs_incydenty', 'mcs_pluginy'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testTrendyDzienneNoweVsPowtarzajaceIUkryte(): void
    {
        $teraz = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dzis  = $teraz->format('Y-m-d');
        $wczoraj = $teraz->modify('-1 days')->format('Y-m-d');

        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => 'nowy',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.10',
            'pierwsze_widziane' => $dzis . ' 08:00:00',
            'ostatnio_widziane' => $dzis . ' 12:00:00',
            'waga'              => 'high',
            'kategoria'         => 'malware',
            'tytul'             => 'Nowy',
            'port'              => 443,
            'ukryty_w_ui'       => 0,
        ]);
        $idNowy = (int) $this->db->insertID();

        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => 'stary',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.11',
            'pierwsze_widziane' => $wczoraj . ' 08:00:00',
            'ostatnio_widziane' => $dzis . ' 11:00:00',
            'waga'              => 'medium',
            'kategoria'         => 'exposure',
            'tytul'             => 'Stary',
            'port'              => 22,
            'ukryty_w_ui'       => 0,
        ]);
        $idStary = (int) $this->db->insertID();

        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'snds',
            'klucz_dedupu'      => 'ukryty-plugin',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.12',
            'pierwsze_widziane' => $dzis . ' 09:00:00',
            'ostatnio_widziane' => $dzis . ' 09:00:00',
            'waga'              => 'critical',
            'kategoria'         => 'abuse',
            'tytul'             => 'Ukryty plugin',
            'ukryty_w_ui'       => 0,
        ]);
        $idUkrytyPlugin = (int) $this->db->insertID();

        $this->db->table('mcs_obserwacje')->insertBatch([
            [
                'incydent_id'       => $idNowy,
                'zaobserwowano_at'  => $dzis . ' 08:00:00',
            ],
            [
                'incydent_id'       => $idStary,
                'zaobserwowano_at'  => $dzis . ' 11:00:00',
            ],
            [
                'incydent_id'       => $idUkrytyPlugin,
                'zaobserwowano_at'  => $dzis . ' 09:00:00',
            ],
        ]);

        $trendy = model(ObserwacjaModel::class)->trendyDzienne(7);

        $this->assertSame(2, $trendy['suma_incydentow']);
        $this->assertArrayHasKey('shadowserver', $trendy['zrodla_suma']);
        $this->assertSame(2, $trendy['zrodla_suma']['shadowserver']);
        $this->assertArrayNotHasKey('snds', $trendy['zrodla_suma']);

        $dzienDzis = null;
        foreach ($trendy['dni'] as $d) {
            if ($d['dzien'] === $dzis) {
                $dzienDzis = $d;
                break;
            }
        }
        $this->assertNotNull($dzienDzis);
        $this->assertSame(2, $dzienDzis['incydenty']);
        $this->assertSame(1, $dzienDzis['nowe']);
        $this->assertSame(1, $dzienDzis['powtarzajace']);

        $tylkoMalware = model(ObserwacjaModel::class)->trendyDzienne(7, null, null, 'malware');
        $this->assertSame(1, $tylkoMalware['suma_incydentow']);

        $tylkoPort = model(ObserwacjaModel::class)->trendyDzienne(7, null, null, null, 22);
        $this->assertSame(1, $tylkoPort['suma_incydentow']);
    }

    public function testTrendyDzienneDistinctIncydentPrzyWieluObserwacjach(): void
    {
        $teraz = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dzis  = $teraz->format('Y-m-d');

        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => 'dnsbl-like',
            'podmiot_typ'       => 'ip',
            'podmiot'           => '198.51.100.20',
            'pierwsze_widziane' => $dzis . ' 08:00:00',
            'ostatnio_widziane' => $dzis . ' 12:00:00',
            'waga'              => 'medium',
            'kategoria'         => 'reputation',
            'tytul'             => 'Wielokrotny check',
            'ukryty_w_ui'       => 0,
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('mcs_obserwacje')->insertBatch([
            ['incydent_id' => $id, 'zaobserwowano_at' => $dzis . ' 08:00:00'],
            ['incydent_id' => $id, 'zaobserwowano_at' => $dzis . ' 09:00:00'],
            ['incydent_id' => $id, 'zaobserwowano_at' => $dzis . ' 10:00:00'],
            ['incydent_id' => $id, 'zaobserwowano_at' => $dzis . ' 11:00:00'],
        ]);

        $trendy = model(ObserwacjaModel::class)->trendyDzienne(1);

        $this->assertSame(1, $trendy['suma_incydentow']);
        $this->assertSame(1, $trendy['zrodla_suma']['shadowserver'] ?? 0);

        $dzienDzis = null;
        foreach ($trendy['dni'] as $d) {
            if ($d['dzien'] === $dzis) {
                $dzienDzis = $d;
                break;
            }
        }
        $this->assertNotNull($dzienDzis);
        $this->assertSame(1, $dzienDzis['incydenty']);
        $this->assertSame(1, $dzienDzis['nowe']);
        $this->assertSame(0, $dzienDzis['powtarzajace']);
    }
}
