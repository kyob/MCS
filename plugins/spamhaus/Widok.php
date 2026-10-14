<?php

namespace McsPluginy\spamhaus;

use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Dane karty /dashboard/spamhaus (tylko odczyt DB).
 */
final class Widok
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{
     *   stan: array<string, mixed>,
     *   pliki: list<array<string, mixed>>,
     *   trafienia: list<array<string, mixed>>,
     *   historia: list<array<string, mixed>>,
     *   health_ok: ?bool,
     *   health_msg: string,
     *   health_at: string,
     *   plik_data: string,
     *   copyright: string
     * }
     */
    public function dane(int $limitHistorii = 30): array
    {
        $stan  = $this->db->table('mcs_spamhaus_stan')->where('id', 1)->get()->getRowArray() ?? [];
        $pliki = $this->db->table('mcs_spamhaus_pliki')->orderBy('lista')->get()->getResultArray();
        $traf  = $this->db->table('mcs_spamhaus_trafienia')
            ->orderBy('aktywne', 'DESC')->orderBy('ostatnie_at', 'DESC')->limit(200)->get()->getResultArray();
        $hist  = $this->db->table('mcs_spamhaus_historia')
            ->orderBy('id', 'DESC')->limit($limitHistorii)->get()->getResultArray();

        $plugin = model(PluginModel::class)->find(Serwis::ZRODLO);
        $plugin = is_array($plugin) ? $plugin : [];

        $najnowszy = null;
        $copyright = '';
        foreach ($pliki as $p) {
            $t = Serwis::ts($p['plik_timestamp'] ?? null);
            if ($t !== null && ($najnowszy === null || $t > $najnowszy)) {
                $najnowszy = $t;
            }
            if ($copyright === '' && (string) ($p['copyright'] ?? '') !== '') {
                $copyright = (string) $p['copyright'];
            }
        }

        $ok = $plugin['health_ok'] ?? null;

        return [
            'stan'       => $stan,
            'pliki'      => $pliki,
            'trafienia'  => $traf,
            'historia'   => $hist,
            'health_ok'  => $ok === null ? null : PluginModel::jakoBool($ok),
            'health_msg' => (string) ($plugin['health_msg'] ?? ''),
            'health_at'  => (string) ($plugin['health_at'] ?? ''),
            'plik_data'  => $najnowszy !== null ? gmdate('Y-m-d H:i', $najnowszy) . ' UTC' : '',
            'copyright'  => $copyright,
        ];
    }
}
