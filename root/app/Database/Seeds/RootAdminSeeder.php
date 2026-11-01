<?php

namespace App\Database\Seeds;

use App\Models\AuthMasterModel;
use App\Models\OtternovaDepartmentModel;
use App\Models\StaffMasterModel;
use CodeIgniter\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * Creates the root admin staff account (auth_master + staff_master) and its department.
 * Safe to run more than once: anything that already exists is left as it is.
 *
 * The generated password is written to the log once; sign in and change it.
 */
class RootAdminSeeder extends Seeder
{
    private const EMAIL = 'eikin@otternova.com';

    public function run()
    {
        $authModel       = model(AuthMasterModel::class);
        $staffModel      = model(StaffMasterModel::class);
        $departmentModel = model(OtternovaDepartmentModel::class);

        if ($authModel->where('email_address', self::EMAIL)->first()) {
            log_message('info', 'RootAdminSeeder: ' . self::EMAIL . ' already exists, skipped.');
            return;
        }

        $this->db->transBegin();
        try {
            $password = $this->createAccount($authModel, $staffModel, $departmentModel);
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        // logger.threshold = 4 only records 'error' and above, so use 'alert' to make sure this is written
        log_message('alert', 'RootAdminSeeder: created ' . self::EMAIL . ' with temporary password: ' . $password);
    }

    /**
     * Insert the department (if missing), auth_master and staff_master rows; return the plain password.
     */
    private function createAccount(AuthMasterModel $authModel, StaffMasterModel $staffModel, OtternovaDepartmentModel $departmentModel): string
    {
        $department = $departmentModel->where('department_code', 'EXE')->first();
        $departmentId = $department['id'] ?? $this->insertOrFail($departmentModel, [
            'department_code' => 'EXE',
            'department_name' => 'Executive Office',
            'country_code'    => 'TH',
        ]);

        $password = $this->generatePassword();
        $authId   = $this->insertOrFail($authModel, [
            'uid'              => $authModel->generateUid(self::EMAIL),
            'email_address'    => self::EMAIL,
            'telephone_number' => '+6597754577',
            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
            'password_expiry'  => date('Y-m-d', strtotime('+30 days')),
            'full_name'        => 'Eikin Rikikawa',
            'otp_mode'         => 'E',
            'account_status'   => 'A',
            'account_type'     => 'S',
            'admin_notes'      => 'Root admin account, created by RootAdminSeeder.',
        ]);

        $this->insertOrFail($staffModel, [
            'auth_id'               => $authId,
            'department_id'         => $departmentId,
            'employee_code'         => '0001',
            'job_title'             => 'CEO',
            'employment_type'       => 'F',
            'employment_status'     => 'A',
            'employment_start_date' => date('Y-m-d'),
            'work_country_code'     => 'SG',
            'work_email_address'    => self::EMAIL,
            'work_telephone_number' => '+6597754577',
            'legal_first_name'      => 'Eikin',
            'legal_last_name'       => 'Rikikawa',
            'preferred_name'        => 'Eikin',
            'gender'                => 'M',
            'date_of_birth'         => '1989-11-15',
            'nationality_code'      => 'TH',
            'country_code'          => 'TH',
            'preferred_language'    => 'en-US',
            'timezone'              => 'Asia/Bangkok',
            'status_message'        => 'Building OtterNova!',
        ]);

        return $password;
    }

    /**
     * Insert through the model so its validation rules apply; throw on failure to roll back.
     */
    private function insertOrFail($model, array $data): int
    {
        $id = $model->insert($data, true);
        if ($id === false) {
            throw new RuntimeException(get_class($model) . ': ' . implode(' ', $model->errors()));
        }
        return (int) $id;
    }

    private function generatePassword(int $length = 16): string
    {
        $chars    = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}
