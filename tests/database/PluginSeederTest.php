<?php

use App\Libraries\PluginSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class PluginSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        $this->db->query(
            "CREATE TABLE {$tabela} (
                zrodlo VARCHAR(50) PRIMARY KEY,
                nazwa VARCHAR(100) NOT NULL,
                opis TEXT NULL,
                import_wstrzymany BOOLEAN NOT NULL DEFAULT 1,
                ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0,
                health_at TEXT NULL,
                health_ok BOOLEAN NULL,
                health_msg TEXT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $tabela = $this->db->prefixTable('mcs_pluginy');
        $this->db->query("DROP TABLE IF EXISTS {$tabela}");
        parent::tearDown();
    }

    public function testZapisujeTrzyZrodlaZWstrzymanymImportem(): void
    {
        $wynik = (new PluginSeeder())->zapisz();

        $this->assertSame(
            ['shadowserver', 'play', 'snds', 'mojecert', 'cert_n6', 'wanguard', 'cloudflare_botnet', 'qrator', 'lms_mapowanie', 'ismalicious', 'spamrats', 'dnsbl', 'spoofer', 'speedtest', 'wazuh'],
            $wynik['nowe'],
        );
        $this->assertSame([], $wynik['juz_byly']);
        $this->seeNumRecords(15, 'mcs_pluginy', []);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'play',
            'nazwa'             => 'Play Scrubbing',
            'import_wstrzymany' => 1,
        ]);
        $play = $this->db->table('mcs_pluginy')->where('zrodlo', 'play')->get()->getRowArray();
        $this->assertIsArray($play);
        $this->assertSame(
            'Play Ochrona Anty-DDoS — MCS importuje alerty e-mail (IMAP) jako incydenty.',
            (string) ($play['opis'] ?? ''),
        );
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'snds',
            'nazwa'             => 'SNDS',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'mojecert',
            'nazwa'             => 'MojeCert',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'cert_n6',
            'nazwa'             => 'CERT N6',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'wanguard',
            'nazwa'             => 'Wanguard',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'cloudflare_botnet',
            'nazwa'             => 'Cloudflare Botnet',
            'import_wstrzymany' => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'qrator',
            'nazwa'             => 'Qrator Radar',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 1,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'dnsbl',
            'nazwa'             => 'DNSBL (AS)',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'spoofer',
            'nazwa'             => 'CAIDA Spoofer',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
        $qrator = $this->db->table('mcs_pluginy')->where('zrodlo', 'qrator')->get()->getRowArray();
        $this->assertIsArray($qrator);
        $this->assertStringContainsString('płatne', (string) ($qrator['opis'] ?? ''));
    }

    public function testPonownyZapisWymuszaQratorUkryty(): void
    {
        (new PluginSeeder())->zapisz();
        $this->db->table('mcs_pluginy')->where('zrodlo', 'qrator')->update([
            'import_wstrzymany' => 0,
            'ukryty_w_ui'       => 0,
        ]);

        (new PluginSeeder())->zapisz();

        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'qrator',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 1,
        ]);
    }

    public function testPonownyZapisNieNadpisujeIstniejacych(): void
    {
        (new PluginSeeder())->zapisz();
        $this->db->table('mcs_pluginy')->where('zrodlo', 'play')->update(['import_wstrzymany' => 0]);

        $wynik = (new PluginSeeder())->zapisz();

        $this->assertSame([], $wynik['nowe']);
        $this->assertSame(
            ['shadowserver', 'play', 'snds', 'mojecert', 'cert_n6', 'wanguard', 'cloudflare_botnet', 'qrator', 'lms_mapowanie', 'ismalicious', 'spamrats', 'dnsbl', 'spoofer', 'speedtest', 'wazuh'],
            $wynik['juz_byly'],
        );
        $this->assertContains('play', $wynik['opis_zaktualizowany']);
        $this->seeNumRecords(15, 'mcs_pluginy', []);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo'            => 'play',
            'import_wstrzymany' => 0,
        ]);
        $this->seeInDatabase('mcs_pluginy', [
            'zrodlo' => 'lms_mapowanie',
            'nazwa'  => 'LMS mapowanie',
        ]);
        $play = $this->db->table('mcs_pluginy')->where('zrodlo', 'play')->get()->getRowArray();
        $this->assertIsArray($play);
        $this->assertSame(
            'Play Ochrona Anty-DDoS — MCS importuje alerty e-mail (IMAP) jako incydenty.',
            (string) ($play['opis'] ?? ''),
        );
    }
}
