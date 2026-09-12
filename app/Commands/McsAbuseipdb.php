<?php

namespace App\Commands;

use App\Libraries\Enrichment\AbuseipdbSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Enrichment AbuseIPDB na żądanie (Faza 3) — NIE jest częścią mcs:pobierz.
 *
 *   php spark mcs:abuseipdb 198.51.100.10
 *   php spark mcs:abuseipdb 198.51.100.10 --force
 *   php spark mcs:abuseipdb 198.51.100.10 --json
 */
class McsAbuseipdb extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:abuseipdb';
    protected $description = 'Sprawdza reputację IP w AbuseIPDB (cache / API / fixture). Poza mcs:pobierz.';
    protected $usage       = 'mcs:abuseipdb <ip> [--force] [--json]';
    protected $arguments   = [
        'ip' => 'Adres IPv4/IPv6 do sprawdzenia',
    ];
    protected $options = [
        '--force' => 'Pomiń cache TTL',
        '--json'  => 'Wypisz wynik jako JSON',
    ];

    public function run(array $params)
    {
        $ip = $params[0] ?? CLI::getSegment(2);
        if ($ip === null || $ip === '') {
            CLI::error('Podaj IP: php spark mcs:abuseipdb 198.51.100.10');

            return EXIT_ERROR;
        }

        $force = CLI::getOption('force') !== null;
        $json  = CLI::getOption('json') !== null;

        $serwis = new AbuseipdbSerwis();
        CLI::write('[abuseipdb] tryb startowy: ' . $serwis->tryb(), 'yellow');

        $wynik = $serwis->sprawdz((string) $ip, $force);

        if ($json) {
            CLI::write(json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $wynik['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
        }

        if (! $wynik['ok']) {
            CLI::error('[abuseipdb] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        $dane = $wynik['wynik'] ?? [];
        CLI::write(
            sprintf(
                '[abuseipdb] OK ip=%s score=%s reports=%s country=%s z_cache=%s tryb=%s',
                $wynik['ip'],
                (string) ($dane['abuse_confidence_score'] ?? '?'),
                (string) ($dane['total_reports'] ?? '?'),
                (string) ($dane['country_code'] ?? '?'),
                $wynik['z_cache'] ? 'tak' : 'nie',
                $wynik['tryb'],
            ),
            'green',
        );

        return EXIT_SUCCESS;
    }
}
