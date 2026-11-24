<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_ledger_entity table (App\Models\OtternovaLedgerEntityModel): a legal entity whose books the
 * ledger keeps, such as a Thai sole proprietorship and, later, the Thai company limited that takes over from it.
 * A country can have several. Every ledger account belongs to one, and the financial reports are per entity, in its
 * base currency, with its fiscal year.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 */
class CreateOtternovaLedgerEntityTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 4]]);
        $this->forge->addField([
            'entity_name'             => ['type' => 'VARCHAR', 'constraint' => 255], // the registered name
            'legal_form'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true], // such as Sole proprietorship, Co., Ltd.
            'country_code'            => ['type' => 'CHAR', 'constraint' => 2], // ISO 3166-1 alpha-2, where it is registered
            'tax_id'                  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'base_currency_code'      => ['type' => 'CHAR', 'constraint' => 3], // ISO 4217; every report is in it
            'timezone'                => ['type' => 'VARCHAR', 'constraint' => 64], // IANA, such as Asia/Bangkok
            'fiscal_year_start_month' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1], // 1 = January
            'registered_on'           => ['type' => 'DATE', 'null' => true],
            'closed_on'               => ['type' => 'DATE', 'null' => true], // when another entity took over, say
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_otternova_ledger_entity_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey('entity_name', 'uq_otternova_ledger_entity_entity_name');
        $this->forge->addKey('country_code');
        $this->forge->createTable('otternova_ledger_entity', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_ledger_entity', true);
    }
}
