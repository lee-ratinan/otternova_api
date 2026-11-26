<?php

namespace App\Controllers;

use App\Libraries\AccountForm;
use App\Libraries\ApiResponse;
use App\Models\AuthAccountModel;
use App\Models\OtternovaDepartmentModel;
use App\Models\StaffMasterModel;
use App\Models\StaffSalaryModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Encryption\Exceptions\EncryptionException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use stdClass;

/**
 * auth_master accounts for the Admin app, behind the jwt:S filter (staff only). Managing accounts (list, any account's
 * full record, admin-edit, delete) is for the Executive Office only (MANAGE_DEPARTMENTS); every staff member reads and
 * self-edits their own record and reads the others' public profiles.
 * showOwn() and saveOwn() are the Client app's own profile, behind jwt:C
 */
class Accounts extends BaseController
{
    private const DEFAULT_LENGTH = 25;
    private const MAX_LENGTH     = 500;

    /** otternova_department.department_code values that manage accounts; the routes' department:EXE filter matches */
    public const MANAGE_DEPARTMENTS = ['EXE'];

    /** staff_master columns of a public profile: work-facing only, nothing personal, legal, identity or HR */
    public const PUBLIC_PROFILE_FIELDS = [
        'preferred_name', 'job_title', 'department_id', 'manager_staff_id', 'employment_type', 'employment_start_date',
        'work_country_code', 'work_email_address', 'work_telephone_number', 'status_message', 'bio',
    ];

    /**
     * POST /staff/accounts { start, length, search, order: [{field, dir}], filters: {column: value} }
     * One page of the server-side DataTables list. Every part of the body is optional, and anything
     * outside the whitelists in AuthAccountModel is ignored rather than rejected.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $start  = $start === false ? 0 : max(0, $start);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $length = $length === false ? self::DEFAULT_LENGTH : min(self::MAX_LENGTH, max(1, $length));
        // a number counts too: a telephone search may arrive as one
        $search = $input['search'] ?? null;
        $search = is_string($search) || is_int($search) ? trim((string) $search) : '';

        try {
            $data = model(AuthAccountModel::class)->listAccounts(
                $start,
                $length,
                $search,
                $this->listFilters($input['filters'] ?? null),
                $this->listOrder($input['order'] ?? null)
            );
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/staff/accounts: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.listFailed'), 500);
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/account { uid }
     * One account with its staff_master or client_master profile, for the Admin app's account form.
     * "profile" is {} when the account has no profile row yet. account.avatar_path is shown but never saved by
     * /staff/account/update; null means the default avatar.
     * The caller's own uid is open to every staff member; anyone else's to MANAGE_DEPARTMENTS only (403 otherwise,
     * before the lookup, so a 404 tells nothing about who exists). Others use /staff/public-profile.
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return ApiResponse::error(lang('Accounts.uidRequired'), 400);
        }
        if ($uid !== (string) service('authContext')->claims()->sub && ($denied = $this->denyUnlessManager()) !== null) {
            return $denied;
        }

        try {
            $record = model(AuthAccountModel::class)->findForForm($uid);
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/staff/account: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.loadFailed'), 500);
        }
        if ($record === null) {
            return ApiResponse::error(lang('Accounts.notFound'), 404);
        }

        // the internal ids never go out
        unset($record['account']['id'], $record['profile']['id']);

        return ApiResponse::success([
            'account' => $record['account'],
            'profile' => $record['profile'] ?: new stdClass(),
        ]);
    }

    /**
     * POST /staff/public-profile { uid }
     * One active staff member's public profile, for every staff member: account {uid, full_name, avatar_path} and
     * the PUBLIC_PROFILE_FIELDS of their staff_master row. Client accounts, staff who are not active and staff
     * without a staff_master row are a 404, as is an unknown uid.
     * @return ResponseInterface
     */
    public function publicProfile(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return ApiResponse::error(lang('Accounts.uidRequired'), 400);
        }

        try {
            $record = model(AuthAccountModel::class)->findForForm($uid);
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/staff/public-profile: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.loadFailed'), 500);
        }
        if ($record === null || $record['profile'] === null
            || $record['account']['account_type'] !== AuthAccountModel::TYPE_STAFF
            || $record['account']['account_status'] !== AuthAccountModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Accounts.notFound'), 404);
        }

