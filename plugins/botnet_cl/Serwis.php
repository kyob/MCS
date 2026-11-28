<?php

namespace McsPluginy\botnet_cl;

use App\Libraries\ImportHarmonogram;
use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Siec\AsMatcher;
use App\Libraries\StanGodzinny;
use Throwable;

/**
 * Przebieg botnet.cl: raz na godzinę strona /asn/{asn}, filtr prefiksów AS, incydent per atak.
 */
final class Serwis
{
    public const ZRODLO = 'botnet_cl';

    private Klient $klient;

    private Parser $parser;

    private Dopasowanie $dopasowanie;

    private AsMatcher $matcher;

    private IncydentZapis $zapis;

    private ImportKoperta $koperta;

    private StanGodzinny $stan;

    private int $teraz;

    public function __construct(
        ?Klient $klient = null,
        ?AsMatcher $matcher = null,
        ?IncydentZapis $zapis = null,
        ?ImportKoperta $koperta = null,
        ?StanGodzinny $stan = null,
        ?int $teraz = null,
        ?Parser $parser = null,
        ?Dopasowanie $dopasowanie = null,
    ) {
        $this->klient      = $klient ?? new Klient();
        $this->parser      = $parser ?? new Parser();
        $this->dopasowanie = $dopasowanie ?? new Dopasowanie();
        $this->matcher     = $matcher ?? new AsMatcher();
        $this->zapis       = $zapis ?? new IncydentZapis();
        $this->koperta     = $koperta ?? new ImportKoperta();
        $this->stan        = $stan ?? new StanGodzinny();
        $this->teraz       = $teraz ?? time();
    }

    /**
     * @return array{status: string, msg: string, trafienia: int}
     */
    public function uruchom(): array
    {
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

        $asn     = Klient::asn();
        $pobrane = $this->klient->pobierz($asn);
        if (! $pobrane['ok']) {
            return $this->koniecBlad($zapisany['bledy_z_rzedu'], 'botnet.cl: ' . $pobrane['error']);
        }

        $sparsowane = $this->parser->parsuj($pobrane['body']);
        if (! $sparsowane['ok']) {
            return $this->koniecBlad($zapisany['bledy_z_rzedu'], 'botnet.cl: ' . $sparsowane['blad']);
        }

        $incydenty = $this->dopasowanie->incydenty($sparsowane['obserwacje'], $this->matcher->cidry());
        $importId  = null;
        try {
            $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, [
                'opis' => 'botnet.cl ASN ' . $asn,
                'url'  => $pobrane['url'],
            ]);
            if ($importId === null) {
                return $this->koniecBlad($zapisany['bledy_z_rzedu'], 'nie otwarto przebiegu importu');
            }
            $dane = [];
            foreach ($incydenty as $inc) {
                $dane[] = [
                    'zrodlo'           => self::ZRODLO,
                    'klucz_dedupu'     => self::ZRODLO . '|' . $inc['id'],
                    'podmiot_typ'      => 'ip',
                    'podmiot'          => $inc['ip'],
                    'zaobserwowano_at' => $inc['zaobserwowano_at'],
                    'waga'             => $inc['waga'],
                    'wersja_wagi'      => $inc['wersja'],
                    'kategoria'        => $inc['kategoria'],
                    'tytul'            => $inc['tytul'],
                    'port'             => $inc['port'],
                    'protokol'         => $inc['protokol'],
                    'import_id'        => $importId,
                    'id_w_zrodle'      => $inc['id'],
                    'payload'          => $inc['payload'],
                ];
            }
            if ($dane !== []) {
                $this->zapis->zapiszWiele($dane);
            }
            $this->koperta->zakonczOk($importId, [
                'asn'        => $asn,
                'obserwacje' => count($sparsowane['obserwacje']),
                'trafienia'  => count($incydenty),
            ]);
        } catch (Throwable $e) {
            if ($importId !== null) {
                try {
                    $this->koperta->zakonczBlad($importId, $e->getMessage());
                } catch (Throwable) {
                }
            }

            return $this->koniecBlad($zapisany['bledy_z_rzedu'], $e->getMessage());
        }

        $this->stan->zapisz(self::ZRODLO, $this->teraz, ImportHarmonogram::poSukcesie($this->teraz), 0);

        return [
            'status'    => 'ok',
            'msg'       => sprintf(
                'botnet.cl AS%d: obserwacje=%d, trafienia=%d',
                $asn,
                count($sparsowane['obserwacje']),
                count($incydenty),
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
}
