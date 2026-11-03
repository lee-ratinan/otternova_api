<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the Finance department (FIN), which shares the ledger with the Executive Office (EXE);
 * see App\Filters\DepartmentAccess
 */
class AddFinanceDepartment extends Migration
{
    public function up()
    {
        // the test database starts empty; the tests create the departments they need
        if (ENVIRONMENT === 'testing') {
            return;
        }
        $departments = $this->db->table('otternova_department');
        if ($departments->where('department_code', 'FIN')->countAllResults() === 0) {
            $departments->insert(['department_code' => 'FIN', 'department_name' => 'Finance', 'country_code' => 'TH']);
        }
    }

    public function down()
    {
        // left in place: FIN may have been there before up() (it only adds a missing one), and staff may be in it now
    }
}
