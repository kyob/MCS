<?php

use App\Libraries\MetrykiTekstu;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class MetrykiTekstuTest extends CIUnitTestCase
{
    public function testRozbijaKomunikatyZrodel(): void
    {
        $przypadki = [
            'DROP czysto: v4=1682 v6=91 asn=442, trafienia=0 (nowe=0, zdjęte=0), prefiksów=10, AS198050' => ['IPv4', 'ASN-DROP', 'Prefiksy', 'ASN'],
            'import API OK (asn=198050, tryb=full_report, api=0, zapisano=0, mapa=cloudflare-botnet-v0)' => ['ASN', 'Tryb', 'Zapisano'],
            'import Speedtest OK (tryb=portal Ookla, pliki=1, zapisano=244, obce=2149)'                   => ['portal Ookla', '1', '244'],
            'DNSBL OK zakres=lms sprawdzono=4802 listed=2 incydenty=2 strefy=uceprotect1,spamcop,dronebl | skaner: #51 recheck listowane=523 nowe=0 zdjete=0 incydenty=0' => ['uceprotect1 · spamcop · dronebl', '523'],
            'import OK (eksport=6583, AS198050=6, nowe/zmienione=0, incydenty=0, bez zmian)' => ['AS198050', 'Nowe / zmienione'],
            'import DataKey OK (6/10 dni, 0 wierszy); pominięte: 101526: SNDS HTTP 404 date=101526' => null,
            'import IMAP OK'                                                                               => null,
            'SNDS HTTP 404'                                                                                => null,
        ];

        foreach ($przypadki as $tekst => $oczekiwane) {
            $pary = MetrykiTekstu::pary($tekst);
            if ($oczekiwane === null) {
                $this->assertNull($pary, $tekst);
                continue;
            }
            $this->assertNotNull($pary, $tekst);
            $plasko = implode(' ', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pary));
            $this->assertStringNotContainsString('AS19805=', $plasko, $tekst);
            foreach ($oczekiwane as $fragment) {
                $this->assertStringContainsString($fragment, $plasko, $tekst);
            }
        }
    }
}
