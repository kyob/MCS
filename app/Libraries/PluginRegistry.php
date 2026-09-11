<?php

namespace App\Libraries;

class PluginRegistry
{
    private readonly string $katalog;

    public function __construct(?string $katalog = null)
    {
        $this->katalog = $katalog ?? ROOTPATH . 'plugins';
    }

    /**
     * Ładuje zaimplementowane wtyczki z `plugins/{zrodlo}/Plugin.php`.
     * Katalog może być pusty — wtedy zwraca pustą listę.
     *
     * @return list<SourcePluginInterface>
     */
    public function zaladuj(): array
    {
        if (! is_dir($this->katalog)) {
            return [];
        }

        $katalogi = glob($this->katalog . '/*', GLOB_ONLYDIR);
        if ($katalogi === false) {
            return [];
        }

        $wynik = [];

        foreach ($katalogi as $katalogZrodla) {
            $zrodlo = basename($katalogZrodla);
            $plik   = $katalogZrodla . DIRECTORY_SEPARATOR . 'Plugin.php';

            if (! is_file($plik)) {
                continue;
            }

            $klasa = 'McsPluginy\\' . $zrodlo . '\\Plugin';
            if (! class_exists($klasa, false)) {
                require_once $plik;
            }
            if (! class_exists($klasa, false)) {
                continue;
            }

            $instancja = new $klasa();
            if (! $instancja instanceof SourcePluginInterface) {
                continue;
            }

            $wynik[] = $instancja;
        }

        return $wynik;
    }
}