        return ApiResponse::success([
            'account' => [
                'uid'         => $record['account']['uid'],
                'full_name'   => $record['account']['full_name'],
                'avatar_path' => $record['account']['avatar_path'],
            ],
            'profile' => array_intersect_key($record['profile'], array_flip(self::PUBLIC_PROFILE_FIELDS)),
        ]);
    }

    /**
     * POST /staff/account/update { mode, uid, account: {...}, profile: {...} }
     * mode "admin-edit": an empty uid creates a staff account (with its staff_master row); otherwise the account
     * with that uid is updated and its profile row inserted or updated.
     * mode "self-edit": the signed-in staff member updates their own account only, without the locked fields
     * (AuthAccountModel::SELF_EDIT_LOCKED_*). See App\Libraries\AccountForm for the checks.
     * mode "admin-edit" is for MANAGE_DEPARTMENTS only (403 otherwise); "self-edit" is open to every staff member.
     * @return ResponseInterface
     */
    public function save(): ResponseInterface
    {
        $input = $this->jsonInput();
        $uid   = $input['uid'] ?? null;
        if ($input === null || ($uid !== null && ! is_string($uid))) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }
        $uid       = trim((string) $uid);
        $mode      = $input['mode'] ?? null;
        if (! in_array($mode, [AuthAccountModel::MODE_ADMIN_EDIT, AuthAccountModel::MODE_SELF_EDIT], true)) {
            return ApiResponse::error(lang('Accounts.invalidMode'), 422);
        }
        $selfEdit  = $mode === AuthAccountModel::MODE_SELF_EDIT;
        $callerUid = (string) service('authContext')->claims()->sub;
        if ($selfEdit && $uid !== $callerUid) {
            return ApiResponse::error(lang('Accounts.selfEditOwnOnly'), 403);
        }
        if (! $selfEdit && ($denied = $this->denyUnlessManager()) !== null) {
            return $denied;
        }
        $accountIn = is_array($input['account'] ?? null) ? $input['account'] : [];
        $profileIn = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $accounts  = model(AuthAccountModel::class);
        $form      = new AccountForm();

        try {
            if ($uid === '') {
                $result = $form->forCreate($accountIn, $profileIn);
                if ($result['errors'] !== []) {
                    return ApiResponse::error(lang('Accounts.invalidFields'), 422, $result['errors']);
                }
                $accounts->createStaffWithProfile($result['account'], $result['profile'], $this->actorId());

                return ApiResponse::success(['uid' => $result['account']['uid']], 200, lang('Accounts.created'));
            }

            $record = $accounts->findForForm($uid);
            if ($record === null) {
                return ApiResponse::error(lang('Accounts.notFound'), 404);
            }
            $result = $form->forUpdate($record, $accountIn, $profileIn, $callerUid, $selfEdit);
            if ($result['errors'] !== []) {
                return ApiResponse::error(lang('Accounts.invalidFields'), 422, $result['errors']);
            }
            $accounts->updateWithProfile($record, $result['account'], $result['profile'], $this->actorId());

            return ApiResponse::success(['uid' => $uid], 200, lang('Accounts.updated'));
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/staff/account/update: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.saveFailed'), 500);
        }
    }

    /**
     * POST /staff/account/delete { uid }
     * Delete an account and its profile row. The account's access tokens stop working with it: the jwt filter
     * looks the account up on every request.
     * @return ResponseInterface
     */
    public function delete(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return ApiResponse::error(lang('Accounts.uidRequired'), 400);
        }

        try {
            $accounts = model(AuthAccountModel::class);
            $record   = $accounts->findForForm($uid);
            if ($record === null) {
                return ApiResponse::error(lang('Accounts.notFound'), 404);
            }
            if ($uid === (string) service('authContext')->claims()->sub) {
                return ApiResponse::error(lang('Accounts.cannotDeleteSelf'), 403);
            }
            // salary rows and payslips are payroll records, kept with the staff_master row they belong to
            if (model(StaffSalaryModel::class)->hasPayHistory((int) $record['account']['id'])) {
                return ApiResponse::error(lang('Accounts.hasPayHistory'), 409);
            }
            if (! $accounts->deleteWithProfile($record, $this->actorId())) {
                return ApiResponse::error(lang('Accounts.lastActiveStaff'), 409);
            }
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/staff/account/delete: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.deleteFailed'), 500);
        }

        return ApiResponse::success(null, 200, lang('Accounts.deleted'));
    }

    /**
     * POST /client/account {}
     * The signed-in client's own account and client_master profile, for the Client app's profile page.
     * The uid comes from the token; admin notes, ID documents and internal notes are left out (CLIENT_SELF_HIDDEN).
     * @return ResponseInterface
     */
    public function showOwn(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        try {
            $record = model(AuthAccountModel::class)->findForForm((string) service('authContext')->claims()->sub);
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/client/account: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.loadFailed'), 500);
        }
        if ($record === null) {
            return ApiResponse::error(lang('Accounts.notFound'), 404);
        }

        $hidden  = array_flip(array_merge(['id'], AuthAccountModel::CLIENT_SELF_HIDDEN));
        $profile = array_diff_key($record['profile'] ?? [], $hidden);

        return ApiResponse::success([
            'account' => array_diff_key($record['account'], $hidden),
            'profile' => $profile ?: new stdClass(),
        ]);
    }

    /**
     * POST /client/account/update { account: {...}, profile: {...} }
     * The signed-in client updates their own account (the uid comes from the token): only the CLIENT_SELF_EDIT_*
     * fields are written, see AccountForm::forClientSelfEdit(). The client_master row is inserted when missing.
     * @return ResponseInterface
     */
    public function saveOwn(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }
        $accountIn = is_array($input['account'] ?? null) ? $input['account'] : [];
        $profileIn = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $accounts  = model(AuthAccountModel::class);
        $uid       = (string) service('authContext')->claims()->sub;

        try {
            $record = $accounts->findForForm($uid);
            if ($record === null) {
                return ApiResponse::error(lang('Accounts.notFound'), 404);
            }
            $result = (new AccountForm())->forClientSelfEdit($record, $accountIn, $profileIn);
            if ($result['errors'] !== []) {
                return ApiResponse::error(lang('Accounts.invalidFields'), 422, $result['errors']);
            }
            $accounts->updateWithProfile($record, $result['account'], $result['profile'], $this->actorId());
        } catch (DatabaseException|EncryptionException $e) {
            log_message('error', 'v1/client/account/update: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $uid], 200, lang('Accounts.profileUpdated'));
    }

    /**
     * POST /staff/departments {}
     * Every department, by name, for the account form's Department dropdown
     * @return ResponseInterface
     */
    public function departments(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        try {
            $rows = model(OtternovaDepartmentModel::class)->listForLookup();
        } catch (DatabaseException $e) {
            log_message('error', 'v1/staff/departments: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.lookupFailed'), 500);
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/staff-members {}
     * Every staff member with a staff_master row, by name, for the account form's Line Manager dropdown
     * and the staff profiles gallery (with avatar_path: the picture, else the initials avatar). Inactive staff are included: the form labels them, and a manager who has left must still show by name.
     * @return ResponseInterface
     */
    public function staffMembers(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        try {
            $rows = model(StaffMasterModel::class)->listForLookup();
        } catch (DatabaseException $e) {
            log_message('error', 'v1/staff/staff-members: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.lookupFailed'), 500);
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * POST /staff/org-chart {}
     * The active staff and who each reports to, for the Admin app's org chart: the Executive Office and Human
     * Resources only (the department filter on the route)
     * @return ResponseInterface
     */
    public function orgChart(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        try {
            $rows = model(StaffMasterModel::class)->listForOrgChart();
        } catch (DatabaseException $e) {
            log_message('error', 'v1/staff/org-chart: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.lookupFailed'), 500);
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * Null when the signed-in staff member's department is one of MANAGE_DEPARTMENTS, else the error response:
     * the same answers as the department filter, for the routes shared with the own-profile paths
     * @return ResponseInterface|null
     */
    private function denyUnlessManager(): ?ResponseInterface
    {
        try {
            $code = model(OtternovaDepartmentModel::class)->codeOfStaff($this->actorId());
        } catch (DatabaseException $e) {
            log_message('error', 'accounts department check: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Auth.departmentCheckFailed'), 500);
        }

        return in_array($code, self::MANAGE_DEPARTMENTS, true) ? null : ApiResponse::error(lang('Auth.wrongDepartment'), 403);
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The valid entries of "filters": a known column with one of its known values
     * @param mixed $filters
     * @return array<string, string>
     */
    private function listFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $valid = [];
        foreach (AuthAccountModel::LIST_FILTERS as $column => $values) {
            if (in_array($filters[$column] ?? null, $values, true)) {
                $valid[$column] = $filters[$column];
            }
        }

        return $valid;
    }

    /**
     * The valid entries of "order", each column once; email_address ASC when none is left
     * @param mixed $order
     * @return list<array{string, string}>
     */
    private function listOrder(mixed $order): array
    {
        $valid = [];
        foreach (is_array($order) ? $order : [] as $item) {
            $field = is_array($item) ? ($item['field'] ?? null) : null;
            if (! in_array($field, AuthAccountModel::LIST_ORDER_FIELDS, true) || isset($valid[$field])) {
                continue;
            }
            $dir           = is_string($item['dir'] ?? null) ? strtolower($item['dir']) : '';
            $valid[$field] = [$field, $dir === 'desc' ? 'DESC' : 'ASC'];
        }

        return $valid === [] ? [['email_address', 'ASC']] : array_values($valid);
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
    }
}
