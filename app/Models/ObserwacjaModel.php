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
}
