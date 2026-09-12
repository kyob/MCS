<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Klucz-wartość ustawień MCS (m.in. prefiksy AS198050).
 */
class CreateMcsUstawienia extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'klucz' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'wartosc' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('klucz', true);
        $this->forge->createTable('mcs_ustawienia', true);

        // Domyślne prefiksy AS198050 (Alfa-System) wg RIPE announced-prefixes (2026-09).
        $cidry = implode("\n", [
            '5.253.230.0/24',
            '64.46.124.0/24',
            '64.190.43.0/24',
            '91.231.70.0/23',
            '91.231.80.0/22',
            '176.97.24.0/21',
            '194.169.138.0/24',
            '209.162.203.0/24',
            '212.47.33.0/24',
            '2a0f:1700::/29',
        ]);

        $this->db->table('mcs_ustawienia')->insertBatch([
            [
                'klucz'      => 'as198050_wlaczone',
                'wartosc'    => '1',
                'updated_at' => date('c'),
            ],
            [
                'klucz'      => 'as198050_cidry',
                'wartosc'    => $cidry,
                'updated_at' => date('c'),
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_ustawienia', true);
    }
}
