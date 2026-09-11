<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Faza 1b Task 1: koperta importu + wersja wagi na incydencie.
 */
class RozszerzImportyKopertaIWaga extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE mcs_importy DROP CONSTRAINT IF EXISTS mcs_importy_status_check');
        $this->db->query(
            "ALTER TABLE mcs_importy ADD CONSTRAINT mcs_importy_status_check
             CHECK (status IN ('ok','blad','w_trakcie','kwarantanna'))",
        );

        $this->forge->addColumn('mcs_importy', [
            'id_wiadomosci' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);

        $this->db->query(
            'CREATE INDEX IF NOT EXISTS mcs_importy_zrodlo_checksum_idx
             ON mcs_importy (zrodlo, checksum)',
        );
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS mcs_importy_zrodlo_id_wiadomosci_idx
             ON mcs_importy (zrodlo, id_wiadomosci)',
        );

        $this->forge->addColumn('mcs_incydenty', [
            'wersja_wagi' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('mcs_incydenty', 'wersja_wagi');

        $this->db->query('DROP INDEX IF EXISTS mcs_importy_zrodlo_id_wiadomosci_idx');
        $this->db->query('DROP INDEX IF EXISTS mcs_importy_zrodlo_checksum_idx');
        $this->forge->dropColumn('mcs_importy', 'id_wiadomosci');

        $this->db->query('ALTER TABLE mcs_importy DROP CONSTRAINT IF EXISTS mcs_importy_status_check');
        $this->db->query(
            "ALTER TABLE mcs_importy ADD CONSTRAINT mcs_importy_status_check
             CHECK (status IN ('ok','blad','w_trakcie'))",
        );
    }
}
