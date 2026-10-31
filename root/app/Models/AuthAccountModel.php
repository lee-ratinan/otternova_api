<?php

namespace App\Models;

/**
 * auth_master joined to its profile table (staff_master for staff, client_master for clients),
 * for signing in. AuthMasterModel itself is shared with the other apps, so API-only queries live here.
 */
class AuthAccountModel extends AuthMasterModel
{
    /** auth_master columns returned to the apps; password_hash and admin_notes are never sent */
    public const ACCOUNT_FIELDS = [
        'id',
        'uid',
        'email_address',
        'telephone_number',
        'full_name',
        'otp_mode',
        'account_status',
        'account_type',
        'password_expiry',
        'created_at',
        'updated_at',
    ];

    /** account_type => profile table and the columns of it returned to the apps */
    public const PROFILES = [
        self::TYPE_STAFF  => [
            'table'  => 'staff_master',
            'fields' => ['employee_code', 'job_title', 'employment_status', 'gender', 'date_of_birth', 'timezone', 'avatar_path', 'status_message'],
        ],
        self::TYPE_CLIENT => [
            'table'  => 'client_master',
            'fields' => ['display_name', 'status_message', 'status_emoji', 'avatar_path', 'timezone', 'preferred_language'],
        ],
    ];

    /**
     * Find an account to sign in, password_hash included
     * @param string $email
     * @param string $accountType S or C; an account of the other type, or one without its profile row, is not found
     * @return array|null
     */
    public function findForLogin(string $email, string $accountType): ?array
    {
        return $this->findAccount('email_address', $email, $accountType, true);
    }

    /**
     * @param int    $id
     * @param string $accountType
     * @return array|null
     */
    public function findForSessionById(int $id, string $accountType): ?array
    {
        return $this->findAccount('id', $id, $accountType);
    }

    /**
     * @param string $uid
     * @param string $accountType
     * @return array|null
     */
    public function findForSessionByUid(string $uid, string $accountType): ?array
    {
        return $this->findAccount('uid', $uid, $accountType);
    }

    /**
     * The user object of the signed-in response
     * @param array $account A row from one of the find methods above
     * @return array
     */
    public static function toUserPayload(array $account): array
    {
        $fields = array_merge(self::ACCOUNT_FIELDS, self::PROFILES[$account['account_type']]['fields']);
        $user   = array_intersect_key($account, array_flip($fields));
        $user['id'] = (int) $user['id'];

        return $user;
    }

    /**
     * @param string     $column       auth_master column to match
     * @param int|string $value
     * @param string     $accountType
     * @param bool       $withPassword
     * @return array|null
     */
    private function findAccount(string $column, int|string $value, string $accountType, bool $withPassword = false): ?array
    {
        $profile = self::PROFILES[$accountType] ?? null;
        if ($profile === null) {
            return null;
        }

        $select = array_map(static fn ($field) => 'auth_master.' . $field, self::ACCOUNT_FIELDS);
        if ($withPassword) {
            $select[] = 'auth_master.password_hash';
        }
        foreach ($profile['fields'] as $field) {
            $select[] = $profile['table'] . '.' . $field;
        }

        return $this->select(implode(', ', $select))
            ->join($profile['table'], $profile['table'] . '.auth_id = auth_master.id', 'inner')
            ->where('auth_master.' . $column, $value)
            ->where('auth_master.account_type', $accountType)
            ->first();
    }
}
