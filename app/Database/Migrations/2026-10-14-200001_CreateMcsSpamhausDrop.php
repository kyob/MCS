<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plugin spamhaus — Spamhaus DROP / DROPv6 / ASN-DROP dopasowane lokalnie do prefiksów AS.
 *
 * mcs_spamhaus_stan       — jeden wiersz (id=1): harmonogram, backoff, wynik (brak|czysto|trafienie).
 * mcs_spamhaus_pliki      — metadane ostatniego udanego pliku per lista (timestamp/copyright z JSON).
 * mcs_spamhaus_trafienia  — stan bieżący trafień (unikat lista+wpis+nasz_prefiks), aktywne/zdjęte.
 * mcs_spamhaus_historia   — dziennik: pobrano / nowe / zdjete / blad.
 */
class CreateMcsSpamhausDrop extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'default' => 1],
            'ostatnia_proba_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'ostatnie_ok_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'nastepne_po'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'bledy_z_rzedu'     => ['type' => 'INT', 'default' => 0],
            'ostatni_blad'      => ['type' => 'TEXT', 'null' => true],
            'ostatni_blad_at'   => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'wynik'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'brak'],
            'wynik_at'          => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'aktywne_trafienia' => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('mcs_spamhaus_stan', true);
        $this->db->table('mcs_spamhaus_stan')->insert(['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0, 'aktywne_trafienia' => 0]);

        $this->forge->addField([
            'lista'          => ['type' => 'VARCHAR', 'constraint' => 16],
            'url'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'plik_timestamp' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'copyright'      => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'terms'          => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'rekordy'        => ['type' => 'INT', 'default' => 0],
            'sha256'         => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => ''],
            'pobrano_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addKey('lista', true);
        $this->forge->createTable('mcs_spamhaus_pliki', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'typ'          => ['type' => 'VARCHAR', 'constraint' => 8],
            'lista'        => ['type' => 'VARCHAR', 'constraint' => 16],
            'wpis'         => ['type' => 'VARCHAR', 'constraint' => 64],
            'sblid'        => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'nasz_prefiks' => ['type' => 'VARCHAR', 'constraint' => 64],
            'zakres'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'podmiot'      => ['type' => 'VARCHAR', 'constraint' => 45],
            'aktywne'      => ['type' => 'BOOLEAN', 'default' => true],
            'pierwsze_at'  => ['type' => 'TIMESTAMPTZ'],
            'ostatnie_at'  => ['type' => 'TIMESTAMPTZ'],
            'zdjete_at'    => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'incydent_id'  => ['type' => 'BIGINT', 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['lista', 'wpis', 'nasz_prefiks']);
        $this->forge->addKey('aktywne');
        $this->forge->createTable('mcs_spamhaus_trafienia', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'at'           => ['type' => 'TIMESTAMPTZ'],
            'zdarzenie'    => ['type' => 'VARCHAR', 'constraint' => 16],
            'lista'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'wpis'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'nasz_prefiks' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'opis'         => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('at');
        $this->forge->createTable('mcs_spamhaus_historia', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_spamhaus_historia', true);
        $this->forge->dropTable('mcs_spamhaus_trafienia', true);
        $this->forge->dropTable('mcs_spamhaus_pliki', true);
        $this->forge->dropTable('mcs_spamhaus_stan', true);
    }
}
