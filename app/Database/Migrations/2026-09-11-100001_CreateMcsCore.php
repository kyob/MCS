<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsCore extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'rola' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'aktywny' => [
                'type'       => 'BOOLEAN',
                'default'    => true,
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'TIMESTAMPTZ',
                'null'    => false,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('mcs_users', true);
        $this->db->query("ALTER TABLE mcs_users ALTER COLUMN created_at SET DEFAULT now()");
        $this->db->query("ALTER TABLE mcs_users ADD CONSTRAINT mcs_users_rola_check CHECK (rola IN ('admin','operator'))");

        $this->forge->addField([
            'zrodlo' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'nazwa' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'import_wstrzymany' => [
                'type'    => 'BOOLEAN',
                'default' => true,
                'null'    => false,
            ],
            'ukryty_w_ui' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'null'    => false,
            ],
            'health_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'health_ok' => [
                'type' => 'BOOLEAN',
                'null' => true,
            ],
            'health_msg' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('zrodlo', true);
        $this->forge->createTable('mcs_pluginy', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'zrodlo' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'rozpoczeto_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'zakonczono_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'checksum' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'meta_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'blad_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('mcs_importy', true);
        $this->db->query("ALTER TABLE mcs_importy ADD CONSTRAINT mcs_importy_status_check CHECK (status IN ('ok','blad','w_trakcie'))");

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'zrodlo' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'klucz_dedupu' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'podmiot_typ' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'ip',
            ],
            'podmiot' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'pierwsze_widziane' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'ostatnio_widziane' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'waga' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'kategoria' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'tytul' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'port' => [
                'type' => 'INT',
                'null' => true,
            ],
            'protokol' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'ukryty_w_ui' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'null'    => false,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['zrodlo', 'klucz_dedupu']);
        $this->forge->addKey(['podmiot_typ', 'podmiot']);
        $this->forge->addKey('ostatnio_widziane');
        $this->forge->createTable('mcs_incydenty', true);
        $this->db->query("ALTER TABLE mcs_incydenty ADD CONSTRAINT mcs_incydenty_waga_check CHECK (waga IN ('low','medium','high','critical'))");

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'auto_increment' => true,
            ],
            'incydent_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => false,
            ],
            'import_id' => [
                'type'     => 'BIGINT',
                'null'     => true,
            ],
            'zaobserwowano_at' => [
                'type' => 'TIMESTAMPTZ',
                'null' => false,
            ],
            'payload_json' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'id_w_zrodle' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('incydent_id');
        $this->forge->addKey('zaobserwowano_at');
        $this->forge->addForeignKey('incydent_id', 'mcs_incydenty', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_obserwacje', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_obserwacje', true);
        $this->forge->dropTable('mcs_incydenty', true);
        $this->forge->dropTable('mcs_importy', true);
        $this->forge->dropTable('mcs_pluginy', true);
        $this->forge->dropTable('mcs_users', true);
    }
}
