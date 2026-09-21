<?php

namespace McsPluginy\lms_mapowanie;

use App\Libraries\Enrichment\LmsMapowanieSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin enrichment LMS — mapowanie IP → komputer (node) + abonent (customer).
 *
 * NIE zapisuje do mcs_incydenty / badge. Cache: mcs_enrichment_lms.
 *
 * Tryby pobierz() (sync zbiorczy):
 * 1) fixture JSON — mcs.lms.fixturePath
 * 2) HTTP API LMS — mcs.lms.baseUrl + mapToken (scaffold LMSMcsMapowaniePlugin)
 * 3) bezpośredni odczyt DB LMS — mcs.lms.db* (gdy firewall pozwala)
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private LmsMapowanieSerwis $serwis;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?LmsMapowanieSerwis $serwis = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new LmsMapowanieSerwis();
    }

    public function zrodlo(): string
    {
        return 'lms_mapowanie';
    }

    public function pobierz(): void
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, [
            'opis' => 'sync mapowania LMS IP→komputer/abonent',
        ]);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        try {
            $wynik = $this->serwis->sync();
            if (! $wynik['ok']) {
                $msg = (string) ($wynik['blad'] ?? 'błąd sync LMS');
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            $msg = sprintf(
                'sync LMS OK (tryb=%s, zapisano=%d)',
                $wynik['tryb'],
                $wynik['zapisano'],
            );
            $this->koperta->zakonczOk($importId, [
                'zapisano'   => $wynik['zapisano'],
                'trafionych' => $wynik['trafionych'],
                'tryb'       => $wynik['tryb'],
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
            'fixture' => 'gotowy fixture (mcs.lms.fixturePath)',
            'api'     => 'gotowy HTTP (mcs.lms.baseUrl + mapToken)',
            'db'      => 'gotowy DB LMS (mcs.lms.db*)',
            default   => 'brak konfiguracji — ustaw baseUrl+mapToken, db* albo fixturePath',
        };
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_ok'  => $ok,
            'health_msg' => $msg,
            'health_at'  => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
