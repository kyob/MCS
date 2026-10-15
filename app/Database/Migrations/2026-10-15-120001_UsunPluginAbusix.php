<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Usunięcie wtyczki abusix (płatna subskrypcja, decyzja 2026-10-15).
 *
 * Kasuje tylko wiersz konfiguracji w mcs_pluginy. Tabele danych mcs_abusix_* i historia
 * w mcs_importy zostają (usunięcie po decyzji właściciela).
 */
class UsunPluginAbusix extends Migration
{
    public function up()
    {
        $this->db->table('mcs_pluginy')->where('zrodlo', 'abusix')->delete();
    }

    public function down()
    {
        // Brak: wiersz odtwarza `php spark mcs:seed-pluginy` po przywróceniu kodu wtyczki.
    }
}
