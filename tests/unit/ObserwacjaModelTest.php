<?php

use App\Models\ObserwacjaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ObserwacjaModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $obserwacje = $this->db->prefixTable('mcs_obserwacje');
        $this->db->query("DROP TABLE IF EXISTS {$obserwacje}");
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
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_obserwacje'));
        parent::tearDown();
    }

    public function testDlaIncydentuZwracaTimelineMalejaco(): void
    {
        $model = model(ObserwacjaModel::class);
        $model->insert([
            'incydent_id'      => 10,
            'zaobserwowano_at' => '2026-09-10 08:00:00+00',
            'payload_json'     => '{"dzień":1}',
            'id_w_zrodle'      => '1',
        ]);
        $model->insert([
            'incydent_id'      => 10,
            'zaobserwowano_at' => '2026-09-11 09:00:00+00',
            'payload_json'     => '{"dzień":2}',
            'id_w_zrodle'      => '2',
        ]);
        $model->insert([
            'incydent_id'      => 99,
            'zaobserwowano_at' => '2026-09-12 10:00:00+00',
            'payload_json'     => null,
            'id_w_zrodle'      => 'x',
        ]);

        $lista = $model->dlaIncydentu(10);

        $this->assertCount(2, $lista);
        $this->assertSame('2', $lista[0]['id_w_zrodle']);
        $this->assertSame('1', $lista[1]['id_w_zrodle']);
        $this->assertIsArray($lista[0]['payload']);
        $this->assertSame(2, $lista[0]['payload']['dzień'] ?? null);
    }

    public function testDlaIncydentuPustaLista(): void
    {
        $lista = model(ObserwacjaModel::class)->dlaIncydentu(404);
        $this->assertSame([], $lista);
    }
}
