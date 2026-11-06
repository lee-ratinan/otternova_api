<?php

namespace App\Controllers;

use App\Libraries\AccountForm;
use App\Libraries\ApiResponse;
use App\Libraries\EmailService;
use App\Models\AuthAccountModel;
use App\Models\AuthMasterModel;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessInvitationModel;
use App\Models\BusinessMasterModel;
use App\Models\ClientMasterModel;
use App\Models\SubscriptionMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Auth as AuthConfig;
use Config\PlanLimits;
use DateTimeImmutable;
use stdClass;
use Throwable;

/**
 * The people of a business (business_client_link, active and terminated, the owners included) and their accounts:
 * the Client app's, behind the jwt:C filter (clients only), and only for the active owners of the business
 * (business_client_link.client_role O). A business comes in by its business_slug, a person by their auth_master.uid.
 * The owners see and change only what AuthAccountModel::OWNER_* allows; see AccountForm::forBusinessOwnerEdit().
 */
class Employees extends BaseController
{
    use ManagedBusinessTrait;

    /** Only the owners: the accounts of the people of the business are theirs to see */
    private const OWNER_ONLY = [BusinessClientLinkModel::ROLE_OWNER];

    /** business_client_link.owner_notes is a TEXT column */
    private const OWNER_NOTES_MAX_BYTES = 65535;

