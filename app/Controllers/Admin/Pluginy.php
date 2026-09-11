<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PluginModel;

/**
 * Panel stanów wtyczek źródeł (Task 8).
 * Chronione filtrem mcsAuth. v1: admin i operator widzą i zapisują to samo.
 */
class Pluginy extends BaseController
{
    public function index()
    {
        return view('admin/pluginy/index', [
            'pluginy'   => model(PluginModel::class)->wszystkie(),
            'email'     => (string) session()->get('mcs_user_email'),
            'rola'      => (string) session()->get('mcs_user_rola'),
            'komunikat' => session()->getFlashdata('komunikat'),
            'blad'      => session()->getFlashdata('blad'),
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
}
