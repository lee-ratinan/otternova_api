<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the tracker_daily table: the traffic of a site or business per UTC day, which the daily tracker job
 * rebuilds from tracker_visit and tracker_visit_page once the day is over. Kept for good, after the visits it was
 * made from are deleted, and what the traffic screens of the Admin and Client apps read.
 * - day: the UTC date; site, business_id: as in tracker_visit (business_id 0 for www and the marketplace's own pages)
 * - dimension, dimension_value: what the row counts: total ('' value, the whole site or business), path (a page),
 *   country (a country code, '' when unknown), source (a source_type of tracker_visit) or device (a device_type)
 * - pageviews, visits, visitors, new_visitors (first seen that day), bounces, visible_s: of the visits, or of the
 *   page for dimension path
 * - funnel_*: visits that reached each step of the shopping funnel (funnel_stage of tracker_visit at least that
 *   step), for conversion and drop-off; orders, revenue: of checkout_confirm, in the business's currency
 */
class CreateTrackerDailyTable extends Migration
{
    public function up()
    {
        $count = ['type' => 'INT', 'unsigned' => true, 'default' => 0];

        $this->forge->addField([
            'id'                      => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'day'                     => ['type' => 'DATE'],
            'site'                    => ['type' => 'VARCHAR', 'constraint' => 8],
            'business_id'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'dimension'               => ['type' => 'VARCHAR', 'constraint' => 16],
            'dimension_value'         => ['type' => 'VARCHAR', 'constraint' => 512, 'default' => ''],
            'pageviews'               => $count,
            'visits'                  => $count,
            'visitors'                => $count,
            'new_visitors'            => $count,
            'bounces'                 => $count,
            'visible_s'               => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'funnel_view_item'        => $count,
            'funnel_add_to_cart'      => $count,
            'funnel_checkout_start'   => $count,
            'funnel_checkout_confirm' => $count,
            'orders'                  => $count,
            'revenue'                 => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['site', 'business_id', 'dimension', 'day', 'dimension_value']); // also a period's rows of one dimension
        $this->forge->createTable('tracker_daily', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('tracker_daily', true);
    }
}
