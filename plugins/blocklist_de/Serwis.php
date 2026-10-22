<?php

namespace McsPluginy\blocklist_de;

use App\Libraries\ImportHarmonogram;
use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\StanGodzinny;
use Throwable;

/**
 * Przebieg blocklist.de: harmonogram → listy → filtr AS → incydenty.
 */
final class Serwis
{
    public const ZRODLO = 'blocklist_de';

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
        $listy = Listy::parsuj((string) env('mcs.blocklistDe.listy', ''));
        if (! $listy['ok']) {
            return ['status' => 'blad', 'msg' => $listy['blad'], 'trafienia' => 0];
        }

        if (! $this->matcher->jestWlaczone() || $this->matcher->cidry() === []) {
            return [
                'status'    => 'blad',
                'msg'       => 'brak prefiksów AS',
                'trafienia' => 0,
            ];
        }

        $zapisany = $this->stan->odczyt(self::ZRODLO);
        if (! ImportHarmonogram::czyPora($this->teraz, $zapisany['nastepne_po'], $zapisany['ostatnia_proba_at'])) {
            return [
                'status'    => 'pominieto',
                'msg'       => 'pominięto (harmonogram, min. 1 h)',
                'trafienia' => 0,
            ];
        }

        $pobrane = $this->klient->pobierz($listy['listy']);
        if (! $pobrane['ok']) {
            return $this->koniecBlad($zapisany['bledy_z_rzedu'], $pobrane['blad']);
        }

        $czyNasze = fn (string $ip): bool => $this->matcher->czyNaszeIp($ip);
        $incydenty = (new Skladacz())->incydenty($pobrane['listy'], $czyNasze);
        $czas      = gmdate('Y-m-d H:i:sP', $this->teraz);

        $importId = null;
        try {
            $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, ['opis' => 'blocklist.de']);
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
                'trafienia' => count($incydenty),
                'md5_brak'  => $pobrane['md5_brak'],
                'md5_stare' => $pobrane['md5_stare'],
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
        $uwagi = [];
        if ($pobrane['md5_brak'] !== []) {
            $uwagi[] = 'bez MD5: ' . implode(', ', $pobrane['md5_brak']);
        }
        if ($pobrane['md5_stare'] !== []) {
            $uwagi[] = 'MD5 starsze niż lista: ' . implode(', ', $pobrane['md5_stare']);
        }
        $msg = sprintf(
            'blocklist.de: %d trafień w prefiksach%s',
            count($incydenty),
            $uwagi === [] ? '' : ' (' . implode('; ', $uwagi) . ')',
        );

        return ['status' => 'ok', 'msg' => $msg, 'trafienia' => count($incydenty)];
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
