<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds allow_shipping ENUM('Y', 'N') DEFAULT 'Y' to business_payment_method: whether the method can pay for an order
 * whose products are shipped, home delivery (shipping_method_type H) or a locker or pickup point (L). N keeps it to
 * the orders picked up at a store (S) and those with no physical products, as cash, which the customer could only
 * pay at the store, not for a parcel sent to them. Every method starts allowed, the ones there already too, until
 * the business turns it off.
 * Depends on business_payment_method.
 */
class AddPaymentMethodAllowShipping extends Migration
{
    public function up()
    {
        $this->forge->addColumn('business_payment_method', [
            'allow_shipping' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y', 'null' => false, 'after' => 'is_active'],
        ]);
    }

    public function down()
    {
        // SQLite (the test database) drops a column by rebuilding the table; the legacy rename keeps the other tables'
        // foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_payment_method', 'allow_shipping');
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
