<?php

namespace App\Libraries;

use App\Models\PluginModel;

/**
 * Wspólna logika `mcs:pobierz` i przycisku „Pobierz teraz” w adminie.
 *
 * Blokada flock per źródło: cron i UI nie uruchamiają równolegle tego samego źródła.
 */
class PobierzSerwis
{
    private PluginRegistry $rejestr;
    private PluginModel $pluginy;
    private string $katalogBlokad;

    public function __construct(
        ?PluginRegistry $rejestr = null,
        ?PluginModel $pluginy = null,
        ?string $katalogBlokad = null,
    ) {
        $this->rejestr       = $rejestr ?? new PluginRegistry();
        $this->pluginy       = $pluginy ?? model(PluginModel::class);
        $this->katalogBlokad = $katalogBlokad ?? WRITEPATH . 'cache';
    }

    /**
     * Kod wyjścia dla CLI: EXIT_ERROR, gdy którekolwiek źródło zakończyło się błędem.
     * Pominięte i zajęte źródła nie są błędem.
     *
     * @param list<array{zrodlo?: string, status?: string, msg?: string}> $wyniki
     */
    public static function kodWyjscia(array $wyniki): int
    {
        foreach ($wyniki as $w) {
            $status = (string) ($w['status'] ?? '');
            if ($status !== 'ok' && $status !== 'pominiety' && $status !== 'zajety') {
                return EXIT_ERROR;
            }
        }

        return EXIT_SUCCESS;
    }

    /**
     * @return list<array{zrodlo: string, status: string, msg: string}>
     *         status: ok|blad|pominiety|zajety
     */
    public function uruchom(string $cel): array
    {
        $cel = trim($cel);
        if ($cel === '') {
            return [[
                'zrodlo' => '',
                'status' => 'blad',
                'msg'    => 'Brak identyfikatora źródła.',
            ]];
        }

        $mapa = $this->mapaPluginow();
        if ($mapa === [] && $cel === 'all') {
            return [[
                'zrodlo' => 'all',
                'status' => 'blad',
                'msg'    => 'Brak zaimplementowanych wtyczek w plugins/.',
            ]];
        }

        $cele = $cel === 'all' ? array_keys($mapa) : [$cel];
        $wyniki = [];

        foreach ($cele as $zrodlo) {
            $wyniki[] = $this->uruchomJedno($zrodlo, $mapa);
        }

        return $wyniki;
    }

    /**
     * @param array<string, SourcePluginInterface> $mapa
     *
     * @return array{zrodlo: string, status: string, msg: string}
     */
    private function uruchomJedno(string $zrodlo, array $mapa): array
    {
        if (! isset($mapa[$zrodlo])) {
            return [
                'zrodlo' => $zrodlo,
                'status' => 'blad',
                'msg'    => "Brak kodu wtyczki dla „{$zrodlo}”.",
            ];
        }

        $meta = $this->pluginy->find($zrodlo);
        if (is_array($meta) && PluginModel::jakoBool($meta['import_wstrzymany'] ?? false)) {
            return [
                'zrodlo' => $zrodlo,
                'status' => 'pominiety',
                'msg'    => 'pominięto — import_wstrzymany=true (odblokuj w /admin/pluginy).',
            ];
        }

        $uchwyt = null;
        if (! $this->zajmijBlokade($zrodlo, $uchwyt)) {
            return [
                'zrodlo' => $zrodlo,
                'status' => 'zajety',
                'msg'    => 'pobieranie już trwa (cron lub inna sesja) — spróbuj za chwilę.',
            ];
        }

        try {
            $plugin = $mapa[$zrodlo];
            $plugin->pobierz();
            // Plugin z własnym harmonogramem może zgłosić pominięcie (np. spamhaus) — nie pokazuj starego health.
            if (method_exists($plugin, 'komunikatPominiecia')) {
                $pominiecie = $plugin->komunikatPominiecia();
                if (is_string($pominiecie) && $pominiecie !== '') {
                    return ['zrodlo' => $zrodlo, 'status' => 'pominiety', 'msg' => $pominiecie];
                }
            }
            $health = $plugin->health();
            $msg    = trim((string) ($health['msg'] ?? ''));

            // Wtyczka, która nie rzuciła wyjątku, ale zgłasza health ok=false, to też awaria.
            if (($health['ok'] ?? true) === false) {
                return [
                    'zrodlo' => $zrodlo,
                    'status' => 'blad',
                    'msg'    => $msg !== '' ? $msg : 'health: błąd bez komunikatu',
                ];
            }

            return [
                'zrodlo' => $zrodlo,
                'status' => 'ok',
                'msg'    => $msg !== '' ? $msg : 'bez komunikatu',
            ];
        } catch (\Throwable $e) {
            return [
                'zrodlo' => $zrodlo,
                'status' => 'blad',
                'msg'    => $e->getMessage(),
            ];
        } finally {
            $this->zwolnijBlokade($uchwyt);
        }
    }

