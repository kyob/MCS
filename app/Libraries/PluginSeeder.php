<?php

namespace App\Libraries;

use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;

class PluginSeeder
{
    /**
     * Placeholdery źródeł Fazy 1a — bez kodu importera.
     *
     * @var list<array{zrodlo: string, nazwa: string}>
     */
    public const PLACEHOLDERY = [
        ['zrodlo' => 'shadowserver', 'nazwa' => 'Shadowserver'],
        ['zrodlo' => 'play', 'nazwa' => 'Play'],
        ['zrodlo' => 'snds', 'nazwa' => 'SNDS'],
        ['zrodlo' => 'mojecert', 'nazwa' => 'MojeCert'],
    ];

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Dopisuje brakujące źródła. Istniejące wiersze zostawia bez zmian.
     *
     * @return array{nowe: list<string>, juz_byly: list<string>}
     */
    public function zapisz(): array
    {
        $model   = new PluginModel($this->db);
        $nowe    = [];
        $juzByly = [];

        foreach (self::PLACEHOLDERY as $wiersz) {
            if ($model->find($wiersz['zrodlo']) !== null) {
                $juzByly[] = $wiersz['zrodlo'];
                continue;
            }

            $model->insert([
                'zrodlo'            => $wiersz['zrodlo'],
                'nazwa'             => $wiersz['nazwa'],
                'import_wstrzymany' => true,
                'ukryty_w_ui'       => false,
            ]);
            $nowe[] = $wiersz['zrodlo'];
        }

        return [
            'nowe'     => $nowe,
            'juz_byly' => $juzByly,
        ];
    }
}
