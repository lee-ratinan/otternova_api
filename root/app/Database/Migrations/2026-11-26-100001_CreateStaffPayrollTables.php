<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the staff payroll tables, for the Executive Office only (the routes' department filter):
 * - staff_salary (App\Models\StaffSalaryModel): the pay terms of a staff member over time, one row per change.
 *   Rows never overlap: each starts after the previous one's end_date, and only the latest may have no end_date
 *   (the current terms). A new row ends the current one the day before; only the latest row may be corrected, and
 *   only while no payslip uses it. base_amount is per rate_unit (M month, W week, D day, H hour); pay_frequency is
 *   how often it is paid (M, S semi-monthly, W). entity_id is the employing otternova_ledger_entity, NULL until one
 *   is registered. change_reason: H hired, P promotion, A annual review, C correction, O other.
 * - staff_pay_account: where a staff member's pay goes, the current details only; bank_account_number is encrypted
 *   (App\Libraries\FieldEncryption). Kept out of staff_master, whose profile any staff member's form can read.
 * - staff_payslip and staff_payslip_line: what was paid for one pay period, with its earnings (E), deductions (D:
 *   income tax, social security) and employer contributions (C). Nothing writes them yet: they are for when the
 *   company is registered. current_flag is 1 unless the payslip is void (NULL), so a period has one live payslip.
 *   The person's details are copied in when it is issued; tax_id_number and bank_account_number are encrypted.
 * Salary rows and payslips are payroll records: RESTRICT keeps a staff_master row from being deleted with them.
 * Depends on staff_master, auth_master, otternova_ledger_entity and otternova_ledger_master.
 */
class CreateStaffPayrollTables extends Migration
{
    public function up()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';
        // SQLite (the test database) names its foreign keys itself
        $fk       = static fn (string $name): string => $mysql ? $name : '';
        $publicId = static fn (int $length): array => $mysql
            ? ['public_id CHAR(' . $length . ') CHARACTER SET ascii COLLATE ascii_bin NOT NULL']
            : ['public_id' => ['type' => 'CHAR', 'constraint' => $length]];
        $timestamps = [
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            $mysql ? 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
                : 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ];

        // ---- staff_salary ----
        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId(8));
        $this->forge->addField([
            'staff_id'            => ['type' => 'INT', 'unsigned' => true],
            'entity_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'effective_date'      => ['type' => 'DATE'],
            'end_date'            => ['type' => 'DATE', 'null' => true],
            'currency_code'       => ['type' => 'CHAR', 'constraint' => 3],
            'base_amount'         => ['type' => 'DECIMAL', 'constraint' => '19,2'],
            'rate_unit'           => ['type' => 'ENUM', 'constraint' => ['M', 'W', 'D', 'H'], 'default' => 'M'],
            'pay_frequency'       => ['type' => 'ENUM', 'constraint' => ['M', 'S', 'W'], 'default' => 'M'],
            'standard_hours_week' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'social_security'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'change_reason'       => ['type' => 'ENUM', 'constraint' => ['H', 'P', 'A', 'C', 'O']],
            'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_staff_salary_public_id');
        $this->forge->addUniqueKey(['staff_id', 'effective_date'], 'uq_staff_salary_staff_effective');
        $this->forge->addKey('entity_id', false, false, 'staff_salary_entity_id');
        $this->forge->addForeignKey('staff_id', 'staff_master', 'id', 'CASCADE', 'RESTRICT', $fk('fk_staff_salary_staff'));
        $this->forge->addForeignKey('entity_id', 'otternova_ledger_entity', 'id', 'CASCADE', 'RESTRICT', $fk('fk_staff_salary_entity'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_staff_salary_created_by'));
        $this->forge->createTable('staff_salary', true); // no ENGINE option so the SQLite test database can run this too

        // ---- staff_pay_account ----
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'staff_id'            => ['type' => 'INT', 'unsigned' => true],
            'bank_name'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'bank_branch'         => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'bank_account_name'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'bank_account_number' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true], // encrypted
            'updated_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('staff_id', 'uq_staff_pay_account_staff');
        $this->forge->addForeignKey('staff_id', 'staff_master', 'id', 'CASCADE', 'CASCADE', $fk('fk_staff_pay_account_staff'));
        $this->forge->addForeignKey('updated_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_staff_pay_account_updated_by'));
        $this->forge->createTable('staff_pay_account', true);

        // ---- staff_payslip ----
        $this->forge->addField(['id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true]]);
        $this->forge->addField($publicId(12));
        $this->forge->addField([
            'staff_id'            => ['type' => 'INT', 'unsigned' => true],
            'salary_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'entity_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'period_start'        => ['type' => 'DATE'],
            'period_end'          => ['type' => 'DATE'],
            'pay_date'            => ['type' => 'DATE'],
            'currency_code'       => ['type' => 'CHAR', 'constraint' => 3],
            'gross_amount'        => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'deduction_amount'    => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'net_amount'          => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'employer_amount'     => ['type' => 'DECIMAL', 'constraint' => '19,2', 'default' => '0.00'],
            'status'              => ['type' => 'ENUM', 'constraint' => ['D', 'I', 'P', 'V'], 'default' => 'D'], // draft, issued, paid, void
            'current_flag'        => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => 1],
            'employee_code'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'employee_name'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'job_title'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'department_name'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tax_id_number'       => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true], // encrypted
            'bank_name'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'bank_account_number' => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true], // encrypted
            'paid_at'             => ['type' => 'DATETIME', 'null' => true],
            'payment_reference'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'ledger_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'void_reason'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'issued_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'issued_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id', 'uq_staff_payslip_public_id');
        $this->forge->addUniqueKey(['staff_id', 'period_start', 'period_end', 'current_flag'], 'uq_staff_payslip_period');
        $this->forge->addKey('pay_date', false, false, 'staff_payslip_pay_date');
        $this->forge->addKey(['entity_id', 'period_start'], false, false, 'staff_payslip_entity_period');
        $this->forge->addKey('status', false, false, 'staff_payslip_status');
        $this->forge->addForeignKey('staff_id', 'staff_master', 'id', 'CASCADE', 'RESTRICT', $fk('fk_staff_payslip_staff'));
        $this->forge->addForeignKey('salary_id', 'staff_salary', 'id', 'CASCADE', 'RESTRICT', $fk('fk_staff_payslip_salary'));
        $this->forge->addForeignKey('entity_id', 'otternova_ledger_entity', 'id', 'CASCADE', 'RESTRICT', $fk('fk_staff_payslip_entity'));
        $this->forge->addForeignKey('ledger_id', 'otternova_ledger_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_staff_payslip_ledger'));
        $this->forge->addForeignKey('created_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_staff_payslip_created_by'));
        $this->forge->addForeignKey('issued_by', 'auth_master', 'id', 'CASCADE', 'SET NULL', $fk('fk_staff_payslip_issued_by'));
        $this->forge->createTable('staff_payslip', true);

        // ---- staff_payslip_line ----
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'payslip_id'  => ['type' => 'INT', 'unsigned' => true],
            'line_type'   => ['type' => 'ENUM', 'constraint' => ['E', 'D', 'C']], // earning, deduction, employer contribution
            'line_code'   => ['type' => 'VARCHAR', 'constraint' => 16],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255],
            'quantity'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'rate'        => ['type' => 'DECIMAL', 'constraint' => '19,4', 'null' => true],
            'amount'      => ['type' => 'DECIMAL', 'constraint' => '19,2'], // positive; line_type gives the sign
            'taxable'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order'  => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addField($timestamps);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['payslip_id', 'line_type'], false, false, 'staff_payslip_line_payslip_type');
        $this->forge->addKey('line_code', false, false, 'staff_payslip_line_code');
        $this->forge->addForeignKey('payslip_id', 'staff_payslip', 'id', 'CASCADE', 'CASCADE', $fk('fk_staff_payslip_line_payslip'));
        $this->forge->createTable('staff_payslip_line', true);
    }

    public function down()
    {
        $this->forge->dropTable('staff_payslip_line', true);
        $this->forge->dropTable('staff_payslip', true);
        $this->forge->dropTable('staff_pay_account', true);
        $this->forge->dropTable('staff_salary', true);
    }
}
