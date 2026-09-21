<?php

namespace App\Commands;

use App\Libraries\Enrichment\IsmaliciousSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lookup isMalicious dla jednego IP.
 *
 *   php spark mcs:ismalicious 198.51.100.10
 *   php spark mcs:ismalicious 198.51.100.10 --force
 *   php spark mcs:ismalicious 198.51.100.10 --json
 */
class McsIsmalicious extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:ismalicious';
    protected $description = 'Enrichment isMalicious — lookup reputacji IP (cache TTL).';
    protected $usage       = 'mcs:ismalicious <ip> [--force] [--json]';
    protected $arguments   = [
        'ip' => 'Adres IPv4/IPv6 do sprawdzenia',
    ];
    protected $options     = [
        '--force' => 'Pomiń cache i odśwież wynik',
        '--json'  => 'Wypisz surowy JSON wyniku',
    ];

    public function run(array $params)
    {
        $ip = trim((string) ($params[0] ?? CLI::getOption('ip') ?? ''));
        if ($ip === '') {
            CLI::error('Podaj IP: php spark mcs:ismalicious 198.51.100.10');

            return EXIT_ERROR;
        }

        $force  = CLI::getOption('force') !== null;
        $serwis = new IsmaliciousSerwis();
        CLI::write('[ismalicious] tryb startowy: ' . $serwis->tryb(), 'yellow');

        $wynik = $serwis->sprawdz($ip, $force);
        if (! $wynik['ok']) {
            CLI::error('[ismalicious] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        if (CLI::getOption('json') !== null) {
            CLI::write(json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        }

        $w = $wynik['wynik'] ?? [];
        CLI::write(sprintf(
            '[ismalicious] OK ip=%s malicious=%s risk=%s hits=%s class=%s z_cache=%s tryb=%s',
            $wynik['ip'],
            isset($w['malicious']) ? ($w['malicious'] ? 'true' : 'false') : 'null',
            (string) ($w['risk_score'] ?? '—'),
            (string) ($w['blocklist_hits'] ?? '—'),
            (string) ($w['classification'] ?? '—'),
            $wynik['z_cache'] ? '1' : '0',
            $wynik['tryb'],
        ), 'green');

        return EXIT_SUCCESS;
    }
}
