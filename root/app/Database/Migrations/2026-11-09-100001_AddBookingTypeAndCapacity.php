<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds how a service variant is booked to artifact_variant, after duration_minutes. Like duration_minutes, they are
 * for services only and NULL for physical products, which the model checks, as the database cannot.
 * - booking_type ENUM('A', 'G') NULL: appointment, the customer picks the time (and the person) and the booking holds
 *   the staff and resources for that customer alone (a 60-minute massage with Ginny); or group, the business sets the
 *   sessions and customers join one until it is full, the staff and resources being held once per session, not per
 *   customer (a Muay Thai class in the main studio). It is on the variant, so one service can have a private lesson
 *   (A) and a group class (G).
 * - staff_required TINYINT NULL: how many people one booking (A) or one session (G) needs; 1 for most, 2 for a 4-hand
 *   massage or a class with two instructors. At least 1 for a service.
 * - capacity SMALLINT NULL: for G only, the most customers one session takes, which may be fewer than its resources
 *   could hold (a class of 12 in a studio that fits 20); NULL for A.
 * The services already there are filled with booking_type A and staff_required 1.
 *
 * Adds resource_master.capacity SMALLINT DEFAULT 1, after resource_name: how many customers the resource holds at
 * once (a massage room 1, a couples room 2, the main studio 20). A group session cannot take more customers than the
 * resources it holds; the resources already there hold 1.
 *
 * Depends on artifact_master, artifact_variant and resource_master.
 */
class AddBookingTypeAndCapacity extends Migration
{
    private const VARIANT_COLUMNS = ['booking_type', 'staff_required', 'capacity'];

    public function up()
    {
        $this->forge->addColumn('artifact_variant', [
            'booking_type'   => ['type' => 'ENUM', 'constraint' => ['A', 'G'], 'null' => true, 'default' => null, 'after' => 'duration_minutes'], // appointment, group; services only
            'staff_required' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'booking_type'], // per booking (A) or session (G); services only
            'capacity'       => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'staff_required'], // customers per session; G only
        ]);
        $this->forge->addColumn('resource_master', [
            'capacity' => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => false, 'default' => 1, 'after' => 'resource_name'], // customers at once
        ]);

        // the services already there were all booked as appointments by one person
        $services = $this->db->table('artifact_master')->select('id')->where('artifact_type', 'S');
        $this->db->table('artifact_variant')
            ->whereIn('artifact_id', $services)
            ->update(['booking_type' => 'A', 'staff_required' => 1]);
    }

    public function down()
    {
        $this->rebuildingTables(function (): void {
            $this->forge->dropColumn('resource_master', 'capacity');
            $this->forge->dropColumn('artifact_variant', self::VARIANT_COLUMNS);
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
