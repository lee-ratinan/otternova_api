<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Splits what an order's state is into three (App\Models\SalesOrderModel), and keeps the history of its money:
 *
 * order_master:
 * - order_status, which was P (placed), C (confirmed) or X (cancelled), becomes how far the business is with it:
 *   open, in_progress (being processed, packed or served) or closed (done with: fulfilled, or cancelled, which
 *   cancelled_at tells). P and C become open, X closed.
 * - financial_status: pending, partially_paid, paid, partially_refunded (some of what was paid given back) or
 *   full_refunded (all of it given back, as for a cancelled order). Never set by hand: it is worked out from the
 *   succeeded rows of order_payment after every change of them. C becomes paid, the others pending (an X with
 *   paid_at, full_refunded).
 * - fulfillment_status, for the physical products: open, partially_fulfilled or fully_fulfilled; NULL for an order
 *   with no product line, which has nothing to send.
 *
 * order_payment, every try at paying an order and every refund of it, failed ones included:
 * - transaction_type payment or refund; amount, above 0, in currency_code (the order's).
 * - transaction_status pending (waiting: placed, or a slip to check), succeeded, failed (failure_reason) or voided
 *   (a pending one that will never come). Only succeeded rows count towards financial_status.
 * - the payment method (its id, code, handler and name as they were), the provider's or bank's reference and a note.
 * - recorded_by: auth_master.id of who recorded it, NULL for the mart or a provider; processed_at: when it
 *   succeeded or failed.
 * An order placed with something to pay gets one pending payment of its total; the orders placed before this get one
 * row for what they are: succeeded for C, pending for P, voided for an unpaid X.
 *
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on order_master, business_payment_method and auth_master.
 */
class AddOrderStatusesAndPayments extends Migration
{
    public function up()
    {
        $mysql    = $this->db->DBDriver === 'MySQLi';
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : '';

        // order_status takes the old and the new values while the rows change over
        $this->orderStatus(['P', 'C', 'X', 'open', 'in_progress', 'closed'], 'open');
        $this->forge->addColumn('order_master', [
            'financial_status'   => ['type' => 'ENUM', 'constraint' => ['pending', 'partially_paid', 'paid', 'partially_refunded', 'full_refunded'],
                'default' => 'pending', 'after' => 'order_status'],
            'fulfillment_status' => ['type' => 'ENUM', 'constraint' => ['open', 'partially_fulfilled', 'fully_fulfilled'], 'null' => true, 'after' => 'financial_status'],
        ]);

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'order_id'             => ['type' => 'INT', 'unsigned' => true],
            'transaction_type'     => ['type' => 'ENUM', 'constraint' => ['payment', 'refund'], 'default' => 'payment'],
            'transaction_status'   => ['type' => 'ENUM', 'constraint' => ['pending', 'succeeded', 'failed', 'voided'], 'default' => 'pending'],
            'currency_code'        => ['type' => 'CHAR', 'constraint' => 3],
            'amount'               => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'payment_method_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'payment_method_code'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'payment_handler_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'payment_method_name'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'reference'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'note'                 => ['type' => 'TEXT', 'null' => true],
            'failure_reason'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recorded_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'processed_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_order_payment_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addKey(['order_id', 'created_at']);
        $this->forge->addForeignKey('order_id', 'order_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_order_payment_order'));
        $this->forge->addForeignKey('payment_method_id', 'business_payment_method', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_payment_method'));
        $this->forge->addForeignKey('recorded_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_order_payment_recorded_by'));
        $this->forge->createTable('order_payment', true);

        // the orders placed so far
        helper('id');
        $newId = static fn (): string => generateNanoId(12);
        $rows = $this->db->table('order_master')->select('id, order_status, currency_code, total_amount, payment_method_id, payment_method_code, '
            . 'payment_handler_code, payment_method_name, paid_at, cancelled_at, created_at')->get()->getResultArray();
        foreach ($rows as $order) {
            $old      = $order['order_status'];
            $paid     = $old === 'C' || ($old === 'X' && $order['paid_at'] !== null);
            $products = $this->db->table('order_line')->where('order_id', $order['id'])->where('line_kind', 'P')->countAllResults() > 0;
            $this->db->table('order_master')->where('id', $order['id'])->update([
                'order_status'       => in_array($old, ['P', 'C'], true) ? 'open' : (in_array($old, ['open', 'in_progress', 'closed'], true) ? $old : 'closed'),
                'financial_status'   => $old === 'X' ? ($paid ? 'full_refunded' : 'pending') : ($paid ? 'paid' : 'pending'),
                'fulfillment_status' => $products ? 'open' : null,
            ]);
            if ((float) $order['total_amount'] <= 0) {
                continue;
            }
            $method = [
                'payment_method_id'    => $order['payment_method_id'],
                'payment_method_code'  => $order['payment_method_code'],
                'payment_handler_code' => $order['payment_handler_code'],
                'payment_method_name'  => $order['payment_method_name'],
            ];
            $this->db->table('order_payment')->insert($method + [
                'public_id'          => $newId(),
                'order_id'           => $order['id'],
                'transaction_type'   => 'payment',
                'transaction_status' => $paid ? 'succeeded' : ($old === 'X' ? 'voided' : 'pending'),
                'currency_code'      => $order['currency_code'],
                'amount'             => $order['total_amount'],
                'processed_at'       => $paid ? ($order['paid_at'] ?? $order['created_at']) : null,
                'created_at'         => $order['created_at'],
            ]);
            if ($old === 'X' && $paid) {
                $this->db->table('order_payment')->insert($method + [
                    'public_id'          => $newId(),
                    'order_id'           => $order['id'],
                    'transaction_type'   => 'refund',
                    'transaction_status' => 'succeeded',
                    'currency_code'      => $order['currency_code'],
                    'amount'             => $order['total_amount'],
                    'processed_at'       => $order['cancelled_at'] ?? $order['created_at'],
                    'created_at'         => $order['cancelled_at'] ?? $order['created_at'],
                ]);
            }
        }

        $this->orderStatus(['open', 'in_progress', 'closed'], 'open');
        $this->forge->addKey(['business_id', 'created_at'], false, false, 'idx_order_master_business_created');
        $this->forge->processIndexes('order_master');
    }

    public function down()
    {
        $mysql  = $this->db->DBDriver === 'MySQLi';
        $orders = $this->db->prefixTable('order_master');

        $this->db->query($mysql ? 'DROP INDEX idx_order_master_business_created ON ' . $orders : 'DROP INDEX IF EXISTS idx_order_master_business_created');
        $this->orderStatus(['P', 'C', 'X', 'open', 'in_progress', 'closed'], 'P');
        $this->db->table('order_master')->where('cancelled_at IS NOT NULL', null, false)->update(['order_status' => 'X']);
        $this->db->table('order_master')->where('cancelled_at', null)->whereIn('financial_status', ['paid', 'partially_refunded'])->update(['order_status' => 'C']);
        $this->db->table('order_master')->where('cancelled_at', null)->whereIn('order_status', ['open', 'in_progress', 'closed'])->update(['order_status' => 'P']);
        $this->orderStatus(['P', 'C', 'X'], 'P');

        $this->forge->dropTable('order_payment', true);
        if ($mysql) {
            $this->forge->dropColumn('order_master', ['financial_status', 'fulfillment_status']);
        } else {
            // in place: the forge's rebuild would lose order_status's CHECK and DEFAULT CURRENT_TIMESTAMP (see orderStatus())
            foreach (['financial_status', 'fulfillment_status'] as $column) {
                $this->db->query('ALTER TABLE "' . $orders . '" DROP COLUMN "' . $column . '"');
            }
        }
    }

    /**
     * order_status as an ENUM of $values.
     * SQLite (the test database) keeps an ENUM as TEXT with a CHECK, which only building the table again changes. The
     * forge's rebuild would turn the DEFAULT CURRENT_TIMESTAMP of created_at and updated_at into that text, so the
     * table is built again here from its own CREATE TABLE with only order_status changed; the legacy rename keeps the
     * other tables' foreign keys pointing at order_master.
     * @param list<string> $values
     * @param string       $default
     * @return void
     */
    private function orderStatus(array $values, string $default): void
    {
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->forge->modifyColumn('order_master', ['order_status' => ['type' => 'ENUM', 'constraint' => $values, 'null' => false, 'default' => $default]]);

            return;
        }
        $table   = $this->db->prefixTable('order_master');
        $temp    = $this->db->prefixTable('order_master_rebuild');
        $create  = $this->db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->getRow()->sql;
        $indexes = array_column($this->db->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table])->getResultArray(), 'sql');
        $list    = implode(', ', array_map(fn (string $value): string => $this->db->escape($value), $values));
        $create  = preg_replace('/CHECK\s*\(\s*[`"]order_status[`"]\s+IN\s*\([^)]*\)\s*\)/', 'CHECK(`order_status` IN (' . $list . '))', $create, 1, $checks);
        $create  = preg_replace('/([`"]order_status[`"] TEXT CHECK\([^)]*\)\)[^,]*?DEFAULT\s+)\'[^\']*\'/', '${1}' . $this->db->escape($default), $create, 1, $defaults);
        $create  = preg_replace('/^CREATE TABLE\s+(?:"[^"]+"|\S+)/', 'CREATE TABLE "' . $temp . '"', $create, 1, $names);
        if ($checks !== 1 || $defaults !== 1 || $names !== 1) {
            throw new \RuntimeException('order_master is not as expected: ' . $create);
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
