<?php

use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Libraries\Watchlista\DigestSerwis;
use App\Models\UstawieniaModel;
use App\Models\WatchlistaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class DigestSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $watchlista = $this->db->prefixTable('mcs_watchlista');
        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');

        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");
        $this->db->query("DROP TABLE IF EXISTS {$watchlista}");

        $this->db->query(
            "CREATE TABLE {$watchlista} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip VARCHAR(45) NOT NULL UNIQUE,
                notatka TEXT NULL,
                dodano_at TEXT NOT NULL,
                dodano_przez VARCHAR(255) NULL
            )",
        );
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

        $this->db->table('mcs_pluginy')->insert([
            'zrodlo'            => 'shadowserver',
            'nazwa'             => 'Shadowserver',
            'import_wstrzymany' => 1,
            'ukryty_w_ui'       => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $watchlista = $this->db->prefixTable('mcs_watchlista');
        $pluginy    = $this->db->prefixTable('mcs_pluginy');
        $incydenty  = $this->db->prefixTable('mcs_incydenty');
        $this->db->query("DROP TABLE IF EXISTS {$incydenty}");
        $this->db->query("DROP TABLE IF EXISTS {$pluginy}");
        $this->db->query("DROP TABLE IF EXISTS {$watchlista}");
        parent::tearDown();
    }

    public function testPustaWatchlistaDajePustyDigest(): void
    {
        $serwis = new DigestSerwis(model(WatchlistaModel::class));
        $wynik  = $serwis->zbierz(24);

        $this->assertSame(24, $wynik['okno_godzin']);
        $this->assertSame(0, $wynik['liczba_ip_watchlista']);
        $this->assertSame([], $wynik['incydenty']);
        $this->assertFalse($wynik['tylko_as198050']);
    }

    public function testZwracaTylkoHighCriticalDlaObserwowanych(): void
    {
        $watch = model(WatchlistaModel::class);
        $watch->dodaj('198.51.100.10');
        $watch->dodaj('198.51.100.20');

        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stare = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-48 hours')
            ->format('Y-m-d H:i:s');

        $this->wstaw('shadowserver', 'a1', '198.51.100.10', 'critical', $teraz);
        $this->wstaw('shadowserver', 'a2', '198.51.100.10', 'low', $teraz);
        $this->wstaw('shadowserver', 'a3', '198.51.100.20', 'high', $teraz);
        $this->wstaw('shadowserver', 'a4', '198.51.100.30', 'critical', $teraz); // nie na watchliście
        $this->wstaw('shadowserver', 'a5', '198.51.100.10', 'high', $stare); // poza oknem

        $wynik = (new DigestSerwis($watch))->zbierz(24);
        $this->assertSame(2, $wynik['liczba_ip_watchlista']);
        $this->assertCount(2, $wynik['incydenty']);

        $ips = array_column($wynik['incydenty'], 'podmiot');
        sort($ips);
        $this->assertSame(['198.51.100.10', '198.51.100.20'], $ips);

        $wagi = array_column($wynik['incydenty'], 'waga');
        sort($wagi);
        $this->assertSame(['critical', 'high'], $wagi);
    }

    public function testFiltrAs198050(): void
    {
        $watch = model(WatchlistaModel::class);
        $watch->dodaj('91.231.81.10'); // nasze
        $watch->dodaj('8.8.8.8');      // obce

        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->wstaw('shadowserver', 'n1', '91.231.81.10', 'high', $teraz);
        $this->wstaw('shadowserver', 'n2', '8.8.8.8', 'critical', $teraz);

        $ustawienia = $this->createMock(UstawieniaModel::class);
        $ustawienia->method('pobierz')->willReturnCallback(static function (string $klucz): ?string {
            return match ($klucz) {
                As198050Matcher::KLUCZ_WLACZONE => '1',
                As198050Matcher::KLUCZ_CIDRY    => '91.231.80.0/22',
                default                         => null,
            };
        });
        $matcher = new As198050Matcher(new CidrMatcher(), $ustawienia);

        $wynik = (new DigestSerwis($watch, $matcher))->zbierz(24, true);
        $this->assertTrue($wynik['tylko_as198050']);
        $this->assertCount(1, $wynik['incydenty']);
        $this->assertSame('91.231.81.10', $wynik['incydenty'][0]['podmiot']);
    }

    public function testFormatTekstowyPoPolsku(): void
    {
        $watch = model(WatchlistaModel::class);
        $watch->dodaj('198.51.100.10', 'test');

        $teraz = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->wstaw('shadowserver', 't1', '198.51.100.10', 'high', $teraz, 'Skrypt botnet');

        $tekst = (new DigestSerwis($watch))->formatujTekst(
            (new DigestSerwis($watch))->zbierz(6),
        );

        $this->assertStringContainsString('Digest operacyjny MCS', $tekst);
        $this->assertStringContainsString('198.51.100.10', $tekst);
        $this->assertStringContainsString('high', $tekst);
        $this->assertStringContainsString('INFO', $tekst);
        $this->assertStringContainsString('bez auto-blokad', $tekst);
    }

    private function wstaw(
        string $zrodlo,
        string $klucz,
        string $ip,
        string $waga,
        string $ostatnio,
        string $tytul = 'Test',
    ): void {
        $this->db->table('mcs_incydenty')->insert([
            'zrodlo'            => $zrodlo,
            'klucz_dedupu'      => $klucz,
            'podmiot_typ'       => 'ip',
            'podmiot'           => $ip,
            'pierwsze_widziane' => $ostatnio,
            'ostatnio_widziane' => $ostatnio,
            'waga'              => $waga,
            'kategoria'         => 'test',
            'tytul'             => $tytul,
            'ukryty_w_ui'       => 0,
        ]);
    }
}
