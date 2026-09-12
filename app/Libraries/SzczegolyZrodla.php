<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Odczyt bogatych danych z tabel źródeł (zrodlo + id_w_zrodle).
 */
class SzczegolyZrodla
{
    /** @var array<string, string> */
    public const TABELE = [
        'shadowserver' => 'mcs_shadowserver_wiersze',
        'play'         => 'mcs_play_wiersze',
        'snds'         => 'mcs_snds_wiersze',
        'cert_n6'      => 'mcs_cert_n6_zdarzenia',
        'mojecert'     => 'mcs_mojecert_skany',
        'wanguard'     => 'mcs_wanguard_anomalie',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function tabelaDla(string $zrodlo): ?string
    {
        $zrodlo = trim($zrodlo);

        return self::TABELE[$zrodlo] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function znajdz(string $zrodlo, string $idWZrodle): ?array
    {
        $tabela = $this->tabelaDla($zrodlo);
        $idWZrodle = trim($idWZrodle);
        if ($tabela === null || $idWZrodle === '') {
            return null;
        }

        if (! ctype_digit($idWZrodle)) {
            return null;
        }

        $wiersz = $this->db->table($tabela)
            ->where('id', (int) $idWZrodle)
            ->get()
            ->getRowArray();

        if (! is_array($wiersz)) {
            return null;
        }

        $payload = $wiersz['payload_json'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $wiersz['payload'] = is_array($decoded) ? $decoded : null;
        }

        return $wiersz;
    }
}
