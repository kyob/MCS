<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache enrichment isMalicious — poza indeksem incydentów / badge.
 */
class CreateMcsEnrichmentIsmalicious extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'malicious' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'risk_score' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'confidence' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'blocklist_hits' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'blocklist_listed' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'classification' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
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
        $this->forge->createTable('mcs_enrichment_ismalicious', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_ismalicious', true);
    }
}
