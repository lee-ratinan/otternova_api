<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which services each of a business's people may perform, and the commission they earn on them.
 *
 * Creates business_service_client_link: one row per service (artifact_master with artifact_type S) per person
 * (business_client_link), so a guitar teacher is offered for guitar lessons only. It is on the artifact, not the
 * variant: whoever teaches guitar teaches every lesson length. The model checks that the artifact is a service, as
 * the database cannot. business_id is repeated so the database itself keeps the service and the person in the same
 * business: (business_id, artifact_id) must be an artifact_master and (business_id, client_id) a business_client_link
 * of that business. A row goes with its business_client_link; artifacts are never deleted.
 * - commission_value DECIMAL(19,2) DEFAULT 0.00: a percentage (10.00 for 10%) or an amount in the business's
 *   currency_code; 0 when the person earns none here (a flat salary, or commission worked out by other software)
 * - commission_unit ENUM('P', 'F') DEFAULT 'P': percentage of the service line after discounts, and without tax, tip
 *   and service charge; or a fixed amount per service performed (times its quantity)
 * This is the person's current rate; a sale line is to keep the rate it was earned at, so a change never alters
 * commission already earned.
 *
 * Adds how a business pays commission to business_master, after service_charge_unit; the businesses already there
 * pay none:
 * - commission_status ENUM('A', 'I') DEFAULT 'I': active, inactive (the rates stay, but nothing is earned)
 * - commission_period ENUM('W', 'S', 'M') DEFAULT 'M': the period a person's sales are added up over, to be checked
 *   against the threshold: weekly (Monday to Sunday), semi-monthly (1st to 15th, 16th to the month's end), monthly
 * - commission_threshold DECIMAL(19,2) DEFAULT 0.00: in currency_code; a person whose commissionable sales in the
 *   period are below it earns no commission for that period, and one who reaches it earns it on all of them; 0 for none
 *
 * Adds business_client_link.commission_threshold DECIMAL(19,2) NULL, after termination_date: the person's own
 * threshold in place of the business's (a senior's higher than a junior's); NULL to use business_master's, 0 for none.
 *
 * Depends on artifact_master, business_master and business_client_link.
 */
class AddEmployeeServicesAndCommission extends Migration
{
    private const BUSINESS_COLUMNS = ['commission_status', 'commission_period', 'commission_threshold'];

    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addColumn('business_master', [
            'commission_status'    => ['type' => 'ENUM', 'constraint' => ['A', 'I'], 'default' => 'I', 'after' => 'service_charge_unit'],
            'commission_period'    => ['type' => 'ENUM', 'constraint' => ['W', 'S', 'M'], 'default' => 'M', 'after' => 'commission_status'],
            'commission_threshold' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00', 'after' => 'commission_period'],
        ]);
        $this->forge->addColumn('business_client_link', [
            'commission_threshold' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'null' => true, 'default' => null, 'after' => 'termination_date'],
        ]);

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'      => ['type' => 'INT', 'unsigned' => true],
            'artifact_id'      => ['type' => 'INT', 'unsigned' => true],
            'client_id'        => ['type' => 'INT', 'unsigned' => true],
            'commission_value' => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'commission_unit'  => ['type' => 'ENUM', 'constraint' => ['P', 'F'], 'default' => 'P'], // percentage, fixed amount
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        // one row per service per person; it also serves the service's foreign key and "who can perform this service"
        $this->forge->addUniqueKey(['business_id', 'artifact_id', 'client_id']);
        // serves the person's foreign key and "which services can this person perform"
        $this->forge->addKey(['business_id', 'client_id']);
        $this->forge->addForeignKey(['business_id', 'artifact_id'], 'artifact_master', ['business_id', 'id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_service_client_link_artifact'));
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'CASCADE', $fkName('fk_business_service_client_link_client'));
        $this->forge->createTable('business_service_client_link', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_service_client_link', true);
        $this->rebuildingTables(function (): void {
            $this->forge->dropColumn('business_client_link', 'commission_threshold');
            $this->forge->dropColumn('business_master', self::BUSINESS_COLUMNS);
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
