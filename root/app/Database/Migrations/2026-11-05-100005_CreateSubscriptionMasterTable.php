<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the subscription_master table: a business on a paid plan. created_at is when it was asked for; it only
 * starts once its first invoice is paid (activated_at), automatically or confirmed by staff.
 *
 * subscription_status: P pending (no paid invoice yet, no period), A active, S suspended, C cancelled, X expired.
 * The times below are UTC; the grace and suspension lengths are in Config\Billing, not here.
 * php spark billing:run (SubscriptionBilling::runSchedule()) makes the changes below that are due to time passing.
 * - P whose first invoice is not paid within Config\Billing::$invoiceHoldHours: X, and the invoice is voided.
 * - Unpaid at current_period_end: still A for the grace days; paid within them, the new period starts at the old
 *   current_period_end.
 * - Unpaid after the grace days: S, from suspended_at. Paid while S: A again, the new period starts when it was paid,
 *   and billing_anchor_day becomes that day.
 * - S for the suspension days: X, at ended_at.
 * - cancel_at_period_end 'Y' at current_period_end: C (cancelled_at, ended_at), with no grace.
 * suspended_at is kept so that a change to the configured lengths does not move the expiry of a suspension under way.
 *
 * billing_anchor_day is the day of the month the periods end on, in the business's local time: a period ends on that
 * day of the next month or year, or on the month's last day when it is shorter (31 Jan, 28 Feb, 31 Mar).
 * plan_version_id is the version of the current period. Each renewal is billed on the version in effect when the new
 * period starts, for the same series, unless next_plan_version_id (a change the business asked for, taking effect at
 * the end of the period) says otherwise.
 *
 * live_guard is computed by the database, 1 while the status is P, A or S and NULL otherwise; nothing writes to it.
 * It is only there for uq_subscription_master_one_live: one live subscription per business, any number of ended ones.
 * public_id is a NanoID (see PublicIdTrait); on MySQL it is ascii_bin, as NanoIDs tell upper from lower case.
 * Depends on business_master and plan_version.
 */
class CreateSubscriptionMasterTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($mysql
            ? ['public_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => 12]]);
        $this->forge->addField([
            'business_id'          => ['type' => 'INT', 'unsigned' => true],
            'plan_version_id'      => ['type' => 'INT', 'unsigned' => true], // of the current period
            'next_plan_version_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true], // from the next period on
            'subscription_status'  => ['type' => 'ENUM', 'constraint' => ['P', 'A', 'S', 'C', 'X'], 'default' => 'P'],
            'billing_mode'         => ['type' => 'ENUM', 'constraint' => ['A', 'M'], 'default' => 'M'], // charged automatically, paid by hand
            'billing_anchor_day'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true], // 1-31, local; set when paid
            'activated_at'         => ['type' => 'DATETIME', 'null' => true], // first invoice paid
            'current_period_start' => ['type' => 'DATETIME', 'null' => true], // NULL while P; included
            'current_period_end'   => ['type' => 'DATETIME', 'null' => true], // excluded
            'next_billing_at'      => ['type' => 'DATETIME', 'null' => true],
            'cancel_at_period_end' => ['type' => 'ENUM', 'constraint' => ['Y', 'N'], 'default' => 'N'],
            'cancel_requested_at'  => ['type' => 'DATETIME', 'null' => true], // when the business asked
            'suspended_at'         => ['type' => 'DATETIME', 'null' => true], // NULL unless S
            'cancelled_at'         => ['type' => 'DATETIME', 'null' => true], // when it became C
            'ended_at'             => ['type' => 'DATETIME', 'null' => true], // when it stopped, as C or X
            "live_guard TINYINT GENERATED ALWAYS AS (CASE WHEN subscription_status IN ('P', 'A', 'S') THEN 1 END) STORED",
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_subscription_master_public_id'); // named after the column, see PublicIdTrait
        $this->forge->addUniqueKey(['business_id', 'live_guard'], 'uq_subscription_master_one_live');
        $this->forge->addKey(['subscription_status', 'current_period_end']); // the renewal, grace and expiry runs
        $this->forge->addKey('plan_version_id');
        $this->forge->addKey('next_plan_version_id');
        $this->forge->addForeignKey('business_id', 'business_master', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_master_business'));
        $this->forge->addForeignKey('plan_version_id', 'plan_version', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_master_plan_version'));
        $this->forge->addForeignKey('next_plan_version_id', 'plan_version', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_subscription_master_next_plan_version'));
        $this->forge->createTable('subscription_master', true);
    }

    public function down()
    {
        $this->forge->dropTable('subscription_master', true);
    }
}
