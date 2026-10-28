<?php

namespace McsPluginy\dnsbl;

use App\Libraries\Dnsbl\HarmonogramSkanera;
use App\Libraries\Enrichment\DnsblClient;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\SourcePluginInterface;
use App\Libraries\OpcjeMcs;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin stream DNSBL — dwa mechanizmy:
 * 1) stary (DnsblSerwis): skan LMS (codziennie) / pełne AS (dzień asScanWeekday), 3 strefy, enrichment na /ip/{ip};
 * 2) skaner E3 (HarmonogramSkanera): pełny skan AS nocą + recheck aktywnych listingów co godzinę, w tle.
 * Oba tworzą incydenty z tym samym kluczem dedupu ip|lista (zrodlo=dnsbl).
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private DnsblSerwis $serwis;
    private DnsblClient $client;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?DnsblSerwis $serwis = null,
        ?DnsblClient $client = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new DnsblSerwis();
        $this->client  = $client ?? DnsblClient::fromEnv();
    }

    public function zrodlo(): string
    {
        return 'dnsbl';
    }

    public function pobierz(): void
    {
        $e3 = $this->krokSkanera();

        $zakres = $this->serwis->wybierzZakres();

        // Stary skan odpytuje wszystkie IP (4805 IP × strefy, ok. 23 min), więc nie może iść przy
        // każdym przebiegu crona. Odstęp liczony osobno dla zakresu lms i as.
        $interwal = max(1, OpcjeMcs::int('mcs.dnsbl.skanLmsCoGodzin'));
        if ($zakres !== 'none' && ! self::czyPoraNaStarySkan($zakres, $this->ostatnieUdaneSkany(), time(), $interwal)) {
            $this->aktualizujHealth(
                ! $e3['fala'],
                $this->zHealthSkanera('skan ' . $zakres . ': pominięto (odstęp ' . $interwal . ' h)', $e3),
            );

            return;
        }

        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, [
            'opis'   => 'skan DNSBL zakres=' . $zakres,
            'zakres' => $zakres,
        ]);
        if ($importId === null) {
            $this->aktualizujHealth(! $e3['fala'], $this->zHealthSkanera('pominięto (duplikat przebiegu)', $e3));

            return;
        }

        try {
            $wynik = $this->serwis->skanuj($importId, $zakres);
            $msg   = sprintf(
                'DNSBL OK zakres=%s sprawdzono=%d listed=%d incydenty=%d strefy=%s',
                $wynik['zakres'],
                $wynik['sprawdzono'],
                $wynik['listed'],
                $wynik['incydenty'],
                implode(',', array_keys($this->client->aktywneStrefy())),
            );
            $this->koperta->zakonczOk($importId, [
                'zakres'     => $wynik['zakres'],
                'sprawdzono' => $wynik['sprawdzono'],
                'listed'     => $wynik['listed'],
                'incydenty'  => $wynik['incydenty'],
            ]);
            $this->aktualizujHealth(! $e3['fala'], $this->zHealthSkanera($msg, $e3));
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Czy minął odstęp od ostatniego udanego skanu tego samego zakresu (5 min luzu na rozjazd crona).
     *
     * @param list<array{ts: int, zakres: string}> $ostatnieUdane od najnowszego
     */
    public static function czyPoraNaStarySkan(string $zakres, array $ostatnieUdane, int $teraz, int $interwalGodzin): bool
    {
        foreach ($ostatnieUdane as $s) {
            if ($s['zakres'] === $zakres) {
                return $teraz - $s['ts'] >= max(1, $interwalGodzin) * 3600 - 300;
            }
        }

        return true;
    }

    /**
     * Ostatnie udane przebiegi starego skanu (mcs_importy), od najnowszego.
     *
     * @return list<array{ts: int, zakres: string}>
     */
    private function ostatnieUdaneSkany(): array
    {
        $wiersze = Database::connect()->table('mcs_importy')
            ->select('rozpoczeto_at, meta_json')
            ->where('zrodlo', $this->zrodlo())
            ->where('status', 'ok')
            ->orderBy('rozpoczeto_at', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($wiersze as $w) {
            $meta   = json_decode((string) ($w['meta_json'] ?? ''), true);
            $zakres = is_array($meta) ? (string) ($meta['zakres'] ?? '') : '';
            $ts     = strtotime((string) ($w['rozpoczeto_at'] ?? ''));
            if ($zakres !== '' && $ts !== false) {
                $out[] = ['ts' => $ts, 'zakres' => $zakres];
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, msg: string}
     */
    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        $ok  = array_key_exists('health_ok', $wiersz) ? $wiersz['health_ok'] : null;
        $msg = (string) ($wiersz['health_msg'] ?? '');

        if ($msg === '' && $ok === null) {
            $msg = $this->opisKonfiguracji();
        }

        return [
            'ok'  => (bool) ($ok ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
        ];
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        return $this->serwis->opisPolaczenia();
    }

    private function opisKonfiguracji(): string
    {
        if ($this->serwis->hasFixture()) {
            return 'gotowy plik testowy (mcs.dnsbl.fixturePath)';
        }

        $strefy = implode(', ', array_keys($this->client->aktywneStrefy()));

        return 'DNSBL strefy: ' . ($strefy !== '' ? $strefy : 'brak');
    }

    /**
     * Harmonogram skanera E3. Błąd nie przerywa starego mechanizmu.
     *
     * @return array{msg: string, fala: bool}
     */
    private function krokSkanera(): array
    {
        try {
            $h    = new HarmonogramSkanera();
            $krok = $h->krok();
            $stan = $h->stanOstatniego();

            return ['msg' => $stan['msg'] . ($krok !== '' ? '; ' . $krok : ''), 'fala' => $stan['fala']];
        } catch (\Throwable $e) {
            log_message('error', 'dnsbl skaner E3: ' . $e->getMessage());

            return ['msg' => 'skaner: błąd harmonogramu: ' . $e->getMessage(), 'fala' => false];
        }
    }

    /**
     * @param array{msg: string, fala: bool} $e3
     */
    private function zHealthSkanera(string $msg, array $e3): string
    {
        return $e3['fala'] ? $e3['msg'] . ' | ' . $msg : $msg . ' | ' . $e3['msg'];
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
            'health_at'  => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
