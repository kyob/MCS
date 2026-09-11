<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Smoke CLI — sprawdzenie, że spark ładuje komendy MCS.
 */
class McsHello extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:hello';
    protected $description = 'Smoke CLI MCS — wypisuje komunikat OK.

Przykłady:
  php spark mcs:hello';
    protected $usage       = 'mcs:hello';

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        CLI::write('MCS hello — OK');

        return EXIT_SUCCESS;
    }
}
