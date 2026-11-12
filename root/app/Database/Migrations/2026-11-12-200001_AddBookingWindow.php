<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds when customers may book an appointment (booking_type A) on the mart: how far ahead, and how much notice the
 * business needs. All of it is counted in the store's store_timezone.
 *
 * Adds the business's own rule to business_master, after commission_threshold; the businesses already there get the
 * defaults:
 * - booking_horizon_days SMALLINT DEFAULT 3: the latest start a customer may book is store-local today plus this many
 *   days (1 to 365)
 * - booking_notice_unit ENUM('M', 'D') DEFAULT 'M': the notice is counted in minutes before the start, or in calendar
 *   days before the start's date with a cutoff time
 * - booking_notice_value SMALLINT DEFAULT 60: minutes (M: 15 for up to 15 minutes before) or days (D: 1 for the day
 *   before)
 * - booking_notice_time TIME NULL: for D only, the store-local time on that day by which to book (D, 1, 23:59 for no
 *   same-day booking; D, 1, 18:00 for by 6pm the day before); NULL for M
 *
 * Adds the same four columns to artifact_variant, after capacity, all NULL: a variant's own rule, NULL for the
 * business's. Like booking_type, they are for services only and NULL for physical products; they are taken in one
 * piece, so the four are either all NULL or, with booking_notice_time as the unit says, all set. The variants already
 * there keep the business's rule.
 *
 * The rule bounds the appointments a customer books on the mart; bookings staff make in the back office are not.
 * A group session (G) keeps its own booking_closes_at, which the variant's notice prefills when the session is
 * created, and is offered however far ahead it is.
 * The models check what the database cannot: the horizon from 1 to 365 days, booking_notice_time set for D and NULL
 * for M, and the notice no longer than the horizon.
 * Depends on business_master and artifact_variant.
 */
class AddBookingWindow extends Migration
{
    private const COLUMNS = ['booking_horizon_days', 'booking_notice_unit', 'booking_notice_value', 'booking_notice_time'];

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'booking_horizon_days' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 3, 'after' => 'commission_threshold'], // days ahead
            'booking_notice_unit'  => ['type' => 'ENUM', 'constraint' => ['M', 'D'], 'default' => 'M', 'after' => 'booking_horizon_days'], // minutes, days
            'booking_notice_value' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 60, 'after' => 'booking_notice_unit'],
            'booking_notice_time'  => ['type' => 'TIME', 'null' => true, 'default' => null, 'after' => 'booking_notice_value'], // store-local; D only
        ]);
        $this->forge->addColumn('artifact_variant', [
            'booking_horizon_days' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'capacity'], // NULL for the business's
            'booking_notice_unit'  => ['type' => 'ENUM', 'constraint' => ['M', 'D'], 'null' => true, 'default' => null, 'after' => 'booking_horizon_days'],
            'booking_notice_value' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'booking_notice_unit'],
            'booking_notice_time'  => ['type' => 'TIME', 'null' => true, 'default' => null, 'after' => 'booking_notice_value'],
        ]);
    }

    public function down()
    {
        $this->rebuildingTables(function (): void {
            $this->forge->dropColumn('artifact_variant', self::COLUMNS);
            $this->forge->dropColumn('business_master', self::COLUMNS);
        });
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
