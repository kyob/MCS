<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Wiersze źródła Wazuh: agregat ip + kategoria_wazuh + dzień (UTC) z eksportu
 * /usr/local/lib/wazuh-mcs-export. Plan: docs/11-plan-zrodlo-wazuh.md
 */
class CreateMcsWazuhWiersze extends Migration
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
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'dzien' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'kategoria_wazuh' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'liczba' => [
                'type'    => 'INTEGER',
                'default' => 0,
            ],
            'poziom_max' => [
                'type'    => 'SMALLINT',
                'default' => 0,
            ],
            'pierwsze_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'ostatnie_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'hosty_docelowe' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'reguly' => [
                'type' => 'JSONB',
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
        $this->forge->addKey('ip');
        $this->forge->addUniqueKey(['ip', 'kategoria_wazuh', 'dzien']);
        $this->forge->addKey('klucz_dedupu');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_wazuh_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_wazuh_wiersze', true);
    }
}
