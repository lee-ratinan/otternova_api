<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds artifact_attribute_value.color_code CHAR(7) NULL, after attribute_value: the colour of a choice as #rrggbb
 * (lower case), for the options shown as swatches, such as Color: Blue #1e40af. NULL for the choices that have none
 * (S, M, L), which are all of those already there.
 * Depends on artifact_attribute_value.
 */
class AddAttributeValueColorCode extends Migration
{
    public function up()
    {
        $this->forge->addColumn('artifact_attribute_value', [
            'color_code' => ['type' => 'CHAR', 'constraint' => 7, 'null' => true, 'default' => null, 'after' => 'attribute_value'], // #rrggbb
        ]);
    }

    public function down()
    {
        $this->rebuildingTables(fn () => $this->forge->dropColumn('artifact_attribute_value', 'color_code'));
    }

    /**
     * SQLite (the test database) drops columns by rebuilding the table; the legacy rename keeps the other tables'
     * foreign keys pointing at it (see AddBusinessAudience)
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
