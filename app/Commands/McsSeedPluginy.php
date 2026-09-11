<?php

namespace App\Commands;

use App\Libraries\PluginSeeder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class McsSeedPluginy extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:seed-pluginy';
    protected $description = 'Zapisuje placeholdery źródeł (shadowserver, play, snds, mojecert) z wstrzymanym importem.

Przykłady:
  php spark mcs:seed-pluginy
  php spark mcs:seed-pluginy --dry-run';
    protected $usage       = 'mcs:seed-pluginy [options]';
    protected $options     = [
        '--dry-run' => 'Pokaż co zostałoby zapisane, bez zmian w bazie.',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);

        if ($dryRun) {
            foreach (PluginSeeder::PLACEHOLDERY as $wiersz) {
                CLI::write($wiersz['zrodlo'] . "\t" . $wiersz['nazwa'] . "\timport_wstrzymany=true");
            }
            CLI::write('dry-run: bez zmian w bazie');

            return EXIT_SUCCESS;
        }

        $wynik = (new PluginSeeder())->zapisz();

        foreach ($wynik['nowe'] as $zrodlo) {
            CLI::write('nowy: ' . $zrodlo);
        }
        foreach ($wynik['juz_byly'] as $zrodlo) {
            CLI::write('już był: ' . $zrodlo);
        }
        CLI::write('razem: ' . (string) (count($wynik['nowe']) + count($wynik['juz_byly'])));

        return EXIT_SUCCESS;
    }
}
