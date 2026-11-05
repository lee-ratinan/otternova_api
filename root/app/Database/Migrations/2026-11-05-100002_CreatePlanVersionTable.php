<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the plan_version table: the price of a plan in one market for one billing cycle, over a stretch of time.
 * A series is one plan_id, country_code and billing_cycle (Boost, TH, monthly); each series changes price on its own.
 * country_code 'ZZ' is every country without a series of its own; a business's own country's row wins over it.
 *
 * effective_from and effective_to are UTC, and the range includes its start but not its end: a price change ends the
 * old row at the very moment the new row starts (Bangkok 2026-11-15 00:00 is 2026-11-14 17:00:00 in both), so there
 * is no gap and no overlap. effective_to NULL means no end yet. The row in effect at a moment is the one with
 * effective_from <= that moment and effective_to NULL or after it; a row scheduled for the future also has no end,
 * so "no end" alone does not mean in effect.
 *
 * open_guard is computed by the database, 1 while effective_to is NULL and NULL once it is set; nothing writes to it.
 * It is only there for uq_plan_version_one_open: as a unique key lets NULLs repeat, each series may have any number of
 * ended rows but only one with no end. The database cannot stop ranges from overlapping otherwise, so a price change
 * ends the old row and inserts the new one in one transaction.
 *
 * Rows are never deleted: subscriptions and invoices keep pointing at the versions they were billed on.
 * Prices are before VAT, to the satang or cent.
 * Depends on plan_master.
 */
class CreatePlanVersionTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'plan_id'        => ['type' => 'INT', 'unsigned' => true],
            'country_code'   => ['type' => 'CHAR', 'constraint' => 2, 'default' => 'ZZ'], // 'ZZ' = rest of the world
            'currency_code'  => ['type' => 'CHAR', 'constraint' => 3],
            'billing_cycle'  => ['type' => 'ENUM', 'constraint' => ['M', 'Y']], // monthly, yearly
            'price'          => ['type' => 'DECIMAL', 'constraint' => '19,2'], // before VAT
            'effective_from' => ['type' => 'DATETIME'], // UTC, included
            'effective_to'   => ['type' => 'DATETIME', 'null' => true], // UTC, excluded; NULL = no end yet
            'open_guard TINYINT GENERATED ALWAYS AS (CASE WHEN effective_to IS NULL THEN 1 END) STORED',
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['plan_id', 'country_code', 'billing_cycle', 'open_guard'], 'uq_plan_version_one_open');
        $this->forge->addKey(['plan_id', 'country_code', 'billing_cycle', 'effective_from']); // the price in effect
        $this->forge->addForeignKey('plan_id', 'plan_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_plan_version_plan'));
        $this->forge->createTable('plan_version', true);
    }

    public function down()
    {
        $this->forge->dropTable('plan_version', true);
    }
}
