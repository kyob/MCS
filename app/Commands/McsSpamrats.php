<?php

namespace App\Commands;

use App\Libraries\Enrichment\SpamratsSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lookup SpamRats DNSBL dla jednego IP.
 *
 *   php spark mcs:spamrats 198.51.100.10
 *   php spark mcs:spamrats 198.51.100.10 --force
 *   php spark mcs:spamrats 198.51.100.10 --json
 */
class McsSpamrats extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:spamrats';
    protected $description = 'Enrichment SpamRats — DNSBL RATS-All (cache TTL).';
    protected $usage       = 'mcs:spamrats <ip> [--force] [--json]';
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
            CLI::error('Podaj IP: php spark mcs:spamrats 198.51.100.10');

            return EXIT_ERROR;
        }

        $force  = CLI::getOption('force') !== null;
        $serwis = new SpamratsSerwis();
        CLI::write('[spamrats] tryb startowy: ' . $serwis->tryb(), 'yellow');

        $wynik = $serwis->sprawdz($ip, $force);
        if (! $wynik['ok']) {
            CLI::error('[spamrats] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        if (CLI::getOption('json') !== null) {
            CLI::write(json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        }

        $w = $wynik['wynik'] ?? [];
        CLI::write(sprintf(
            '[spamrats] OK ip=%s listed=%s dyna=%s noptr=%s spam=%s auth=%s z_cache=%s tryb=%s',
            $wynik['ip'],
            isset($w['listed']) ? ($w['listed'] ? 'true' : 'false') : 'null',
            isset($w['listed_dyna']) ? ($w['listed_dyna'] ? 'true' : 'false') : 'null',
            isset($w['listed_noptr']) ? ($w['listed_noptr'] ? 'true' : 'false') : 'null',
            isset($w['listed_spam']) ? ($w['listed_spam'] ? 'true' : 'false') : 'null',
            isset($w['listed_auth']) ? ($w['listed_auth'] ? 'true' : 'false') : 'null',
            $wynik['z_cache'] ? '1' : '0',
            $wynik['tryb'],
        ), 'green');

        return EXIT_SUCCESS;
    }
}
