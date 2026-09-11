<?php

namespace App\Controllers;

use App\Models\IncydentModel;

/**
 * Strona podmiotu IP — lista skrótów z indeksu mcs_incydenty (Task 6).
 */
class Ip extends BaseController
{
    public function show(string $ip)
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->response->setStatusCode(400)->setBody(
                view('ip/show', [
                    'ip'           => $ip,
                    'blad'         => 'Nieprawidłowy adres IP.',
                    'incydenty'    => [],
                    'zrodla'       => [],
                    'filtrZrodlo'  => null,
                    'email'        => (string) session()->get('mcs_user_email'),
                    'rola'         => (string) session()->get('mcs_user_rola'),
                ]),
            );
        }

        $filtrZrodlo = $this->request->getGet('zrodlo');
        $filtrZrodlo = is_string($filtrZrodlo) && $filtrZrodlo !== '' ? $filtrZrodlo : null;

        $model     = model(IncydentModel::class);
        $incydenty = $model->dlaPodmiotuIp($ip, $filtrZrodlo);
        $zrodla    = $model->zrodlaDlaPodmiotuIp($ip);

        return view('ip/show', [
            'ip'          => $ip,
            'blad'        => null,
            'incydenty'   => $incydenty,
            'zrodla'      => $zrodla,
            'filtrZrodlo' => $filtrZrodlo,
            'email'       => (string) session()->get('mcs_user_email'),
            'rola'        => (string) session()->get('mcs_user_rola'),
        ]);
    }
}
