<?php

namespace App\Controllers;

use App\Libraries\IncydentSzczegoly;
use App\Models\IncydentModel;

/**
 * Szczegóły pojedynczego incydentu (timeline + tabela źródła) + triage FP (ukryj w UI).
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
                    'flashOk'    => null,
                    'flashBlad'  => null,
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
                    'flashOk'    => null,
                    'flashBlad'  => null,
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
            'flashOk'    => session()->getFlashdata('komunikat'),
            'flashBlad'  => session()->getFlashdata('blad'),
        ]);
    }

    /**
     * Triage FP: ukrywa pojedynczy incydent w UI (nie kasuje obserwacji / danych źródła).
     */
    public function ukryj(string $ip, int $id)
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP) || $id <= 0) {
            return redirect()->to('/')->with('blad', 'Nieprawidłowy adres IP lub identyfikator incydentu.');
        }

        $szczegoly = (new IncydentSzczegoly())->dlaIpIId($ip, $id);
        if ($szczegoly === null) {
            return redirect()->to('/ip/' . rawurlencode($ip))
                ->with('blad', 'Nie znaleziono widocznego incydentu do ukrycia.');
        }

        $ok = model(IncydentModel::class)->ukryjWUi($id);
        if (! $ok) {
            return redirect()->to('/ip/' . rawurlencode($ip) . '/incydent/' . $id)
                ->with('blad', 'Nie udało się ukryć incydentu.');
        }

        return redirect()->to('/ip/' . rawurlencode($ip))
            ->with('komunikat', "Ukryto incydent #{$id} w UI (false positive / triage).");
    }
}
