<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsQratorWiersze extends Migration
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
            'event_typ' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'asn' => [
                'type' => 'INTEGER',
                'null' => false,
            ],
            'prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'severity' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'first_seen' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'last_seen' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
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
        $this->forge->addKey('asn');
        $this->forge->addKey('event_typ');
        $this->forge->addKey('prefix');
        $this->forge->addUniqueKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_qrator_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_qrator_wiersze', true);
    }
}
