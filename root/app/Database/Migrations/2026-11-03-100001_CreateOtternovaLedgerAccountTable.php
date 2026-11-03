<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_ledger_account table (App\Models\OtternovaLedgerAccountModel): where the money of a ledger
 * entry comes from and goes to, OtterNova's own (internal) or someone else's (external).
 * account_class is kept for the balance sheet and may be empty until then.
 */
class CreateOtternovaLedgerAccountTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_name'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'account_type'  => ['type' => 'ENUM', 'constraint' => ['I', 'X']], // internal, external
            'currency_code' => ['type' => 'CHAR', 'constraint' => 3], // ISO 4217; the balance is kept in it
            'account_class' => ['type' => 'ENUM', 'constraint' => ['A', 'L', 'E', 'R', 'X'], 'null' => true], // asset, liability, equity, revenue, expense
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('account_name');
        $this->forge->addKey('account_type');
        $this->forge->createTable('otternova_ledger_account', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_ledger_account', true);
    }
}
