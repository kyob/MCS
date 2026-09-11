<?php

use App\Libraries\IncydentZapis;
use App\Libraries\WagaV0;
use App\Models\IncydentModel;
use App\Models\ObserwacjaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class IncydentZapisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $incydenty   = $this->db->prefixTable('mcs_incydenty');
        $obserwacje  = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");

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
                wersja_wagi VARCHAR(20) NULL,
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
    }

    protected function tearDown(): void
    {
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $obserwacje = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        parent::tearDown();
    }

    public function testNowyIncydentIObserwacja(): void
    {
        $waga = (new WagaV0())->wylicz('malware');
        $zapis = new IncydentZapis($this->db);

        $wynik = $zapis->zapisz([
            'zrodlo'            => 'shadowserver',
            'klucz_dedupu'      => '198.51.100.10|open|22',
            'podmiot'           => '198.51.100.10',
            'zaobserwowano_at'  => '2026-09-11 10:00:00+00',
            'waga'              => $waga['waga'],
            'wersja_wagi'       => $waga['wersja'],
            'kategoria'         => 'malware',
            'tytul'             => 'Shadowserver — test',
            'id_w_zrodle'       => 'src-1',
            'payload'           => ['raw' => 'x'],
        ]);

        $this->assertTrue($wynik['utworzono_incydent']);
        $this->assertGreaterThan(0, $wynik['incydent_id']);
        $this->assertGreaterThan(0, $wynik['obserwacja_id']);

        $inc = model(IncydentModel::class)->find($wynik['incydent_id']);
        $this->assertSame('critical', $inc['waga']);
        $this->assertSame('v0', $inc['wersja_wagi']);

        $obs = model(ObserwacjaModel::class)->find($wynik['obserwacja_id']);
        $this->assertSame($wynik['incydent_id'], (int) $obs['incydent_id']);
    }

    public function testPowtorzenieAktualizujeIncydentIDodajeObserwacje(): void
    {
        $zapis = new IncydentZapis($this->db);

        $baza = [
            'zrodlo'       => 'play',
            'klucz_dedupu' => 'dedup-1',
            'podmiot'      => '198.51.100.20',
            'waga'         => 'medium',
            'wersja_wagi'  => 'v0',
            'kategoria'    => 'abuse',
            'tytul'        => 'Play',
        ];

        $a = $zapis->zapisz(array_merge($baza, ['zaobserwowano_at' => '2026-09-10 10:00:00+00']));
        $b = $zapis->zapisz(array_merge($baza, [
            'zaobserwowano_at' => '2026-09-11 12:00:00+00',
            'waga'             => 'high',
            'tytul'            => 'Play — update',
        ]));

        $this->assertTrue($a['utworzono_incydent']);
        $this->assertFalse($b['utworzono_incydent']);
        $this->assertSame($a['incydent_id'], $b['incydent_id']);
        $this->assertNotSame($a['obserwacja_id'], $b['obserwacja_id']);

        $inc = model(IncydentModel::class)->find($a['incydent_id']);
        $this->assertSame('high', $inc['waga']);
        $this->assertSame('Play — update', $inc['tytul']);
        $this->assertSame('2026-09-10 10:00:00+00', $inc['pierwsze_widziane']);
        $this->assertSame('2026-09-11 12:00:00+00', $inc['ostatnio_widziane']);

        $ile = model(ObserwacjaModel::class)->where('incydent_id', $a['incydent_id'])->countAllResults();
        $this->assertSame(2, $ile);
    }
}
