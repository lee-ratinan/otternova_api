<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A customer saying they paid an order (App\Models\SalesOrderModel::reportPayment()), for the people of its store to
 * check and mark received:
 * order_payment.reported_at: when the customer said so; slip_path: their transfer slip, kept at
 * Config\Mart::$orderSlipUploadPath, never under public/. The payment stays pending until the business settles it.
 * Depends on order_payment.
 */
class AddOrderPaymentReports extends Migration
{
    public function up()
    {
        $this->forge->addColumn('order_payment', [
            'reported_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'failure_reason'],
            'slip_path'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'reported_at'],
        ]);
    }

    public function down()
    {
        if ($this->db->DBDriver === 'SQLite3') {
            // in place: neither is indexed, and the forge's rebuild would lose the table's DEFAULT CURRENT_TIMESTAMP
            foreach (['reported_at', 'slip_path'] as $column) {
                $this->db->query('ALTER TABLE "' . $this->db->prefixTable('order_payment') . '" DROP COLUMN "' . $column . '"');
            }

            return;
        }
        $this->forge->dropColumn('order_payment', ['reported_at', 'slip_path']);
    }
}
