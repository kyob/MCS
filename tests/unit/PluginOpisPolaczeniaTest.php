<?php

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PluginOpisPolaczeniaTest extends CIUnitTestCase
{
    public function testHostZUrlWycinaAdresInstalacji(): void
    {
        $this->assertSame(
            '192.0.2.60',
            PluginOpisPolaczenia::hostZUrl('http://192.0.2.60/plugins/LMSMcsMapowaniePlugin/api.php'),
        );
    }

    public function testPustaPozycjaMaEtykieteNieUstawione(): void
    {
        $p = PluginOpisPolaczenia::pozycja('HTTP LMS', '', false);

        $this->assertSame('nie ustawione', $p['wartosc']);
        $this->assertFalse($p['ustawiona']);
        $this->assertFalse($p['aktywna']);
    }

    public function testSekretNigdyNieZwracaTresci(): void
    {
        $this->assertSame('ustawiony', PluginOpisPolaczenia::sekret(true));
        $this->assertSame('brak', PluginOpisPolaczenia::sekret(false));
    }

    public function testDolaczDoWierszyDokladaOpisZPluginu(): void
    {
        $plugin = new class () implements SourcePluginInterface {
            public function zrodlo(): string
            {
                return 'lms_mapowanie';
            }

            public function pobierz(): void
            {
            }

            public function health(): array
            {
                return ['ok' => true, 'msg' => 'ok'];
            }

            public function opisPolaczenia(): array
            {
                return PluginOpisPolaczenia::zloz(
                    'plik_testowy',
                    'plik testowy (JSON)',
                    'plik testowy · sample.json',
                    [
                        PluginOpisPolaczenia::pozycja('Plik testowy (JSON)', '/tmp/sample.json', true),
                    ],
                );
            }
        };

        $wiersze = PluginOpisPolaczenia::dolaczDoWierszy(
            [['zrodlo' => 'lms_mapowanie', 'nazwa' => 'LMS']],
            [$plugin],
        );

        $this->assertSame('plik testowy · sample.json', $wiersze[0]['opis_polaczenia']['skrot']);
        $this->assertSame('plik testowy (JSON)', $wiersze[0]['opis_polaczenia']['tryb_etykieta']);
    }

    public function testBezZargonuZastepujeFixtureWKomunikacie(): void
    {
        $this->assertSame(
            'sync LMS OK (tryb=plik testowy, zapisano=1)',
            PluginOpisPolaczenia::bezZargonu('sync LMS OK (tryb=fixture, zapisano=1)'),
        );
    }
}
