<?php

namespace App\Commands;

use App\Libraries\Dnsbl\Skaner;
use App\Libraries\Dnsbl\ZakresSkanu;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Skan DNSBL IPv4 AS198050 przez lokalny unbound (plugin dnsbl, E1).
 *
 *   php spark mcs:dnsbl-skan                         # wszystkie prefiksy IPv4 z as198050_cidry
 *   php spark mcs:dnsbl-skan --cidr=91.231.70.0/23
 *   php spark mcs:dnsbl-skan --cidr=91.231.70.0/23 --strefa=spamcop
 *   php spark mcs:dnsbl-skan --cidr=91.231.70.0/23 --dry-run
 *   php spark mcs:dnsbl-skan --skan-id=12            # przebieg zlecony z WWW
 */
class McsDnsblSkan extends BaseCommand
{
    protected $group       = 'MCS';
    protected $name        = 'mcs:dnsbl-skan';
    protected $description = 'Skan DNSBL IPv4 z as198050_cidry (lokalny unbound; incydenty tylko dla nowych listingów stref o wysokiej wadze).';
    protected $usage       = 'mcs:dnsbl-skan [--cidr=] [--strefa=] [--dry-run] [--skan-id=]';
    protected $options     = [
        '--cidr'    => 'IP/CIDR (lista po przecinku), /21–/32, tylko z as198050_cidry. Brak = całe AS.',
        '--strefa'  => 'Kod strefy z mcs_dnsbl_strefy (np. spamcop)',
        '--dry-run' => 'Tylko plan i testy kontrolne stref, bez zapytań o IP i bez zapisu',
        '--skan-id' => 'Wykonaj istniejący skan (status oczekuje) — używane przez WWW',
    ];

    public function run(array $params)
    {
        $opt = $this->opcje();
        $log = static function (string $m): void {
            CLI::write('[dnsbl-skan] ' . $m);
        };
        $skaner = new Skaner(null, null, $log);

        if (($opt['skan-id'] ?? '') !== '') {
            $id = (int) $opt['skan-id'];
            if (! $this->zajmijBlokade()) {
                \Config\Database::connect()->table('mcs_dnsbl_skany')->where('id', $id)->where('status', 'oczekuje')->update([
                    'status' => 'blad', 'koniec_at' => gmdate('Y-m-d H:i:sP'), 'komunikat' => 'Pominięty: trwa inny skan DNSBL.',
                ]);
                CLI::error('[dnsbl-skan] skan #' . $id . ': trwa inny skan DNSBL (blokada).');

                return EXIT_ERROR;
            }
            try {
                $w = $skaner->wykonaj($id);
            } catch (\Throwable $e) {
                CLI::error('[dnsbl-skan] skan #' . $id . ': ' . $e->getMessage());

                return EXIT_ERROR;
            }
            $this->podsumuj($id, $w);

            return EXIT_SUCCESS;
        }

        $prefiksy = Skaner::prefiksyAs();
        if ($prefiksy === []) {
            CLI::error('[dnsbl-skan] puste as198050_cidry (IPv4).');

            return EXIT_ERROR;
        }

        $cidrArg = trim((string) ($opt['cidr'] ?? ''));
        if ($cidrArg === '') {
            $cidry = $prefiksy;
        } else {
            $wal = ZakresSkanu::waliduj($cidrArg, $prefiksy);
            if (! $wal['ok']) {
                CLI::error('[dnsbl-skan] ' . $wal['blad']);

                return EXIT_ERROR;
            }
            $cidry = $wal['cidry'];
        }

        $strefa = trim((string) ($opt['strefa'] ?? ''));
        if ($strefa !== '' && $skaner->strefy($strefa) === []) {
            CLI::error('[dnsbl-skan] nieznana lub nieaktywna strefa: ' . $strefa);

            return EXIT_ERROR;
        }

        $plan = $skaner->plan($cidry, $strefa !== '' ? $strefa : null);
        $u    = Skaner::ustawienia();
        CLI::write(sprintf(
            '[dnsbl-skan] zakres=%s IP=%d /24=%d strefy=%s zapytań≈%d szac.≥%ds resolver=%s qps/strefę=%d w_locie=%d timeout=%dms retry=%d',
            implode(',', $cidry), $plan['ip'], $plan['sieci24'], implode(',', $plan['strefy']), $plan['zapytan'], $plan['szac_s'],
            $u['resolver'], $u['qps'], $u['w_locie'], $u['timeout_ms'], $u['retry'],
        ), 'yellow');

        if (array_key_exists('dry-run', $opt)) {
            foreach ($skaner->testyKontrolne($skaner->strefy($strefa !== '' ? $strefa : null)) as $kod => $t) {
                CLI::write(sprintf('[dnsbl-skan] test %-12s %s', $kod, $t['ok'] ? 'OK' : 'WYŁĄCZONA: ' . $t['powod']), $t['ok'] ? 'green' : 'red');
            }
            CLI::write('[dnsbl-skan] dry-run: bez zapytań o IP i bez zapisu.');

            return EXIT_SUCCESS;
        }

        if (! $this->zajmijBlokade()) {
            CLI::error('[dnsbl-skan] trwa inny skan DNSBL (blokada) — spróbuj później.');

            return EXIT_ERROR;
        }
        $id = $skaner->utworzSkan($cidry, $strefa !== '' ? $strefa : null, 'cli', 'cli');
        CLI::write('[dnsbl-skan] skan #' . $id);
        try {
            $w = $skaner->wykonaj($id);
        } catch (\Throwable $e) {
            CLI::error('[dnsbl-skan] skan #' . $id . ': ' . $e->getMessage());

            return EXIT_ERROR;
        }
        $this->podsumuj($id, $w);

        return EXIT_SUCCESS;
    }

