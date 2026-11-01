<?php

namespace App\Libraries;

use App\Models\AuthAccountModel;
use App\Models\AuthMasterModel;
use App\Models\StaffMasterModel;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Random\RandomException;

/**
 * Checks what the Admin app's account form sends to /staff/account/update: only whitelisted fields get through,
 * empty strings become NULL, country codes are upper-cased, telephone numbers become E.164, and the result is
 * validated with the AuthMaster / StaffMaster / ClientMaster model rules.
 * Errors are keyed "account.<field>" or "profile.<field>", as the form strips the prefix to highlight the field.
 */
class AccountForm
{
    /** Fields holding an ISO 3166-1 alpha-2 code */
    private const COUNTRY_FIELDS = ['work_country_code', 'nationality_code', 'country_code'];

    /** Fields holding a telephone number */
    private const TELEPHONE_FIELDS = ['telephone_number', 'work_telephone_number', 'emergency_contact_telephone'];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * A new staff account. uid comes from the email address and password_hash from a secret nobody knows;
     * the invite / reset flow sets a real password later.
     * @param array $accountIn The request's "account"
     * @param array $profileIn The request's "profile"
     * @return array{account: array, profile: array, errors: array<string, string>} Write only when errors is empty
     * @throws RandomException
     */
    public function forCreate(array $accountIn, array $profileIn): array
    {
        $this->errors = [];
        if (($accountIn['account_type'] ?? null) !== AuthMasterModel::TYPE_STAFF) {
            return ['account' => [], 'profile' => [], 'errors' => ['account.account_type' => lang('Accounts.staffOnly')]];
        }

        $model   = model(AuthAccountModel::class);
        $fields  = array_diff(AuthAccountModel::FORM_ACCOUNT_FIELDS, ['uid', 'account_type']);
        $account = $this->clean('account', $accountIn, $fields, AuthAccountModel::FORM_ACCOUNT_NULLABLE, $model->getValidationRules());
        $account['account_type'] = AuthMasterModel::TYPE_STAFF;
        $account['uid']          = $model->generateUid((string) ($account['email_address'] ?? ''));
        $this->validate('account', $account + ['id' => null], array_merge($fields, ['uid', 'account_type']), $model, 'AuthMaster');
        // the uid is derived from the email address, so that is the field to fix
        if (isset($this->errors['account.uid'])) {
            $this->errors['account.email_address'] ??= $this->errors['account.uid'];
            unset($this->errors['account.uid']);
        }

        $profile = $this->profile(AuthMasterModel::TYPE_STAFF, $profileIn, [], null);
        $account['password_hash'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        return ['account' => $account, 'profile' => $profile, 'errors' => $this->errors];
    }

    /**
     * Changes to an existing account. email_address and account_type are fixed, and the profile table
     * comes from the stored account_type.
     * @param array  $record    AuthAccountModel::findForForm()
     * @param array  $accountIn The request's "account"
     * @param array  $profileIn The request's "profile"
     * @param string $callerUid The signed-in staff member, who may not change their own account_status
     * @return array{account: array, profile: array, errors: array<string, string>} Only the columns to change
     */
    public function forUpdate(array $record, array $accountIn, array $profileIn, string $callerUid): array
    {
        $this->errors = [];
        $stored  = $record['account'];
        $model   = model(AuthAccountModel::class);
        $fields  = array_diff(AuthAccountModel::FORM_ACCOUNT_FIELDS, ['uid', 'email_address', 'account_type']);
        $account = $this->clean('account', $accountIn, $fields, AuthAccountModel::FORM_ACCOUNT_NULLABLE, $model->getValidationRules());
        if ($stored['uid'] === $callerUid && isset($account['account_status']) && $account['account_status'] !== $stored['account_status']) {
            $this->errors['account.account_status'] = lang('Accounts.ownStatus');
        }
        // the whole row is validated, so a change cannot leave it invalid
        $this->validate('account', $account + $stored, $fields, $model, 'AuthMaster');

        $profile = isset(AuthAccountModel::FORM_PROFILES[$stored['account_type']])
            ? $this->profile($stored['account_type'], $profileIn, $record['profile'] ?? [], $record['profile']['id'] ?? null)
            : [];

        return ['account' => $account, 'profile' => $profile, 'errors' => $this->errors];
    }

    /**
     * @param string          $accountType Picks the profile table
     * @param array           $input
     * @param array           $stored      The stored profile row, or [] when there is none yet
     * @param int|string|null $profileId   The stored row's id: is_unique ignores it, and a manager cannot be it
     * @return array
     */
    private function profile(string $accountType, array $input, array $stored, int|string|null $profileId): array
    {
        $form  = AuthAccountModel::FORM_PROFILES[$accountType];
        $model = model($form['model']);
        $rules = $model->getValidationRules();
        if ($accountType === AuthMasterModel::TYPE_STAFF) {
            $rules['department_id'] .= '|is_not_unique[otternova_department.id]';
        }

        $profile = $this->clean('profile', $input, $form['fields'], $form['nullable'], $rules);
        $data    = ['id' => $profileId === null ? null : (string) $profileId] + $profile + $stored;
        $this->validate('profile', $data, $form['fields'], $model, $accountType === AuthMasterModel::TYPE_STAFF ? 'StaffMaster' : 'ClientMaster', $rules);
        // only a manager being set now is checked, so an untouched reporting line never blocks other changes
        if ($accountType === AuthMasterModel::TYPE_STAFF && isset($profile['manager_staff_id']) && ! isset($this->errors['profile.manager_staff_id'])) {
            $this->checkManager((int) $profile['manager_staff_id'], $profileId === null ? null : (int) $profileId);
        }

        return $profile;
    }

    /**
     * The line manager must be a staff account's staff_master row, and must not report to this staff member,
     * directly or indirectly. differs[id] has already ruled out the staff member themselves.
     * @param int      $managerId staff_master.id of the chosen line manager
     * @param int|null $staffId   This staff member's staff_master.id; null on create, when nobody can report to them yet
     * @return void
     */
    private function checkManager(int $managerId, ?int $staffId): void
    {
        $staff = model(StaffMasterModel::class);
        if (! $staff->isStaffMember($managerId)) {
            $this->errors['profile.manager_staff_id'] = lang('Accounts.managerNotFound');
        } elseif ($staffId !== null && $staff->wouldReportTo($managerId, $staffId)) {
            $this->errors['profile.manager_staff_id'] = lang('Accounts.managerLoop');
        }
    }

    /**
     * The whitelisted fields of $input as trimmed strings; an empty value becomes NULL where the column allows it,
     * is dropped where the field is optional (so the default or stored value stays), and stays '' for 'required'
     * @param string $prefix   account or profile, for the error keys
     * @param array  $input
     * @param array  $fields   Allowed fields
     * @param array  $nullable Fields whose column may be NULL
     * @param array  $rules    Validation rules, to tell optional fields from required ones
     * @return array
     */
    private function clean(string $prefix, array $input, array $fields, array $nullable, array $rules): array
    {
        $clean = [];
        foreach (array_intersect_key($input, array_flip($fields)) as $field => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } elseif ($value !== null && ! is_string($value)) {
                continue; // arrays and objects are not form values
            }
            $value = $value === null ? '' : trim($value);

            if ($value === '') {
                if (in_array($field, $nullable, true)) {
                    $clean[$field] = null;
                } elseif (str_contains($rules[$field] ?? '', 'required')) {
                    $clean[$field] = '';
                }

                continue;
            }
            if (in_array($field, self::COUNTRY_FIELDS, true)) {
                $value = strtoupper($value);
                helper('list');
                if (! isset(listCountries()[$value])) {
                    $this->errors[$prefix . '.' . $field] = lang('Accounts.invalidCountry');
                }
            }
            if (in_array($field, self::TELEPHONE_FIELDS, true)) {
                $e164 = $this->toE164($value);
                if ($e164 === null) {
                    $this->errors[$prefix . '.' . $field] = lang('Accounts.invalidTelephone');
                }
                $value = $e164 ?? $value;
            }
            $clean[$field] = $value;
        }

        return $clean;
    }

