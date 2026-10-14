<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\spamhaus\DropKlient;
use McsPluginy\spamhaus\Serwis;

/**
 * Przebieg pluginu spamhaus na SQLite :memory: z podstawionym HTTP.
 *
 * @internal
 */
final class SpamhausSerwisTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = [
        'mcs_spamhaus_stan', 'mcs_spamhaus_pliki', 'mcs_spamhaus_trafienia', 'mcs_spamhaus_historia',
        'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje',
    ];

    private int $teraz = 1_800_000_000;

    /** @var array<string, array{ok: bool, http_code: int, body: string, error: string, retry_after: int}> */
    private array $odpowiedzi = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_stan')} (id INTEGER PRIMARY KEY, ostatnia_proba_at TEXT NULL, ostatnie_ok_at TEXT NULL,
            nastepne_po TEXT NULL, bledy_z_rzedu INTEGER NOT NULL DEFAULT 0, ostatni_blad TEXT NULL, ostatni_blad_at TEXT NULL,
            wynik VARCHAR(16) NOT NULL DEFAULT 'brak', wynik_at TEXT NULL, aktywne_trafienia INTEGER NOT NULL DEFAULT 0)");
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_pliki')} (lista VARCHAR(16) PRIMARY KEY, url TEXT, plik_timestamp TEXT NULL,
            copyright TEXT, terms TEXT, rekordy INTEGER, sha256 TEXT, pobrano_at TEXT)");
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_trafienia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, typ TEXT, lista TEXT, wpis TEXT,
            sblid TEXT, nasz_prefiks TEXT, zakres TEXT, podmiot TEXT, aktywne BOOLEAN, pierwsze_at TEXT, ostatnie_at TEXT,
            zdjete_at TEXT NULL, incydent_id INTEGER NULL, payload_json TEXT NULL, UNIQUE (lista, wpis, nasz_prefiks))");
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_historia')} (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT, zdarzenie TEXT,
            lista TEXT NULL, wpis TEXT NULL, nasz_prefiks TEXT NULL, opis TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_importy')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            id_wiadomosci TEXT NULL, rozpoczeto_at TEXT NOT NULL, zakonczono_at TEXT NULL, status VARCHAR(20) NOT NULL,
            checksum VARCHAR(255) NULL, meta_json TEXT NULL, blad_text TEXT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_incydenty')} (id INTEGER PRIMARY KEY AUTOINCREMENT, zrodlo VARCHAR(50) NOT NULL,
            klucz_dedupu VARCHAR(255) NOT NULL, podmiot_typ VARCHAR(20) NOT NULL DEFAULT 'ip', podmiot VARCHAR(255) NOT NULL,
            pierwsze_widziane TEXT NOT NULL, ostatnio_widziane TEXT NOT NULL, waga VARCHAR(20) NOT NULL, wersja_wagi VARCHAR(20) NULL,
            kategoria VARCHAR(100) NOT NULL, tytul VARCHAR(255) NOT NULL, port INTEGER NULL, protokol VARCHAR(20) NULL,
            ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, UNIQUE (zrodlo, klucz_dedupu))");
        $this->db->query("CREATE TABLE {$p('mcs_obserwacje')} (id INTEGER PRIMARY KEY AUTOINCREMENT, incydent_id INTEGER NOT NULL,
            import_id INTEGER NULL, zaobserwowano_at TEXT NOT NULL, payload_json TEXT NULL, id_w_zrodle TEXT NULL)");

        $this->ustawPliki([['cidr' => '1.10.16.0/20', 'sblid' => 'SBL1']], [['cidr' => '2001:678:254::/48', 'sblid' => 'SBL2']], [['asn' => 245]]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        parent::tearDown();
    }

    public function testCzystoPoUdanymPobraniu(): void
    {
        $w = $this->serwis()->uruchom();

        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(0, $w['trafienia']);
        $stan = $this->stan();
        $this->assertSame('czysto', $stan['wynik']);
        $this->assertNotNull($stan['ostatnie_ok_at']);
        $this->assertSame(3, $this->db->table('mcs_spamhaus_pliki')->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    public function testBladPobraniaToNieCzysto(): void
    {
        $this->odpowiedzi['drop_v6'] = ['ok' => false, 'http_code' => 503, 'body' => '', 'error' => 'HTTP 503', 'retry_after' => 0];

        $w = $this->serwis()->uruchom();

        $this->assertSame('blad', $w['status']);
        $stan = $this->stan();
        $this->assertSame('brak', $stan['wynik']);
        $this->assertNull($stan['ostatnie_ok_at']);
        $this->assertSame(1, (int) $stan['bledy_z_rzedu']);
        $this->assertSame(0, $this->db->table('mcs_spamhaus_pliki')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_spamhaus_historia')->where('zdarzenie', 'blad')->countAllResults());

        // 30 min później: harmonogram nie pozwala (min. 1 h + backoff)
        $this->teraz += 1800;
        $this->assertSame('pominieto', $this->serwis()->uruchom()['status']);
    }

    public function testPustaOdpowiedz200ToBlad(): void
    {
        $this->odpowiedzi['asndrop'] = ['ok' => true, 'http_code' => 200, 'body' => '', 'error' => '', 'retry_after' => 0];

        $this->assertSame('blad', $this->serwis()->uruchom()['status']);
        $this->assertSame('brak', $this->stan()['wynik']);
    }

    public function testBladPoCzystymNieZmieniaWyniku(): void
    {
        $this->serwis()->uruchom();
        $this->teraz += 2 * 86400;
        $this->odpowiedzi['drop_v4'] = ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'cURL: timeout', 'retry_after' => 0];

        $this->assertSame('blad', $this->serwis()->uruchom()['status']);
        $this->assertSame('czysto', $this->stan()['wynik']);
    }

    public function testTrafienieCidrTworzyJedenIncydentZDedupem(): void
    {
        $this->ustawPliki(
            [['cidr' => '91.231.81.0/24', 'sblid' => 'SBL9']],
            [['cidr' => '2001:678:254::/48', 'sblid' => 'SBL2']],
            [['asn' => 245]],
        );

        $w = $this->serwis()->uruchom();
        $this->assertSame('ok', $w['status'], $w['msg']);
        $this->assertSame(1, $w['trafienia']);
        $this->assertSame('trafienie', $this->stan()['wynik']);

        $this->teraz += 25 * 3600 + 3600;
        $w2 = $this->serwis()->uruchom();
        $this->assertSame('ok', $w2['status'], $w2['msg']);
        $this->assertSame(0, $w2['nowe']);

        $inc = $this->db->table('mcs_incydenty')->get()->getResultArray();
        $this->assertCount(1, $inc);
        $this->assertSame('ip', $inc[0]['podmiot_typ']);
        $this->assertSame('91.231.81.0', $inc[0]['podmiot']);
        $this->assertSame('hijack', $inc[0]['kategoria']);
        $this->assertSame('critical', $inc[0]['waga']);
        $this->assertSame(2, $this->db->table('mcs_obserwacje')->countAllResults());

        // wpis znika z DROP → trafienie zdjęte, wynik czysto
        $this->ustawPliki([['cidr' => '1.10.16.0/20', 'sblid' => 'SBL1']], [['cidr' => '2001:678:254::/48']], [['asn' => 245]]);
        $this->teraz += 25 * 3600 + 3600;
        $w3 = $this->serwis()->uruchom();
        $this->assertSame(1, $w3['zdjete']);
        $this->assertSame('czysto', $this->stan()['wynik']);
        $this->assertSame(1, $this->db->table('mcs_spamhaus_historia')->where('zdarzenie', 'zdjete')->countAllResults());
    }

    public function testTrafienieAsn(): void
    {
        $this->ustawPliki([['cidr' => '1.10.16.0/20']], [['cidr' => '2001:678:254::/48']], [['asn' => 198050, 'asname' => 'ALFA']]);

        $w = $this->serwis()->uruchom();

        $this->assertSame(2, $w['trafienia']); // jeden wpis na każdy nasz prefiks
        $inc = $this->db->table('mcs_incydenty')->orderBy('podmiot')->get()->getResultArray();
        $this->assertSame('spam', $inc[0]['kategoria']);
        $this->assertSame(['2a0f:1700::', '91.231.80.0'], array_column($inc, 'podmiot'));
    }

    private function serwis(): Serwis
    {
        $http = function (string $url, int $timeout): array {
            $lista = array_search(basename($url), DropKlient::PLIKI, true);

            return $this->odpowiedzi[$lista];
        };

        return new Serwis(
            $this->db,
            new DropKlient($http),
            null,
            new \App\Libraries\ImportKoperta(null, $this->db),
            fn (): int => $this->teraz,
            static fn (): array => ['91.231.80.0/22', '2a0f:1700::/29'],
            static fn (): int => 198050,
            24,
            static fn (): int => 0,
        );
    }

    /**
     * @param list<array<string, mixed>> $v4
     * @param list<array<string, mixed>> $v6
     * @param list<array<string, mixed>> $asn
     */
    private function ustawPliki(array $v4, array $v6, array $asn): void
    {
        foreach (['drop_v4' => $v4, 'drop_v6' => $v6, 'asndrop' => $asn] as $lista => $wpisy) {
            $linie = array_map(static fn (array $w): string => (string) json_encode($w), $wpisy);
            $linie[] = (string) json_encode([
                'type' => 'metadata', 'timestamp' => $this->teraz - 600, 'records' => count($wpisy),
                'copyright' => '(c) 2026 The Spamhaus Project SLU',
            ]);
            $this->odpowiedzi[$lista] = ['ok' => true, 'http_code' => 200, 'body' => implode("\n", $linie) . "\n", 'error' => '', 'retry_after' => 0];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stan(): array
    {
        return $this->db->table('mcs_spamhaus_stan')->where('id', 1)->get()->getRowArray() ?? [];
    }
}