    /** @var resource|null uchwyt blokady (zwalniany przy końcu procesu) */
    private $blokada;

    /**
     * Jeden skan DNSBL naraz (flock, nieblokujący).
     */
    private function zajmijBlokade(): bool
    {
        $plik = WRITEPATH . 'cache/mcs-dnsbl-skan.lock';
        $fh   = @fopen($plik, 'c+e');
        if ($fh === false) {
            return true;
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        $this->blokada = $fh;

        return true;
    }

    /**
     * @param array<string, mixed> $w
     */
    private function podsumuj(int $id, array $w): void
    {
        foreach ($w['strefy'] as $s) {
            CLI::write(sprintf(
                '[dnsbl-skan]   %-12s %-15s zapytań=%d listed=%d czyste=%d błędy=%d%s%s',
                $s['kod'], $s['status'], $s['zapytan'], $s['listed'], $s['czyste'], $s['bledy'],
                $s['informacyjna'] ? ' (informacyjna)' : '',
                $s['powod'] !== '' ? ' — ' . $s['powod'] : '',
            ));
        }
        CLI::write(sprintf(
            '[dnsbl-skan] OK skan #%d IP=%d czyste=%d listowane=%d błędy=%d czas=%.1fs zapytań=%d nowe=%d zdjete=%d incydenty=%d%s',
            $id, $w['liczba_ip'], $w['czyste'], $w['listowane'], $w['bledy'], $w['czas_ms'] / 1000, $w['zapytan_wykonano'],
            $w['nowe'] ?? 0, $w['zdjete'] ?? 0, $w['incydenty'] ?? 0, ! empty($w['fala']) ? ' FALA' : '',
        ), 'green');
    }

    /**
     * CLI::getOption nie zawsze obsługuje `--opcja=wartość` — parsuj argv.
     *
     * @return array<string, string>
     */
    private function opcje(): array
    {
        $out = [];
        foreach (['cidr', 'strefa', 'skan-id', 'dry-run'] as $k) {
            $v = CLI::getOption($k);
            if ($v !== null) {
                $out[$k] = $v === true ? '' : (string) $v;
            }
        }
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (is_string($arg) && preg_match('/^--(cidr|strefa|skan-id|dry-run)(?:=(.*))?$/', $arg, $m) === 1) {
                $out[$m[1]] = $m[2] ?? ($out[$m[1]] ?? '');
            }
        }

        return $out;
    }
}