    /**
     * Zleca import w tle (setsid) i wraca od razu — dla żądania HTTP (limit FPM/nginx).
     * Identyfikator musi być zarejestrowaną wtyczką; do shella trafia tylko escapeshellarg().
     *
     * @return array{zrodlo: string, status: string, msg: string}
     *         status: zlecono|blad|pominiety|zajety
     */
    public function zlecWTle(string $zrodlo): array
    {
        $zrodlo = trim($zrodlo);
        $mapa   = $this->mapaPluginow();
        if ($zrodlo === '' || ! isset($mapa[$zrodlo])) {
            return ['zrodlo' => $zrodlo, 'status' => 'blad', 'msg' => 'Nieznane źródło.'];
        }

        $meta = $this->pluginy->find($zrodlo);
        if (is_array($meta) && PluginModel::jakoBool($meta['import_wstrzymany'] ?? false)) {
            return [
                'zrodlo' => $zrodlo,
                'status' => 'pominiety',
                'msg'    => 'pominięto — import_wstrzymany=true (odblokuj w /admin/pluginy).',
            ];
        }

        if ($this->czyZajete($zrodlo)) {
            return ['zrodlo' => $zrodlo, 'status' => 'zajety', 'msg' => 'Pobieranie już trwa.'];
        }

        $kod = $this->wykonajPolecenie($this->poleceniePobierz($zrodlo));
        if ($kod !== 0) {
            return ['zrodlo' => $zrodlo, 'status' => 'blad', 'msg' => 'Nie uruchomiono pobierania w tle (kod ' . $kod . ').'];
        }

        return ['zrodlo' => $zrodlo, 'status' => 'zlecono', 'msg' => 'zlecono'];
    }

    /** Polecenie shell dla importu w tle; $zrodlo musi być już zwalidowane. */
    public function poleceniePobierz(string $zrodlo): string
    {
        $php = trim((string) env('mcs.dnsbl.phpCli', '/usr/bin/php'));
        $log = WRITEPATH . 'logs/mcs-pobierz-tlo.log';

        return sprintf(
            '/usr/bin/setsid -f %s %s mcs:pobierz %s >> %s 2>&1 < /dev/null',
            escapeshellarg($php),
            escapeshellarg(ROOTPATH . 'spark'),
            escapeshellarg($zrodlo),
            escapeshellarg($log),
        );
    }

    protected function wykonajPolecenie(string $cmd): int
    {
        exec($cmd, $wyj, $kod);

        return (int) $kod;
    }

    /** Sprawdza (bez trzymania) czy flock źródła jest zajęty. */
    public function czyZajete(string $zrodlo): bool
    {
        $uchwyt = null;
        if (! $this->zajmijBlokade($zrodlo, $uchwyt)) {
            return true;
        }
        $this->zwolnijBlokade($uchwyt);

        return false;
    }

    /**
     * @return array<string, SourcePluginInterface>
     */
    private function mapaPluginow(): array
    {
        $mapa = [];
        foreach ($this->rejestr->zaladuj() as $plugin) {
            $mapa[$plugin->zrodlo()] = $plugin;
        }

        return $mapa;
    }

    /**
     * @param resource|null $uchwyt
     */
    private function zajmijBlokade(string $zrodlo, &$uchwyt): bool
    {
        if (! is_dir($this->katalogBlokad) && ! mkdir($this->katalogBlokad, 0775, true) && ! is_dir($this->katalogBlokad)) {
            // Bez katalogu blokad — lepiej nie blokować całego importu.
            $uchwyt = null;

            return true;
        }

        $bezpieczne = preg_replace('/[^a-zA-Z0-9._-]/', '_', $zrodlo) ?: 'zrodlo';
        $plik       = rtrim($this->katalogBlokad, '/\\') . '/mcs-pobierz-' . $bezpieczne . '.lock';
        $fh         = @fopen($plik, 'c+e'); // e = O_CLOEXEC: skan w tle (setsid) nie dziedziczy blokady
        if ($fh === false) {
            $uchwyt = null;

            return true;
        }

        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            $uchwyt = null;

            return false;
        }

        $uchwyt = $fh;

        return true;
    }

    /**
     * @param resource|null $uchwyt
     */
    private function zwolnijBlokade(&$uchwyt): void
    {
        if (! is_resource($uchwyt)) {
            $uchwyt = null;

            return;
        }

        flock($uchwyt, LOCK_UN);
        fclose($uchwyt);
        $uchwyt = null;
    }
}
