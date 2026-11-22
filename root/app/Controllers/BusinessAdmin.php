<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMasterModel;
use App\Models\OtternovaDepartmentModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Every business in the system, for the Admin app, behind the jwt:S filter: any staff member looks them up, by their
 * business_slug; the ids stay inside. The tax ID is sent to the Executive Office and Finance only. Every time is UTC.
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
    ];

    /**
     * POST /staff/businesses/list { start, length, search, filters: {is_business_open, wht_status, subscription_status, country_code} }
     * One page of businesses, newest first, with the status of their latest subscription; search matches the
     * business's name, slug and contact email. subscription_status NONE is a business that never subscribed.
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
}