    /**
     * Validate $data against the model's rules for $fields, labelled from the model's language file;
     * an error already found for a field is kept
     * @param string     $prefix
     * @param array      $data     Includes 'id' for the {id} placeholders of is_unique and differs
     * @param array      $fields
     * @param object     $model    The model whose rules apply
     * @param string     $langFile Labels come from <langFile>.fields.*
     * @param array|null $rules    The model's rules, when the caller has added to them
     * @return void
     */
    private function validate(string $prefix, array $data, array $fields, object $model, string $langFile, ?array $rules = null): void
    {
        $rules ??= $model->getValidationRules();
        $set     = [];
        foreach (array_merge(['id'], $fields) as $field) {
            if (isset($rules[$field])) {
                $set[$field] = ['label' => lang($langFile . '.fields.' . $field), 'rules' => $rules[$field]];
            }
        }
        if (isset($set['manager_staff_id'])) {
            $set['manager_staff_id']['errors'] = ['differs' => lang('Accounts.managerIsSelf')];
        }
        if (isset($set['department_id'])) {
            $set['department_id']['errors'] = ['is_not_unique' => lang('Accounts.departmentNotFound')];
        }

        $validation = service('validation', null, false);
        if (! $validation->setRules($set)->run($data)) {
            foreach ($validation->getErrors() as $field => $message) {
                $this->errors[$prefix . '.' . $field] ??= $message;
            }
        }
    }

    /**
     * @param string $number In international format: a leading + (or 00) and the country code
     * @return string|null Null when it is not a valid number
     */
    private function toE164(string $number): ?string
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse(preg_replace('/^00/', '+', $number), null);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($parsed) ? $util->format($parsed, PhoneNumberFormat::E164) : null;
    }
}
