<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cancelling an order, and a customer not coming (App\Models\SalesOrderModel::cancel() and noShow()):
 *
 * order_master:
 * - cancelled_at (there already), cancelled_by (auth_master.id; NULL for the customer or the system) and
 *   cancel_reason, which every cancellation must give, for the business to look into later.
 * - refund_due_amount: what the business still owes back for a cancelled order. Set to what was paid and not refunded
 *   yet when it is cancelled, lowered by every refund, raised by a payment that comes after; refund_closed_reason
 *   when an owner or manager settles it without refunding all of it (a late-cancellation fee kept).
 * - no_show_at and no_show_by: a booked service that started without the customer. An order that has started can no
 *   longer be cancelled, only marked a no-show.
 *
 * business_customer_flag: what a business notes against one of its customers, by order; flag_type no_show when an
 * unpaid order's customer did not come. A customer is flagged by each business on its own.
 * Depends on order_master, business_master, customer_master and auth_master.
 */
class AddOrderCancellation extends Migration
{
    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : '';

        $this->forge->addColumn('order_master', [
            'cancelled_by'         => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'cancelled_at'],
            'cancel_reason'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'cancelled_by'],
            'refund_due_amount'    => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'cancel_reason'],
            'refund_closed_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'refund_due_amount'],
            'no_show_at'           => ['type' => 'DATETIME', 'null' => true, 'after' => 'refund_closed_reason'],
            'no_show_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'no_show_at'],
        ]);
        // SQLite (the test database) cannot add a foreign key to a table that is there already
        if ($mysql) {
            $orders = $this->db->prefixTable('order_master');
            $auth   = $this->db->prefixTable('auth_master');
            $this->db->query("ALTER TABLE {$orders} ADD CONSTRAINT fk_order_master_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES {$auth} (id) ON UPDATE CASCADE ON DELETE SET NULL");
            $this->db->query("ALTER TABLE {$orders} ADD CONSTRAINT fk_order_master_no_show_by FOREIGN KEY (no_show_by) REFERENCES {$auth} (id) ON UPDATE CASCADE ON DELETE SET NULL");
        }

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id' => ['type' => 'INT', 'unsigned' => true],
            'customer_id' => ['type' => 'INT', 'unsigned' => true],
            'order_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'flag_type'   => ['type' => 'ENUM', 'constraint' => ['no_show'], 'default' => 'no_show'],
            'flagged_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['order_id', 'flag_type']);
        $this->forge->addKey(['business_id', 'customer_id', 'flag_type']);
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_customer_flag_business'));
        $this->forge->addForeignKey('customer_id', 'customer_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_business_customer_flag_customer'));
        $this->forge->addForeignKey('order_id', 'order_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_business_customer_flag_order'));
        $this->forge->addForeignKey('flagged_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_business_customer_flag_flagged_by'));
        $this->forge->createTable('business_customer_flag', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_customer_flag', true);
        if ($this->db->DBDriver === 'MySQLi') {
            $orders = $this->db->prefixTable('order_master');
            $this->db->query("ALTER TABLE {$orders} DROP FOREIGN KEY fk_order_master_cancelled_by");
            $this->db->query("ALTER TABLE {$orders} DROP FOREIGN KEY fk_order_master_no_show_by");
        }
        $this->dropColumns(['cancelled_by', 'cancel_reason', 'refund_due_amount', 'refund_closed_reason', 'no_show_at', 'no_show_by']);
    }

    /**
     * Drop columns of order_master. SQLite (the test database) drops them in place: the forge's rebuild would lose the
     * table's CHECKs, indexes and DEFAULT CURRENT_TIMESTAMP, which the migrations before this one rely on.
     * @param list<string> $columns Neither indexed nor foreign keys on SQLite
     */
    private function dropColumns(array $columns): void
    {
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->forge->dropColumn('order_master', $columns);

            return;
        }
        foreach ($columns as $column) {
            $this->db->query('ALTER TABLE "' . $this->db->prefixTable('order_master') . '" DROP COLUMN "' . $column . '"');
        }
    }
}
