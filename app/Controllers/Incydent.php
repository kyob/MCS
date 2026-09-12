<?php

namespace App\Controllers;

use App\Libraries\IncydentSzczegoly;

/**
 * Szczegóły pojedynczego incydentu (timeline + tabela źródła).
 */
class Incydent extends BaseController
{
    public function show(string $ip, int $id)
    {
        $ip = trim($ip);
        $email = (string) session()->get('mcs_user_email');
        $rola  = (string) session()->get('mcs_user_rola');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP) || $id <= 0) {
            return $this->response->setStatusCode(400)->setBody(
                view('ip/incydent', [
                    'title'      => 'Incydent — MCS',
                    'navAktywny' => 'ip',
                    'ipNav'      => $ip,
                    'szukajIp'   => $ip,
                    'ip'         => $ip,
                    'email'      => $email,
                    'rola'       => $rola,
                    'blad'       => 'Nieprawidłowy adres IP lub identyfikator incydentu.',
                    'szczegoly'  => null,
                ]),
            );
        }

        $szczegoly = (new IncydentSzczegoly())->dlaIpIId($ip, $id);
        if ($szczegoly === null) {
            return $this->response->setStatusCode(404)->setBody(
                view('ip/incydent', [
                    'title'      => 'Incydent — MCS',
                    'navAktywny' => 'ip',
                    'ipNav'      => $ip,
                    'szukajIp'   => $ip,
                    'ip'         => $ip,
                    'email'      => $email,
                    'rola'       => $rola,
                    'blad'       => 'Nie znaleziono widocznego incydentu dla tego IP.',
                    'szczegoly'  => null,
                ]),
            );
        }

        $tytul = (string) ($szczegoly['incydent']['tytul'] ?? 'Incydent');

        return view('ip/incydent', [
            'title'      => $tytul . ' — MCS',
            'navAktywny' => 'ip',
            'ipNav'      => $ip,
            'szukajIp'   => $ip,
            'ip'         => $ip,
            'email'      => $email,
            'rola'       => $rola,
            'blad'       => null,
            'szczegoly'  => $szczegoly,
        ]);
    }
}
