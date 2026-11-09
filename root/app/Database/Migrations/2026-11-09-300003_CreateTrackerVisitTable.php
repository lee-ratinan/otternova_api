<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tracker_visit table: one row per visit (a session of the tracker script), merged by the tracker job
 * from all of that session's rows in tracker_event_raw, however many batches they came in.
 * - site, business_id, visitor_id, session_id: as in tracker_visitor (business_id 0 for www and the marketplace's own pages)
 * - started_at, ended_at: the first and last event (UTC); duration_s: the time its pages were visible, in seconds
 * - pageviews, events: counts; is_bounce: a single pageview and nothing else
 * - entry_path, exit_path: the first and last page
 * - referrer_url, referrer_host, utm_*: where the visit came from; source_type: direct, search, social, referral or
 *   campaign
 * - ip_address: zeroed in its last part (IPv4 /24, IPv6 /48) once the visit is 30 days old, when ip_anonymised
 *   becomes 1;
 *   country_code ... longitude: where that address is, from MaxMind GeoLite2, looked up before it is zeroed
 * - device_type: desktop, mobile, tablet or bot; browser, os: from the user agent; language: the browser's;
 *   screen: such as 390x844; timezone: the browser's, such as Asia/Bangkok
 * - funnel_stage: the furthest step of the shopping funnel the visit reached: 0 none, 1 view_item, 2 add_to_cart,
 *   3 checkout_start, 4 checkout_confirm; order_value, order_currency: of checkout_confirm
 * - updated_at: when the tracker job last merged events into it
 */
class CreateTrackerVisitTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        $nanoId = static fn (string $column): array => $mysql
            ? [$column . ' CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : [$column => ['type' => 'CHAR', 'constraint' => 16]];

        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField([
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'business_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($nanoId('visitor_id'));
        $this->forge->addField($nanoId('session_id'));
        $this->forge->addField([
            'started_at'       => ['type' => 'DATETIME'],
            'ended_at'         => ['type' => 'DATETIME'],
            'duration_s'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'pageviews'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'events'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'is_bounce'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'entry_path'       => ['type' => 'VARCHAR', 'constraint' => 512],
            'exit_path'        => ['type' => 'VARCHAR', 'constraint' => 512],
            'referrer_url'     => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'referrer_host'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'utm_source'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'utm_medium'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'utm_campaign'     => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'source_type'      => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'direct'],
            'ip_address'       => ['type' => 'VARCHAR', 'constraint' => 45],
            'country_code'     => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'subdivision_code' => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'city'             => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'latitude'         => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'longitude'        => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'device_type'      => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'browser'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'os'               => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'language'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'screen'           => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'timezone'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'funnel_stage'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'order_value'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'order_currency'   => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'ip_anonymised'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            // maintained by the database, not the model
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . ($mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : ''),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['site', 'business_id', 'session_id']); // the job merges each session into one row
        $this->forge->addKey(['site', 'business_id', 'started_at']); // a site's or business's visits of a period
        $this->forge->addKey(['site', 'business_id', 'visitor_id']); // a visitor's visits
        $this->forge->addKey(['ip_anonymised', 'started_at']); // the visits whose address is due to be zeroed
        $this->forge->createTable('tracker_visit', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('tracker_visit', true);
    }
}
