<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tracker_visitor table: one row per browser the tracker has seen, per site and business, which the
 * tracker job keeps up to date from tracker_event_raw. It tells new visitors from returning ones, and keeps where a
 * visitor first came from, for attribution.
 * - site, business_id, visitor_id: as in tracker_event_raw, except that business_id is 0 rather than NULL (www, and the marketplace's own pages),
 *   so that the unique key holds (MySQL takes NULLs as all different); hence no foreign key either. The same browser
 *   on two businesses' mart pages is two visitors, one for each business
 * - first_seen_at, last_seen_at: UTC; visits, pageviews: counts so far
 * - first_referrer_host, first_utm_*: of the visitor's first visit
 * - last_country_code: of the latest visit, from the IP address (MaxMind GeoLite2)
 */
class CreateTrackerVisitorTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';

        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField([
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'business_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($mysql
            ? ['visitor_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['visitor_id' => ['type' => 'CHAR', 'constraint' => 16]]);
        $this->forge->addField([
            'first_seen_at'       => ['type' => 'DATETIME'],
            'last_seen_at'        => ['type' => 'DATETIME'],
            'visits'              => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'pageviews'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'first_referrer_host' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'first_utm_source'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'first_utm_medium'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'first_utm_campaign'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'last_country_code'   => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['site', 'business_id', 'visitor_id']);
        $this->forge->addKey(['site', 'business_id', 'first_seen_at']); // new visitors of a period
        $this->forge->createTable('tracker_visitor', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('tracker_visitor', true);
    }
}
