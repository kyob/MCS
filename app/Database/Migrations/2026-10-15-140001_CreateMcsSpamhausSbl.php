<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plugin spamhaus_sbl — powiadomienia e-mail Spamhaus SBL (IMAP).
 *
 * mcs_spamhaus_sbl_wiadomosci — dziennik decyzji per wiadomość (unikat `klucz` = Message-ID / sha256):
 *                               przyjeta | nadawca | temat | auth_fail | brak_numeru | brak_ip | obcy_ip.
 * mcs_spamhaus_sbl_wiersze    — wiersze źródła dla incydentów (id_w_zrodle), jeden na adres z AS.
 */
class CreateMcsSpamhausSbl extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'auto_increment' => true],
            'klucz'              => ['type' => 'VARCHAR', 'constraint' => 255],
            'uid'                => ['type' => 'BIGINT', 'null' => true],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 24],
            'powod'              => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'nadawca'            => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'temat'              => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'uwierzytelnienie'   => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'nieuwierzytelniony' => ['type' => 'BOOLEAN', 'default' => false],
            'sbl'                => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'ip'                 => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'import_id'          => ['type' => 'BIGINT', 'null' => true],
            'przetworzono_at'    => ['type' => 'TIMESTAMPTZ'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('klucz');
        $this->forge->addKey('status');
        $this->forge->createTable('mcs_spamhaus_sbl_wiadomosci', true);

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'auto_increment' => true],
            'import_id'        => ['type' => 'BIGINT', 'null' => true],
            'zaobserwowano_at' => ['type' => 'TIMESTAMPTZ'],
            'sbl'              => ['type' => 'VARCHAR', 'constraint' => 16],
            'ip'               => ['type' => 'VARCHAR', 'constraint' => 45],
            'cidr'             => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'uwierzytelnienie' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'klucz_dedupu'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'payload_json'     => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('klucz_dedupu');
        $this->forge->createTable('mcs_spamhaus_sbl_wiersze', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_spamhaus_sbl_wiersze', true);
        $this->forge->dropTable('mcs_spamhaus_sbl_wiadomosci', true);
    }
}
