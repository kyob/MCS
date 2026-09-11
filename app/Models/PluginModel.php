<?php

namespace App\Models;

use CodeIgniter\Model;

class PluginModel extends Model
{
    protected $table            = 'mcs_pluginy';
    protected $primaryKey       = 'zrodlo';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'nazwa',
        'import_wstrzymany',
        'ukryty_w_ui',
        'health_at',
        'health_ok',
        'health_msg',
    ];
    protected $useTimestamps = false;
}
