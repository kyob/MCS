<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Usunięcie wtyczki qrator (API Qrator Radar płatne, decyzja 2026-10-15).
 *
 * Kasuje tylko wiersz konfiguracji w mcs_pluginy. Tabela danych mcs_qrator_wiersze
 * zostaje (usunięcie po decyzji właściciela).
 */
class UsunPluginQrator extends Migration
{
    public function up()
    {
        $this->db->table('mcs_pluginy')->where('zrodlo', 'qrator')->delete();
    }

    public function down()
    {
        // Brak: wiersz odtwarza `php spark mcs:seed-pluginy` po przywróceniu kodu wtyczki.
    }
}
