<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tracker_visit_page table: the pages of a visit, one row per path however often the visitor came back
 * to it, merged by the tracker job.
 * - visit_id: tracker_visit
 * - path: as in tracker_event_raw; views: how often it was viewed in the visit
 * - visible_s: how long it was visible, in seconds, all views together; max_scroll_pct: how far down it was scrolled
 * - first_viewed_at: UTC
 * Depends on tracker_visit.
 */
class CreateTrackerVisitPageTable extends Migration
{
    public function up()
    {
        $fkName = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // SQLite has no named foreign keys

        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'visit_id'        => ['type' => 'BIGINT', 'unsigned' => true],
            'path'            => ['type' => 'VARCHAR', 'constraint' => 512],
            'views'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'visible_s'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'max_scroll_pct'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'first_viewed_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['visit_id', 'path']); // the job merges each page of a visit into one row
        // a visit's pages go with it
        $this->forge->addForeignKey('visit_id', 'tracker_visit', 'id', 'CASCADE', 'CASCADE', $fkName('fk_tracker_visit_page_visit'));
        $this->forge->createTable('tracker_visit_page', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('tracker_visit_page', true);
    }
}
