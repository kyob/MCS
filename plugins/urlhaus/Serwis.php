<?php

namespace McsPluginy\urlhaus;

use App\Libraries\ImportHarmonogram;
use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\StanGodzinny;
use Throwable;

/**
 * Przebieg URLhaus: zrzut recent → host IP w prefiksach AS → jeden incydent na IP.
 */
final class Serwis
{
    public const ZRODLO = 'urlhaus';

    private Klient $klient;

    private As198050Matcher $matcher;

    private IncydentZapis $zapis;

    private ImportKoperta $koperta;

    private StanGodzinny $stan;

    private int $teraz;

    public function __construct(
        ?Klient $klient = null,
        ?As198050Matcher $matcher = null,
        ?IncydentZapis $zapis = null,
        ?ImportKoperta $koperta = null,
        ?StanGodzinny $stan = null,
        ?int $teraz = null,
    ) {
        $this->klient  = $klient ?? Klient::fromOpcje();
        $this->matcher = $matcher ?? new As198050Matcher();
        $this->zapis   = $zapis ?? new IncydentZapis();
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->stan    = $stan ?? new StanGodzinny();
        $this->teraz   = $teraz ?? time();
    }

    /**
     * @return array{status: string, msg: string, trafienia: int}
     */
    public function uruchom(): array
    {
        $klucz = trim(\App\Libraries\OpcjeMcs::tekst('mcs.urlhaus.authKey'), " \t\n\r\0\x0B'\"");
        if ($klucz === '') {
            return ['status' => 'blad', 'msg' => 'Brak klucza URLhaus. Wtyczki → URLhaus → Klucz.', 'trafienia' => 0];
        }

        if (! $this->matcher->jestWlaczone() || $this->matcher->cidry() === []) {
            return ['status' => 'blad', 'msg' => 'brak prefiksów AS', 'trafienia' => 0];
        }

        $zapisany = $this->stan->odczyt(self::ZRODLO);
        if (! ImportHarmonogram::czyPora($this->teraz, $zapisany['nastepne_po'], $zapisany['ostatnia_proba_at'])) {
            return [
                'status'    => 'pominieto',
                'msg'       => 'pominięto (harmonogram, min. 1 h)',
                'trafienia' => 0,
            ];
        }

        $pobrane = $this->klient->pobierz(Klient::szablonZEnv(), $klucz);
        if (! $pobrane['ok']) {
            return $this->koniecBlad($zapisany['bledy_z_rzedu'], $this->bezKlucza($pobrane['blad'], $klucz));
        }

        $parsed = (new Parser())->parsuj($pobrane['body']);
        if (! $parsed['ok']) {
            return $this->koniecBlad($zapisany['bledy_z_rzedu'], $parsed['blad']);
        }

        $czyNasze  = fn (string $ip): bool => $this->matcher->czyNaszeIp($ip);
        $incydenty = (new Skladacz())->incydenty($parsed['wiersze'], $czyNasze);
        $czas      = gmdate('Y-m-d H:i:sP', $this->teraz);
        $importId  = null;

        try {
            $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, ['opis' => 'URLhaus recent']);
            if ($importId === null) {
                return $this->koniecBlad($zapisany['bledy_z_rzedu'], 'nie otwarto przebiegu importu');
            }
            foreach ($incydenty as $inc) {
                $this->zapis->zapisz([
                    'zrodlo'           => self::ZRODLO,
                    'klucz_dedupu'     => self::ZRODLO . '|' . $inc['ip'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $inc['ip'],
                    'zaobserwowano_at' => $czas,
                    'waga'             => $inc['waga'],
                    'wersja_wagi'      => $inc['wersja'],
                    'kategoria'        => $inc['kategoria'],
                    'tytul'            => $inc['tytul'],
                    'import_id'        => $importId,
                    'payload'          => $inc['payload'],
                ]);
            }
            $this->koperta->zakonczOk($importId, [
                'trafienia'         => count($incydenty),
                'pominiete_domeny'  => $parsed['pominiete_domeny'],
            ]);
        } catch (Throwable $e) {
            if ($importId !== null) {
                try {
                    $this->koperta->zakonczBlad($importId, $this->bezKlucza($e->getMessage(), $klucz));
                } catch (Throwable) {
                }
            }

            return $this->koniecBlad($zapisany['bledy_z_rzedu'], $this->bezKlucza($e->getMessage(), $klucz));
        }

        $this->stan->zapisz(self::ZRODLO, $this->teraz, ImportHarmonogram::poSukcesie($this->teraz), 0);

        return [
            'status'    => 'ok',
            'msg'       => sprintf(
                'URLhaus: %d trafień w prefiksach, pominięte domeny %d',
                count($incydenty),
                $parsed['pominiete_domeny'],
            ),
            'trafienia' => count($incydenty),
        ];
    }

    /**
     * @return array{status: string, msg: string, trafienia: int}
     */
    private function koniecBlad(int $bledyDotad, string $msg): array
    {
        $bledy = $bledyDotad + 1;
        $this->stan->zapisz(self::ZRODLO, $this->teraz, ImportHarmonogram::poBledzie($this->teraz, $bledy), $bledy);

        return ['status' => 'blad', 'msg' => $msg, 'trafienia' => 0];
    }

    private function bezKlucza(string $msg, string $klucz): string
    {
        if ($klucz === '') {
            return $msg;
        }

        return str_replace([$klucz, rawurlencode($klucz)], '[klucz]', $msg);
    }
}
