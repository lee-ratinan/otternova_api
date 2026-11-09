<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tracker_event_raw table: what the tracker script of the website (www) and the marketplace (mart) sends
 * to POST /v1/track, one row per event, as received. It is only an inbox: the tracker job merges the rows into
 * tracker_visit, tracker_visit_page and tracker_visitor, sets processed_at, and deletes the rows a few days later.
 * - site: www or mart (App\Models\TrackerEventRawModel::SITES)
 * - business_id: the business whose mart pages were seen, from the slug the script sends; NULL for www and for the
 *   marketplace's own pages (home, search)
 * - visitor_id: the browser, kept by the script for a year; session_id: one visit, renewed after 30 minutes idle.
 *   Both are 16 NanoID characters made by the script, so ascii_bin on MySQL as they tell upper from lower case
 * - event_type: pageview, engage (time the page was visible and how far it was scrolled) or event (event_name says
 *   which, such as add_to_cart or checkout_confirm)
 * - path: the page's path, without its query string; referrer: where the visitor came from, on a session's first page
 * - received_at: when the API received the batch (UTC, the API's clock); occurred_at: when the event happened, the
 *   batch's received_at less how long ago the script says it was, so a visitor's wrong clock does not matter
 * - ip_address, user_agent, accept_language: from the request, not the script
 * - payload JSON: the rest the script sent with the event, such as title, query, utm, screen, timezone, visible_ms,
 *   scroll_pct and props
 * - processed_at: NULL until the tracker job has merged the row
 * Depends on business_master.
 */
class CreateTrackerEventRawTable extends Migration
{
    public function up()
    {
        $mysql  = $this->db->DBDriver === 'MySQLi';
        $fkName = static fn (string $name): string => $mysql ? $name : ''; // SQLite has no named foreign keys
        $nanoId = static fn (string $column): array => $mysql
            ? [$column . ' CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : [$column => ['type' => 'CHAR', 'constraint' => 16]];

        $this->forge->addField(['id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField([
            'site'        => ['type' => 'VARCHAR', 'constraint' => 8],
            'business_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addField($nanoId('visitor_id'));
        $this->forge->addField($nanoId('session_id'));
        $this->forge->addField([
            'event_type'      => ['type' => 'VARCHAR', 'constraint' => 16],
            'event_name'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'path'            => ['type' => 'VARCHAR', 'constraint' => 512],
            'referrer'        => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'received_at'     => ['type' => 'DATETIME'],
            'occurred_at'     => ['type' => 'DATETIME'],
            'ip_address'      => ['type' => 'VARCHAR', 'constraint' => 45], // IPv6 at most
            'user_agent'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'accept_language' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'payload'         => ['type' => 'JSON', 'null' => true],
            'processed_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['processed_at', 'id']); // the job's next batch, and the cleanup of processed rows
        $this->forge->addKey('session_id'); // the rest of a session the job is merging
        $this->forge->addKey('business_id');
        // a business's events go with it
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_tracker_event_raw_business'));
        $this->forge->createTable('tracker_event_raw', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('tracker_event_raw', true);
    }
}
