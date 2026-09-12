<?php

namespace App\Models;

use CodeIgniter\Model;

class ObserwacjaModel extends Model
{
    protected $table            = 'mcs_obserwacje';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'incydent_id',
        'import_id',
        'zaobserwowano_at',
        'payload_json',
        'id_w_zrodle',
    ];
    protected $useTimestamps = false;

    /**
     * Timeline obserwacji dla jednego incydentu (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function dlaIncydentu(int $incydentId): array
    {
        if ($incydentId <= 0) {
            return [];
        }

        $wiersze = $this->where('incydent_id', $incydentId)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        if (! is_array($wiersze)) {
            return [];
        }

        $lista = [];
        foreach ($wiersze as $wiersz) {
            $payload = $wiersz['payload_json'] ?? null;
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $wiersz['payload'] = is_array($decoded) ? $decoded : null;
            } else {
                $wiersz['payload'] = null;
            }
            $lista[] = $wiersz;
        }

        return $lista;
    }
}
