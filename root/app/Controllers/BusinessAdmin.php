<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessMasterModel;
use App\Models\BusinessStoreHoursModel;
use App\Models\BusinessStoreModel;
use App\Models\OtternovaDepartmentModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Every business in the system, for the Admin app, behind the jwt:S filter: any staff member looks them up, by their
 * business_slug, with everything under it: its staff, its stores, and its products and services with their variants. The
 * ids stay inside. The tax ID is sent to the Executive Office and Finance only. Every time is UTC.
 */
class BusinessAdmin extends BaseController
{
    use BillingRequestTrait;

    /** The departments that see a business's tax ID */
    private const TAX_ID_DEPARTMENTS = ['EXE', 'FIN'];

    /** The values each filter of list() takes; any other value is refused */
    private const FILTERS = [
        'is_business_open'    => [BusinessMasterModel::OPEN, BusinessMasterModel::CLOSED],
        'wht_status'          => [BusinessMasterModel::WHT_NONE, BusinessMasterModel::WHT_REQUESTED, BusinessMasterModel::WHT_APPROVED],
        'subscription_status' => ['P', 'A', 'S', 'C', 'X', BusinessMasterModel::NO_SUBSCRIPTION],
        'review_status'       => ['U', 'A', 'F', 'P'],
        'moderation_status'   => ['N', 'B', 'R'],
        'awaiting_review'     => ['Y'], // the business, or one of its products or services, is pending re-review
    ];

