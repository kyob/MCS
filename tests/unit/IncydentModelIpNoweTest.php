<?php

use App\Models\IncydentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Znaczek NEW: IncydentModel::ipNowe().
 *
 * @internal
 */
final class IncydentModelIpNoweTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $incydenty = $this->db->prefixTable('mcs_incydenty');
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
                kategoria VARCHAR(100) NOT NULL,
                tytul VARCHAR(255) NOT NULL,
                port INTEGER NULL,
                protokol VARCHAR(20) NULL,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                UNIQUE (zrodlo, klucz_dedupu)
            )",
        );
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable('mcs_incydenty'));
        parent::tearDown();
    }

    private function wstaw(string $zrodlo, string $ip, string $pierwsze): void
    {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $ip . '|' . $zrodlo,
            'podmiot'           => $ip,
            'pierwsze_widziane' => $pierwsze,
            'ostatnio_widziane' => $pierwsze,
            'waga'              => 'high',
            'kategoria'         => 'exposure',
            'tytul'             => 'test',
        ]);
    }

    private function przed(string $modyfikator): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($modyfikator)->format('Y-m-d H:i:s');
    }

    public function testIpZPierwszymWidzeniemWOknieJestNowe(): void
    {
        $this->wstaw('shadowserver', '198.51.100.1', $this->przed('-1 day'));

        $nowe = model(IncydentModel::class)->ipNowe(['198.51.100.1'], 7);

        $this->assertArrayHasKey('198.51.100.1', $nowe);
    }

    public function testIpStarszeNizOknoNieJestNowe(): void
    {
        $this->wstaw('shadowserver', '198.51.100.2', $this->przed('-30 days'));

        $this->assertSame([], model(IncydentModel::class)->ipNowe(['198.51.100.2'], 7));
    }

    public function testStarszeZrodloWygrywaZNowszym(): void
    {
        $this->wstaw('shadowserver', '198.51.100.3', $this->przed('-30 days'));
        $this->wstaw('cert_n6', '198.51.100.3', $this->przed('-1 day'));

        $this->assertSame([], model(IncydentModel::class)->ipNowe(['198.51.100.3'], 7));
    }

    public function testPustaListaIPomijanieObcychIp(): void
    {
        $this->wstaw('shadowserver', '198.51.100.4', $this->przed('-1 day'));
        $model = model(IncydentModel::class);

        $this->assertSame([], $model->ipNowe([], 7));
        $this->assertSame([], $model->ipNowe(['203.0.113.9'], 7));
    }
}
