<?php

use App\Models\ImportModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ImportModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $tabela = $this->db->prefixTable('mcs_importy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zrodlo VARCHAR(50) NOT NULL,
                id_wiadomosci TEXT NULL,
                rozpoczeto_at TEXT NOT NULL,
                zakonczono_at TEXT NULL,
                status VARCHAR(20) NOT NULL,
                checksum VARCHAR(255) NULL,
                meta_json TEXT NULL,
                blad_text TEXT NULL
            )",
        );

        $model = model(ImportModel::class);
        $model->insert([
            'zrodlo'        => 'play',
            'rozpoczeto_at' => '2026-09-11 10:00:00+00',
            'zakonczono_at' => '2026-09-11 10:01:00+00',
            'status'        => 'ok',
        ]);
        $model->insert([
            'zrodlo'        => 'shadowserver',
            'rozpoczeto_at' => '2026-09-11 11:00:00+00',
            'zakonczono_at' => '2026-09-11 11:01:00+00',
            'status'        => 'blad',
            'blad_text'     => 'timeout',
        ]);
        $model->insert([
            'zrodlo'        => 'snds',
            'rozpoczeto_at' => '2026-09-11 12:00:00+00',
            'zakonczono_at' => '2026-09-11 12:01:00+00',
            'status'        => 'kwarantanna',
            'blad_text'     => 'zły CSV',
        ]);
        $model->insert([
            'zrodlo'        => 'play',
            'rozpoczeto_at' => '2026-09-11 13:00:00+00',
            'zakonczono_at' => null,
            'status'        => 'w_trakcie',
        ]);
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_importy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testOstatnieSortujeOdNajnowszychZLimitem(): void
    {
        $lista = model(ImportModel::class)->ostatnie(2);

        $this->assertCount(2, $lista);
        $this->assertSame('w_trakcie', $lista[0]['status']);
        $this->assertSame('play', $lista[0]['zrodlo']);
        $this->assertSame('kwarantanna', $lista[1]['status']);
    }

    public function testOstatnieFiltrujePoZrodle(): void
    {
        $lista = model(ImportModel::class)->ostatnie(10, 'play');

        $this->assertCount(2, $lista);
        foreach ($lista as $w) {
            $this->assertSame('play', $w['zrodlo']);
        }
    }

    public function testSzukajFiltrujePoStatusieITekscie(): void
    {
        $lista = model(ImportModel::class)->szukaj(
            null,
            'blad',
            'timeout',
            null,
            null,
            25,
        );

        $this->assertCount(1, $lista);
        $this->assertSame('shadowserver', $lista[0]['zrodlo']);
        $this->assertSame('blad', $lista[0]['status']);
    }

    public function testSzukajFiltrujePoZrodleIDacie(): void
    {
        $lista = model(ImportModel::class)->szukaj(
            'play',
            null,
            null,
            '2026-09-11',
            '2026-09-11',
            25,
        );

        $this->assertCount(2, $lista);
        foreach ($lista as $w) {
            $this->assertSame('play', $w['zrodlo']);
            $this->assertStringStartsWith('2026-09-11', (string) $w['rozpoczeto_at']);
        }
    }

    public function testListaZrodelZwracaPosortowaneUnikalne(): void
    {
        $lista = model(ImportModel::class)->listaZrodel();

        $this->assertSame(['play', 'shadowserver', 'snds'], $lista);
    }
}
