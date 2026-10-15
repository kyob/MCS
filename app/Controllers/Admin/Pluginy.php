<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\OpcjeMcs;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\PluginRegistry;
use App\Libraries\PobierzSerwis;
use App\Models\ImportModel;
use App\Models\PluginModel;
use App\Models\UstawieniaModel;

/**
 * Panel stanów wtyczek źródeł + „Pobierz teraz” + podgląd historii importów.
 * Chronione filtrami mcsAuth + mcsRola (admin i superadmin).
 */
class Pluginy extends BaseController
{
    private const HISTORIA_PODGLAD = 8;

    private const HISTORIA_NA_STRONE = 25;

    public function index()
    {
        OpcjeMcs::ustawModel(model(UstawieniaModel::class));
        $sndsOdczyt = OpcjeMcs::odczyt('mcs.snds.key');

        return view('admin/pluginy/index', [
            'title'      => 'Wtyczki źródeł — MCS',
            'navAktywny' => 'pluginy',
            'pluginy'    => PluginOpisPolaczenia::dolaczDoWierszy(
                model(PluginModel::class)->wszystkie(),
                (new PluginRegistry())->zaladuj(),
            ),
            'importy'    => model(ImportModel::class)->ostatnie(self::HISTORIA_PODGLAD),
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'komunikat'  => session()->getFlashdata('komunikat'),
            'blad'       => session()->getFlashdata('blad'),
            'sndsKlucz'  => [
                'ustawiony' => $sndsOdczyt['wartosc'] !== '',
                'zrodlo'    => $sndsOdczyt['zrodlo'],
                'skrot'     => OpcjeMcs::skrotSekretu($sndsOdczyt['wartosc']),
            ],
            'spamhausSbl' => $this->kartaSpamhausSbl(),
        ]);
    }

    /**
     * Dane karty Spamhaus SBL (null = brak wtyczki / tabel — karta się nie pokazuje).
     *
     * @return array<string, mixed>|null
     */
    private function kartaSpamhausSbl(): ?array
    {
        if (! class_exists(\McsPluginy\spamhaus_sbl\Widok::class)) {
            return null;
        }
        try {
            return (new \McsPluginy\spamhaus_sbl\Widok())->dane();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pełna historia importów z paginacją i filtrami.
     */
    public function historiaImportow()
    {
        $zrodlo = trim((string) $this->request->getGet('zrodlo'));
        $status = trim((string) $this->request->getGet('status'));
        $q      = trim((string) $this->request->getGet('q'));
        $od     = trim((string) $this->request->getGet('od'));
        $do     = trim((string) $this->request->getGet('do'));

        $importModel = model(ImportModel::class);
        $zrodla      = $importModel->listaZrodel();
        $importy     = $importModel->szukaj(
            $zrodlo !== '' ? $zrodlo : null,
            $status !== '' ? $status : null,
            $q !== '' ? $q : null,
            $od !== '' ? $od : null,
            $do !== '' ? $do : null,
            self::HISTORIA_NA_STRONE,
        );

        return view('admin/pluginy/historia_importow', [
            'title'       => 'Historia importów — MCS',
            'navAktywny'  => 'pluginy',
            'importy'     => $importy,
            'pager'       => $importModel->pager,
            'zrodla'      => $zrodla,
            'filtrZrodlo' => $zrodlo,
            'filtrStatus' => $status,
            'filtrQ'      => $q,
            'filtrOd'     => $od,
            'filtrDo'     => $do,
            'email'       => (string) session()->get('mcs_user_email'),
            'rola'        => (string) session()->get('mcs_user_rola'),
        ]);
    }

    public function zapisz()
    {
        $zrodlo = trim((string) $this->request->getPost('zrodlo'));
        $importWstrzymany = $this->request->getPost('import_wstrzymany') === '1';
        $ukrytyWUi        = $this->request->getPost('ukryty_w_ui') === '1';

        if ($zrodlo === '') {
            return redirect()->to('/admin/pluginy')->with('blad', 'Brak identyfikatora źródła.');
        }

        $ok = model(PluginModel::class)->ustawStany($zrodlo, $importWstrzymany, $ukrytyWUi);

        if (! $ok) {
            return redirect()->to('/admin/pluginy')->with(
                'blad',
                'Nie udało się zapisać stanów dla źródła: ' . $zrodlo,
            );
        }

        return redirect()->to('/admin/pluginy')->with(
            'komunikat',
            'Zapisano stany wtyczki: ' . $zrodlo,
        );
    }

    /**
     * Zapis UUID Automated Data Access SNDS (override w mcs_ustawienia).
     * Puste pole = usuń override (wraca mcs.snds.key z .env).
     */
    public function zapiszKluczSnds()
    {
        $klucz = trim((string) $this->request->getPost('mcs_snds_key'));
        $wynik = OpcjeMcs::zapisz(
            model(UstawieniaModel::class),
            ['mcs.snds.key' => $klucz],
        );

        if (! $wynik['ok']) {
            $blad = $wynik['bledy']['mcs.snds.key']
                ?? ($wynik['bledy']['_'] ?? 'Nie udało się zapisać klucza SNDS.');

            return redirect()->to('/admin/pluginy')->with('blad', $blad);
        }

        if ($klucz === '') {
            return redirect()->to('/admin/pluginy')->with(
                'komunikat',
                'Usunięto klucz SNDS z bazy — obowiązuje wartość z .env (jeśli jest). Uruchom „Pobierz teraz”, żeby sprawdzić import.',
            );
        }

        return redirect()->to('/admin/pluginy')->with(
            'komunikat',
            'Zapisano klucz SNDS. Uruchom „Pobierz teraz”, żeby sprawdzić import.',
        );
    }

    /**
     * Ręczne „Pobierz teraz” — zleca `php spark mcs:pobierz <źródło>` w tle (bez czekania na koniec).
     */
    public function pobierz()
    {
        $zrodlo = trim((string) $this->request->getPost('zrodlo'));
        if ($zrodlo === '') {
            return redirect()->to('/admin/pluginy')->with('blad', 'Brak identyfikatora źródła.');
        }

        $w      = $this->serwisPobierz()->zlecWTle($zrodlo);
        $status = (string) ($w['status'] ?? '');
        $msg    = (string) ($w['msg'] ?? '');

        return match ($status) {
            'zlecono' => redirect()->to('/admin/pluginy')->with(
                'komunikat',
                'Zlecono pobieranie w tle: ' . $zrodlo . '. Wynik: kolumna statusu źródła.',
            ),
            'zajety' => redirect()->to('/admin/pluginy')->with('blad', 'Pobieranie już trwa: ' . $zrodlo),
            'pominiety' => redirect()->to('/admin/pluginy')->with('blad', 'Import wstrzymany — ' . $zrodlo . ': ' . $msg),
            default => redirect()->to('/admin/pluginy')->with('blad', 'Błąd pobierania — ' . $zrodlo . ': ' . $msg),
        };
    }

    protected function serwisPobierz(): PobierzSerwis
    {
        return new PobierzSerwis();
    }
}
