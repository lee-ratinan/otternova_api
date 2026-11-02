<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Turns client_master.marketing_opt_in from TINYINT(1) 0/1 into ENUM('Y', 'N') defaulting to 'N', so no one is
 * opted in without consent and more options can be added later. 1 becomes 'Y' and 0 becomes 'N'.
 * The column passes through CHAR(1) so the values can be rewritten before the ENUM is applied.
 * Depends on client_master.
 */
class MarketingOptInToEnum extends Migration
{
    public function up()
    {
        $this->rebuildingTables(function () {
            $this->forge->modifyColumn('client_master', [
                'marketing_opt_in' => ['name' => 'marketing_opt_in', 'type' => 'CHAR', 'constraint' => 1, 'default' => 'N'],
            ]);
            $this->db->table('client_master')->where('marketing_opt_in', '1')->update(['marketing_opt_in' => 'Y']);
            $this->db->table('client_master')->where('marketing_opt_in !=', 'Y')->update(['marketing_opt_in' => 'N']);
            $this->forge->modifyColumn('client_master', [
                'marketing_opt_in' => ['name' => 'marketing_opt_in', 'type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N'],
            ]);
        });
    }

    public function down()
    {
        // anything other than 'Y' goes back to 0
        $this->rebuildingTables(function () {
            $this->forge->modifyColumn('client_master', [
                'marketing_opt_in' => ['name' => 'marketing_opt_in', 'type' => 'CHAR', 'constraint' => 1, 'default' => '0'],
            ]);
            $this->db->table('client_master')->where('marketing_opt_in', 'Y')->update(['marketing_opt_in' => '1']);
            $this->db->table('client_master')->where('marketing_opt_in !=', '1')->update(['marketing_opt_in' => '0']);
            $this->forge->modifyColumn('client_master', [
                'marketing_opt_in' => ['name' => 'marketing_opt_in', 'type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
            ]);
        });
    }

    /**
     * SQLite (the test database) modifies columns by renaming the table to temp_<table>, creating it anew and
     * dropping temp_<table>. Since SQLite 3.26 the rename also points the other tables' foreign keys at
     * temp_<table>, leaving them broken once it is dropped; the legacy rename leaves them alone.
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