    /**
     * POST /staff/businesses/list { start, length, search, filters: {is_business_open, wht_status, subscription_status,
     * review_status, moderation_status, awaiting_review, country_code} }
     * One page of businesses, newest first, with the status of their latest subscription, their review and moderation,
     * and how many of their products and services are pending re-review (artifacts_to_review); search matches the
     * business's name, slug and contact email. subscription_status NONE is a business that never subscribed;
     * awaiting_review Y is a business pending re-review or with a product or service that is.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $given            = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $filters          = [];
        $errors           = [];
        foreach ([...array_keys(self::FILTERS), 'country_code'] as $name) {
            $value = strtoupper($this->text($given, $name));
            if ($value === '') {
                continue;
            }
            $valid = $name === 'country_code' ? preg_match('/^[A-Z]{2}$/', $value) === 1 : in_array($value, self::FILTERS[$name], true);
            if ($valid) {
                $filters[$name] = $value;
            } else {
                $errors['filters.' . $name] = lang('SubscriptionAdmin.invalidFields');
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidFields'), 422, $errors);
        }
        try {
            $data = model(BusinessMasterModel::class)->listForStaff($start, $length, $this->text($input, 'search'), $filters);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/businesses/list', $e, 'SubscriptionAdmin.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/businesses/business { business_slug }
     * One business's business_master columns with its type, its names in other languages and its latest subscription;
     * tax_id is null unless the staff member is in the Executive Office or Finance
     * @return ResponseInterface
     */
    public function business(): ResponseInterface
    {
        $input = $this->jsonInput();
        $slug  = $input === null ? '' : $this->text($input, 'business_slug');
        if ($slug === '') {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        try {
            $department = model(OtternovaDepartmentModel::class)->codeOfStaff($this->actorId());
            $business   = model(BusinessMasterModel::class)->findForStaff($slug, in_array($department, self::TAX_ID_DEPARTMENTS, true));
        } catch (Throwable $e) {
            return $this->failed('v1/staff/businesses/business', $e, 'SubscriptionAdmin.loadFailed');
        }
        if ($business === null) {
            return ApiResponse::error(lang('SubscriptionAdmin.businessNotFound'), 404);
        }

        return ApiResponse::success($business);
    }

    /**
     * POST /staff/businesses/staff { business_slug }
     * Everyone ever linked to the business: name, sign-in email, account status, role, dates, and the stores they work
     * in now and the services they give; see BusinessClientLinkModel::staffForOtternova()
     * @return ResponseInterface
     */
    public function staff(): ResponseInterface
    {
        return $this->ofBusiness('v1/staff/businesses/staff', static fn (int $id): array => model(BusinessClientLinkModel::class)->staffForOtternova($id));
    }

    /**
     * POST /staff/businesses/stores { business_slug }
     * The business's stores by name, with their names in other languages and their weekly hours
     * @return ResponseInterface
     */
    public function stores(): ResponseInterface
    {
        return $this->ofBusiness('v1/staff/businesses/stores', static function (int $id): array {
            $hours = model(BusinessStoreHoursModel::class);

            return array_map(static function (array $store) use ($hours): array {
                $store['weekly_hours'] = $hours->weeklyOf($store['id']);
                unset($store['id']);

                return $store;
            }, model(BusinessStoreModel::class)->listOfBusiness($id, true));
        });
    }

    /**
     * POST /staff/businesses/artifacts { business_slug, start, length, search, filters: {artifact_type, artifact_status, review_status, moderation_status} }
     * One page of the business's products and services by name; search matches the name and the variants' SKUs
     * @return ResponseInterface
     */
    public function artifacts(): ResponseInterface
    {
        $input = $this->jsonInput() ?? [];
        $given = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $valid = [
            'artifact_type'   => [ArtifactMasterModel::TYPE_PRODUCT, ArtifactMasterModel::TYPE_SERVICE],
            'artifact_status' => [ArtifactMasterModel::STATUS_ACTIVE, ArtifactMasterModel::STATUS_INACTIVE, ArtifactMasterModel::STATUS_DISCONTINUED],
            'review_status'     => ['U', 'A', 'F', 'P'],
            'moderation_status' => ['N', 'B', 'R'],
        ];
        $filters = [];
        $errors  = [];
        foreach ($valid as $name => $values) {
            $value = strtoupper($this->text($given, $name));
            if ($value === '') {
                continue;
            }
            if (in_array($value, $values, true)) {
                $filters[$name] = $value;
            } else {
                $errors['filters.' . $name] = lang('SubscriptionAdmin.invalidFields');
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidFields'), 422, $errors);
        }
        [$start, $length] = $this->page($input);

        return $this->ofBusiness('v1/staff/businesses/artifacts', fn (int $id): array => model(ArtifactMasterModel::class)
            ->listForStaff($id, $start, $length, $this->text($input, 'search'), $filters));
    }

    /**
     * POST /staff/businesses/artifact { business_slug, public_id }
     * One product or service of the business with its names in other languages, its variants (their attribute values
     * and stock per store, by name) and its images; 404 when the business has no such artifact
     * @return ResponseInterface
     */
    public function artifact(): ResponseInterface
    {
        $publicId = $this->text($this->jsonInput() ?? [], 'public_id');
        if ($publicId === '') {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }

        return $this->ofBusiness('v1/staff/businesses/artifact', static fn (int $id): ?array => model(ArtifactMasterModel::class)->detailForStaff($id, $publicId),
            'SubscriptionAdmin.artifactNotFound');
    }

    /**
     * The answer of an endpoint about one business, by the business_slug of the body: 422 without it, 404 when there
     * is no such business (or $read finds nothing, with $notFound), and $read's data otherwise
     * @param string                    $endpoint for the error log
     * @param callable(int): ?array     $read     Given business_master.id
     * @param string                    $notFound The message when $read returns null
     * @return ResponseInterface
     */
    private function ofBusiness(string $endpoint, callable $read, string $notFound = 'SubscriptionAdmin.businessNotFound'): ResponseInterface
    {
        $input = $this->jsonInput();
        $slug  = $input === null ? '' : $this->text($input, 'business_slug');
        if ($slug === '') {
            return ApiResponse::error(lang('SubscriptionAdmin.invalidRequest'), 422);
        }
        try {
            $business = model(BusinessMasterModel::class)->select('id')->where('business_slug', $slug)->first();
            if ($business === null) {
                return ApiResponse::error(lang('SubscriptionAdmin.businessNotFound'), 404);
            }
            $data = $read((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'SubscriptionAdmin.loadFailed');
        }
        if ($data === null) {
            return ApiResponse::error(lang($notFound), 404);
        }

        return ApiResponse::success($data);
    }
}
