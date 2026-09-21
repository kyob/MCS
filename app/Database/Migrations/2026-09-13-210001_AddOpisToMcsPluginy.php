<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Opis źródła w rejestrze wtyczek (admin UI).
 */
class AddOpisToMcsPluginy extends Migration
{
    private const PLAY_OPIS = 'Play Ochrona Anty-DDoS — MCS importuje alerty e-mail (IMAP) jako incydenty.';

    public function up()
    {
        if (! $this->db->fieldExists('opis', 'mcs_pluginy')) {
            $this->forge->addColumn('mcs_pluginy', [
                'opis' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]);
        }

        $this->db->table('mcs_pluginy')
            ->where('zrodlo', 'play')
            ->update(['opis' => self::PLAY_OPIS]);
    }

    public function down()
    {
        if ($this->db->fieldExists('opis', 'mcs_pluginy')) {
            $this->forge->dropColumn('mcs_pluginy', 'opis');
        }
    }
}
