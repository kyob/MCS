<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsCloudflareBotnetWiersze extends Migration
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
            'cidr' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'report_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'offense_count' => [
                'type'    => 'INTEGER',
                'null'    => false,
                'default' => 0,
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
        $this->forge->addKey('report_date');
        $this->forge->addUniqueKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_cloudflare_botnet_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_cloudflare_botnet_wiersze', true);
    }
}
