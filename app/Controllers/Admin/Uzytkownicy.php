<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\UzytkownicySerwis;

/**
 * Panel kont MCS — tylko superadmin (filtr mcsRola).
 */
class Uzytkownicy extends BaseController
{
    public function index()
    {
        return view('admin/uzytkownicy/index', $this->daneWidoku());
    }

    public function dodaj()
    {
        $wynik = $this->serwis()->utworz(
            (string) $this->request->getPost('email'),
            (string) $this->request->getPost('haslo'),
            (string) $this->request->getPost('rola'),
        );

        return $this->poZmianie($wynik, 'Dodano konto.');
    }

    public function zmienRole()
    {
        $wynik = $this->serwis()->zmienRole(
            (int) $this->request->getPost('id'),
            (string) $this->request->getPost('rola'),
        );

        return $this->poZmianie($wynik, 'Zapisano rolę.');
    }

    public function ustawHaslo()
    {
        $wynik = $this->serwis()->ustawHaslo(
            (int) $this->request->getPost('id'),
            (string) $this->request->getPost('haslo'),
        );

        return $this->poZmianie($wynik, 'Ustawiono nowe hasło.');
    }

    public function ustawAktywny()
    {
        $aktywny = $this->request->getPost('aktywny') === '1';
        $wynik   = $this->serwis()->ustawAktywny(
            (int) $this->request->getPost('id'),
            $aktywny,
        );

        return $this->poZmianie($wynik, $aktywny ? 'Włączono konto.' : 'Wyłączono konto.');
    }

    public function usun()
    {
        $wynik = $this->serwis()->usun(
            (int) $this->request->getPost('id'),
            (int) session()->get('mcs_user_id'),
        );

        return $this->poZmianie($wynik, 'Usunięto konto.');
    }

    /**
     * @param array{ok: bool, blad: ?string, id?: int|string} $wynik
     */
    private function poZmianie(array $wynik, string $ok): \CodeIgniter\HTTP\RedirectResponse
    {
        if (! $wynik['ok']) {
            return redirect()->to('/admin/uzytkownicy')->with('blad', (string) ($wynik['blad'] ?? 'Nie udało się zapisać.'));
        }

        return redirect()->to('/admin/uzytkownicy')->with('komunikat', $ok);
    }

    /**
     * @return array<string, mixed>
     */
    private function daneWidoku(): array
    {
        $serwis = $this->serwis();
        $lista  = $serwis->lista();
        foreach ($lista as &$u) {
            $u['ostatni_sa'] = $serwis->jestOstatnimAktywnymSuperadminem($u);
        }
        unset($u);

        return [
            'title'        => 'Użytkownicy — MCS',
            'navAktywny'   => 'uzytkownicy',
            'email'        => (string) session()->get('mcs_user_email'),
            'rola'         => (string) session()->get('mcs_user_rola'),
            'sesjaId'      => (int) session()->get('mcs_user_id'),
            'komunikat'    => session()->getFlashdata('komunikat'),
            'blad'         => session()->getFlashdata('blad'),
            'uzytkownicy'  => $lista,
        ];
    }

    private function serwis(): UzytkownicySerwis
    {
        return new UzytkownicySerwis();
    }
}
