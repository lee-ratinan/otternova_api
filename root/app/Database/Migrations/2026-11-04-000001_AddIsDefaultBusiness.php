<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds business_client_link.is_default_business ENUM('Y', 'N') defaulting to 'N': the business a client's app opens
 * on. A client's first link is their default (see BusinessMasterModel::createWithOwner()), so the links already there
 * get 'Y' on each client's earliest one.
 * Depends on business_client_link.
 */
class AddIsDefaultBusiness extends Migration
{
    public function up()
    {
        $this->forge->addColumn('business_client_link', [
            'is_default_business' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N', 'after' => 'client_role'],
        ]);

        // the derived table, as MySQL cannot select from the table it updates
        $table = $this->db->escapeIdentifiers($this->db->prefixTable('business_client_link'));
        $this->db->query('UPDATE ' . $table . " SET is_default_business = 'Y' WHERE id IN"
            . ' (SELECT id FROM (SELECT MIN(id) AS id FROM ' . $table . ' GROUP BY client_id) AS first_links)');
    }

    public function down()
    {
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_client_link', 'is_default_business'));
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
