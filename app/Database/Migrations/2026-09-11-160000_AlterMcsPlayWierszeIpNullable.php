<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Powiadomienia DDoS Play bywają na poziomie sieci/policy (bez IP hosta).
 */
class AlterMcsPlayWierszeIpNullable extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE mcs_play_wiersze ALTER COLUMN ip DROP NOT NULL');
    }

    public function down()
    {
        $this->db->query("UPDATE mcs_play_wiersze SET ip = '0.0.0.0' WHERE ip IS NULL");
        $this->db->query('ALTER TABLE mcs_play_wiersze ALTER COLUMN ip SET NOT NULL');
    }
}
