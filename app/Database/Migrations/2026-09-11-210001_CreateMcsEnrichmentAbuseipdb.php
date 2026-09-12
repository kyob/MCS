<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache enrichment AbuseIPDB (Faza 3) — poza indeksem incydentów / badge.
 */
class CreateMcsEnrichmentAbuseipdb extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'abuse_confidence_score' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'total_reports' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'last_reported_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'country_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 8,
                'null'       => true,
            ],
            'isp' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'usage_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'is_whitelisted' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
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
        $this->forge->createTable('mcs_enrichment_abuseipdb', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_abuseipdb', true);
    }
}
