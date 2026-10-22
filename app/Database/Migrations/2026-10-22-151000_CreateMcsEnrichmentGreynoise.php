<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache enrichment GreyNoise Community — poza indeksem incydentów / badge.
 */
class CreateMcsEnrichmentGreynoise extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'noise' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'riot' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'classification' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'link' => [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ],
            'last_seen' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'http_status' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'raw_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'zrodlo_danych' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'api',
            ],
            'checked_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
        ]);
        $this->forge->addKey('ip', true);
        $this->forge->addKey('checked_at');
        $this->forge->createTable('mcs_enrichment_greynoise', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_greynoise', true);
    }
}
