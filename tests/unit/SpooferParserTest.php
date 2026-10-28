<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\spoofer\ApiClient;
use McsPluginy\spoofer\Parser;

/**
 * @internal
 */
final class SpooferParserTest extends CIUnitTestCase
{
    public function testMapujeTylkoRodzineAs198050(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujSesje([
            'session'     => 2228539,
            'client4'     => '91.231.70.0/24',
            'client6'     => '2a00:c020:8000::/40',
            'timestamp'   => '2026-09-07T06:46:56+00:00',
            'asn4'        => '198050',
            'asn6'        => '51175',
            'routedspoof' => 'blocked',
            'routedspoof6'=> 'received',
        ], 198050);

        $this->assertCount(1, $mapped);
        $this->assertSame('ip', $mapped[0]['podmiot_typ']);
        $this->assertSame('91.231.70.0', $mapped[0]['podmiot']);
        $this->assertSame('91.231.70.0/24', $mapped[0]['prefix']);
        $this->assertSame('v4', $mapped[0]['ip_wersja']);
        $this->assertSame('session|2228539|v4', $mapped[0]['klucz_dedupu']);
        $this->assertSame('blocked', $mapped[0]['routedspoof']);
        $this->assertSame('other', $mapped[0]['kategoria']);
        $this->assertSame('low', $mapped[0]['severity_sygnal']);
    }

    public function testPomijaSesjeInnegoAsn(): void
    {
        $parser = new Parser();
        $this->assertSame([], $parser->mapujSesje([
            'session'     => 54306,
            'client4'     => '84.247.77.0/24',
            'asn4'        => '12310',
            'timestamp'   => '2016-02-09T14:49:10+00:00',
            'routedspoof' => 'received',
        ], 198050));
    }

    public function testMapujeReceivedJakoAbuse(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujSesje([
            'session'     => 2230001,
            'client4'     => '5.253.230.0/24',
            'asn4'        => 198050,
            'timestamp'   => '2026-09-01T13:12:32+00:00',
            'routedspoof' => 'received',
        ], 198050);

        $this->assertCount(1, $mapped);
        $this->assertSame('abuse', $mapped[0]['kategoria']);
        $this->assertSame('high', $mapped[0]['severity_sygnal']);
        $this->assertSame('spoofer-received', $mapped[0]['tag']);
        $this->assertStringContainsString('received', $mapped[0]['tytul']);
        $this->assertStringContainsString('5.253.230.0/24', $mapped[0]['tytul']);
    }

    public function testMapujeTylkoIpv6GdyAsn6Pasuje(): void
    {
        $parser = new Parser();
        $mapped = $parser->mapujSesje([
            'session'      => 2226472,
            'client6'      => '2a0f:1700::/40',
            'asn6'         => '198050',
            'timestamp'    => '2026-09-03T07:43:26+00:00',
            'routedspoof6' => 'blocked',
        ], 198050);

        $this->assertCount(1, $mapped);
        $this->assertSame('v6', $mapped[0]['ip_wersja']);
        $this->assertSame('ip', $mapped[0]['podmiot_typ']);
        $this->assertSame('2a0f:1700::', $mapped[0]['podmiot']);
        $this->assertSame('2a0f:1700::/40', $mapped[0]['prefix']);
        $this->assertSame('session|2226472|v6', $mapped[0]['klucz_dedupu']);
    }

    public function testParsujFixtureHydraIOdfiltrujAsn(): void
    {
        $sciezka = ROOTPATH . 'tests/_support/fixtures/spoofer_sample.json';
        $this->assertFileExists($sciezka);
        $parser   = new Parser();
        $sessions = $parser->parsujJson((string) file_get_contents($sciezka));
        $this->assertCount(5, $sessions);

        $mapped = $parser->mapujListe($sessions, 198050);
        $this->assertCount(4, $mapped);
        $klucze = array_column($mapped, 'klucz_dedupu');
        $this->assertContains('session|2232992|v4', $klucze);
        $this->assertContains('session|2228539|v4', $klucze);
        $this->assertContains('session|2226472|v6', $klucze);
        $this->assertContains('session|2230001|v4', $klucze);
        $this->assertNotContains('session|54306|v4', $klucze);
        $this->assertNotContains('session|2228539|v6', $klucze);
    }

