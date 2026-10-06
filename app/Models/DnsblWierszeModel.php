<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Wiersze źródła DNSBL (stream) — historia per (ip, lista).
 */
class DnsblWierszeModel extends Model
{
    protected $table            = 'mcs_dnsbl_wiersze';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'import_id',
        'ip',
        'lista',
        'kod_a',
        'zakres_skanu',
        'listed',
        'pierwszy_seen_at',
        'ostatni_seen_at',
        'klucz_dedupu',
        'payload_json',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoIpLista(string $ip, string $lista): ?array
    {
        $wiersz = $this->where('ip', $ip)->where('lista', $lista)->first();

        return is_array($wiersz) ? $wiersz : null;
    }
}
