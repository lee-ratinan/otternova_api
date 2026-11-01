<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\FieldEncryption;
use Throwable;

/**
 * auth_master joined to its profile table (staff_master for staff, client_master for clients),
 * for signing in. AuthMasterModel itself is shared with the other apps, so API-only queries live here.
 */
class AuthAccountModel extends AuthMasterModel
{
    use TransactionTrait;

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

    /** auth_master columns of the Admin app's account form; id and password_hash never go in or out */
    public const FORM_ACCOUNT_FIELDS = [
        'uid',
        'email_address',
        'telephone_number',
        'password_expiry',
        'full_name',
        'otp_mode',
        'account_status',
        'account_type',
        'admin_notes',
    ];

    /** auth_master form columns that may be NULL */
    public const FORM_ACCOUNT_NULLABLE = ['telephone_number', 'password_expiry', 'admin_notes'];

    /**
     * account_type => profile table of the account form: its model (for the validation rules), its columns but
     * id, auth_id, avatar_path and the timestamps, and which of those may be NULL
     */
    public const FORM_PROFILES = [
        self::TYPE_STAFF  => [
            'table'    => 'staff_master',
            'model'    => StaffMasterModel::class,
            'fields'   => [
                'department_id', 'employee_code', 'job_title', 'manager_staff_id', 'employment_type', 'employment_status',
                'employment_start_date', 'probation_end_date', 'employment_end_date', 'work_country_code',
                'work_email_address', 'work_telephone_number', 'legal_first_name', 'legal_middle_name', 'legal_last_name',
                'preferred_name', 'gender', 'date_of_birth', 'nationality_code', 'national_id_type', 'national_id_number',
                'tax_id_number', 'address_line_1', 'address_line_2', 'address_city', 'subdivision_code', 'postal_code',
                'country_code', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_telephone',
                'preferred_language', 'timezone', 'status_message', 'bio', 'hr_notes',
            ],
            'nullable' => [
                'manager_staff_id', 'probation_end_date', 'employment_end_date', 'work_email_address',
                'work_telephone_number', 'legal_middle_name', 'preferred_name', 'gender', 'date_of_birth',
                'nationality_code', 'national_id_type', 'national_id_number', 'tax_id_number', 'address_line_1',
                'address_line_2', 'address_city', 'subdivision_code', 'postal_code', 'country_code',
                'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_telephone',
                'preferred_language', 'timezone', 'status_message', 'bio', 'hr_notes',
            ],
        ],
        self::TYPE_CLIENT => [
            'table'    => 'client_master',
            'model'    => ClientMasterModel::class,
            'fields'   => [
                'legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'national_id_type',
                'national_id_number', 'status_message', 'status_emoji', 'gender', 'date_of_birth', 'nationality_code',
                'preferred_language', 'timezone', 'bio', 'marketing_opt_in', 'internal_notes',
            ],
            'nullable' => [
                'legal_first_name', 'legal_middle_name', 'legal_last_name', 'display_name', 'national_id_type',
                'national_id_number', 'status_message', 'status_emoji', 'gender', 'date_of_birth', 'nationality_code',
                'preferred_language', 'timezone', 'bio', 'internal_notes',
            ],
        ],
    ];

    /**
     * One account with its profile row, for the account form, with the encrypted profile columns decrypted.
     * Both rows carry their internal id for the caller's own use; strip it before anything goes out.
     * @param string $uid
     * @return array{account: array, profile: array|null}|null Null when no account has this uid; profile is null
     *                                                          when the account has no profile row yet
     */
    public function findForForm(string $uid): ?array
    {
        $account = $this->db->table($this->table)
            ->select(implode(', ', array_merge(['id'], self::FORM_ACCOUNT_FIELDS)))
            ->where('uid', $uid)
            ->get()
            ->getRowArray();
        if ($account === null) {
            return null;
        }

        $form    = self::FORM_PROFILES[$account['account_type']] ?? null;
        $profile = $form === null ? null : $this->db->table($form['table'])
            ->select(implode(', ', array_merge(['id'], $form['fields'])))
            ->where('auth_id', $account['id'])
            ->get()
            ->getRowArray();

        return ['account' => $account, 'profile' => $profile === null ? null : (new FieldEncryption())->decryptRow($form['table'], $profile)];
    }

