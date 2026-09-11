<?php

namespace App\Controllers;

/**
 * Placeholder Task 5 — pełna strona IP w Task 6.
 * Chronione filtrem mcsAuth (obie role widzą wszystkie IP w v1).
 */
class Ip extends BaseController
{
    public function show(string $ip): string
    {
        $email = (string) session()->get('mcs_user_email');
        $rola  = (string) session()->get('mcs_user_rola');

        return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8">'
            . '<title>IP — MCS</title></head><body>'
            . '<p>Sesja OK (Task 5). Lista incydentów dla <strong>'
            . esc($ip) . '</strong> powstanie w Task 6.</p>'
            . '<p>Użytkownik: ' . esc($email) . ' · rola: ' . esc($rola) . '</p>'
            . '<p><a href="' . site_url('logout') . '">Wyloguj</a></p>'
            . '</body></html>';
    }
}
