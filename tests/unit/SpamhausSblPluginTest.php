<?php

use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientStub;
use App\Libraries\OpcjeMcs;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use McsPluginy\spamhaus_sbl\Filtr;
use McsPluginy\spamhaus_sbl\Naglowki;
use McsPluginy\spamhaus_sbl\Plugin;
use McsPluginy\spamhaus_sbl\Widok;

/**
 * Przebieg wtyczki spamhaus_sbl na SQLite :memory: z syntetycznymi .eml (stub IMAP, bez sieci).
 *
 * @internal
 */
final class SpamhausSblPluginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;
    protected $refresh   = false;
    protected $namespace = '';

    private const TABELE = [
        'mcs_spamhaus_sbl_wiadomosci', 'mcs_spamhaus_sbl_wiersze', 'mcs_importy', 'mcs_incydenty', 'mcs_obserwacje', 'mcs_pluginy',
    ];

    private const FIXTURES = SUPPORTPATH . 'fixtures/spamhaus_sbl/';

    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::ustawModel(null);
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        $p = fn (string $t): string => $this->db->prefixTable($t);
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_sbl_wiadomosci')} (id INTEGER PRIMARY KEY AUTOINCREMENT, klucz VARCHAR(255) NOT NULL UNIQUE,
            uid INTEGER NULL, status VARCHAR(24) NOT NULL, powod VARCHAR(255) NOT NULL DEFAULT '', nadawca VARCHAR(255) NOT NULL DEFAULT '',
            temat VARCHAR(255) NOT NULL DEFAULT '', uwierzytelnienie VARCHAR(32) NOT NULL DEFAULT '', nieuwierzytelniony BOOLEAN NOT NULL DEFAULT 0,
            sbl VARCHAR(16) NULL, ip VARCHAR(64) NULL, import_id INTEGER NULL, przetworzono_at TEXT NOT NULL)");
        $this->db->query("CREATE TABLE {$p('mcs_spamhaus_sbl_wiersze')} (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INTEGER NULL,
            zaobserwowano_at TEXT NOT NULL, sbl VARCHAR(16) NOT NULL, ip VARCHAR(45) NOT NULL, cidr VARCHAR(64) NULL,
            uwierzytelnienie VARCHAR(32) NOT NULL DEFAULT '', klucz_dedupu VARCHAR(255) NOT NULL, payload_json TEXT NULL)");
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
        $this->db->query("CREATE TABLE {$p('mcs_pluginy')} (zrodlo VARCHAR(50) PRIMARY KEY, nazwa VARCHAR(100) NOT NULL, opis TEXT NULL,
            import_wstrzymany BOOLEAN NOT NULL DEFAULT 1, ukryty_w_ui BOOLEAN NOT NULL DEFAULT 0, health_at TEXT NULL,
            health_ok BOOLEAN NULL, health_msg TEXT NULL)");
        $this->db->table('mcs_pluginy')->insert(['zrodlo' => 'spamhaus_sbl', 'nazwa' => 'Spamhaus SBL', 'import_wstrzymany' => 1, 'ukryty_w_ui' => 0]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABELE as $t) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->prefixTable($t));
        }
        OpcjeMcs::resetuj();
        parent::tearDown();
    }

    public function testPoprawnySblWAsTworzyIncydentHigh(): void
    {
        $stub = $this->stub(['ok_dkim.eml']);
        $s    = $this->plugin()->importujZImap($stub);

        $this->assertSame(1, $s['przyjete']);
        $this->assertSame(1, $s['incydenty']);
        $this->assertSame(0, $s['nieuwierzytelnione']);
        $inc = $this->db->table('mcs_incydenty')->get()->getRowArray();
        $this->assertSame('spamhaus_sbl', $inc['zrodlo']);
        $this->assertSame('ip', $inc['podmiot_typ']);
        $this->assertSame('91.231.81.0', $inc['podmiot']);
        $this->assertSame('spam', $inc['kategoria']);
        $this->assertSame('high', $inc['waga']);
        $this->assertSame('sbl|SBL654321|91.231.81.0', $inc['klucz_dedupu']);
        $this->assertStringNotContainsString('nieuwierzytelniony', $inc['tytul']);
        $wiersz = $this->db->table('mcs_spamhaus_sbl_wiersze')->get()->getRowArray();
        $this->assertSame('91.231.81.0/24', $wiersz['cidr']);
        $this->assertSame('dkim=pass', $wiersz['uwierzytelnienie']);
        $payload = json_decode((string) $wiersz['payload_json'], true);
        $this->assertSame('snowshoe spam operation (synthetic fixture)', $payload['powod']);
        // Przyjęta wiadomość oznaczona jako przeczytana.
        $this->assertSame([], $stub->listujUid(true));
    }

    public function testObcyIpOdrzuconyZLicznikiem(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['obcy_ip.eml']));

        $this->assertSame(0, $s['przyjete']);
        $this->assertSame(1, $s['pominiete']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->where('status', 'obcy_ip')->countAllResults());
    }

    public function testPodrobionyNadawcaDomenaIDisplayName(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['spoof_domena.eml', 'spoof_display.eml']));

        $this->assertSame(0, $s['przyjete']);
        $this->assertSame(2, $s['pominiete']);
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(2, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->where('status', 'nadawca')->countAllResults());
    }

    public function testBrakAuthenticationResultsPrzyjetyZObnizonaWaga(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['bez_ar.eml']));

        $this->assertSame(1, $s['przyjete']);
        $this->assertSame(1, $s['nieuwierzytelnione']);
        $inc = $this->db->table('mcs_incydenty')->get()->getRowArray();
        $this->assertSame('medium', $inc['waga']);
        $this->assertStringContainsString('nieuwierzytelniony nadawca', $inc['tytul']);
        $this->assertSame(0, (int) $inc['ukryty_w_ui']);
        $this->assertSame('91.231.82.10', $inc['podmiot']);
    }

    public function testDkimFailOdrzucony(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['dkim_fail.eml']));

        $this->assertSame(0, $s['przyjete']);
        $this->assertSame(1, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->where('status', 'auth_fail')->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    public function testDuplikatNieTworzyDrugiegoIncydentu(): void
    {
        $plugin = $this->plugin();
        $plugin->importujZImap($this->stub(['ok_dkim.eml']));
        // Ten sam mail ponownie (np. drugi folder / ponowne UNSEEN) — inny UID, ten sam Message-ID.
        $s = $plugin->importujZImap($this->stub(['ok_dkim.eml'], 50));

        $this->assertSame(1, $s['duplikaty']);
        $this->assertSame(1, $this->db->table('mcs_incydenty')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_obserwacje')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_importy')->countAllResults());
        $this->assertSame(1, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->countAllResults());
    }

    public function testTematBezSblPominiety(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['temat_bez_sbl.eml']));

        $this->assertSame(1, $s['pominiete']);
        $this->assertSame(1, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->where('status', 'temat')->countAllResults());
        $this->assertSame(0, $this->db->table('mcs_incydenty')->countAllResults());
    }

    public function testInnaPocztaBezZapisu(): void
    {
        $stub = new ImapKlientStub([[
            'uid' => 1, 'message_id' => '<x@example.net>', 'subject' => 'Faktura', 'from' => 'biuro@example.net',
            'tresc' => 'IP 91.231.81.1', 'naglowki' => "From: biuro@example.net\nSubject: Faktura\n",
        ]]);
        $s = $this->plugin()->importujZImap($stub);

        $this->assertSame(1, $s['inne']);
        $this->assertSame(0, $this->db->table('mcs_spamhaus_sbl_wiadomosci')->countAllResults());
        $this->assertSame([1], $stub->listujUid(true));
    }

    public function testHtmlIDlugiePolaBezHtmlWZapisie(): void
    {
        $s = $this->plugin()->importujZImap($this->stub(['html_dlugie.eml']));

        $this->assertSame(1, $s['przyjete']);
        $inc = $this->db->table('mcs_incydenty')->get()->getRowArray();
        $this->assertSame('91.231.83.5', $inc['podmiot']);
        $this->assertSame('sbl|SBL700007|91.231.83.5', $inc['klucz_dedupu']);
        $wiad = $this->db->table('mcs_spamhaus_sbl_wiadomosci')->get()->getRowArray();
        $this->assertStringNotContainsString('<', $wiad['temat']);
        $this->assertLessThanOrEqual(255, mb_strlen($wiad['temat']));
        $payload = json_decode((string) $this->db->table('mcs_spamhaus_sbl_wiersze')->get()->getRowArray()['payload_json'], true);
        $this->assertStringNotContainsString('<', $payload['powod']);
        $this->assertStringNotContainsString('onerror', $payload['powod']);
        $this->assertLessThanOrEqual(300, mb_strlen($payload['powod']));
        $this->assertStringStartsWith('AAAA', $payload['powod']);
        $this->assertSame('', $payload['link_w_tresci']);
    }

    public function testBrakKonfiguracjiImapToPominiecieBezWyjatku(): void
    {
        $klucz = 'mcs.spamhaus_sbl.imapHost';
        $stare = [$_ENV[$klucz] ?? null, $_SERVER[$klucz] ?? null];
        $_ENV[$klucz] = $_SERVER[$klucz] = '';

        try {
            $plugin = new Plugin(imapFabryka: new ImapKlientFabryka());
            $plugin->pobierz();
        } finally {
            [$e, $s] = $stare;
            if ($e === null) { unset($_ENV[$klucz]); } else { $_ENV[$klucz] = $e; }
            if ($s === null) { unset($_SERVER[$klucz]); } else { $_SERVER[$klucz] = $s; }
        }

        $this->assertNotNull($plugin->komunikatPominiecia());
        $wiersz = $this->db->table('mcs_pluginy')->where('zrodlo', 'spamhaus_sbl')->get()->getRowArray();
        $this->assertSame(0, (int) $wiersz['health_ok']);
    }

    public function testWidokLiczniki(): void
    {
        $this->plugin()->importujZImap($this->stub(['ok_dkim.eml', 'bez_ar.eml', 'obcy_ip.eml', 'spoof_display.eml']));
        $d = (new Widok())->dane();

        $this->assertSame(2, $d['przetworzone']);
        $this->assertSame(2, $d['pominiete']);
        $this->assertSame(1, $d['nieuwierzytelnione']);
        $this->assertSame('SBL700004', $d['ostatni_sbl']);
        $this->assertSame('https://check.spamhaus.org/sbl/query/SBL700004', $d['ostatni_sbl_link']);

        $html = view('admin/pluginy/_spamhaus_sbl_karta', ['k' => $d]);
        $this->assertStringContainsString('SBL700004', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    private function plugin(): Plugin
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierz')->willReturnCallback(static fn (string $k): ?string => match ($k) {
            As198050Matcher::KLUCZ_WLACZONE => '1',
            As198050Matcher::KLUCZ_CIDRY    => "91.231.80.0/22\n5.253.230.0/24",
            default                         => null,
        });

        return new Plugin(filtr: new Filtr(), matcher: new As198050Matcher(new CidrMatcher(), $model));
    }

    /**
     * @param list<string> $pliki
     */
    private function stub(array $pliki, int $uidStart = 1): ImapKlientStub
    {
        $wiad = [];
        foreach ($pliki as $i => $plik) {
            $raw = (string) file_get_contents(self::FIXTURES . $plik);
            [$hdr, $body] = array_pad(preg_split('/\r?\n\r?\n/', $raw, 2), 2, '');
            $n = new Naglowki($hdr);
            $wiad[] = [
                'uid'        => $uidStart + $i,
                'message_id' => $n->pierwszy('message-id'),
                'subject'    => (string) $n->pierwszy('subject'),
                'date'       => $n->pierwszy('date'),
                'from'       => (string) $n->pierwszy('from'),
                'tresc'      => $body,
                'naglowki'   => $hdr,
            ];
        }

        return new ImapKlientStub($wiad);
    }
}
