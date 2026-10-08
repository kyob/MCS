<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Dane widoku /dashboard/abusix: KPI, sprawy, zdarzenia, klienci, czas ostatniego udanego pobrania.
 */
class AbusixWidok
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{
     *   ostatni_ok: ?string, health_ok: ?bool, health_msg: string, health_at: ?string,
     *   kpi: array{sprawy: int, otwarte: int, zdarzenia: int, klienci: int},
     *   wg_stanu: array<string,int>,
     *   sprawy: list<array<string,mixed>>, zdarzenia: list<array<string,mixed>>, klienci: list<array<string,mixed>>
     * }
     */
    public function dane(int $limit = 100): array
    {
        $plugin = $this->db->table('mcs_pluginy')->where('zrodlo', 'abusix')->get()->getRowArray() ?: [];
        $ok     = $this->db->table('mcs_importy')->selectMax('zakonczono_at', 'ts')
            ->where('zrodlo', 'abusix')->where('status', 'ok')->get()->getRowArray();

        $wgStanu = [];
        foreach ($this->db->table('mcs_abusix_sprawy')->select('state, COUNT(*) AS n')->groupBy('state')->get()->getResultArray() as $r) {
            $wgStanu[(string) $r['state']] = (int) $r['n'];
        }
        $otwarte = ($wgStanu['NEW'] ?? 0) + ($wgStanu['IN_PROGRESS'] ?? 0);

        $zdarzenia = $this->db->table('mcs_abusix_zdarzenia')->orderBy('zaobserwowano_at', 'DESC')->limit($limit)->get()->getResultArray();

        return [
            'ostatni_ok' => ! empty($ok['ts']) ? (string) $ok['ts'] : null,
            'health_ok'  => array_key_exists('health_ok', $plugin) && $plugin['health_ok'] !== null ? \App\Models\PluginModel::jakoBool($plugin['health_ok']) : null,
            'health_msg' => (string) ($plugin['health_msg'] ?? ''),
            'health_at'  => ! empty($plugin['health_at']) ? (string) $plugin['health_at'] : null,
            'kpi'        => [
                'sprawy'    => array_sum($wgStanu),
                'otwarte'   => $otwarte,
                'zdarzenia' => $this->db->table('mcs_abusix_zdarzenia')->countAllResults(),
                'klienci'   => $this->db->table('mcs_abusix_klienci')->countAllResults(),
            ],
            'wg_stanu'  => $wgStanu,
            'sprawy'    => $this->db->table('mcs_abusix_sprawy')->orderBy('last_event_date', 'DESC')->limit($limit)->get()->getResultArray(),
            'zdarzenia' => $zdarzenia,
            'klienci'   => $this->db->table('mcs_abusix_klienci')->orderBy('customer_id')->limit($limit)->get()->getResultArray(),
        ];
    }
}
