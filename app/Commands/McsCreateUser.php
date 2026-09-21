<?php

namespace App\Commands;

use App\Libraries\UzytkownicySerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class McsCreateUser extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:create-user';
    protected $description = 'Tworzy użytkownika MCS (rola superadmin, admin lub operator).

Przykłady:
  php spark mcs:create-user admin@example.local \'haslo\' superadmin
  php spark mcs:create-user operator@example.local \'haslo\' operator';
    protected $usage       = 'mcs:create-user <email> <haslo> <rola>';
    protected $arguments   = [
        'email' => 'Adres e-mail (unikalny).',
        'haslo' => 'Hasło w jawnej postaci (zapisane jako password_hash).',
        'rola'  => 'superadmin, admin albo operator.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $email = isset($params[0]) ? strtolower(trim((string) $params[0])) : '';
        $haslo = isset($params[1]) ? (string) $params[1] : '';
        $rola  = isset($params[2]) ? strtolower(trim((string) $params[2])) : '';

        if ($email === '' || $haslo === '' || $rola === '') {
            CLI::error('Użycie: php spark mcs:create-user <email> <haslo> <rola>');

            return EXIT_ERROR;
        }

        $wynik = (new UzytkownicySerwis())->utworz($email, $haslo, $rola);
        if (! $wynik['ok']) {
            CLI::error((string) ($wynik['blad'] ?? 'Nie udało się utworzyć użytkownika.'));

            return EXIT_ERROR;
        }

        CLI::write('Utworzono użytkownika id=' . (string) ($wynik['id'] ?? '') . ' email=' . $email . ' rola=' . $rola);

        return EXIT_SUCCESS;
    }
}
