<?php

use App\Libraries\ImportKoperta;
use App\Models\ImportModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ImportKopertaTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_importy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testRozpocznijIZakonczOk(): void
    {
        $koperta = new ImportKoperta(model(ImportModel::class), $this->db);
        $id      = $koperta->rozpocznij('shadowserver', 'abc123', '<msg-1@example>');

        $this->assertNotNull($id);

        $koperta->zakonczOk((int) $id, ['wierszy' => 3]);

        $wiersz = model(ImportModel::class)->find($id);
        $this->assertIsArray($wiersz);
        $this->assertSame('ok', $wiersz['status']);
        $this->assertSame('abc123', $wiersz['checksum']);
        $this->assertSame('<msg-1@example>', $wiersz['id_wiadomosci']);
        $this->assertNotNull($wiersz['zakonczono_at']);
    }

    public function testDuplikatChecksumNieOtwieraPonownie(): void
    {
        $koperta = new ImportKoperta(model(ImportModel::class), $this->db);
        $id1     = $koperta->rozpocznij('play', 'ten-sam', null);
        $this->assertNotNull($id1);
        $koperta->zakonczOk((int) $id1);

        $id2 = $koperta->rozpocznij('play', 'ten-sam', null);
        $this->assertNull($id2);
        $this->assertTrue($koperta->juzZaimportowano('play', 'ten-sam'));
    }

    public function testKwarantanna(): void
    {
        $koperta = new ImportKoperta(model(ImportModel::class), $this->db);
        $id      = $koperta->rozpocznij('snds', 'x1', 'ext-9');
        $this->assertNotNull($id);

        $koperta->zakonczKwarantanna((int) $id, 'brak IP w wierszu');

        $wiersz = model(ImportModel::class)->find($id);
        $this->assertSame('kwarantanna', $wiersz['status']);
        $this->assertSame('brak IP w wierszu', $wiersz['blad_text']);
    }

    public function testBladNieBlokujePonownegoChecksum(): void
    {
        $koperta = new ImportKoperta(model(ImportModel::class), $this->db);
        $id1     = $koperta->rozpocznij('shadowserver', 'retry-me', null);
        $koperta->zakonczBlad((int) $id1, 'timeout');

        $id2 = $koperta->rozpocznij('shadowserver', 'retry-me', null);
        $this->assertNotNull($id2);
    }
}
