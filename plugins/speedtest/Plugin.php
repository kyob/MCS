<?php

namespace McsPluginy\speedtest;

use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin enrichment Speedtest Servers reporting — CSV z account.ookla.com.
 *
 * NIE zapisuje do mcs_incydenty / badge. Historia: mcs_enrichment_speedtest.
 * UX: agregaty /24 (IPv4) i /48 (IPv6) na /dashboard/speedtest.
 * Źródło = testy do hostowanego serwera Ookla (Servers reporting), nie cały ruch klientów.
 *
 * Tryby pobierz() (priorytet):
 * 1) fixture CSV — mcs.speedtest.fixturePath
 * 2) portal Ookla — mcs.speedtest.email + password (Auth0 + /api/servers/reports?format=csv)
 * 3) katalog CSV — mcs.speedtest.csvDir
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private SpeedtestSerwis $serwis;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?SpeedtestSerwis $serwis = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new SpeedtestSerwis();
    }

    public function zrodlo(): string
    {
        return 'speedtest';
    }

    public function pobierz(): void
    {
        $checksum = $this->serwis->checksumZrodel();
        $importId = $this->koperta->rozpocznij($this->zrodlo(), $checksum, null, [
            'opis' => 'import CSV Speedtest Servers reporting',
        ]);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        try {
            $wynik = $this->serwis->sync();
            if (! $wynik['ok']) {
                $msg = (string) ($wynik['blad'] ?? 'błąd importu Speedtest');
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            $trybEtykieta = match ((string) $wynik['tryb']) {
                'fixture' => 'plik testowy',
                'portal'  => 'portal Ookla',
                'csv'     => 'katalog CSV',
                default   => (string) $wynik['tryb'],
            };
            $msg          = sprintf(
                'import Speedtest OK (tryb=%s, pliki=%d, zapisano=%d, obce=%d)',
                $trybEtykieta,
                $wynik['pliki'],
                $wynik['zapisano'],
                $wynik['pominieto_obce'],
            );
            $this->koperta->zakonczOk($importId, [
                'tryb'           => $wynik['tryb'],
                'pliki'          => $wynik['pliki'],
                'zapisano'       => $wynik['zapisano'],
                'pominieto_obce' => $wynik['pominieto_obce'],
            ]);
            $this->aktualizujHealth(true, $msg);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }
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
        $tryb = $this->serwis->tryb();

        return match ($tryb) {
            'fixture' => 'gotowy plik testowy (mcs.speedtest.fixturePath)',
            'portal'  => 'gotowy portal Ookla (mcs.speedtest.email)',
            'csv'     => 'gotowy katalog CSV (mcs.speedtest.csvDir)',
            default   => 'brak konfiguracji — ustaw mcs.speedtest.email+password, csvDir albo fixturePath',
        };
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_ok'  => $ok,
            'health_msg' => PluginOpisPolaczenia::bezZargonu($msg),
            'health_at'  => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
