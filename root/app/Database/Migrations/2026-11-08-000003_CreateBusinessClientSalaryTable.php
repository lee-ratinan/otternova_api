<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the business_client_salary table: the base salary each of a business's people (business_client_link) is
 * paid, as a history. A raise is a new row starting after the previous one's end_date, never an edit of an old row,
 * so past pay can always be worked out again. A person on commission only (or paid outside the system) has no rows.
 * - salary_amount DECIMAL(19,2): in business_master.currency_code, per salary_period
 * - salary_period ENUM('W', 'S', 'M'): weekly (Monday to Sunday), semi-monthly (1st to 15th, 16th to the month's
 *   end), monthly; the same periods as business_master.commission_period
 * - effective_date DATE: the first day it is paid for
 * - end_date DATE NULL: the last day it is paid for, inclusive; NULL while it is the current salary
 * The model checks what the database cannot: end_date is not before effective_date, a person's rows never overlap
 * (each effective_date is after the previous row's end_date), and only the latest row may have no end_date.
 * The rows are payroll records, so a business_client_link with any cannot be deleted (it is terminated instead).
 * Depends on business_client_link.
 */
class CreateBusinessClientSalaryTable extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $mysql ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = static fn (string $name): string => $mysql ? $name : ''; // nor named foreign keys

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'business_id'    => ['type' => 'INT', 'unsigned' => true],
            'client_id'      => ['type' => 'INT', 'unsigned' => true],
            'salary_amount'  => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'salary_period'  => ['type' => 'ENUM', 'constraint' => ['W', 'S', 'M'], 'default' => 'M'], // weekly, semi-monthly, monthly
            'effective_date' => ['type' => 'DATE'],
            'end_date'       => ['type' => 'DATE', 'null' => true, 'default' => null], // NULL = current
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        // no two rows of a person start on the same day; it also serves the foreign key and "the salary on a date"
        $this->forge->addUniqueKey(['business_id', 'client_id', 'effective_date']);
        $this->forge->addForeignKey(['business_id', 'client_id'], 'business_client_link', ['business_id', 'client_id'], 'CASCADE', 'RESTRICT', $fkName('fk_business_client_salary_client'));
        $this->forge->createTable('business_client_salary', true); // no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('business_client_salary', true);
    }
}
