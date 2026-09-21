<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Historia testów Speedtest Servers reporting — poza indeksem incydentów / badge.
 */
class CreateMcsEnrichmentSpeedtest extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'test_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'zaobserwowano_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'download_kbps' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'upload_kbps' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'latency_ms' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'isp' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'platform' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'server_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'raw_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'zrodlo_danych' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'csv',
            ],
            'created_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
        ]);
        $this->forge->addKey('test_id', true);
        $this->forge->addKey('ip');
        $this->forge->addKey('zaobserwowano_at');
        $this->forge->createTable('mcs_enrichment_speedtest', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_speedtest', true);
    }
}
