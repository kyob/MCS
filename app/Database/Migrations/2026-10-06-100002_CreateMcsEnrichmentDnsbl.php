<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache enrichment DNSBL multi-list — panel /ip/{ip} i dashboard.
 */
class CreateMcsEnrichmentDnsbl extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'listed_any' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'listy_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'raw_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'zrodlo_danych' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'dns',
            ],
            'zakres_skanu' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
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
        $this->forge->addKey('listed_any');
        $this->forge->createTable('mcs_enrichment_dnsbl', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_dnsbl', true);
    }
}
