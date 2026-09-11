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
}
