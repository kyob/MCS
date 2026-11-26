<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Klucze ustawień AS bez numeru ASN w nazwie (ASN jest konfigurowalny w mcs.as.asn).
 * Istniejący nowy klucz wygrywa — stary wiersz zostaje wtedy nietknięty.
 */
class ZmienKluczeAsNaNeutralne extends Migration
{
    private const ZMIANY = [
        'as198050_cidry'    => 'as_cidry',
        'as198050_wlaczone' => 'as_wlaczone',
        'mcs.as198050.asn'  => 'mcs.as.asn',
    ];

    public function up()
    {
        foreach (self::ZMIANY as $stary => $nowy) {
            $this->przenies($stary, $nowy);
        }
    }

    public function down()
    {
        foreach (self::ZMIANY as $stary => $nowy) {
            $this->przenies($nowy, $stary);
        }
    }

    private function przenies(string $z, string $na): void
    {
        if ($this->db->table('mcs_ustawienia')->where('klucz', $na)->countAllResults() > 0) {
            return;
        }
        $this->db->table('mcs_ustawienia')->where('klucz', $z)->update(['klucz' => $na]);
    }
}
