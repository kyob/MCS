<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cache enrichment SpamRats DNSBL — poza indeksem incydentów / badge.
 */
class CreateMcsEnrichmentSpamrats extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'listed' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'listed_dyna' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'listed_noptr' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'listed_spam' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'listed_auth' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'kody_json' => [
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
        $this->forge->createTable('mcs_enrichment_spamrats', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_enrichment_spamrats', true);
    }
}
