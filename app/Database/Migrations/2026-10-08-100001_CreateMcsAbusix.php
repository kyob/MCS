<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMcsAbusix extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'auto_increment' => true],
            'import_id'        => ['type' => 'BIGINT', 'null' => true],
            'event_id'         => ['type' => 'VARCHAR', 'constraint' => 128],
            'event_type'       => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'zaobserwowano_at' => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'ip'               => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
            'domain'           => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'sender_email'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'klucz_dedupu'     => ['type' => 'VARCHAR', 'constraint' => 255],
            'payload_json'     => ['type' => 'JSONB', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('ip');
        $this->forge->addUniqueKey('event_id');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_abusix_zdarzenia', true);

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'auto_increment' => true],
            'import_id'        => ['type' => 'BIGINT', 'null' => true],
            'case_id'          => ['type' => 'VARCHAR', 'constraint' => 128],
            'customer_id'      => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'tenant_id'        => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'contract_id'      => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'state'            => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'created_at'       => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'first_event_date' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'last_event_date'  => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'zaktualizowano_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'payload_json'     => ['type' => 'JSONB', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('state');
        $this->forge->addKey('customer_id');
        $this->forge->addUniqueKey('case_id');
        $this->forge->addForeignKey('import_id', 'mcs_importy', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_abusix_sprawy', true);

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'auto_increment' => true],
            'customer_id' => ['type' => 'VARCHAR', 'constraint' => 128],
            'tenant_id'   => ['type' => 'VARCHAR', 'constraint' => 128, 'default' => ''],
            'widziano_at' => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'customer_id']);
        $this->forge->createTable('mcs_abusix_klienci', true);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_abusix_klienci', true);
        $this->forge->dropTable('mcs_abusix_sprawy', true);
        $this->forge->dropTable('mcs_abusix_zdarzenia', true);
    }
}
