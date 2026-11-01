<?php

namespace App\Database\Seeds;

use App\Models\AuthMasterModel;
use App\Models\ClientMasterModel;
use CodeIgniter\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * Creates a test client account (auth_master + client_master).
 * Safe to run more than once: an account that already exists is left as it is.
 * Run with: php spark db:seed TestClientSeeder
 *
 * The generated password is written to the log once; sign in and change it.
 */
class TestClientSeeder extends Seeder
{
    private const EMAIL = 'lee.ratinan@gmail.com';

    public function run()
    {
        $authModel   = model(AuthMasterModel::class);
        $clientModel = model(ClientMasterModel::class);

        if ($authModel->where('email_address', self::EMAIL)->first()) {
            log_message('info', 'TestClientSeeder: ' . self::EMAIL . ' already exists, skipped.');
            return;
        }

        $this->db->transBegin();
        try {
            $password = $this->createAccount($authModel, $clientModel);
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        // logger.threshold = 4 only records 'error' and above, so use 'alert' to make sure this is written
        log_message('alert', 'TestClientSeeder: created ' . self::EMAIL . ' with temporary password: ' . $password);
    }

    /**
     * Insert the auth_master and client_master rows; return the plain password.
     */
    private function createAccount(AuthMasterModel $authModel, ClientMasterModel $clientModel): string
    {
        $password = $this->generatePassword();
        $authId   = $this->insertOrFail($authModel, [
            'uid'              => $authModel->generateUid(self::EMAIL),
            'email_address'    => self::EMAIL,
            'telephone_number' => '+66897828331',
            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
            'password_expiry'  => date('Y-m-d', strtotime('+30 days')),
            'full_name'        => 'Ratinan Lee',
            'otp_mode'         => 'E',
            'account_status'   => 'A',
            'account_type'     => 'C',
            'admin_notes'      => 'Test client account, created by TestClientSeeder.',
        ]);

        // national ID and avatar are left empty
        $this->insertOrFail($clientModel, [
            'auth_id'            => $authId,
            'legal_first_name'   => 'Ratinan',
            'legal_last_name'    => 'Lee',
            'display_name'       => 'Ratinan Lee',
            'status_message'     => 'Testing OtterNova!',
            'status_emoji'       => '🦦',
            'gender'             => 'N',
            'date_of_birth'      => '1990-01-01',
            'nationality_code'   => 'TH',
            'preferred_language' => 'en-US',
            'timezone'           => 'Asia/Bangkok',
            'bio'                => 'Test client account.',
            'marketing_opt_in'   => 0,
            'internal_notes'     => 'Created by TestClientSeeder.',
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
