<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Placeholder Task 5 — panel wtyczek w Task 8.
 * Chronione filtrem mcsAuth.
 */
class Pluginy extends BaseController
{
    public function index(): string
    {
        $email = (string) session()->get('mcs_user_email');
        $rola  = (string) session()->get('mcs_user_rola');

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">'
            . '<title>Admin pluginy — MCS</title></head><body>'
            . '<p>Sesja OK (Task 5). Panel stanów wtyczek powstanie w Task 8.</p>'
            . '<p>Użytkownik: ' . esc($email) . ' · rola: ' . esc($rola) . '</p>'
            . '<p><a href="' . site_url('logout') . '">Wyloguj</a></p>'
            . '</body></html>';
    }
}
