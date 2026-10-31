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

    /** auth_master columns of each row of the account list; no id, password_hash or admin_notes */
    public const LIST_FIELDS = [
        'uid',
        'email_address',
        'telephone_number',
        'password_expiry',
        'full_name',
        'otp_mode',
        'account_status',
        'account_type',
    ];

    /** Columns the account list can be ordered by */
    public const LIST_ORDER_FIELDS = [
        'email_address',
        'telephone_number',
        'password_expiry',
        'full_name',
        'otp_mode',
        'account_status',
        'account_type',
    ];

    /** Columns the global search of the account list looks in */
    public const LIST_SEARCH_FIELDS = ['email_address', 'telephone_number', 'full_name'];

    /** Column => the values the account list can be filtered on (exact match) */
    public const LIST_FILTERS = [
        'otp_mode'       => [self::OTP_EMAIL, self::OTP_TELEPHONE, self::OTP_NONE],
        'account_status' => [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_BLOCKED, self::STATUS_SUSPENDED],
        'account_type'   => [self::TYPE_STAFF, self::TYPE_CLIENT],
    ];

    /**
     * One page of all accounts, for the Admin app's account list.
     * The arguments must already be checked against the LIST_ constants: column names go into the SQL as they are.
     * @param int                       $start   Offset
     * @param int                       $length  Page size
     * @param string                    $search  Matched anywhere in LIST_SEARCH_FIELDS; empty for no search
     * @param array<string, string>     $filters LIST_FILTERS column => value
     * @param list<array{string, string}> $order [column, ASC|DESC] pairs, applied in order
     * @return array{records_total: int, records_filtered: int, rows: list<array>}
     */
    public function listAccounts(int $start, int $length, string $search, array $filters, array $order): array
    {
        $total = $this->db->table($this->table)->countAllResults();

        $builder = $this->db->table($this->table)->select(implode(', ', self::LIST_FIELDS));
        foreach ($filters as $column => $value) {
            $builder->where($column, $value);
        }
        if ($search !== '') {
            // like() binds the term and adds ESCAPE '!', but leaves % and _ in it as wildcards, so escape those here.
            // Not escapeLikeString(): it also doubles quotes, which the binding then does again.
            $char = $this->db->likeEscapeChar;
            $term = str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $search);

            // grouped so the OR of the search ANDs with the filters
            $builder->groupStart();
            foreach (self::LIST_SEARCH_FIELDS as $i => $column) {
                $i === 0 ? $builder->like($column, $term, 'both', null, true) : $builder->orLike($column, $term, 'both', null, true);
            }
            $builder->groupEnd();
        }
        $filtered = $builder->countAllResults(false);

        foreach ($order as [$column, $dir]) {
            $builder->orderBy($column, $dir);
        }
        // a unique last key, so rows with equal values never swap between pages
        $builder->orderBy('id', 'ASC');
        $rows = $builder->limit($length, $start)->get()->getResultArray();

        return ['records_total' => $total, 'records_filtered' => $filtered, 'rows' => $rows];
    }

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
