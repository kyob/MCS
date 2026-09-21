<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsSpooferWiersze extends Migration
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
            'session_id' => [
                'type' => 'BIGINT',
                'null' => false,
            ],
            'ip_wersja' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
            ],
            'prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'asn' => [
                'type' => 'INTEGER',
                'null' => false,
            ],
            'routedspoof' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'privatespoof' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'country' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
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
        $this->forge->addKey('asn');
        $this->forge->addKey('prefix');
        $this->forge->addKey('session_id');
        $this->forge->addUniqueKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_spoofer_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_spoofer_wiersze', true);
    }
}
