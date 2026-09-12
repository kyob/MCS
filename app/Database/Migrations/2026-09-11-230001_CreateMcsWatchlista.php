<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Watchlista obserwowanych IP (digest operacyjny — tylko INFO).
 */
class CreateMcsWatchlista extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'notatka' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'dodano_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'dodano_przez' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('ip');
        $this->forge->createTable('mcs_watchlista', true);
        $this->db->query('ALTER TABLE mcs_watchlista ALTER COLUMN dodano_at SET DEFAULT now()');
    }

    public function down()
    {
        $this->forge->dropTable('mcs_watchlista', true);
    }
}
