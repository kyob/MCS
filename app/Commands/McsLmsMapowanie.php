<?php

namespace App\Commands;

use App\Libraries\Enrichment\LmsMapowanieSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Enrichment LMS — lookup IP → komputer/abonent (cache / API / DB / fixture).
 * Sync zbiorczy: php spark mcs:pobierz lms_mapowanie
 *
 *   php spark mcs:lms-mapowanie 198.51.100.10
 *   php spark mcs:lms-mapowanie 198.51.100.10 --force
 *   php spark mcs:lms-mapowanie 198.51.100.10 --json
 */
class McsLmsMapowanie extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:lms-mapowanie';
    protected $description = 'Mapuje IP na komputer/abonenta LMS (cache / API / DB / fixture). Poza indeksem badge.';
    protected $usage       = 'mcs:lms-mapowanie <ip> [--force] [--json]';
    protected $arguments   = [
        'ip' => 'Adres IPv4 do zmapowania (API LMS: tylko IPv4)',
    ];
    protected $options = [
        '--force' => 'Pomiń cache TTL',
        '--json'  => 'Wypisz wynik jako JSON',
    ];

    public function run(array $params)
    {
        $ip = $params[0] ?? CLI::getSegment(2);
        if ($ip === null || $ip === '') {
            CLI::error('Podaj IP: php spark mcs:lms-mapowanie 198.51.100.10');

            return EXIT_ERROR;
        }

        $force = CLI::getOption('force') !== null;
        $json  = CLI::getOption('json') !== null;

        $serwis = new LmsMapowanieSerwis();
        CLI::write('[lms_mapowanie] tryb startowy: ' . $serwis->tryb(), 'yellow');

        $wynik = $serwis->sprawdz((string) $ip, $force);

        if ($json) {
            CLI::write(json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $wynik['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
        }

        if (! $wynik['ok']) {
            CLI::error('[lms_mapowanie] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        $dane = $wynik['wynik'] ?? [];
        CLI::write(
            sprintf(
                '[lms_mapowanie] OK ip=%s trafiony=%s komputer=%s abonent=%s node_id=%s z_cache=%s tryb=%s',
                $wynik['ip'],
                ! empty($dane['trafiony']) ? 'tak' : 'nie',
                (string) ($dane['komputer'] ?? '—'),
                (string) ($dane['abonent'] ?? '—'),
                (string) ($dane['node_id'] ?? '—'),
                $wynik['z_cache'] ? 'tak' : 'nie',
                $wynik['tryb'],
            ),
            'green',
        );

        return EXIT_SUCCESS;
    }
}
