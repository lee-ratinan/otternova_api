<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\ClientMasterModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The access check of the Client app's endpoints that only the active owners and managers of a business may use
 * (Stores, ArtifactImages). Messages come from the controller's language file, $langFile, which has the keys
 * loadFailed and managersOnly, and slugRequired and businessNotFound for managedBusinessBySlug().
 * The class defines actorId().
 */
trait ManagedBusinessTrait
{
    /** business_client_link.client_role values that may manage the business */
    private const MANAGING_ROLES = [BusinessClientLinkModel::ROLE_OWNER, BusinessClientLinkModel::ROLE_MANAGER];

    /**
     * The business of the request's business_slug, when the signed-in client is one of its active owners or managers
     * @param array  $input
     * @param string $endpoint for the error log
     * @param string $langFile
     * @return array{0: array|null, 1: ResponseInterface|null} [business (id, country_code), null] or [null, error response]
     */
    private function managedBusinessBySlug(array $input, string $endpoint, string $langFile): array
    {
        $slug = is_string($input['business_slug'] ?? null) ? trim($input['business_slug']) : '';
        if ($slug === '') {
            return [null, ApiResponse::error(lang($langFile . '.slugRequired'), 400)];
        }
        try {
            $business = model(BusinessMasterModel::class)->select('id, country_code')->where('business_slug', $slug)->first();
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, ApiResponse::error(lang($langFile . '.loadFailed'), 500)];
        }
        $error = $business === null
            ? ApiResponse::error(lang($langFile . '.businessNotFound'), 404)
            : $this->roleError((int) $business['id'], $endpoint, lang($langFile . '.businessNotFound'), $langFile);

        return $error === null ? [$business, null] : [null, $error];
    }

    /**
     * Null when the signed-in client is an active owner or manager of the business, else the error response:
     * 404 with $notFound when they are not active in it (it is not theirs to see either), 403 for an employee
     * @param int    $businessId
     * @param string $endpoint for the error log
     * @param string $notFound
     * @param string $langFile
     * @return ResponseInterface|null
     */
    private function roleError(int $businessId, string $endpoint, string $notFound, string $langFile): ?ResponseInterface
    {
        try {
            $client = model(ClientMasterModel::class)->findByAuthId($this->actorId());
            $role   = $client === null ? null : model(BusinessClientLinkModel::class)->activeRole($businessId, (int) $client['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang($langFile . '.loadFailed'), 500);
        }
        if ($role === null) {
            return ApiResponse::error($notFound, 404);
        }

        return in_array($role, self::MANAGING_ROLES, true) ? null : ApiResponse::error(lang($langFile . '.managersOnly'), 403);
    }
}
