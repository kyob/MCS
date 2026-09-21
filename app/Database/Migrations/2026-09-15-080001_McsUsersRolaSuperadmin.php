<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dawna rola admin → superadmin; nowa rola admin (wtyczki/ustawienia, bez kont).
 */
class McsUsersRolaSuperadmin extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE mcs_users DROP CONSTRAINT IF EXISTS mcs_users_rola_check');
        $this->db->query("UPDATE mcs_users SET rola = 'superadmin' WHERE rola = 'admin'");
        $this->db->query(
            "ALTER TABLE mcs_users ADD CONSTRAINT mcs_users_rola_check CHECK (rola IN ('superadmin','admin','operator'))",
        );
    }

    public function down()
    {
        $this->db->query('ALTER TABLE mcs_users DROP CONSTRAINT IF EXISTS mcs_users_rola_check');
        $this->db->query("UPDATE mcs_users SET rola = 'admin' WHERE rola = 'superadmin'");
        $this->db->query(
            "ALTER TABLE mcs_users ADD CONSTRAINT mcs_users_rola_check CHECK (rola IN ('admin','operator'))",
        );
    }
}
