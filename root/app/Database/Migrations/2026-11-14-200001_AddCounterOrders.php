<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Orders taken at the counter (walk-ins and call-ins) by the people of a business in the Client app, besides the ones
 * customers place on the mart (App\Models\MartOrderModel::place() with a counter):
 *
 * order_master:
 * - order_source: mart (the default) or counter.
 * - created_by: auth_master.id of whom took a counter order; NULL for the mart.
 * - counter_store_id: the store a counter order was taken at; the products handed over there come out of its stock.
 *
 * customer_master.email_address may be NULL: a counter order's customer gives an email address or a telephone number,
 * and one who gives only a number is found again by it. It stays unique where it is set.
 * Depends on order_master, business_store, auth_master and customer_master.
 */
class AddCounterOrders extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';

        $this->forge->addColumn('order_master', [
            'order_source'     => ['type' => 'ENUM', 'constraint' => ['mart', 'counter'], 'default' => 'mart', 'after' => 'order_number'],
            'created_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'order_source'],
            'counter_store_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'created_by'],
        ]);
        if ($mysql) {
            $orders = $this->db->prefixTable('order_master');
            $this->db->query("ALTER TABLE {$orders} ADD CONSTRAINT fk_order_master_created_by FOREIGN KEY (created_by) REFERENCES {$this->db->prefixTable('auth_master')} (id) ON UPDATE CASCADE ON DELETE SET NULL");
            $this->db->query("ALTER TABLE {$orders} ADD CONSTRAINT fk_order_master_counter_store FOREIGN KEY (counter_store_id) REFERENCES {$this->db->prefixTable('business_store')} (id) ON UPDATE CASCADE ON DELETE SET NULL");
        }
        $this->emailNullable(true);
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $orders = $this->db->prefixTable('order_master');
            $this->db->query("ALTER TABLE {$orders} DROP FOREIGN KEY fk_order_master_created_by");
            $this->db->query("ALTER TABLE {$orders} DROP FOREIGN KEY fk_order_master_counter_store");
            $this->forge->dropColumn('order_master', ['order_source', 'created_by', 'counter_store_id']);
        } else {
            // in place: the forge's rebuild would lose order_master's CHECKs and DEFAULT CURRENT_TIMESTAMP
            foreach (['order_source', 'created_by', 'counter_store_id'] as $column) {
                $this->db->query('ALTER TABLE "' . $this->db->prefixTable('order_master') . '" DROP COLUMN "' . $column . '"');
            }
        }
        // a customer with no email address cannot go back: they are given none and kept out
        if ($this->db->table('customer_master')->where('email_address', null)->countAllResults() === 0) {
            $this->emailNullable(false);
        }
    }

    /**
     * customer_master.email_address NULL or NOT NULL. SQLite (the test database) builds the table again from its own
     * CREATE TABLE with only that changed, keeping its DEFAULT CURRENT_TIMESTAMP, indexes and the other tables'
     * foreign keys (legacy rename), as AddOrderStatusesAndPayments::orderStatus() does.
     */
    private function emailNullable(bool $nullable): void
    {
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->forge->modifyColumn('customer_master', ['email_address' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => $nullable]]);

            return;
        }
        $table   = $this->db->prefixTable('customer_master');
        $temp    = $this->db->prefixTable('customer_master_rebuild');
        $create  = $this->db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->getRow()->sql;
        $indexes = array_column($this->db->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table])->getResultArray(), 'sql');
        $create  = preg_replace('/([`"]email_address[`"]\s+VARCHAR(?:\(\d+\))?\s+)(?:NOT\s+)?NULL/i', '${1}' . ($nullable ? 'NULL' : 'NOT NULL'), $create, 1, $changed);
        $create  = preg_replace('/^CREATE TABLE\s+(?:"[^"]+"|`[^`]+`|\S+)/', 'CREATE TABLE "' . $temp . '"', $create, 1, $named);
        if ($changed !== 1 || $named !== 1) {
            throw new \RuntimeException('customer_master is not as expected: ' . $create);
        }

        $this->db->query('PRAGMA foreign_keys = OFF');
        $this->db->query('PRAGMA legacy_alter_table = ON');
        try {
            $this->db->query($create);
            $this->db->query('INSERT INTO "' . $temp . '" SELECT * FROM "' . $table . '"');
            $this->db->query('DROP TABLE "' . $table . '"');
            $this->db->query('ALTER TABLE "' . $temp . '" RENAME TO "' . $table . '"');
            foreach ($indexes as $index) {
                $this->db->query($index);
            }
        } finally {
            $this->db->query('PRAGMA legacy_alter_table = OFF');
            $this->db->query('PRAGMA foreign_keys = ON');
        }
    }
}
