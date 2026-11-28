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
        'as198050_wlaczone' => 'as_wlaczone',
        'mcs.as198050.asn'  => 'mcs.as.asn',
    ];

    public function up()
    {
        // Dawna lista CIDR pozostaje przypisana do AS198050.
        foreach (self::ZMIANY as $stary => $nowy) {
            $this->przenies($stary, $nowy);
        }
    }

    public function down()
    {
        $this->db->transStart();
        foreach (['as198050_cidry' => 'as_cidry'] + self::ZMIANY as $stary => $nowy) {
            $tabela = $this->db->table('mcs_ustawienia');
            $wiersz = $tabela->where('klucz', $nowy)->get()->getRowArray();
            if ($wiersz === null) {
                continue;
            }

            $wiersz['klucz'] = $stary;
            $tabela->upsert($wiersz);
            // Zachowaj jawną listę także dla ponownej aktualizacji z alternatywnym ASN.
            if ($nowy !== 'as_cidry') {
                $tabela->where('klucz', $nowy)->delete();
            }
        }
        if (! $this->db->transComplete()) {
            throw new \RuntimeException('Nie udało się przywrócić starszych kluczy AS.');
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