    /**
     * POST /client/employees { business_slug }
     * Everyone ever linked to that business, by name: uid, email_address, telephone_number, full_name,
     * account_status, client_role, client_status, start_date, termination_date; with the business's plan_code,
     * max_employees (its cap, Config\PlanLimits::EMPLOYEES), active_employees (the active links), invitations (the open
     * ones: email_address, client_role, expires_at) and can_add (false once active links and open invitations, which
     * the cap counts together, reach max_employees)
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/employees';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Employee.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Employee', self::OWNER_ONLY);
        if ($error !== null) {
            return $error;
        }
        try {
            $rows        = model(AuthAccountModel::class)->employeesOf((int) $business['id']);
            $invitations = model(BusinessInvitationModel::class)->select('email_address, client_role, expires_at')
                ->where('business_id', (int) $business['id'])->where('expires_at >', time())->orderBy('email_address')->findAll();
            $cap         = $this->employeeCap((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.loadFailed'), 500);
        }
        $active = count(array_filter($rows, static fn (array $row) => $row['client_status'] === BusinessClientLinkModel::STATUS_ACTIVE));

        return ApiResponse::success([
            'employees'        => $rows,
            'invitations'      => $invitations,
            'plan_code'        => $cap['plan_code'],
            'max_employees'    => $cap['max_employees'],
            'active_employees' => $active,
            'can_add'          => $cap['can_add'],
        ]);
    }

    /**
     * POST /client/employee { business_slug, uid }
     * The account of someone linked to that business (active or terminated): "account" (OWNER_VIEW_ACCOUNT),
     * "profile" (OWNER_EDIT_PROFILE, {} when there is none), "link" (client_role, client_status, start_date,
     * termination_date, owner_notes), "can_leave" (their own link, and another active owner can take over),
     * "rehire" (for a terminated link: {plan_code, max_employees, can_add}, else null), "status_options" (the account_status values the owner may save: the current one and those
     * of OWNER_STATUS_CHANGES; only the current one for their own account) and "is_self"
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/employee';
        [$record, $link, $error] = $this->employee($endpoint);
        if ($error !== null) {
            return $error;
        }
        $account = $record['account'];
        $isSelf  = $account['uid'] === $this->callerUid();
        $profile = array_intersect_key($record['profile'] ?? [], array_flip(AuthAccountModel::OWNER_EDIT_PROFILE));
        try {
            $links  = model(BusinessClientLinkModel::class);
            // an owner may leave once another active owner can take over
            $canLeave = $isSelf && $links->activeCount((int) $link['business_id'], BusinessClientLinkModel::ROLE_OWNER) > 1;
            $rehire   = $link['client_status'] === BusinessClientLinkModel::STATUS_TERMINATED ? $this->employeeCap((int) $link['business_id']) : null;
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.loadFailed'), 500);
        }

        return ApiResponse::success([
            'account'        => array_intersect_key($account, array_flip(AuthAccountModel::OWNER_VIEW_ACCOUNT)),
            'profile'        => $profile ?: new stdClass(),
            'link'           => array_intersect_key($link, array_flip(['client_role', 'client_status', 'start_date', 'termination_date', 'owner_notes'])),
            'status_options' => [$account['account_status'], ...($isSelf ? [] : AuthAccountModel::OWNER_STATUS_CHANGES[$account['account_status']] ?? [])],
            'is_self'        => $isSelf,
            'can_leave'      => $canLeave,
            // for a terminated link: whether the plan's cap leaves room to rehire them, and the cap
            'rehire'         => $rehire,
        ]);
    }

    /**
     * POST /client/employee/edit { business_slug, uid, account: {...}, profile: {...}, link: {owner_notes} }
     * Updates the account of someone linked to that business: only the OWNER_EDIT_* fields are written, anything
     * else is ignored; a field left out keeps its value. See AccountForm::forBusinessOwnerEdit() for the checks.
     * link.owner_notes is the business's own notes about them (business_client_link); empty clears them.
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/edit';
        [$record, $link, $error, $input] = $this->employee($endpoint);
        if ($error !== null) {
            return $error;
        }
        $accountIn = is_array($input['account'] ?? null) ? $input['account'] : [];
        $profileIn = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $linkIn    = is_array($input['link'] ?? null) ? $input['link'] : [];
        [$notes, $notesError] = $this->ownerNotes($linkIn, $link);

        try {
            $result = (new AccountForm())->forBusinessOwnerEdit($record, $accountIn, $profileIn, $this->callerUid());
            $errors = $result['errors'] + ($notesError === null ? [] : ['link.owner_notes' => $notesError]);
            if ($errors !== []) {
                return ApiResponse::error(lang('Employee.invalidFields'), 422, $errors);
            }
            model(BusinessClientLinkModel::class)->saveOwnerNotes($link, $notes, $this->actorId(),
                fn () => model(AuthAccountModel::class)->updateWithProfile($record, $result['account'], $result['profile'], $this->actorId()));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $record['account']['uid']], 200, lang('Employee.updated'));
    }

    /**
     * POST /client/employee/terminate { business_slug, uid, termination_date }
     * Ends the employment of someone active in that business: their business_client_link becomes T with the
     * termination_date (Y-m-d, not before their start_date, and not after today anywhere on earth). Their account is
     * left as it is, so they keep working in their other businesses. Refused with 409 when they are terminated
     * already, or are the business's last active owner. An owner may end their own (leave the business) once
     * another active owner can take over.
     * @return ResponseInterface
     */
    public function terminate(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/terminate';
        [$record, $link, $error, $input] = $this->employee($endpoint);
        if ($error !== null) {
            return $error;
        }
        if ($link['client_status'] !== BusinessClientLinkModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Employee.alreadyTerminated'), 409);
        }
        $date      = is_string($input['termination_date'] ?? null) ? trim($input['termination_date']) : '';
        $parsed    = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateError = match (true) {
            $parsed === false || $parsed->format('Y-m-d') !== $date => lang('Employee.invalidTerminationDate'),
            $date < $link['start_date']                              => lang('Employee.terminationBeforeStart', [$link['start_date']]),
            // the latest time zone (UTC+14) is the first to reach a date
            $date > gmdate('Y-m-d', time() + 14 * 3600)              => lang('Employee.terminationInFuture'),
            default                                                  => null,
        };
        if ($dateError !== null) {
            return ApiResponse::error(lang('Employee.invalidFields'), 422, ['termination_date' => $dateError]);
        }

        try {
            if (! model(BusinessClientLinkModel::class)->terminate($link, $date, $this->actorId())) {
                $self = $record['account']['uid'] === $this->callerUid();

                return ApiResponse::error(lang($self ? 'Employee.lastOwnerCannotLeave' : 'Employee.lastOwner'), 409);
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $record['account']['uid']], 200, lang('Employee.terminated'));
    }

