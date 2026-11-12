<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the store's address in other languages to business_store_translation: store_address_line_1,
 * store_address_line_2 and store_city, as on business_store. All optional; where a language has none, the column of
 * business_store is shown. NULL for an online store, which has no address.
 * Depends on business_store_translation.
 */
class AddStoreAddressTranslations extends Migration
{
    private const COLUMNS = ['store_address_line_1', 'store_address_line_2', 'store_city'];

    public function up()
    {
        $this->forge->addColumn('business_store_translation', [
            'store_address_line_1' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'store_name'],
            'store_address_line_2' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'store_address_line_1'],
            'store_city'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'after' => 'store_address_line_2'],
        ]);
    }

    public function down()
    {
        // SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other
        // tables' foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_store_translation', self::COLUMNS);
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
