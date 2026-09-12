<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\PobierzSerwis;
use App\Models\ImportModel;
use App\Models\PluginModel;

/**
 * Panel stanów wtyczek źródeł + „Pobierz teraz” + historia importów.
 * Chronione filtrem mcsAuth. v1: admin i operator mają ten sam dostęp.
 */
class Pluginy extends BaseController
{
    private const HISTORIA_LIMIT = 40;

    public function index()
    {
        return view('admin/pluginy/index', [
            'title'      => 'Wtyczki źródeł — MCS',
            'navAktywny' => 'pluginy',
            'pluginy'    => model(PluginModel::class)->wszystkie(),
            'importy'    => model(ImportModel::class)->ostatnie(self::HISTORIA_LIMIT),
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'komunikat'  => session()->getFlashdata('komunikat'),
            'blad'       => session()->getFlashdata('blad'),
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
