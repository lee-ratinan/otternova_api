<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the staff_master table (App\Models\StaffMasterModel)
 * Depends on auth_master and otternova_department.
 */
class CreateStaffMasterTable extends Migration
{
    public function up()
    {
        // SQLite (the test database) has no ON UPDATE; there the model has to be relied on instead
        $onUpdate = $this->db->DBDriver === 'MySQLi' ? ' ON UPDATE CURRENT_TIMESTAMP' : '';
        $fkName   = fn (string $name): string => $this->db->DBDriver === 'MySQLi' ? $name : ''; // nor named foreign keys

        $gender = ['M', 'F', 'NB', 'X', 'N'];

        $this->forge->addField([
            'id'                          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'auth_id'                     => ['type' => 'INT', 'unsigned' => true],
            'department_id'               => ['type' => 'INT', 'unsigned' => true],
            'employee_code'               => ['type' => 'VARCHAR', 'constraint' => 32],
            'job_title'                   => ['type' => 'VARCHAR', 'constraint' => 128],
            'manager_staff_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'employment_type'             => ['type' => 'ENUM', 'constraint' => ['F', 'P', 'C', 'I'], 'default' => 'F'],
            'employment_status'           => ['type' => 'ENUM', 'constraint' => ['A', 'P', 'T', 'R'], 'default' => 'P'],
            'employment_start_date'       => ['type' => 'DATE'],
            'probation_end_date'          => ['type' => 'DATE', 'null' => true],
            'employment_end_date'         => ['type' => 'DATE', 'null' => true],
            'work_country_code'           => ['type' => 'CHAR', 'constraint' => 2],
            'work_email_address'          => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'work_telephone_number'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'legal_first_name'            => ['type' => 'VARCHAR', 'constraint' => 128],
            'legal_middle_name'           => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'legal_last_name'             => ['type' => 'VARCHAR', 'constraint' => 128],
            'preferred_name'              => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'gender'                      => ['type' => 'ENUM', 'constraint' => $gender, 'null' => true],
            'date_of_birth'               => ['type' => 'DATE', 'null' => true],
            'nationality_code'            => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'national_id_type'            => ['type' => 'ENUM', 'constraint' => ['N', 'P', 'W'], 'null' => true],
            'national_id_number'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tax_id_number'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'address_line_1'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'address_line_2'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'address_city'                => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'subdivision_code'            => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true],
            'postal_code'                 => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'country_code'                => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'emergency_contact_name'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'emergency_contact_relation'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'emergency_contact_telephone' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'preferred_language'          => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'timezone'                    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'avatar_path'                 => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status_message'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'bio'                         => ['type' => 'TEXT', 'null' => true],
            'hr_notes'                    => ['type' => 'TEXT', 'null' => true],
            // maintained by the database, not the model
            'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' . $onUpdate,
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('auth_id');
        $this->forge->addUniqueKey('employee_code');
        $this->forge->addKey('department_id');
        $this->forge->addKey('manager_staff_id');
        $this->forge->addKey('employment_status');
        $this->forge->addForeignKey('auth_id', 'auth_master', 'id', 'CASCADE', 'CASCADE', $fkName('fk_staff_master_auth'));
        $this->forge->addForeignKey('department_id', 'otternova_department', 'id', 'CASCADE', 'RESTRICT', $fkName('fk_staff_master_department'));
        $this->forge->addForeignKey('manager_staff_id', 'staff_master', 'id', 'CASCADE', 'SET NULL', $fkName('fk_staff_master_manager'));
        $this->forge->createTable('staff_master', true); // InnoDB is MySQL's default engine; no ENGINE option so the SQLite test database can run this too
    }

    public function down()
    {
        $this->forge->dropTable('staff_master', true);
    }
}