    /**
     * Create a staff account and its staff_master row in one transaction, logged to log_activity in it
     * @param array $account Checked auth_master columns, uid and password_hash included
     * @param array $profile Checked staff_master columns, without auth_id
     * @param int   $actorId auth_master.id of the staff member creating it
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createStaffWithProfile(array $account, array $profile, int $actorId): void
    {
        $table     = self::FORM_PROFILES[self::TYPE_STAFF]['table'];
        $log       = new ActivityLog();
        $encrypted = (new FieldEncryption())->encryptRow($table, $profile);
        $this->inTransaction(function () use ($account, $profile, $encrypted, $actorId, $table, $log) {
            $this->db->table($this->table)->insert($account);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created account ' . $account['uid'], $this->table, $id, $account);

            $this->db->table($table)->insert($encrypted + ['auth_id' => $id]);
            $log->inserted($actorId, 'Created staff profile of ' . $account['uid'], $table, (int) $this->db->insertID(), $profile + ['auth_id' => $id]);
        });
    }

    /**
     * Update an account and insert or update its profile row in one transaction, logged to log_activity in it.
     * Only the columns whose value changes are written and logged; encrypted columns are compared as plaintext.
     * @param array $record  findForForm(); its account_type picks the profile table
     * @param array $account Checked auth_master columns
     * @param array $profile Checked profile columns
     * @param int   $actorId auth_master.id of the staff member making the change
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateWithProfile(array $record, array $account, array $profile, int $actorId): void
    {
        $stored = $record['account'];
        $id     = (int) $stored['id'];
        $uid    = $stored['uid'];
        $table  = self::FORM_PROFILES[$stored['account_type']]['table'] ?? null;
        $log    = new ActivityLog();
        $crypt  = new FieldEncryption();
        $this->inTransaction(function () use ($record, $stored, $id, $uid, $table, $account, $profile, $actorId, $log, $crypt) {
            $changes = ActivityLog::changes($stored, $account);
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated account ' . $uid, $this->table, $id, $stored, $changes);
            }
            if ($table === null) {
                return;
            }

            $what = ($stored['account_type'] === self::TYPE_STAFF ? 'staff' : 'client') . ' profile of ' . $uid;
            if ($record['profile'] === null) {
                $row = $profile + ['auth_id' => $id];
                $this->db->table($table)->insert($crypt->encryptRow($table, $row));
                $log->inserted($actorId, 'Created ' . $what, $table, (int) $this->db->insertID(), $row);

                return;
            }
            $changes = ActivityLog::changes($record['profile'], $profile);
            if ($changes !== []) {
                $profileId = (int) $record['profile']['id'];
                $this->db->table($table)->where('id', $profileId)->update($crypt->encryptRow($table, $changes));
                $log->updated($actorId, 'Updated ' . $what, $table, $profileId, $record['profile'], $changes);
            }
        });
    }

    /**
     * Delete an account, its profile row and its pending OTP challenges in one transaction, logged to
     * log_activity in it. Refused when it is the last active staff account, checked inside the transaction
     * so two staff deleting each other at the same time cannot both succeed.
     * @param array $record  findForForm()
     * @param int   $actorId auth_master.id of the staff member deleting it
     * @return bool False when refused as the last active staff account; nothing is deleted then
     * @throws Throwable When a delete fails; nothing is deleted then
     */
    public function deleteWithProfile(array $record, int $actorId): bool
    {
        $stored = $record['account'];
        $id     = (int) $stored['id'];
        $log    = new ActivityLog();

        return $this->inTransaction(function () use ($record, $stored, $id, $actorId, $log) {
            $isActiveStaff = $this->db->table($this->table)
                ->where('id', $id)
                ->where('account_type', self::TYPE_STAFF)
                ->where('account_status', self::STATUS_ACTIVE)
                ->countAllResults() > 0;
            $otherActiveStaff = $this->db->table($this->table)
                ->where('id !=', $id)
                ->where('account_type', self::TYPE_STAFF)
                ->where('account_status', self::STATUS_ACTIVE)
                ->countAllResults();
            if ($isActiveStaff && $otherActiveStaff === 0) {
                return false;
            }

            // the foreign keys cascade, but delete explicitly so it does not depend on them, and so each is logged
            $table = self::FORM_PROFILES[$stored['account_type']]['table'] ?? null;
            if ($table !== null && $record['profile'] !== null) {
                $this->db->table($table)->where('id', $record['profile']['id'])->delete();
                $log->deleted($actorId, 'Deleted profile of ' . $stored['uid'], $table, (int) $record['profile']['id'], $record['profile']);
            }
            $challenges = $this->db->table('auth_otp_challenge')
                ->select('id, auth_id, account_type, attempts, expires_at')
                ->where('auth_id', $id)
                ->get()
                ->getResultArray();
            foreach ($challenges as $challenge) {
                $this->db->table('auth_otp_challenge')->where('id', $challenge['id'])->delete();
                $log->deleted($actorId, 'Deleted OTP challenge of ' . $stored['uid'], 'auth_otp_challenge', (int) $challenge['id'], $challenge);
            }
            $this->db->table($this->table)->where('id', $id)->delete();
            $log->deleted($actorId, 'Deleted account ' . $stored['uid'], $this->table, $id, $stored);

            return true;
        });
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