    /**
     * POST /client/employee/rehire { business_slug, uid }
     * Takes someone whose employment in that business ended back: their business_client_link becomes A again with no
     * termination_date; role, start_date and owner_notes stay as they were. Refused with 409 when they are active
     * already, or the business has as many active people as its plan allows (Config\PlanLimits::EMPLOYEES).
     * @return ResponseInterface
     */
    public function rehire(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/rehire';
        [$record, $link, $error] = $this->employee($endpoint);
        if ($error !== null) {
            return $error;
        }
        if ($link['client_status'] !== BusinessClientLinkModel::STATUS_TERMINATED) {
            return ApiResponse::error(lang('Employee.alreadyActive'), 409);
        }

        try {
            $cap = $this->employeeCap((int) $link['business_id']);
            if (! model(BusinessClientLinkModel::class)->rehire($link, $cap['max_employees'], $this->actorId())) {
                return ApiResponse::error(lang('Employee.limitReached', [$cap['max_employees'], ucfirst($cap['plan_code'])]), 409);
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $record['account']['uid']], 200, lang('Employee.rehired'));
    }

    /**
     * The plan's cap of active people of a business (Config\PlanLimits::EMPLOYEES, owners included), which its open
     * invitations count toward too: each holds the seat its invitee takes on signing up
     * @param int $businessId
     * @return array{plan_code: string, max_employees: int, can_add: bool}
     * @throws Throwable
     */
    private function employeeCap(int $businessId): array
    {
        $plan  = model(SubscriptionMasterModel::class)->planOfBusiness($businessId)['plan_code'];
        $max   = config(PlanLimits::class)->limitOf($plan, PlanLimits::EMPLOYEES);
        $taken = model(BusinessClientLinkModel::class)->activeCount($businessId) + model(BusinessInvitationModel::class)->openCount($businessId);

        return ['plan_code' => $plan, 'max_employees' => $max, 'can_add' => $taken < $max];
    }

