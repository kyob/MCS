<?php

namespace App\Controllers;

use App\Libraries\LimitLogowania;
use App\Models\UserModel;

class Auth extends BaseController
{
    public const KOMUNIKAT_LIMIT = 'Zbyt wiele prób logowania. Spróbuj ponownie za kilka minut.';

    /** Hash losowego, nieznanego hasła (bcrypt, koszt 12 = PASSWORD_DEFAULT w PHP 8.4). */
    private const FIKCYJNY_HASH = '$2y$12$kKQ7/zzvpvQQ0quI2Dld.OYCRnaJfR..MgQVnCCLP3yo.UNrJjivW';

    public function login()
    {
        if (session()->get('mcs_user_id') !== null) {
            return redirect()->to('/');
        }

        return view('auth/login', [
            'blad' => session()->getFlashdata('blad'),
        ]);
    }

    public function loginSubmit()
    {
        if (session()->get('mcs_user_id') !== null) {
            return redirect()->to('/');
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        $haslo = (string) $this->request->getPost('haslo');

        if ($email === '' || $haslo === '') {
            return redirect()->to('/login')->with('blad', 'Podaj e-mail i hasło.');
        }

        if (! (new LimitLogowania())->pozwol($this->request->getIPAddress(), $email)) {
            return redirect()->to('/login')->with('blad', self::KOMUNIKAT_LIMIT);
        }

        $user = model(UserModel::class)->znajdzAktywnegoPoEmailu($email);

        // Stały czas: gdy konta brak, i tak liczymy password_verify (na fikcyjnym hashu).
        $hash = $user === null ? self::FIKCYJNY_HASH : (string) $user['password_hash'];
        $ok   = password_verify($haslo, $hash);

        if ($user === null || ! $ok) {
            return redirect()->to('/login')->with('blad', 'Nieprawidłowy e-mail lub hasło.');
        }

        session()->regenerate(true);
        session()->set([
            'mcs_user_id'    => $user['id'],
            'mcs_user_email' => $user['email'],
            'mcs_user_rola'  => $user['rola'],
        ]);

        $cel = session()->get('mcs_po_logowaniu');
        session()->remove('mcs_po_logowaniu');

        if (is_string($cel) && $cel !== '' && str_starts_with($cel, '/') && ! str_starts_with($cel, '//')) {
            return redirect()->to($cel);
        }

        return redirect()->to('/');
    }

    public function logout()
    {
        $sesja = session();
        $sesja->destroy();

        return redirect()->to('/login');
    }
}
