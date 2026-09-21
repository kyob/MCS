<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache mapowania IP → komputer/abonent LMS (enrichment + sync plugin).
 * Poza indeksem incydentów / badge streamowym.
 */
class CreateMcsEnrichmentLms extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'node_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'komputer' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'node_login' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'customer_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'abonent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'customer_lastname' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'customer_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'netid' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'access' => [
                'type'       => 'SMALLINT',
                'null'       => true,
            ],
            'trafiony' => [
                'type'    => 'BOOLEAN',
                'null'    => false,
                'default' => false,
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
        $this->forge->addKey('node_id');
        $this->forge->addKey('customer_id');
        $this->forge->addKey('checked_at');
        $this->forge->createTable('mcs_enrichment_lms', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_lms', true);
    }
}