    /**
     * POST /client/employee/find { business_slug, email_address }
     * Whether a client account has exactly that email address (case aside), for adding them to that business:
     * {found: false}, or {found: true, account: {uid, email_address, full_name, avatar_path}, client_status: null when
     * never linked to the business, else their link's A or T}. Staff accounts are never found.
     * @return ResponseInterface
     */
    public function find(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/find';
        [$business, $email, $error] = $this->businessAndEmail($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            [$account, $link] = $this->clientByEmail($email, (int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.loadFailed'), 500);
        }
        if ($account === null) {
            return ApiResponse::success(['found' => false]);
        }

        return ApiResponse::success([
            'found'         => true,
            'account'       => array_intersect_key($account, array_flip(['uid', 'email_address', 'full_name', 'avatar_path'])),
            'client_status' => $link['client_status'] ?? null,
        ]);
    }

    /**
     * POST /client/employee/add { business_slug, email_address, client_role, owner_notes }
     * Links the client account with that email address to that business: client_role O, M or E, starting today,
     * with the owner's notes (optional). Refused with 404 when no client account has it (invite them instead), 409 when
     * they are linked already (active, or terminated: rehire them instead), or the plan's cap is reached.
     * @return ResponseInterface
     */
    public function add(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/add';
        [$business, $email, $error, $input] = $this->businessAndEmail($endpoint);
        if ($error !== null) {
            return $error;
        }
        [$role, $notes, $errors] = $this->roleAndNotes($input);
        if ($errors !== []) {
            return ApiResponse::error(lang('Employee.invalidFields'), 422, $errors);
        }
        try {
            [$account, $link] = $this->clientByEmail($email, (int) $business['id']);
            if ($account === null) {
                return ApiResponse::error(lang('Employee.accountNotFound'), 404);
            }
            if ($link !== null) {
                return ApiResponse::error(lang($link['client_status'] === BusinessClientLinkModel::STATUS_ACTIVE ? 'Employee.alreadyActive' : 'Employee.useRehire'), 409);
            }
            $cap = $this->employeeCap((int) $business['id']);
            if (! $cap['can_add']) {
                return ApiResponse::error(lang('Employee.limitReachedAdd', [$cap['max_employees'], ucfirst($cap['plan_code'])]), 409);
            }
            model(BusinessClientLinkModel::class)->link((int) $business['id'], (int) $account['client_id'], $role, gmdate('Y-m-d'), $this->actorId(), $notes);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $account['uid']], 200, lang('Employee.added', [$account['full_name']]));
    }

    /**
     * POST /client/employee/invite { business_slug, email_address, client_role, owner_notes }
     * Emails someone with no account yet a link to sign up with that address; once they do, their new account is
     * linked to that business with this role and these notes (see Auth::createClientAccount()). Inviting the same
     * address again replaces the open invitation, and its old link stops working. An open invitation holds a seat of
     * the plan's cap until it expires (Config\Auth::$invitationTtl). Refused with 409 when the address has an account
     * already (add them instead), or the cap is reached.
     * @return ResponseInterface
     */
    public function invite(): ResponseInterface
    {
        $endpoint = 'v1/client/employee/invite';
        [$business, $email, $error, $input] = $this->businessAndEmail($endpoint);
        if ($error !== null) {
            return $error;
        }
        [$role, $notes, $errors] = $this->roleAndNotes($input);
        if ($errors !== []) {
            return ApiResponse::error(lang('Employee.invalidFields'), 422, $errors);
        }
        $ttl = config(AuthConfig::class)->invitationTtl;
        try {
            if ($this->accountByEmail($email) !== null) {
                return ApiResponse::error(lang('Employee.accountExists'), 409);
            }
            $cap   = $this->employeeCap((int) $business['id']);
            $token = model(BusinessInvitationModel::class)->invite((int) $business['id'], $email, $role, $notes, time() + $ttl, $cap['max_employees'], $this->actorId());
            if ($token === null) {
                return ApiResponse::error(lang('Employee.limitReachedAdd', [$cap['max_employees'], ucfirst($cap['plan_code'])]), 409);
            }
            $name = model(BusinessMasterModel::class)->select('business_name')->find((int) $business['id'])['business_name'];
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.saveFailed'), 500);
        }
        service('emailService')->send(EmailService::TYPE_BUSINESS_INVITATION, $email, [
            'inviter'       => (string) service('authContext')->account()['full_name'],
            'business_name' => $name,
            'role'          => lang('BusinessClientLink.enum.client_role.' . $role),
            'link'          => rtrim((string) env('LINK_CLIENT', ''), '/') . '/create-account?invitation=' . $token,
            'days'          => intdiv($ttl, DAY),
        ]);

        return ApiResponse::success(['email_address' => mb_strtolower($email)], 200, lang('Employee.invited', [$email]));
    }

    /**
     * The request's business, once the signed-in client is found to be an active owner of it, and its email_address
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: string, 2: ResponseInterface|null, 3: array}
     *         [business (id, country_code), email address, null, request body] or [null, '', error response, []]
     */
    private function businessAndEmail(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, '', ApiResponse::error(lang('Employee.invalidRequest'), 422), []];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Employee', self::OWNER_ONLY);
        if ($error !== null) {
            return [null, '', $error, []];
        }
        $email = is_string($input['email_address'] ?? null) ? trim($input['email_address']) : '';
        if (strlen($email) > 128 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, '', ApiResponse::error(lang('Employee.invalidFields'), 422, ['email_address' => lang('Employee.emailInvalid')]), []];
        }

