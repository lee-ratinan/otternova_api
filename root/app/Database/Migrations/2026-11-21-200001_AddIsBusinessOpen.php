<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds is_business_open ENUM('Y', 'N') DEFAULT 'N' to business_master: whether the business shows on otternova_mart.
 * Closed (N), it is left out of the search and its shop pages say it is not open, and no order can be placed; the
 * orders placed already can still be looked up and paid. Only its owners switch it (Businesses::open()). Every
 * business starts closed, the ones there already too, until an owner opens it.
 * Depends on business_master.
 */
class AddIsBusinessOpen extends Migration
{
    private const INDEX = 'is_business_open';

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'is_business_open' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N', 'null' => false, 'after' => 'business_slug'],
        ]);
        $this->forge->addKey('is_business_open', false, false, self::INDEX); // the search looks at open businesses only
        $this->forge->processIndexes('business_master');
    }

    public function down()
    {
        $this->db->query($this->db->DBDriver === 'MySQLi'
            ? 'DROP INDEX ' . self::INDEX . ' ON ' . $this->db->prefixTable('business_master')
            : 'DROP INDEX IF EXISTS ' . self::INDEX);
        // SQLite (the test database) drops a column by rebuilding the table; the legacy rename keeps the other tables'
        // foreign keys pointing at it (see MoveAvatarAndTrimProfileLocale)
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            $this->forge->dropColumn('business_master', 'is_business_open');
        } finally {
            if ($sqlite) {
                $this->db->query('PRAGMA legacy_alter_table = OFF');
            }
        }
    }
}
