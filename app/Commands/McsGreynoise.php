<?php

namespace App\Commands;

use App\Libraries\Enrichment\GreynoiseSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lookup GreyNoise Community dla jednego IP. Zużywa limit konta.
 *
 *   php spark mcs:greynoise 198.51.100.10
 *   php spark mcs:greynoise 198.51.100.10 --force
 *   php spark mcs:greynoise 198.51.100.10 --json
 */
class McsGreynoise extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:greynoise';
    protected $description = 'Enrichment GreyNoise Community — lookup IP na żądanie (cache TTL, poza badge).';
    protected $usage       = 'mcs:greynoise <ip> [--force] [--json]';
    protected $arguments   = [
        'ip' => 'Adres IPv4/IPv6 do sprawdzenia',
    ];
    protected $options = [
        '--force' => 'Pomiń cache i odśwież wynik',
        '--json'  => 'Wypisz surowy JSON wyniku',
    ];

    public function run(array $params)
    {
        $ip = trim((string) ($params[0] ?? CLI::getOption('ip') ?? ''));
        if ($ip === '') {
            CLI::error('Podaj IP: php spark mcs:greynoise 198.51.100.10');

            return EXIT_ERROR;
        }

        $force  = CLI::getOption('force') !== null;
        $wynik  = (new GreynoiseSerwis())->sprawdz($ip, $force);
        if (! $wynik['ok']) {
            CLI::error('[greynoise] ' . ($wynik['blad'] ?? 'błąd'));

            return EXIT_ERROR;
        }

        if (CLI::getOption('json') !== null) {
            CLI::write((string) json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        }

        $w = is_array($wynik['wynik']) ? $wynik['wynik'] : [];
        CLI::write(sprintf(
            '[greynoise] OK ip=%s class=%s noise=%s riot=%s http=%s z_cache=%s',
            $wynik['ip'],
            (string) ($w['classification'] ?? '—'),
            $this->takNie($w['noise'] ?? null),
            $this->takNie($w['riot'] ?? null),
            (string) ($w['http_status'] ?? '—'),
            $wynik['z_cache'] ? 'tak' : 'nie',
        ));

        return EXIT_SUCCESS;
    }

    private function takNie(mixed $wartosc): string
    {
        if ($wartosc === true) {
            return 'tak';
        }
        if ($wartosc === false) {
            return 'nie';
        }

        return '—';
    }
}