    public function testApiClientFetchSessionsPaginujeIFiltrujeAsn(): void
    {
        $page1 = json_encode([
            'hydra:member' => [
                [
                    'session'     => 1,
                    'client4'     => '91.231.70.0/24',
                    'asn4'        => '198050',
                    'timestamp'   => '2026-09-14T18:19:47+00:00',
                    'routedspoof' => 'blocked',
                ],
            ],
            'hydra:view' => [
                'hydra:next' => '/sessions?asn=198050&page=2',
            ],
        ], JSON_THROW_ON_ERROR);
        $page2 = json_encode([
            'hydra:member' => [
                [
                    'session'     => 2,
                    'client4'     => '5.253.230.0/24',
                    'asn4'        => '198050',
                    'timestamp'   => '2026-09-01T13:12:32+00:00',
                    'routedspoof' => 'received',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $seen   = [];
        $client = new ApiClient(
            'https://api.spoofer.caida.org',
            true,
            static function (string $url, array $meta) use ($page1, $page2, &$seen): array {
                $seen[] = $url;

                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'body'      => str_contains($url, 'page=2') ? $page2 : $page1,
                    'error'     => '',
                ];
            },
        );

        $res = $client->fetchSessions(198050);
        $this->assertTrue($res['ok']);
        $this->assertCount(2, $res['sessions']);
        $this->assertSame(1, $res['sessions'][0]['session']);
        $this->assertCount(2, $seen);
        $this->assertStringContainsString('/sessions?', $seen[0]);
        $this->assertStringContainsString('asn=198050', $seen[0]);
        $this->assertStringContainsString('page=2', $seen[1]);
    }

    public function testPluginOpisPolaczeniaNieWymagaKluczaIPokazujeAsn(): void
    {
        $plugin = new \McsPluginy\spoofer\Plugin();
        $this->assertSame('spoofer', $plugin->zrodlo());
        $this->assertSame(198050, $plugin->resolveAsn());

        $opis = $plugin->opisPolaczenia();
        $this->assertSame('http', $opis['tryb_aktywny']);
        $this->assertStringContainsString('api.spoofer.caida.org', $opis['skrot']);
        $json = json_encode($opis, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertDoesNotMatchRegularExpression('/tryb=fixture/', $json);

        $asnPozycja = null;
        foreach ($opis['pozycje'] as $p) {
            if ($p['nazwa'] === 'ASN') {
                $asnPozycja = $p;
                break;
            }
        }
        $this->assertNotNull($asnPozycja);
        $this->assertSame('198050', $asnPozycja['wartosc']);
    }

    public function testHydraNextNaObcyHostJestOdrzucony(): void
    {
        $zapytania = [];
        $client    = new \McsPluginy\spoofer\ApiClient(
            'https://api.example.test',
            true,
            static function (string $url) use (&$zapytania): array {
                $zapytania[] = $url;

                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'body'      => json_encode(['hydra:member' => [], 'hydra:view' => ['hydra:next' => 'http://169.254.169.254/latest']]),
                    'error'     => '',
                ];
            },
        );

        $w = $client->fetchSessions(198050);

        $this->assertFalse($w['ok']);
        $this->assertStringContainsString('inny host', $w['error']);
        $this->assertCount(1, $zapytania, 'drugie zapytanie na obcy host nie może wyjść');
    }

    public function testHydraNextWzgledneIWTymSamymHosciePrzechodzi(): void
    {
        $zapytania = [];
        $strony    = [
            ['hydra:view' => ['hydra:next' => '/sessions?page=2']],
            ['hydra:view' => ['hydra:next' => 'https://API.example.test/sessions?page=3']],
            ['hydra:member' => []],
        ];
        $client = new \McsPluginy\spoofer\ApiClient(
            'https://api.example.test',
            true,
            static function (string $url) use (&$zapytania, &$strony): array {
                $zapytania[] = $url;

                return ['ok' => true, 'http_code' => 200, 'body' => json_encode(array_shift($strony) + ['hydra:member' => []]), 'error' => ''];
            },
        );

        $w = $client->fetchSessions(198050);

        $this->assertTrue($w['ok']);
        $this->assertCount(3, $zapytania);
    }
}
