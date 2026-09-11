<?php

namespace App\Commands;

use App\Libraries\PluginRegistry;
use App\Models\PluginModel;
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

        $rejestr = new PluginRegistry();
        $pluginy = $rejestr->zaladuj();
        if ($pluginy === []) {
            CLI::error('Brak zaimplementowanych wtyczek w plugins/');

            return;
        }

        $mapa = [];
        foreach ($pluginy as $p) {
            $mapa[$p->zrodlo()] = $p;
        }

        $cele = $cel === 'all' ? array_keys($mapa) : [$cel];

        foreach ($cele as $zrodlo) {
            if (! isset($mapa[$zrodlo])) {
                CLI::error("Brak kodu wtyczki dla „{$zrodlo}”.");
                continue;
            }

            $meta = model(PluginModel::class)->find($zrodlo);
            if (is_array($meta) && PluginModel::jakoBool($meta['import_wstrzymany'] ?? false)) {
                CLI::write("[{$zrodlo}] pominięto — import_wstrzymany=true (odblokuj w /admin/pluginy).", 'yellow');
                continue;
            }

            CLI::write("[{$zrodlo}] start…", 'green');
            try {
                $mapa[$zrodlo]->pobierz();
                $health = $mapa[$zrodlo]->health();
                CLI::write("[{$zrodlo}] OK — " . ($health['msg'] ?: 'bez komunikatu'), 'green');
            } catch (\Throwable $e) {
                CLI::error("[{$zrodlo}] błąd: " . $e->getMessage());
            }
        }
    }
}
