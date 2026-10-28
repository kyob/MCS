<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ZdrowieWtyczek;
use App\Models\PluginModel;

/**
 * Zdrowie wtyczek-importerów dla monitoringu (token serwerowy — filtr mcsBadgeToken).
 *
 * 200 = OK. 503 = błąd lub brak świeżego przebiegu (opis w polu `opis`).
 */
class Health extends BaseController
{
    public function index()
    {
        try {
            $wynik = ZdrowieWtyczek::ocen(model(PluginModel::class)->wszystkie());
        } catch (\Throwable) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'UNKNOWN',
                'opis'   => 'Nie można odczytać stanu wtyczek.',
            ]);
        }

        return $this->response
            ->setStatusCode($wynik['status'] === 'OK' ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON($wynik);
    }
}
