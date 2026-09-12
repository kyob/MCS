<?php

namespace App\Commands;

use App\Libraries\PobierzSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Uruchamia pobierz() wtyczki źródła (Faza 1b).
 *
 *   php spark mcs:pobierz shadowserver
 *   php spark mcs:pobierz all
 */
class McsPobierz extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:pobierz';
    protected $description = 'Pobiera dane ze źródła (plugin). Respektuje import_wstrzymany.';
    protected $usage       = 'mcs:pobierz <zrodlo|all>';
    protected $arguments   = [
        'zrodlo' => 'Identyfikator źródła (np. shadowserver) albo all',
    ];

    public function run(array $params)
    {
        $cel = $params[0] ?? CLI::getSegment(2);
        if ($cel === null || $cel === '') {
            CLI::error('Podaj źródło: php spark mcs:pobierz shadowserver');

            return;
        }

        $wyniki = (new PobierzSerwis())->uruchom((string) $cel);

        foreach ($wyniki as $w) {
            $zrodlo = (string) ($w['zrodlo'] ?? '');
            $status = (string) ($w['status'] ?? '');
            $msg    = (string) ($w['msg'] ?? '');
            $etykieta = $zrodlo !== '' ? "[{$zrodlo}]" : '[?]';

            switch ($status) {
                case 'ok':
                    CLI::write("{$etykieta} OK — {$msg}", 'green');
                    break;
                case 'pominiety':
                    CLI::write("{$etykieta} {$msg}", 'yellow');
                    break;
                case 'zajety':
                    CLI::write("{$etykieta} {$msg}", 'yellow');
                    break;
                default:
                    CLI::error("{$etykieta} błąd: {$msg}");
                    break;
            }
        }
    }
}
