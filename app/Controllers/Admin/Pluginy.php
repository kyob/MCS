<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\PluginRegistry;
use App\Libraries\PobierzSerwis;
use App\Models\ImportModel;
use App\Models\PluginModel;

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
        ]);
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
     * Ręczne „Pobierz teraz” — ta sama logika co `php spark mcs:pobierz`.
     */
    public function pobierz()
    {
        $zrodlo = trim((string) $this->request->getPost('zrodlo'));
        if ($zrodlo === '') {
            return redirect()->to('/admin/pluginy')->with('blad', 'Brak identyfikatora źródła.');
        }

        $wyniki = (new PobierzSerwis())->uruchom($zrodlo);
        $w      = $wyniki[0] ?? null;
        if (! is_array($w)) {
            return redirect()->to('/admin/pluginy')->with('blad', 'Brak wyniku pobierania.');
        }

        $status = (string) ($w['status'] ?? '');
        $msg    = (string) ($w['msg'] ?? '');
        $etykieta = $zrodlo . ': ' . $msg;

        return match ($status) {
            'ok' => redirect()->to('/admin/pluginy')->with('komunikat', 'Pobrano — ' . $etykieta),
            'pominiety' => redirect()->to('/admin/pluginy')->with(
                'blad',
                'Import wstrzymany — ' . $etykieta,
            ),
            'zajety' => redirect()->to('/admin/pluginy')->with(
                'blad',
                'Pobieranie zajęte — ' . $etykieta,
            ),
            default => redirect()->to('/admin/pluginy')->with('blad', 'Błąd pobierania — ' . $etykieta),
        };
    }
}
