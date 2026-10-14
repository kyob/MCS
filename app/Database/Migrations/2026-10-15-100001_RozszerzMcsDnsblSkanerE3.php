<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Skaner DNSBL, etap E3: harmonogram (noc / recheck), historia zmian listingów, incydenty, fala.
 *
 * mcs_dnsbl_skany: pary (recheck: lista [kod, klucz]), nowe / zdjete / incydenty, fala (możliwy błąd skanu).
 * mcs_dnsbl_zdarzenia: historia „nowy” / „zdjety” per ip+strefa.
 */
class RozszerzMcsDnsblSkanerE3 extends Migration
{
    public function up()
    {
        $this->forge->addColumn('mcs_dnsbl_skany', [
            'pary'      => ['type' => 'JSONB', 'null' => true],
            'nowe'      => ['type' => 'INT', 'default' => 0],
            'zdjete'    => ['type' => 'INT', 'default' => 0],
            'incydenty' => ['type' => 'INT', 'default' => 0],
            'fala'      => ['type' => 'BOOLEAN', 'default' => false],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'auto_increment' => true],
            'at'          => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'ip'          => ['type' => 'VARCHAR', 'constraint' => 45],
            'strefa_kod'  => ['type' => 'VARCHAR', 'constraint' => 32],
            // nowy | zdjety
            'typ'         => ['type' => 'VARCHAR', 'constraint' => 16],
            'skan_id'     => ['type' => 'BIGINT', 'null' => true],
            'kody'        => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'incydent_id' => ['type' => 'BIGINT', 'null' => true],
            'uwagi'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['ip', 'strefa_kod']);
        $this->forge->addKey('at');
        $this->forge->addForeignKey('skan_id', 'mcs_dnsbl_skany', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_dnsbl_zdarzenia', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_dnsbl_zdarzenia', true);
        $this->forge->dropColumn('mcs_dnsbl_skany', ['pary', 'nowe', 'zdjete', 'incydenty', 'fala']);
    }
}
