<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the business's cancellation policy to business_master, after booking_notice_time: until when a customer may
 * cancel a booking for free, and what cancelling later costs. It is the business's alone (no service has its own),
 * counted in the store's store_timezone, and is copied onto each booking when it is made, so a later change never
 * reaches the bookings already made. The businesses already there get the defaults: free until 24 hours before, and
 * no fee after.
 * - cancel_notice_unit ENUM('M', 'D') DEFAULT 'M', cancel_notice_value SMALLINT DEFAULT 1440, cancel_notice_time
 *   TIME NULL: the free-cancellation deadline, shaped like the booking notice (see AddBookingWindow): minutes before
 *   the start (M, 1440: 24 hours before), or by cancel_notice_time on the day cancel_notice_value days before the
 *   start's date (D, 1, 18:00: by 6pm the day before); cancel_notice_time is for D only. A group session's start is
 *   its first slot's.
 * - late_cancel_allowed ENUM('Y', 'N') DEFAULT 'Y': after the deadline, the customer may still cancel online, with the
 *   fee (Y), or must contact the business (N)
 * - late_cancel_fee_value DECIMAL(19,2) DEFAULT 0.00, late_cancel_fee_unit ENUM('P', 'F') DEFAULT 'P': what cancelling
 *   after the deadline costs: a percentage of the booked service line (its service charge included, its tip not), or
 *   a fixed amount in the business's currency_code; never more than the line
 * - no_show_fee_value DECIMAL(19,2) DEFAULT 0.00, no_show_fee_unit ENUM('P', 'F') DEFAULT 'P': the same, for a
 *   customer who does not come
 * The business cancelling a booking, a slot or a session never costs the customer anything.
 * App\Libraries\CancelPolicy::errors() keeps the columns consistent: a percentage no more than 100, the notice within
 * its unit's bounds, and cancel_notice_time set for D and NULL for M.
 * Depends on business_master and AddBookingWindow.
 */
class AddCancellationPolicy extends Migration
{
    private const COLUMNS = ['cancel_notice_unit', 'cancel_notice_value', 'cancel_notice_time', 'late_cancel_allowed',
        'late_cancel_fee_value', 'late_cancel_fee_unit', 'no_show_fee_value', 'no_show_fee_unit'];

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'cancel_notice_unit'    => ['type' => 'ENUM', 'constraint' => ['M', 'D'], 'default' => 'M', 'after' => 'booking_notice_time'], // minutes, days
            'cancel_notice_value'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1440, 'after' => 'cancel_notice_unit'],
            'cancel_notice_time'    => ['type' => 'TIME', 'null' => true, 'default' => null, 'after' => 'cancel_notice_value'], // store-local; D only
            'late_cancel_allowed'   => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'Y', 'after' => 'cancel_notice_time'],
            'late_cancel_fee_value' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'late_cancel_allowed'],
            'late_cancel_fee_unit'  => ['type' => 'ENUM', 'constraint' => ['P', 'F'], 'default' => 'P', 'after' => 'late_cancel_fee_value'], // percentage, fixed
            'no_show_fee_value'     => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'late_cancel_fee_unit'],
            'no_show_fee_unit'      => ['type' => 'ENUM', 'constraint' => ['P', 'F'], 'default' => 'P', 'after' => 'no_show_fee_value'],
        ]);
    }

    public function down()
    {
        $this->rebuildingTables(fn () => $this->forge->dropColumn('business_master', self::COLUMNS));
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
