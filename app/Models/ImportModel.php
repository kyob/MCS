<?php

namespace App\Models;

use CodeIgniter\Model;

class ImportModel extends Model
{
    protected $table            = 'mcs_importy';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'id_wiadomosci',
        'rozpoczeto_at',
        'zakonczono_at',
        'status',
        'checksum',
        'meta_json',
        'blad_text',
    ];
    protected $useTimestamps = false;

    /**
     * Ostatnie przebiegi importu (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function ostatnie(int $limit = 30, ?string $zrodlo = null): array
    {
        $limit = max(1, min(200, $limit));
        $b     = $this->builder();

        if ($zrodlo !== null && $zrodlo !== '') {
            $b->where('zrodlo', $zrodlo);
        }

        return $b->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }
}
