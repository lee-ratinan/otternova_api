<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the Client Experience department (CXD), which answers the contact form and watches how the businesses' customers
 * get on; see App\Libraries\ClientExperienceDashboard
 */
class AddClientExperienceDepartment extends Migration
{
    public function up()
    {
        // the test database starts empty; the tests create the departments they need
        if (ENVIRONMENT === 'testing') {
            return;
        }
        $departments = $this->db->table('otternova_department');
        if ($departments->where('department_code', 'CXD')->countAllResults() === 0) {
            $departments->insert(['department_code' => 'CXD', 'department_name' => 'Client Experience', 'country_code' => 'TH']);
        }
    }

    public function down()
    {
        // left in place: CXD may have been there before up() (it only adds a missing one), and staff may be in it now
    }
}
