<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds how a business takes tips and service charges to business_master, after age_restricted; the businesses already
 * there get no tipping and no service charge:
 * - tip_policy ENUM('D', 'OFF', 'O', 'M') DEFAULT 'D': disabled (tipping is not allowed through the system),
 *   off-system (tipping is welcome but not collected at checkout), optional (the customer may tip at checkout),
 *   mandatory (the customer must tip at least tip_value at checkout)
 * - tip_value DECIMAL(19,2) DEFAULT 0.00: a percentage (10.00 for 10%) or an amount in the business's currency_code
 * - tip_unit ENUM('P', 'F') DEFAULT 'P': percentage, fixed amount
 * - tip_value_kind ENUM('S', 'M') DEFAULT 'S': tip_value is the suggested tip, or the minimum one
 * - service_charge_value DECIMAL(19,2) DEFAULT 0.00: 0 for none; charged on services only (artifact_type S)
 * - service_charge_unit ENUM('P', 'F') DEFAULT 'P': a percentage of each service line, or a fixed amount added to each
 *   service line (not once per order); product lines never carry it
 * BusinessMasterModel::chargeErrors() keeps the columns consistent with each other.
 * Depends on business_master.
 */
class AddTipAndServiceCharge extends Migration
{
    private const COLUMNS = ['tip_policy', 'tip_value', 'tip_unit', 'tip_value_kind', 'service_charge_value', 'service_charge_unit'];

    public function up()
    {
        $this->forge->addColumn('business_master', [
            'tip_policy'           => ['type' => 'ENUM', 'constraint' => ['D', 'OFF', 'O', 'M'], 'default' => 'D', 'after' => 'age_restricted'],
            'tip_value'            => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'tip_policy'],
            'tip_unit'             => ['type' => 'ENUM', 'constraint' => ['P', 'F'], 'default' => 'P', 'after' => 'tip_value'],
            'tip_value_kind'       => ['type' => 'ENUM', 'constraint' => ['S', 'M'], 'default' => 'S', 'after' => 'tip_unit'],
            'service_charge_value' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'tip_value_kind'],
            'service_charge_unit'  => ['type' => 'ENUM', 'constraint' => ['P', 'F'], 'default' => 'P', 'after' => 'service_charge_value'],
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