        return [$business, $email, null, $input];
    }

    /**
     * The request's client_role (O, M or E) and owner_notes (optional), as add() and invite() take them
     * @param array $input
     * @return array{0: string, 1: string|null, 2: array<string, string>} [role, notes, field => error]
     */
    private function roleAndNotes(array $input): array
    {
        $role   = is_string($input['client_role'] ?? null) ? strtoupper(trim($input['client_role'])) : '';
        $errors = [];
        if (! in_array($role, self::ALL_ROLES, true)) {
            $errors['client_role'] = lang('Employee.roleInvalid');
        }
        [$notes, $notesError] = $this->ownerNotes(['owner_notes' => $input['owner_notes'] ?? null], ['owner_notes' => null]);
        if ($notesError !== null) {
            $errors['owner_notes'] = $notesError;
        }

        return [$role, $notes, $errors];
    }

    /**
     * The account with an email address, case aside, of any type
     * @param string $email
     * @return array|null auth_master id, uid, email_address, full_name, avatar_path, account_type
     */
    private function accountByEmail(string $email): ?array
    {
        $db = db_connect();

        return $db->table('auth_master')
            ->select('id, uid, email_address, full_name, avatar_path, account_type')
            ->where('LOWER(email_address) = ' . $db->escape(mb_strtolower($email)), null, false)
            ->get()
            ->getRowArray();
    }

    /**
     * The client account with an email address, and its link to a business
     * @param string $email
     * @param int    $businessId
     * @return array{0: array|null, 1: array|null} [accountByEmail() with client_id, or null when it is not a client's;
     *         its business_client_link row, or null]
     */
    private function clientByEmail(string $email, int $businessId): array
    {
        $account = $this->accountByEmail($email);
        $client  = $account === null || $account['account_type'] !== AuthMasterModel::TYPE_CLIENT
            ? null : model(ClientMasterModel::class)->findByAuthId((int) $account['id']);
        if ($client === null) {
            return [null, null];
        }
        $account['client_id'] = (int) $client['id'];

        return [$account, model(BusinessClientLinkModel::class)->findLink($businessId, $account['client_id'])];
    }

    /**
     * The request's person of the request's business, once the signed-in client is found to be an active owner of it
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null, 3: array|null}
     *         [AuthAccountModel::findForForm(), their business_client_link row, null, the request body] or [null, null, error response, null]
     */
    private function employee(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('Employee.invalidRequest'), 422), null];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Employee', self::OWNER_ONLY);
        if ($error !== null) {
            return [null, null, $error, null];
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return [null, null, ApiResponse::error(lang('Employee.uidRequired'), 400), null];
        }
        try {
            $record = model(AuthAccountModel::class)->findForForm($uid);
            $link   = $record === null || $record['account']['account_type'] !== AuthMasterModel::TYPE_CLIENT || $record['profile'] === null
                ? null
                : model(BusinessClientLinkModel::class)->findLink((int) $business['id'], (int) $record['profile']['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, null, ApiResponse::error(lang('Employee.loadFailed'), 500), null];
        }
        // someone never linked to the business is not its owners' to see
        if ($link === null) {
            return [null, null, ApiResponse::error(lang('Employee.notFound'), 404), null];
        }

        return [$record, $link, null, $input];
    }

    /**
     * link.owner_notes of the request, trimmed; left out, the stored notes stay
     * @param array $linkIn The request's "link"
     * @param array $stored The business_client_link row
     * @return array{0: string|null, 1: string|null} [the notes to save (null: none), null] or [null, error message]
     */
    private function ownerNotes(array $linkIn, array $stored): array
    {
        if (! array_key_exists('owner_notes', $linkIn)) {
            return [$stored['owner_notes'], null];
        }
        $notes = $linkIn['owner_notes'];
        if ($notes !== null && ! is_string($notes)) {
            return [null, lang('Employee.invalidNotes')];
        }
        $notes = trim((string) $notes);
        if (strlen($notes) > self::OWNER_NOTES_MAX_BYTES) {
            return [null, lang('Employee.notesTooLong')];
        }

        return [$notes === '' ? null : $notes, null];
    }

    /**
     * auth_master.uid of the signed-in account
     * @return string
     */
    private function callerUid(): string
    {
        return (string) service('authContext')->claims()->sub;
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
