<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsCertN6Zdarzenia extends Migration
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
            'n6_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'asn' => [
                'type'       => 'INTEGER',
                'default'    => 0,
            ],
            'cc' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'default'    => '',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'confidence' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '',
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'dport' => [
                'type'    => 'INTEGER',
                'default' => 0,
            ],
            'sport' => [
                'type'    => 'INTEGER',
                'default' => 0,
            ],
            'fqdn' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '',
            ],
            'proto' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => '',
            ],
            'origin' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'restriction' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => '',
            ],
            'modified' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => '',
            ],
            'adip' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'default'    => '',
            ],
            'product' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'default'    => '',
            ],
            'n6kb_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 1024,
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
        $this->forge->addUniqueKey('n6_id');
        $this->forge->addKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_cert_n6_zdarzenia', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_cert_n6_zdarzenia', true);
    }
}
