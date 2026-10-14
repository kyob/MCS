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
