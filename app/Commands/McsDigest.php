<?php

namespace App\Commands;

use App\Libraries\Watchlista\DigestSerwis;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Digest operacyjny watchlisty IP — tylko INFO (bez auto-blokad).
 *
 *   php spark mcs:digest
 *   php spark mcs:digest --godziny=6
 *   php spark mcs:digest --tylko-as
 *   php spark mcs:digest --json
 *
 * Cron (przykład co godzinę):
 *   5 * * * * www-data cd /var/www/mcs && php spark mcs:digest --godziny=1 >> /var/www/mcs/writable/logs/mcs-digest.log 2>&1
 */
class McsDigest extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:digest';
    protected $description = 'Digest high|critical dla IP z watchlisty (INFO, bez blokad).';
    protected $usage       = 'mcs:digest [--godziny=24] [--tylko-as] [--json]';
    protected $options     = [
        '--godziny'  => 'Okno w godzinach (1–168, domyślnie 24)',
        '--tylko-as' => 'Tylko IP z watchlisty należące do naszego AS (ASN z ustawień)',
        '--json'     => 'Wypisz wynik jako JSON',
    ];

    /**
     * `--tylko-as` albo dawne `--tylko-as<ASN>` (np. --tylko-as198050) — numer w nazwie
     * nie ma znaczenia, filtr zawsze używa prefiksów z ustawień.
     *
     * @param array<string, mixed> $opcje klucze opcji CLI bez `--`
     * @param list<mixed>          $argv
     */
    public static function czyTylkoAs(array $opcje, array $argv = []): bool
    {
        foreach (array_keys($opcje) as $nazwa) {
            if (preg_match('/^tylko-as\d*$/', (string) $nazwa) === 1) {
                return true;
            }
        }
        foreach ($argv as $arg) {
            if (is_string($arg) && preg_match('/^--tylko-as\d*(=.*)?$/', $arg) === 1) {
                return true;
            }
        }

        return false;
    }

    public function run(array $params)
    {
        // CI CLI::getOption obsługuje `--godziny 1`, ale nie zawsze `--godziny=1`.
        $godzinyRaw = CLI::getOption('godziny');
        if ($godzinyRaw === null || $godzinyRaw === false) {
            foreach ($_SERVER['argv'] ?? [] as $arg) {
                if (is_string($arg) && preg_match('/^--godziny=(.+)$/', $arg, $m) === 1) {
                    $godzinyRaw = $m[1];
                    break;
                }
            }
        }
        $godziny = $godzinyRaw !== null && $godzinyRaw !== false
            ? (int) $godzinyRaw
            : 24;
        if ($godziny < 1) {
            $godziny = 24;
        }

        $tylkoAs = self::czyTylkoAs(CLI::getOptions(), $_SERVER['argv'] ?? []);
        $json    = CLI::getOption('json') !== null;

        $serwis = new DigestSerwis();
        $wynik  = $serwis->zbierz($godziny, $tylkoAs);

        if ($json) {
            CLI::write(json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return EXIT_SUCCESS;
        }

        CLI::write($serwis->formatujTekst($wynik));

        $ile = count($wynik['incydenty']);
        if ($ile > 0) {
            CLI::write("[digest] znaleziono {$ile} — tylko INFO, bez auto-blokad", 'yellow');
        } else {
            CLI::write('[digest] brak alertów high|critical w oknie', 'green');
        }

        $this->podgrzejCacheRevDns();

        return EXIT_SUCCESS;
    }

    /**
     * Pre-warm cache PTR dla IP z watchlisty (issue #447) — poza requestem HTTP,
     * więc strona /ip/{ip} i dashboard czytają potem tylko cache (mapaZCache / cache hit).
     * Każdy zimny wpis kosztuje maks. twardy timeout mcs.revDns.timeoutMs.
     */
    private function podgrzejCacheRevDns(): void
    {
        try {
            $rev = new \App\Libraries\Siec\RevDns();
            if (! $rev->jestWlaczone()) {
                return;
            }
            $lista = model(\App\Models\WatchlistaModel::class)->listaIp();
            foreach ($lista as $ip) {
                $rev->dlaIp((string) $ip);
            }
            if ($lista !== []) {
                CLI::write('[digest] revDNS: podgrzano cache dla ' . count($lista) . ' IP z watchlisty', 'green');
            }
        } catch (\Throwable $e) {
            CLI::write('[digest] revDNS: pre-warm pominięty (' . $e->getMessage() . ')', 'yellow');
        }
    }
}
