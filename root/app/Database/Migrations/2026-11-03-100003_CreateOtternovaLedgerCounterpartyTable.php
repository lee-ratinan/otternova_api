<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the otternova_ledger_counterparty table (App\Models\OtternovaLedgerCounterpartyModel): the other side of
 * a ledger entry that involves an external account, such as a supplier
 */
class CreateOtternovaLedgerCounterpartyTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';

        $this->forge->addField([
            'id'                       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'counterparty_name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'counterparty_description' => ['type' => 'TEXT', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('counterparty_name');
        $this->forge->createTable('otternova_ledger_counterparty', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('otternova_ledger_counterparty', true);
    }
}
