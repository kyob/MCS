<?php

namespace App\Models;

use CodeIgniter\Model;

class PluginModel extends Model
{
    protected $table            = 'mcs_pluginy';
    protected $primaryKey       = 'zrodlo';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'nazwa',
        'opis',
        'import_wstrzymany',
        'ukryty_w_ui',
        'health_at',
        'health_ok',
        'health_msg',
    ];
    protected $useTimestamps = false;

    /**
     * Wszystkie wtyczki z rejestru, alfabetycznie po źródle.
     *
     * @return list<array<string, mixed>>
     */
    public function wszystkie(): array
    {
        $lista = $this->orderBy('zrodlo', 'ASC')->findAll();

        foreach ($lista as &$wiersz) {
            $wiersz['import_wstrzymany'] = self::jakoBool($wiersz['import_wstrzymany'] ?? false);
            $wiersz['ukryty_w_ui']       = self::jakoBool($wiersz['ukryty_w_ui'] ?? false);
            if (array_key_exists('health_ok', $wiersz) && $wiersz['health_ok'] !== null) {
                $wiersz['health_ok'] = self::jakoBool($wiersz['health_ok']);
            }
        }
        unset($wiersz);

        return $lista;
    }

    /**
     * Zapisuje rozdzielone stany: wstrzymanie importu oraz ukrycie w UI/badge.
     * Nie rusza health_* ani nazwy.
     */
    public function ustawStany(string $zrodlo, bool $importWstrzymany, bool $ukrytyWUi): bool
    {
        $zrodlo = trim($zrodlo);
        if ($zrodlo === '' || $this->find($zrodlo) === null) {
            return false;
        }

        return $this->update($zrodlo, [
            'import_wstrzymany' => $importWstrzymany,
            'ukryty_w_ui'       => $ukrytyWUi,
        ]);
    }

    /**
     * Postgres potrafi zwrócić 't'/'f'; SQLite w testach — 0/1.
     */
    public static function jakoBool(mixed $wartosc): bool
    {
        if (is_bool($wartosc)) {
            return $wartosc;
        }
        if (is_int($wartosc) || is_float($wartosc)) {
            return (int) $wartosc === 1;
        }
        if (is_string($wartosc)) {
            return in_array(strtolower($wartosc), ['1', 't', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
