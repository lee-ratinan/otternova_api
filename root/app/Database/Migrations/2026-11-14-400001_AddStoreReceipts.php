<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The receipts a store prints for its paid orders, set by the business's owners and managers for each store, since
 * each may have its own printer:
 * - business_store.receipt_format: 58mm or 80mm (a thermal roll) or A4 (a normal printer); NULL is a store with no
 *   printer, which prints no receipts (an online store, or one not set up yet)
 * - business_store.receipt_footer: the line or lines at the bottom of the receipt ("Thank you"), in the store's own
 *   language; NULL for none
 * - business_store_translation.receipt_footer: the footer in another language, as the staff choose the receipt's
 *   language when they print; where a language has none, the one of business_store is printed
 * Nothing about the receipts is kept: a receipt is printed from the order as it is.
 * Depends on business_store and business_store_translation.
 */
class AddStoreReceipts extends Migration
{
    public const FORMATS = ['58mm', '80mm', 'A4'];

    public function up()
    {
        $this->forge->addColumn('business_store', [
            'receipt_format' => ['type' => 'ENUM', 'constraint' => self::FORMATS, 'null' => true, 'default' => null, 'after' => 'store_postal_code'],
            'receipt_footer' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'receipt_format'],
        ]);
        $this->forge->addColumn('business_store_translation', [
            'receipt_footer' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'store_city'],
        ]);
    }

    public function down()
    {
        // SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other
        // tables' foreign keys pointing at it (see AddStoreAddressTranslations)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_store_translation', 'receipt_footer');
            $this->forge->dropColumn('business_store', ['receipt_format', 'receipt_footer']);
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
