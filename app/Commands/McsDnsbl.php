<?php

namespace App\Commands;

use App\Libraries\Enrichment\DnsblSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lookup DNSBL multi-list dla jednego IP.
 *
 *   php spark mcs:dnsbl 198.51.100.10
 *   php spark mcs:dnsbl 198.51.100.10 --force
 *   php spark mcs:dnsbl 198.51.100.10 --json
 */
class McsDnsbl extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:dnsbl';
    protected $description = 'Enrichment DNSBL (UCEPROTECT/SpamCop/DroneBL).';
    protected $usage       = 'mcs:dnsbl <ip> [--force] [--json]';
    protected $arguments   = [
        'ip' => 'Adres IPv4 do sprawdzenia',
    ];
    protected $options     = [
        '--force' => 'Pomiń cache i odśwież wynik',
        '--json'  => 'Wypisz surowy JSON wyniku',
    ];

    public function run(array $params)
    {
        $ip = trim((string) ($params[0] ?? CLI::getOption('ip') ?? ''));
        if ($ip === '') {
            CLI::error('Podaj IP: php spark mcs:dnsbl 198.51.100.10');

            return EXIT_ERROR;
        }

        $force  = CLI::getOption('force') !== null;
        $serwis = new DnsblSerwis();
        CLI::write('[dnsbl] tryb startowy: ' . $serwis->tryb(), 'yellow');

        $wynik = $serwis->sprawdz($ip, $force);
        if (! $wynik['ok']) {
            CLI::error('[dnsbl] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        if (CLI::getOption('json') !== null) {
            CLI::write(json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        }

        $w     = $wynik['wynik'] ?? [];
        $listy = is_array($w['listy'] ?? null) ? $w['listy'] : [];
        $hit   = [];
        foreach ($listy as $id => $info) {
            if (is_array($info) && ! empty($info['listed'])) {
                $hit[] = (string) $id;
            }
        }

        CLI::write(sprintf(
            '[dnsbl] OK ip=%s listed_any=%s listy=[%s] z_cache=%s tryb=%s',
            $wynik['ip'],
            isset($w['listed_any']) ? ($w['listed_any'] ? 'true' : 'false') : 'null',
            implode(',', $hit),
            $wynik['z_cache'] ? '1' : '0',
            $wynik['tryb'],
        ), 'green');

        return EXIT_SUCCESS;
    }
}
