<?php

namespace App\Controllers;

use App\Libraries\Siec\As198050Matcher;
use App\Models\WatchlistaModel;

/**
 * Watchlista obserwowanych IP + podgląd digesta (tylko INFO).
 */
class Watchlista extends BaseController
{
    public function index()
    {
        $model   = model(WatchlistaModel::class);
        $lista   = $model->wszystkie();
        $matcher = new As198050Matcher();

        foreach ($lista as &$wiersz) {
            $ip                 = (string) ($wiersz['ip'] ?? '');
            $wiersz['nasze_ip'] = $ip !== '' && $matcher->czyNaszeIp($ip);
        }
        unset($wiersz);

        return view('watchlista/index', [
            'title'      => 'Watchlista IP — MCS',
            'navAktywny' => 'watchlista',
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'komunikat'  => session()->getFlashdata('komunikat'),
            'blad'       => session()->getFlashdata('blad'),
            'lista'      => $lista,
            'asAsn'      => As198050Matcher::ASN,
        ]);
    }

    public function dodaj()
    {
        $ip      = trim((string) $this->request->getPost('ip'));
        $notatka = trim((string) $this->request->getPost('notatka'));
        $email   = (string) session()->get('mcs_user_email');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return redirect()->to('/watchlista')->with('blad', 'Podaj prawidłowy adres IPv4 lub IPv6.');
        }

        $model = model(WatchlistaModel::class);
        if ($model->czyObserwowane($ip)) {
            return redirect()->to('/watchlista')->with('blad', "IP {$ip} jest już na watchliście.");
        }

        if (! $model->dodaj($ip, $notatka !== '' ? $notatka : null, $email !== '' ? $email : null)) {
            return redirect()->to('/watchlista')->with('blad', 'Nie udało się dodać IP do watchlisty.');
        }

        return redirect()->to('/watchlista')->with(
            'komunikat',
            "Dodano {$ip} do watchlisty (tylko INFO — bez auto-blokad).",
        );
    }

    public function usun()
    {
        $ip = trim((string) $this->request->getPost('ip'));
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return redirect()->to('/watchlista')->with('blad', 'Nieprawidłowy adres IP do usunięcia.');
        }

        $model = model(WatchlistaModel::class);
        if (! $model->usun($ip)) {
            return redirect()->to('/watchlista')->with('blad', "Nie znaleziono {$ip} na watchliście.");
        }

        return redirect()->to('/watchlista')->with('komunikat', "Usunięto {$ip} z watchlisty.");
    }
}
