<?php

namespace App\Libraries;

use App\Models\IncydentModel;
use App\Models\ObserwacjaModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Składa szczegóły incydentu: skrót + timeline + wiersz tabeli źródła.
 */
class IncydentSzczegoly
{
    private BaseConnection $db;
    private SzczegolyZrodla $zrodla;

    public function __construct(?BaseConnection $db = null, ?SzczegolyZrodla $zrodla = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->zrodla = $zrodla ?? new SzczegolyZrodla($this->db);
    }

    /**
     * @return array{
     *   incydent: array<string, mixed>,
     *   obserwacje: list<array<string, mixed>>,
     *   zrodlo_tabela: ?string,
     *   zrodlo_wiersz: ?array<string, mixed>
     * }|null
     */
    public function dlaIpIId(string $ip, int $incydentId): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || $incydentId <= 0) {
            return null;
        }

        $incydent = model(IncydentModel::class)->find($incydentId);
        if (! is_array($incydent)) {
            return null;
        }

        if (($incydent['podmiot_typ'] ?? '') !== 'ip'
            || (string) ($incydent['podmiot'] ?? '') !== $ip
            || ! empty($incydent['ukryty_w_ui'])
        ) {
            return null;
        }

        // Ukryte źródło w rejestrze pluginów → brak szczegółów w UI.
        $zrodlo = (string) ($incydent['zrodlo'] ?? '');
        if ($zrodlo !== '') {
            $plugin = $this->db->table('mcs_pluginy')
                ->where('zrodlo', $zrodlo)
                ->get()
                ->getRowArray();
            if (is_array($plugin) && ! empty($plugin['ukryty_w_ui'])) {
                return null;
            }
        }

        $obserwacje = model(ObserwacjaModel::class)->dlaIncydentu($incydentId);

        $idWZrodle = null;
        foreach ($obserwacje as $obs) {
            $kandydat = isset($obs['id_w_zrodle']) ? trim((string) $obs['id_w_zrodle']) : '';
            if ($kandydat !== '') {
                $idWZrodle = $kandydat;
                break;
            }
        }

        $zrodloTabela = $this->zrodla->tabelaDla($zrodlo);
        $zrodloWiersz = ($idWZrodle !== null) ? $this->zrodla->znajdz($zrodlo, $idWZrodle) : null;

        return [
            'incydent'      => $incydent,
            'obserwacje'    => $obserwacje,
            'zrodlo_tabela' => $zrodloTabela,
            'zrodlo_wiersz' => $zrodloWiersz,
        ];
    }
}
