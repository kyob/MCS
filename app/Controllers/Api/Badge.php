<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\IncydentModel;

/**
 * Agregat badge 7 dni dla LMS (token serwerowy — filtr mcsBadgeToken).
 */
class Badge extends BaseController
{
    public function index()
    {
        $ip = $this->request->getGet('ip');
        $ip = is_string($ip) ? trim($ip) : '';

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->response->setStatusCode(400)->setJSON([
                'blad' => 'Wymagany prawidłowy parametr ip.',
            ]);
        }

        $agregat = model(IncydentModel::class)->agregatBadgeDlaIp($ip);
        $baza    = rtrim((string) config('App')->baseURL, '/');

        return $this->response->setJSON([
            'ip'            => $ip,
            'okno_dni'      => $agregat['okno_dni'],
            'liczba'        => $agregat['liczba'],
            'max_waga'      => $agregat['max_waga'],
            'liczba_zrodel' => $agregat['liczba_zrodel'],
            'url'           => $baza . '/ip/' . rawurlencode($ip),
        ]);
    }
}
