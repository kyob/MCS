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

        // Brak wiersza CIDR pozwala dobrać prefiksy do ASN lub odczytać je z .env.
        $this->db->table('mcs_ustawienia')->insert([
            'klucz'      => 'as198050_wlaczone',
            'wartosc'    => '1',
            'updated_at' => date('c'),
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_ustawienia', true);
    }
}
