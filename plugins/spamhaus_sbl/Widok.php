<?php

namespace McsPluginy\spamhaus_sbl;

use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Dane karty Spamhaus SBL w /admin/pluginy (tylko odczyt DB, bez zapytań sieciowych).
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
     *   health_ok: ?bool, health_msg: string, health_at: string,
     *   przetworzone: int, pominiete: int, nieuwierzytelnione: int,
     *   ostatni_sbl: string, ostatni_sbl_at: string, ostatni_sbl_link: ?string
     * }
     */
    public function dane(): array
    {
        $t = Plugin::TAB_WIADOMOSCI;

        $przetworzone = $this->db->table($t)->where('status', Plugin::STATUS_PRZYJETA)->countAllResults();
        $pominiete    = $this->db->table($t)->where('status !=', Plugin::STATUS_PRZYJETA)->countAllResults();
        $nieuw        = $this->db->table($t)->where('status', Plugin::STATUS_PRZYJETA)
            ->where('nieuwierzytelniony', true)->countAllResults();
        $ostatni = $this->db->table($t)->select('sbl, przetworzono_at')
            ->where('status', Plugin::STATUS_PRZYJETA)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $plugin = model(PluginModel::class)->find(Plugin::ZRODLO);
        $plugin = is_array($plugin) ? $plugin : [];
        $ok     = $plugin['health_ok'] ?? null;
        $sbl    = is_array($ostatni) ? (string) ($ostatni['sbl'] ?? '') : '';

        return [
            'health_ok'          => $ok === null ? null : PluginModel::jakoBool($ok),
            'health_msg'         => (string) ($plugin['health_msg'] ?? ''),
            'health_at'          => (string) ($plugin['health_at'] ?? ''),
            'przetworzone'       => $przetworzone,
            'pominiete'          => $pominiete,
            'nieuwierzytelnione' => $nieuw,
            'ostatni_sbl'        => $sbl,
            'ostatni_sbl_at'     => is_array($ostatni) ? (string) ($ostatni['przetworzono_at'] ?? '') : '',
            'ostatni_sbl_link'   => $sbl !== '' ? Parser::linkPrzegladu($sbl) : null,
        ];
    }
}
