<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Proste ustawienia klucz→wartość (mcs_ustawienia).
 */
class UstawieniaModel extends Model
{
    protected $table            = 'mcs_ustawienia';
    protected $primaryKey       = 'klucz';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['klucz', 'wartosc', 'updated_at'];
    protected $useTimestamps    = false;

    public function pobierz(string $klucz, ?string $domyslnie = null): ?string
    {
        $wiersz = $this->find($klucz);
        if ($wiersz === null) {
            return $domyslnie;
        }

        return (string) ($wiersz['wartosc'] ?? $domyslnie);
    }

    /**
     * @param list<string> $klucze
     *
     * @return array<string, string> klucz → wartość (tylko istniejące wiersze)
     */
    public function pobierzWiele(array $klucze): array
    {
        if ($klucze === []) {
            return [];
        }

        $out = [];
        foreach ($this->whereIn('klucz', $klucze)->findAll() as $wiersz) {
            $out[(string) $wiersz['klucz']] = (string) ($wiersz['wartosc'] ?? '');
        }

        return $out;
    }

    public function ustaw(string $klucz, string $wartosc): bool
    {
        $klucz = trim($klucz);
        if ($klucz === '') {
            return false;
        }

        $dane = [
            'klucz'      => $klucz,
            'wartosc'    => $wartosc,
            'updated_at' => date('c'),
        ];

        if ($this->find($klucz) === null) {
            return $this->insert($dane) !== false;
        }

        return $this->update($klucz, [
            'wartosc'    => $wartosc,
            'updated_at' => $dane['updated_at'],
        ]);
    }

    public function jakoBool(string $klucz, bool $domyslnie = false): bool
    {
        $raw = $this->pobierz($klucz);
        if ($raw === null) {
            return $domyslnie;
        }

        return PluginModel::jakoBool($raw);
    }
}
