<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds business_store.store_phone_number VARCHAR(32) NULL, after store_type: the store's own phone number, for
 * either type of store (such as an online store's hotline).
 * Depends on business_store.
 */
class AddStorePhoneNumber extends Migration
{
    public function up()
    {
        $this->forge->addColumn('business_store', [
            'store_phone_number' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'store_type'],
        ]);
    }

    public function down()
    {
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_store', 'store_phone_number'));
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
     * @param callable $work
     * @return void
     */
    private function rebuildingTables(callable $work): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $work();
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
