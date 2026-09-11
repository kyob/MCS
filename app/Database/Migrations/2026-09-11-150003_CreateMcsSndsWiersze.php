<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsSndsWiersze extends Migration
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
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'filtr' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
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
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_snds_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_snds_wiersze', true);
    }
}
