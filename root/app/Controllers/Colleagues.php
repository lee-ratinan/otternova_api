<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\AuthAccountModel;
use App\Models\AuthMasterModel;
use App\Models\BusinessClientLinkModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use stdClass;
use Throwable;

/**
 * The public profiles of the people of a business, so colleagues can know each other: the Client app's, behind the
 * jwt:C filter (clients only), for anyone active in the business whatever their role. Only the people active there
 * are listed and shown, and only the PUBLIC_* fields of their accounts; what an owner manages of an account is
 * App\Controllers\Employees. A business comes in by its business_slug, a person by their auth_master.uid.
 */
class Colleagues extends BaseController
{
    use ManagedBusinessTrait;

    /** auth_master columns a colleague may see */
    public const PUBLIC_ACCOUNT = ['uid', 'full_name', 'email_address', 'telephone_number', 'avatar_path'];

    /** client_master columns a colleague may see */
    public const PUBLIC_PROFILE = ['display_name', 'status_message', 'status_emoji', 'bio', 'gender', 'date_of_birth',
        'nationality_code', 'preferred_language'];

    /** business_client_link columns a colleague may see */
    public const PUBLIC_LINK = ['client_role', 'start_date'];

    /**
     * POST /client/colleagues { business_slug }
     * The people active in that business, by name: uid, full_name, display_name, avatar_path, status_emoji,
     * status_message, client_role, start_date
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/colleagues';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Employee.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Employee', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = model(AuthAccountModel::class)->colleaguesOf((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.loadFailed'), 500);
        }

        return ApiResponse::success(['colleagues' => $rows]);
    }

    /**
     * POST /client/colleague { business_slug, uid }
     * The public profile of someone active in that business: "account" (PUBLIC_ACCOUNT), "profile" (PUBLIC_PROFILE,
     * {} when there is none), "link" (PUBLIC_LINK) and "is_self"; 404 for anyone not active there
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/colleague';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Employee.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Employee', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return ApiResponse::error(lang('Employee.uidRequired'), 400);
        }
        try {
            $record = model(AuthAccountModel::class)->findForForm($uid);
            $link   = $record === null || $record['account']['account_type'] !== AuthMasterModel::TYPE_CLIENT || $record['profile'] === null
                ? null
                : model(BusinessClientLinkModel::class)->findLink((int) $business['id'], (int) $record['profile']['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Employee.loadFailed'), 500);
        }
        // someone not working at the business (never, or no longer) is not its people's to see
        if ($link === null || $link['client_status'] !== BusinessClientLinkModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Employee.notFound'), 404);
        }
        $profile = array_intersect_key($record['profile'], array_flip(self::PUBLIC_PROFILE));

        return ApiResponse::success([
            'account' => array_intersect_key($record['account'], array_flip(self::PUBLIC_ACCOUNT)),
            'profile' => $profile ?: new stdClass(),
            'link'    => array_intersect_key($link, array_flip(self::PUBLIC_LINK)),
            'is_self' => $record['account']['uid'] === (string) service('authContext')->claims()->sub,
        ]);
    }

    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

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
