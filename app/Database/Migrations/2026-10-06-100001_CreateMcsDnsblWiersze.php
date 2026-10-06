<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Historia trafień DNSBL (stream dnsbl) — source rows pod IncydentZapis.
 */
class CreateMcsDnsblWiersze extends Migration
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
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'lista' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'kod_a' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'zakres_skanu' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => false,
                'default'    => 'lms',
            ],
            'listed' => [
                'type'    => 'BOOLEAN',
                'null'    => false,
                'default' => true,
            ],
            'pierwszy_seen_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'ostatni_seen_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
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
        $this->forge->addKey('lista');
        $this->forge->addKey('listed');
        $this->forge->addUniqueKey(['ip', 'lista']);
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_dnsbl_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_dnsbl_wiersze', true);
    }
}
