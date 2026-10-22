<?php

use App\Controllers\Api\Badge;
use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\KolejkaDnia;
use App\Libraries\OpcjeMcs;
use App\Libraries\ProblemyIp;
use App\Libraries\Siec\As198050Matcher;
use App\Models\UstawieniaModel;
use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\play\Parser as PlayParser;

/**
 * @internal
 */
final class OpcjeMcsTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $envUstawione = [];

    /** @var array<string, array{0: mixed, 1: mixed, 2: false|string}> */
    private array $envKopia = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpcjeMcs::ustawModel(null);

        // Izolacja od .env serwera: testy zakładają brak kluczy opcji w środowisku.
        foreach (array_keys(OpcjeMcs::definicje()) as $k) {
            $this->envKopia[$k] = [$_SERVER[$k] ?? null, $_ENV[$k] ?? null, getenv($k)];
            unset($_SERVER[$k], $_ENV[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envUstawione as $k) {
            unset($_SERVER[$k], $_ENV[$k]);
        }
        $this->envUstawione = [];
        foreach ($this->envKopia as $k => [$server, $env, $getenv]) {
            if ($server !== null) {
                $_SERVER[$k] = $server;
            }
            if ($env !== null) {
                $_ENV[$k] = $env;
            }
            putenv($getenv === false ? $k : $k . '=' . $getenv);
        }
        $this->envKopia = [];
        // Jak w bootstrapie testów: bez DB (zapisz() woła resetuj()).
        OpcjeMcs::ustawModel(null);
        parent::tearDown();
    }

    private function ustawEnv(string $klucz, string $wartosc): void
    {
        $_SERVER[$klucz]      = $wartosc;
        $this->envUstawione[] = $klucz;
    }

    /**
     * @param array<string, string> $wiersze
     */
    private function modelZ(array $wiersze): UstawieniaModel
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierzWiele')->willReturnCallback(
            static fn (array $klucze): array => array_intersect_key($wiersze, array_flip($klucze)),
        );

        return $model;
    }

    /**
     * Domyślne = wartości wcześniej zahardkodowane w kodzie.
     */
    public function testDomyslneRownePoprzednimHardkodom(): void
    {
        $oczekiwane = [
            'mcs.as198050.asn'                      => 198050,
            'mcs.revDns.cacheTtl'                   => 3600,
            'mcs.kolejkaDnia.oknoDni'               => 2,
            'mcs.problemyIp.oknoTimelineDni'        => 14,
            'mcs.badge.maxIpsBatch'                 => 100,
            'mcs.speedtest.minDownloadMbps'         => 500,
            'mcs.speedtest.minUploadMbps'           => 500,
            'mcs.speedtest.topN'                    => 20,
            'mcs.speedtest.httpTimeout'             => 120,
            'mcs.speedtest.httpConnectTimeout'      => 15,
            'mcs.lms.httpTimeout'                   => 60,
            'mcs.lms.cacheTtl'                      => 86400,
            'mcs.lms.syncPageSize'                  => 2000,
            'mcs.lms.syncMaxPages'                  => 100,
            'mcs.abuseipdb.maxAgeInDays'            => 90,
            'mcs.abuseipdb.cacheTtl'                => 86400,
            'mcs.abuseipdb.httpTimeout'             => 30,
            'mcs.ismalicious.cacheTtl'              => 86400,
            'mcs.ismalicious.httpTimeout'           => 30,
            'mcs.spamrats.cacheTtl'                 => 86400,
            'mcs.dnsbl.listaUceprotect'             => 'tak',
            'mcs.dnsbl.listaSpamcop'                => 'tak',
            'mcs.dnsbl.listaDronebl'                => 'tak',
            'mcs.dnsbl.skanLms'                     => 'tak',
            'mcs.dnsbl.skanAs'                      => 'tak',
            'mcs.dnsbl.forceZakres'                 => 'auto',
            'mcs.dnsbl.asScanWeekday'               => '0',
            'mcs.dnsbl.cacheTtl'                    => 86400,
            'mcs.dnsbl.rateLimitQps'                => 25,
            'mcs.dnsbl.skanerRetencjaDni'           => 90,
            'mcs.shadowserver.imapRecentLimit'      => 40,
            'mcs.play.imapRecentLimit'              => 100,
            'mcs.snds.lookbackDays'                 => 3,
            'mcs.snds.httpTimeout'                  => 60,
            'mcs.snds.httpConnectTimeout'           => 15,
            'mcs.mojecert.syncDaysBack'             => 14,
            'mcs.mojecert.syncMaxPagesPerSubnet'    => 100,
            'mcs.mojecert.httpTimeout'              => 60,
            'mcs.mojecert.httpConnectTimeout'       => 15,
            'mcs.cert_n6.lookbackDays'              => 90,
            'mcs.cert_n6.httpTimeout'               => 120,
            'mcs.cert_n6.httpConnectTimeout'        => 30,
            'mcs.wanguard.apiListLimit'             => 500,
            'mcs.wanguard.concurrency'              => 15,
            'mcs.wanguard.httpTimeout'              => 60,
            'mcs.wanguard.httpConnectTimeout'       => 10,
            'mcs.cloudflare_botnet.httpTimeout'     => 60,
            'mcs.cloudflare_botnet.httpConnectTimeout' => 10,
            'mcs.spoofer.maxPages'                  => 50,
            'mcs.spoofer.httpTimeout'               => 60,
            'mcs.spoofer.httpConnectTimeout'        => 10,
            'mcs.spamhaus.httpTimeout'              => 30,
            'mcs.blocklistDe.httpTimeout'           => 30,
            'mcs.greynoise.cacheTtl'                => 604800,
            'mcs.greynoise.httpTimeout'             => 15,
            'mcs.wazuh.oknoGodzin'                  => 48,
            'mcs.wazuh.minZdarzen'                  => 5,
            'mcs.wazuh.progHigh'                    => 20,
            'mcs.wazuh.timeout'                     => 60,
            'mcs.wazuh.connectTimeout'              => 10,
        ];

        foreach ($oczekiwane as $klucz => $wartosc) {
            if (env($klucz) !== null) {
                continue; // środowisko testowe nadpisuje — pomiń
            }
            if (is_string($wartosc) && ! preg_match('/^-?\d+$/', $wartosc)) {
                $this->assertSame($wartosc, OpcjeMcs::tekst($klucz), $klucz);
            } else {
                $this->assertSame((int) $wartosc, OpcjeMcs::int($klucz), $klucz);
            }
            $this->assertSame(OpcjeMcs::ZRODLO_DOMYSLNA, OpcjeMcs::odczyt($klucz)['zrodlo'], $klucz);
        }

        $this->assertTrue(OpcjeMcs::jestTak('mcs.dnsbl.listaUceprotect'));
        $this->assertSame('high', OpcjeMcs::tekst('mcs.kolejkaDnia.minWaga'));
        $this->assertSame('antyddos@play.pl', OpcjeMcs::tekst('mcs.play.fromAddress'));
        $this->assertSame('', OpcjeMcs::tekst('mcs.spoofer.lookbackDays'));
        $this->assertSame('', OpcjeMcs::tekst('mcs.spoofer.asn'));
    }

    public function testWrapperyZwracajaPoprzednieWartosci(): void
    {
        $this->assertSame(Badge::MAX_IPS_BATCH, Badge::maxIpsBatch());
        $this->assertSame(KolejkaDnia::OKNO_DNI, KolejkaDnia::oknoDni());
        $this->assertSame(KolejkaDnia::MIN_WAGA, KolejkaDnia::minWaga());
        $this->assertSame(ProblemyIp::OKNO_TIMELINE_DNI, ProblemyIp::oknoTimelineDni());
        $this->assertSame(SpeedtestSerwis::DOMYSLNY_TOP_N, SpeedtestSerwis::domyslnyTopN());
        $this->assertSame(As198050Matcher::ASN, As198050Matcher::asn());
        $this->assertSame(PlayParser::FROM_PLAY, PlayParser::nadawca());
        $this->assertSame('@play.pl', PlayParser::domenaNadawcy());

        $f = SpeedtestSerwis::parsujFiltry([]);
        $this->assertSame(500, $f['min_download_mbps']);
        $this->assertSame(500, $f['min_upload_mbps']);
    }

    public function testEnvNadpisujeDomyslna(): void
    {
        $this->ustawEnv('mcs.badge.maxIpsBatch', '250');

        $this->assertSame(250, Badge::maxIpsBatch());
        $this->assertSame(OpcjeMcs::ZRODLO_ENV, OpcjeMcs::odczyt('mcs.badge.maxIpsBatch')['zrodlo']);
    }

    public function testDbMaPierwszenstwoPrzedEnv(): void
    {
        $this->ustawEnv('mcs.wazuh.minZdarzen', '7');
        OpcjeMcs::ustawModel($this->modelZ(['mcs.wazuh.minZdarzen' => '12']));

        $this->assertSame(12, OpcjeMcs::int('mcs.wazuh.minZdarzen'));
        $this->assertSame(OpcjeMcs::ZRODLO_DB, OpcjeMcs::odczyt('mcs.wazuh.minZdarzen')['zrodlo']);
    }

    public function testNiepoprawnaWartoscDbSpadaDoEnvIDomyslnej(): void
    {
        OpcjeMcs::ustawModel($this->modelZ([
            'mcs.wazuh.minZdarzen'    => 'abc',
            'mcs.kolejkaDnia.oknoDni' => '5',
        ]));
        $this->ustawEnv('mcs.wazuh.minZdarzen', '7');

        $this->assertSame(7, OpcjeMcs::int('mcs.wazuh.minZdarzen'));
        $this->assertSame(2, KolejkaDnia::oknoDni());
    }

    public function testDbZWyjatkiemNieLamieOdczytu(): void
    {
        $model = $this->createMock(UstawieniaModel::class);
        $model->method('pobierzWiele')->willThrowException(new RuntimeException('brak tabeli'));
        OpcjeMcs::ustawModel($model);

        $this->assertSame(100, OpcjeMcs::int('mcs.badge.maxIpsBatch'));
    }

    public function testAsnGlobalnyZDbUzywanyPrzezPluginy(): void
    {
        OpcjeMcs::ustawModel($this->modelZ(['mcs.as198050.asn' => '65001']));

        $this->assertSame(65001, As198050Matcher::asn());
        $this->assertSame(65001, (new \McsPluginy\spoofer\Plugin())->resolveAsn());
    }

    public function testWalidacjaTypowIZakresow(): void
    {
        $this->assertTrue(OpcjeMcs::waliduj('mcs.badge.maxIpsBatch', '1000')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.badge.maxIpsBatch', '1001')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.badge.maxIpsBatch', '0')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.badge.maxIpsBatch', '10.5')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.badge.maxIpsBatch', '')['ok']);

        $this->assertSame('198050', OpcjeMcs::waliduj('mcs.as198050.asn', 'AS198050')['wartosc']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.as198050.asn', '0')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.as198050.asn', '4294967296')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.as198050.asn', '')['ok']);
        $this->assertTrue(OpcjeMcs::waliduj('mcs.spoofer.asn', '')['ok']);

        $this->assertTrue(OpcjeMcs::waliduj('mcs.play.fromAddress', 'Alert@Example.com')['ok']);
        $this->assertSame('alert@example.com', OpcjeMcs::waliduj('mcs.play.fromAddress', 'Alert@Example.com')['wartosc']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.play.fromAddress', 'nie-mail')['ok']);

        $this->assertTrue(OpcjeMcs::waliduj('mcs.kolejkaDnia.minWaga', 'critical')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.kolejkaDnia.minWaga', 'huge')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.kolejkaDnia.oknoDni', '3')['ok']);

        $this->assertTrue(OpcjeMcs::waliduj('mcs.spoofer.lookbackDays', '')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.spoofer.lookbackDays', '0')['ok']);

        $this->assertFalse(OpcjeMcs::waliduj('mcs.nieistnieje', '1')['ok']);
    }

    public function testNieMaSekretowAniSciezekWWww(): void
    {
        foreach (OpcjeMcs::definicje() as $klucz => $def) {
            if (($def['typ'] ?? '') === 'secret') {
                continue; // edycja poza formularzem Opcji (np. modal SNDS)
            }
            $this->assertDoesNotMatchRegularExpression(
                '/(token|key|pass|secret|user|host|url|path|file|dir|tls|verify|credential|email|mailbox|ssh)/i',
                str_replace(['fromAddress'], [''], $klucz),
                $klucz,
            );
        }

        $this->assertSame('secret', OpcjeMcs::definicje()['mcs.snds.key']['typ'] ?? null);
        $this->assertSame('secret', OpcjeMcs::definicje()['mcs.greynoise.apiKey']['typ'] ?? null);
        $this->assertSame('secret', OpcjeMcs::definicje()['mcs.urlhaus.authKey']['typ'] ?? null);
        foreach (OpcjeMcs::doWidoku() as $opcje) {
            $this->assertArrayNotHasKey('mcs.snds.key', $opcje);
            $this->assertArrayNotHasKey('mcs.greynoise.apiKey', $opcje);
            $this->assertArrayNotHasKey('mcs.urlhaus.authKey', $opcje);
        }
    }

    public function testSekretSndsUuidIPierwszenstwoDb(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $this->assertTrue(OpcjeMcs::waliduj('mcs.snds.key', $uuid)['ok']);
        $this->assertSame($uuid, OpcjeMcs::waliduj('mcs.snds.key', strtoupper($uuid))['wartosc']);
        $this->assertTrue(OpcjeMcs::waliduj('mcs.snds.key', '')['ok']);
        $this->assertFalse(OpcjeMcs::waliduj('mcs.snds.key', 'nie-uuid')['ok']);
        $this->assertSame('a1b2…7890', OpcjeMcs::skrotSekretu($uuid));

        $this->ustawEnv('mcs.snds.key', '11111111-2222-3333-4444-555555555555');
        OpcjeMcs::ustawModel($this->modelZ(['mcs.snds.key' => $uuid]));
        $this->assertSame($uuid, OpcjeMcs::tekst('mcs.snds.key'));
        $this->assertSame(OpcjeMcs::ZRODLO_DB, OpcjeMcs::odczyt('mcs.snds.key')['zrodlo']);
    }

    public function testKluczGreynoiseIUrlhausNieJestUuid(): void
    {
        foreach (['mcs.greynoise.apiKey', 'mcs.urlhaus.authKey'] as $klucz) {
            $this->assertTrue(OpcjeMcs::waliduj($klucz, '')['ok'], $klucz);
            $this->assertTrue(OpcjeMcs::waliduj($klucz, 'abcdEFGH1234')['ok'], $klucz);
            $this->assertFalse(OpcjeMcs::waliduj($klucz, 'krotki')['ok'], $klucz);
            $this->assertFalse(OpcjeMcs::waliduj($klucz, "ze\nspacja")['ok'], $klucz);
        }

        $this->ustawEnv('mcs.greynoise.apiKey', 'z-pliku-env-123');
        OpcjeMcs::ustawModel($this->modelZ(['mcs.greynoise.apiKey' => 'z-panelu-1234']));
        $this->assertSame('z-panelu-1234', OpcjeMcs::tekst('mcs.greynoise.apiKey'));
        $this->assertSame(OpcjeMcs::ZRODLO_DB, OpcjeMcs::odczyt('mcs.greynoise.apiKey')['zrodlo']);
    }

    public function testZapiszPustySekretUsuwaWierszDb(): void
    {
        $model = $this->modelZ(['mcs.snds.key' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890']);
        $model->expects($this->once())->method('delete')
            ->with('mcs.snds.key')->willReturn(true);
        $model->expects($this->never())->method('ustaw');

        $wynik = OpcjeMcs::zapisz($model, ['mcs.snds.key' => '']);
        $this->assertTrue($wynik['ok']);
        $this->assertSame(1, $wynik['zmienione']);
    }

    public function testZapiszWszystkoAlboNic(): void
    {
        $model = $this->modelZ([]);
        $model->expects($this->never())->method('ustaw');

        $wynik = OpcjeMcs::zapisz($model, [
            'mcs.badge.maxIpsBatch' => '200',
            'mcs.wazuh.minZdarzen'  => 'xx',
        ]);

        $this->assertFalse($wynik['ok']);
        $this->assertArrayHasKey('mcs.wazuh.minZdarzen', $wynik['bledy']);
    }

    public function testZapiszTylkoZmienioneIPrzywroc(): void
    {
        $model = $this->modelZ(['mcs.wazuh.progHigh' => '30']);
        $model->expects($this->once())->method('ustaw')
            ->with('mcs.badge.maxIpsBatch', '200')->willReturn(true);
        $model->expects($this->once())->method('delete')
            ->with('mcs.wazuh.progHigh')->willReturn(true);

        $wynik = OpcjeMcs::zapisz($model, [
            'mcs.badge.maxIpsBatch'     => '200',  // zmiana → zapis
            'mcs.lms.syncPageSize'      => '2000', // = domyślna → bez wiersza
            'mcs.kolejkaDnia.minWaga'   => 'high', // = domyślna → bez wiersza
            'mcs.wazuh.progHigh'        => '40',   // przywróć ma pierwszeństwo
        ], ['mcs.wazuh.progHigh']);

        $this->assertTrue($wynik['ok']);
        $this->assertSame(2, $wynik['zmienione']);
    }

    public function testWidokOpcjiMaPolaICsrf(): void
    {
        $html = view('admin/ustawienia/opcje', [
            'title'      => 'Opcje strojenia — MCS',
            'navAktywny' => 'ustawienia',
            'email'      => 'admin@example.com',
            'rola'       => 'admin',
            'komunikat'  => null,
            'blad'       => null,
            'bledy'      => [],
            'grupy'      => OpcjeMcs::doWidoku(),
        ]);

        $this->assertStringContainsString('name="opcja[mcs.badge.maxIpsBatch]"', $html);
        $this->assertStringContainsString('name="opcja[mcs.kolejkaDnia.minWaga]"', $html);
        $this->assertStringContainsString('csrf', $html);
        $this->assertStringNotContainsString('apiKey', $html);
        $this->assertStringNotContainsString('mcs.snds.key', $html);
        $this->assertStringNotContainsString('name="opcja[mcs.snds.key]"', $html);
    }
}
