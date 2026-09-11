<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsWanguardAnomalie extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'import_id' => [
                'type' => 'BIGINT',
                'null' => true,
            ],
            'zaobserwowano_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'anomaly_id' => [
                'type' => 'BIGINT',
                'null' => false,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'anomaly' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '',
            ],
            'direction' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'from_unix' => [
                'type' => 'BIGINT',
                'null' => true,
            ],
            'until_unix' => [
                'type' => 'BIGINT',
                'null' => true,
            ],
            'duration_seconds' => [
                'type' => 'INTEGER',
                'null' => true,
            ],
            'mitigated' => [
                'type' => 'SMALLINT',
                'null' => true,
            ],
            'severity' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'link_severity' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'peak_pkts_s' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'peak_bits_s' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'klucz_dedupu' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'payload_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('import_id');
        $this->forge->addKey('ip');
        $this->forge->addUniqueKey('anomaly_id');
        $this->forge->addKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_wanguard_anomalie', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_wanguard_anomalie', true);
    }
}
