<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class McsCreateUser extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:create-user';
    protected $description = 'Tworzy użytkownika MCS (rola admin lub operator).

Przykłady:
  php spark mcs:create-user admin@example.local \'haslo\' admin
  php spark mcs:create-user operator@example.local \'haslo\' operator';
    protected $usage       = 'mcs:create-user <email> <haslo> <rola>';
    protected $arguments   = [
        'email' => 'Adres e-mail (unikalny).',
        'haslo' => 'Hasło w jawnej postaci (zapisane jako password_hash).',
        'rola'  => 'admin albo operator.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $email = isset($params[0]) ? trim((string) $params[0]) : '';
        $haslo = isset($params[1]) ? (string) $params[1] : '';
        $rola  = isset($params[2]) ? strtolower(trim((string) $params[2])) : '';

        if ($email === '' || $haslo === '' || $rola === '') {
            CLI::error('Użycie: php spark mcs:create-user <email> <haslo> <rola>');

            return EXIT_ERROR;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Nieprawidłowy e-mail.');

            return EXIT_ERROR;
        }

        if (! in_array($rola, ['admin', 'operator'], true)) {
            CLI::error('Rola musi być: admin albo operator.');

            return EXIT_ERROR;
        }

        if (strlen($haslo) < 8) {
            CLI::error('Hasło musi mieć co najmniej 8 znaków.');

            return EXIT_ERROR;
        }

        $model = model(UserModel::class);

        if ($model->where('email', $email)->first() !== null) {
            CLI::error('Użytkownik o tym e-mailu już istnieje.');

            return EXIT_ERROR;
        }

        $id = $model->insert([
            'email'         => $email,
            'password_hash' => password_hash($haslo, PASSWORD_DEFAULT),
            'rola'          => $rola,
            'aktywny'       => true,
        ], true);

        if ($id === false) {
            CLI::error('Nie udało się utworzyć użytkownika.');
            $bledy = $model->errors();
            if ($bledy !== []) {
                CLI::error(implode('; ', $bledy));
            }

            return EXIT_ERROR;
        }

        CLI::write('Utworzono użytkownika id=' . (string) $id . ' email=' . $email . ' rola=' . $rola);

        return EXIT_SUCCESS;
    }
}
