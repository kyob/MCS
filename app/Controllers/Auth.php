<?php

namespace App\Controllers;

use App\Models\UserModel;

class Auth extends BaseController
{
    public function login()
    {
        if (session()->get('mcs_user_id') !== null) {
            return redirect()->to('/admin/pluginy');
        }

        return view('auth/login', [
            'blad' => session()->getFlashdata('blad'),
        ]);
    }

    public function loginSubmit()
    {
        if (session()->get('mcs_user_id') !== null) {
            return redirect()->to('/admin/pluginy');
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        $haslo = (string) $this->request->getPost('haslo');

        if ($email === '' || $haslo === '') {
            return redirect()->to('/login')->with('blad', 'Podaj e-mail i hasło.');
        }

        $user = model(UserModel::class)->znajdzAktywnegoPoEmailu($email);

        if ($user === null || ! password_verify($haslo, (string) $user['password_hash'])) {
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

        return redirect()->to('/admin/pluginy');
    }

    public function logout()
    {
        $sesja = session();
        $sesja->destroy();

        return redirect()->to('/login');
    }
}
