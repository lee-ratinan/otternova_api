<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessClientHoursModel;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessClientSalaryModel;
use App\Models\BusinessMasterModel;
use App\Models\BusinessServiceClientLinkModel;
use App\Models\BusinessStoreClientLinkModel;
use App\Models\BusinessStoreHoursModel;
use App\Models\BusinessStoreModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Where the people of a business work and what they are paid: the stores they work in
 * (business_store_client_link), the services they may perform and the commission on them
 * (business_service_client_link, business_client_link.commission_threshold), their salary history
 * (business_client_salary), and how the business pays commission (business_master.commission_*).
 * The Client app's, behind the jwt:C filter (clients only), and only for the active owners and managers of the
 * business. A business comes in by its business_slug, a person by their auth_master.uid, a store or a service by its
 * public_id; the ids stay inside. Unlike Employees, no contact details or account fields go out.
 */
class Staff extends BaseController
{
    use ManagedBusinessTrait;

    /** DECIMAL(19,2): up to 17 digits before the point, 2 after */
    private const AMOUNT_PATTERN = '/^\d{1,17}(\.\d{1,2})?$/';

    /**
     * POST /client/staff { business_slug }
     * Everyone ever linked to that business, by name: uid, full_name, client_role, client_status, start_date,
     * termination_date, commission_threshold (their own; null: the business's), store_count, service_count, and the
     * salary paid today (UTC), salary_amount and salary_period (null when none); with the business's commission
     * settings as "commission" and its currency_code
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/staff';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Staff.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Staff');
        if ($error !== null) {
            return $error;
        }
        try {
            $staff  = model(BusinessClientLinkModel::class)->staffOf((int) $business['id'], gmdate('Y-m-d'));
            $policy = $this->policyOf((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }

        return ApiResponse::success(['staff' => $staff] + $policy);
    }

    /**
     * POST /client/staff/show { business_slug, uid }
     * One person of that business: "person" (uid, full_name, client_role, client_status, start_date,
     * termination_date, commission_threshold), "stores" (every store of the business: public_id, store_name,
     * store_type, translations, assigned), "services" (every service of the business but the discontinued ones they
     * do not have: public_id, artifact_name, artifact_status, translations, assigned, commission_value,
     * commission_unit), "salaries" (the latest first: salary_amount, salary_period, effective_date, end_date); with
     * the business's "commission" settings and its currency_code
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/show';
        [$link, , $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $link['business_id'];
        $clientId   = (int) $link['client_id'];
        try {
            $assigned   = array_flip(model(BusinessStoreClientLinkModel::class)->storeIdsOf($businessId, $clientId));
            $stores     = model(BusinessStoreModel::class)->listOfBusiness($businessId, true);
            $commission = model(BusinessServiceClientLinkModel::class)->servicesOf($businessId, $clientId);
            $services   = model(ArtifactMasterModel::class)->servicesOf($businessId);
            $salaries   = model(BusinessClientSalaryModel::class)->historyOf($businessId, $clientId);
            $policy     = $this->policyOf($businessId);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }

        $storesOut = array_map(static fn (array $store): array => [
            'public_id'    => $store['public_id'],
            'store_name'   => $store['store_name'],
            'store_type'   => $store['store_type'],
            'translations' => $store['translations'],
            'assigned'     => isset($assigned[$store['id']]),
        ], $stores);
        $servicesOut = [];
        foreach ($services as $service) {
            $has = $commission[$service['id']] ?? null;
            if ($has === null && $service['artifact_status'] === ArtifactMasterModel::STATUS_DISCONTINUED) {
                continue;
            }
            $servicesOut[] = [
                'public_id'        => $service['public_id'],
                'artifact_name'    => $service['artifact_name'],
                'artifact_status'  => $service['artifact_status'],
                'translations'     => $service['translations'],
                'assigned'         => $has !== null,
                'commission_value' => $has['commission_value'] ?? '0.00',
                'commission_unit'  => $has['commission_unit'] ?? BusinessServiceClientLinkModel::UNIT_PERCENTAGE,
            ];
        }

        return ApiResponse::success([
            'person'   => $this->personOut($link),
            'stores'   => $storesOut,
            'services' => $servicesOut,
            'salaries' => $salaries,
        ] + $policy);
    }

    /**
     * POST /client/staff/stores/save { business_slug, uid, stores: [public_id, ...] }
     * Makes the person work in exactly those stores of the business: the ones left out are taken off, [] for none
     * @return ResponseInterface
     */
    public function saveStores(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/stores/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $wanted = $input['stores'] ?? null;
        if (! is_array($wanted) || ! array_is_list($wanted)) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, ['stores' => lang('Staff.invalidList')]);
        }
        try {
            $ids = [];
            foreach (model(BusinessStoreModel::class)->listOfBusiness((int) $link['business_id'], true) as $store) {
                $ids[$store['public_id']] = $store['id'];
            }
            $storeIds = [];
            foreach ($wanted as $publicId) {
                if (! is_string($publicId) || ! isset($ids[$publicId])) {
                    return ApiResponse::error(lang('Staff.invalidFields'), 422, ['stores' => lang('Staff.unknownStore')]);
                }
                $storeIds[] = $ids[$publicId];
            }
            model(BusinessStoreClientLinkModel::class)->replaceStores((int) $link['business_id'], (int) $link['client_id'], $storeIds, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $link['uid']], 200, lang('Staff.storesSaved'));
    }

    /**
     * POST /client/staff/services/save { business_slug, uid, services: [{public_id, commission_value, commission_unit}], commission_threshold }
     * Makes the person perform exactly those services of the business, at those commissions: commission_unit P (a
     * percentage, 0 to 100) or F (a fixed amount per service), commission_value 0 for none. A discontinued service can
     * be kept, but not given to someone new. commission_threshold is their own (an amount, 0 for none); null or ""
     * to use the business's; left out, it stays as it is.
     * @return ResponseInterface
     */
    public function saveServices(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/services/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $wanted = $input['services'] ?? null;
        if (! is_array($wanted) || ! array_is_list($wanted)) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, ['services' => lang('Staff.invalidList')]);
        }
        $threshold = $link['commission_threshold'] === null ? null : number_format((float) $link['commission_threshold'], 2, '.', '');
        $errors    = [];
        if (array_key_exists('commission_threshold', $input)) {
            $threshold = null;
            if ($input['commission_threshold'] !== null && $input['commission_threshold'] !== '') {
                $threshold = self::amount($input['commission_threshold']);
                if ($threshold === null) {
                    $errors['commission_threshold'] = lang('Staff.invalidAmount');
                }
            }
        }

        $businessId = (int) $link['business_id'];
        try {
            $current  = model(BusinessServiceClientLinkModel::class)->servicesOf($businessId, (int) $link['client_id']);
            $byPublic = [];
            foreach (model(ArtifactMasterModel::class)->servicesOf($businessId) as $service) {
                $byPublic[$service['public_id']] = $service;
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }

        $services = [];
        foreach ($wanted as $i => $item) {
            $publicId = is_array($item) && is_string($item['public_id'] ?? null) ? $item['public_id'] : '';
            $key      = 'services.' . ($publicId === '' ? $i : $publicId);
            $service  = $byPublic[$publicId] ?? null;
            if ($service === null) {
                $errors[$key . '.public_id'] = lang('Staff.unknownService');
                continue;
            }
            if ($service['artifact_status'] === ArtifactMasterModel::STATUS_DISCONTINUED && ! isset($current[$service['id']])) {
                $errors[$key . '.public_id'] = lang('Staff.discontinuedService');
                continue;
            }
            $unit = $item['commission_unit'] ?? BusinessServiceClientLinkModel::UNIT_PERCENTAGE;
            if (! in_array($unit, [BusinessServiceClientLinkModel::UNIT_PERCENTAGE, BusinessServiceClientLinkModel::UNIT_FIXED], true)) {
                $errors[$key . '.commission_unit'] = lang('Staff.invalidUnit');
                continue;
            }
            $value = self::amount($item['commission_value'] ?? 0);
            if ($value === null || ($unit === BusinessServiceClientLinkModel::UNIT_PERCENTAGE && (float) $value > 100)) {
                $errors[$key . '.commission_value'] = lang($unit === BusinessServiceClientLinkModel::UNIT_PERCENTAGE ? 'Staff.invalidPercentage' : 'Staff.invalidAmount');
                continue;
            }
            $services[$service['id']] = ['commission_value' => $value, 'commission_unit' => $unit];
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors);
        }

        try {
            model(BusinessServiceClientLinkModel::class)->replaceServices($link, $services, $threshold, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $link['uid']], 200, lang('Staff.servicesSaved'));
    }

    /**
     * POST /client/staff/salary/add { business_slug, uid, salary: {salary_amount, salary_period, effective_date, end_date} }
     * Adds a salary after the person's history: salary_period W, S or M; effective_date (Y-m-d) not before they
     * started, and after the latest salary ends; end_date null for an open one. When the latest has no end_date yet,
     * it is ended the day before the new one starts.
     * @return ResponseInterface
     */
    public function addSalary(): ResponseInterface
    {
        return $this->saveSalary(false);
    }

    /**
     * POST /client/staff/salary/edit { business_slug, uid, salary: {...} }
     * Corrects the person's latest salary, the same fields as salary/add; it must still start after the one before
     * ends. Refused with 409 when they have none.
     * @return ResponseInterface
     */
    public function editSalary(): ResponseInterface
    {
        return $this->saveSalary(true);
    }

    /**
     * POST /client/commission-policy { business_slug }
     * How that business pays commission: "commission" {commission_status (A, I), commission_period (W, S, M),
     * commission_threshold} and its currency_code
     * @return ResponseInterface
     */
    public function policy(): ResponseInterface
    {
        $endpoint = 'v1/client/commission-policy';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Staff.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Staff');
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success($this->policyOf((int) $business['id']));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }
    }

    /**
     * POST /client/commission-policy/save { business_slug, commission: {commission_status, commission_period, commission_threshold} }
     * Saves how that business pays commission; a field left out keeps its value. commission_threshold is an amount
     * in its currency, 0 for none: someone whose commissionable sales in a period are below it earns none for it.
     * @return ResponseInterface
     */
    public function savePolicy(): ResponseInterface
    {
        $endpoint = 'v1/client/commission-policy/save';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Staff.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Staff');
        if ($error !== null) {
            return $error;
        }
        $in = $input['commission'] ?? [];
        if (! is_array($in)) {
            return ApiResponse::error(lang('Staff.invalidRequest'), 422);
        }
        try {
            $stored = model(BusinessMasterModel::class)->select('id, ' . implode(', ', BusinessMasterModel::COMMISSION_FIELDS))->find((int) $business['id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }
        $stored['commission_threshold'] = number_format((float) $stored['commission_threshold'], 2, '.', '');
        $policy = array_intersect_key($stored, array_flip(BusinessMasterModel::COMMISSION_FIELDS));
        $errors = [];
        if (array_key_exists('commission_status', $in)) {
            $policy['commission_status'] = $in['commission_status'];
            if (! in_array($in['commission_status'], ['A', 'I'], true)) {
                $errors['commission_status'] = lang('Staff.invalidStatus');
            }
        }
        if (array_key_exists('commission_period', $in)) {
            $policy['commission_period'] = $in['commission_period'];
            if (! in_array($in['commission_period'], BusinessClientSalaryModel::PERIODS, true)) {
                $errors['commission_period'] = lang('Staff.invalidPeriod');
            }
        }
        if (array_key_exists('commission_threshold', $in)) {
            $policy['commission_threshold'] = self::amount($in['commission_threshold'] ?? null);
            if ($policy['commission_threshold'] === null) {
                $errors['commission_threshold'] = lang('Staff.invalidAmount');
            }
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors);
        }
        try {
            model(BusinessMasterModel::class)->saveCommissionPolicy($stored, $policy, $this->actorId());
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['commission' => $policy], 200, lang('Staff.policySaved'));
    }

    /**
     * POST /client/staff/hours { business_slug, uid }
     * When and where the person works: "stores" (the ones they work in now: public_id, store_name, store_timezone,
     * translations), "weekly" (their regular shifts: store_public_id, day_of_week 1 Monday to 7 Sunday, start_time,
     * end_time), "exceptions" (from today on: exception_date, exception_type V vacation, S sick, O other,
     * exception_reason, off, shifts [{store_public_id, start_time, end_time}]), "today" (the earliest date anywhere)
     * and max_shifts. Times are HH:MM in each store's timezone; an end not after the start is on the next day.
     * @return ResponseInterface
     */
    public function hours(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/hours';
        [$link, , $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $link['business_id'];
        $clientId   = (int) $link['client_id'];
        $hours      = model(BusinessClientHoursModel::class);
        try {
            [$stores, $publicIds] = $this->storesOf($link);
            $today      = self::earliestToday();
            $storeOut   = static fn (array $row): array => ['store_public_id' => $publicIds[$row['store_id']] ?? null] + array_diff_key($row, ['store_id' => 1]);
            $weekly     = array_map($storeOut, $hours->weeklyOf($businessId, $clientId));
            $exceptions = array_map(static fn (array $e): array => array_merge($e, ['shifts' => array_map($storeOut, $e['shifts'])]), $hours->exceptionsFrom($businessId, $clientId, $today));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.loadFailed'), 500);
        }

        return ApiResponse::success([
            'person'     => $this->personOut($link),
            'stores'     => array_values(array_map(static fn (array $s): array => array_diff_key($s, ['id' => 1]), $stores)),
            'weekly'     => $weekly,
            'exceptions' => $exceptions,
            'today'      => $today,
            'max_shifts' => BusinessClientHoursModel::MAX_SHIFTS_PER_DAY,
        ]);
    }

    /**
     * POST /client/staff/hours/save { business_slug, uid, weekly: [{store_public_id, day_of_week, start_time, end_time}, ...] }
     * Replaces the person's regular shifts, each at a store they work in now; a day left out is a day off. Shifts must
     * not overlap, at any store, a late one running into the next day's first included (Sunday's into Monday's).
     * Removed shifts are kept, inactive.
     * @return ResponseInterface
     */
    public function saveHours(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/hours/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $weekly = $input['weekly'] ?? null;
        if (! is_array($weekly) || ! array_is_list($weekly)) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, ['weekly' => lang('Staff.invalidList')]);
        }
        $hours = model(BusinessClientHoursModel::class);
        try {
            $storeIds = array_flip($this->storesOf($link)[1]);
            $rows     = [];
            $errors   = [];
            foreach ($weekly as $i => $item) {
                $item = is_array($item) ? $item : [];
                $day  = $item['day_of_week'] ?? null;
                $day  = is_int($day) || (is_string($day) && ctype_digit($day)) ? (int) $day : 0;
                if ($day < 1 || $day > 7) {
                    $errors['weekly.' . $i . '.day_of_week'] = lang('Staff.invalidDay');
                    continue;
                }
                [$row, $rowErrors] = $this->shiftIn($item, 'weekly.' . $i, $storeIds);
                $errors += $rowErrors;
                if ($row !== null) {
                    $rows[] = ['store_id' => $row['store_id'], 'day_of_week' => $day, 'start_time' => $row['start_time'], 'end_time' => $row['end_time']];
                }
            }
            $problem = $errors === [] ? $hours->weeklyError((int) $link['business_id'], $rows) : null;
            if ($problem !== null) {
                $errors['weekly'] = lang($problem, [BusinessClientHoursModel::MAX_SHIFTS_PER_DAY]);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors);
            }
            $hours->replaceWeekly((int) $link['business_id'], (int) $link['client_id'], $rows, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $link['uid']], 200, lang('Staff.hoursSaved'));
    }

    /**
     * POST /client/staff/hours-exception/save { business_slug, uid, exception_date, exception_type, exception_reason, shifts: [{store_public_id, start_time, end_time}, ...] }
     * Sets the person's shifts on a date (today or later) in place of their regular ones: exception_type V (vacation
     * or leave), S (sick) or O (other: a swapped shift, an extra day), for whoever looks to see why; exception_reason
     * an optional note; shifts [] for a day off. Whatever the date had is replaced. The shifts must not overlap each
     * other, the late shift of the day before, or the first shift of the day after.
     * @return ResponseInterface
     */
    public function saveHoursException(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/hours-exception/save';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $errors = [];
        $date   = self::date($input['exception_date'] ?? null);
        if ($date === null) {
            $errors['exception_date'] = lang('Staff.invalidDate');
        } elseif ($date < self::earliestToday()) {
            $errors['exception_date'] = lang('Staff.pastDate');
        }
        $type = $input['exception_type'] ?? null;
        if (! in_array($type, BusinessClientHoursModel::TYPES, true)) {
            $errors['exception_type'] = lang('Staff.invalidExceptionType');
        }
        $reason = is_string($input['exception_reason'] ?? null) ? trim($input['exception_reason']) : '';
        if (mb_strlen($reason) > 255) {
            $errors['exception_reason'] = lang('Staff.reasonTooLong', [255]);
        }
        $list = $input['shifts'] ?? [];
        if (! is_array($list) || ! array_is_list($list)) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors + ['shifts' => lang('Staff.invalidList')]);
        }
        $hours = model(BusinessClientHoursModel::class);
        try {
            $storeIds = array_flip($this->storesOf($link)[1]);
            $rows     = [];
            foreach ($list as $i => $item) {
                [$row, $rowErrors] = $this->shiftIn(is_array($item) ? $item : [], 'shifts.' . $i, $storeIds);
                $errors += $rowErrors;
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $problem = $errors === [] ? $hours->exceptionError((int) $link['business_id'], (int) $link['client_id'], $date, $rows) : null;
            if ($problem !== null) {
                $errors['shifts'] = lang($problem, [BusinessClientHoursModel::MAX_SHIFTS_PER_DAY]);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors);
            }
            $hours->saveException((int) $link['business_id'], (int) $link['client_id'], $date, $type, $reason === '' ? null : $reason, $rows, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $link['uid'], 'exception_date' => $date], 200, lang('Staff.hoursExceptionSaved'));
    }

    /**
     * POST /client/staff/hours-exception/delete { business_slug, uid, exception_date }
     * Gives the date back the person's regular shifts; what it had is kept, inactive. 404 when it has no exception.
     * @return ResponseInterface
     */
    public function deleteHoursException(): ResponseInterface
    {
        $endpoint = 'v1/client/staff/hours-exception/delete';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $date = self::date($input['exception_date'] ?? null);
        if ($date === null) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, ['exception_date' => lang('Staff.invalidDate')]);
        }
        try {
            $deleted = model(BusinessClientHoursModel::class)->deleteException((int) $link['business_id'], (int) $link['client_id'], $date, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }
        if (! $deleted) {
            return ApiResponse::error(lang('Staff.exceptionNotFound'), 404);
        }

        return ApiResponse::success(['uid' => $link['uid'], 'exception_date' => $date], 200, lang('Staff.hoursExceptionDeleted'));
    }

    /**
     * The stores the person works in now
     * @param array $link findStaff()
     * @return array{0: list<array>, 1: array<int, string>} [listOfBusiness() rows with ids and store_timezone, store id => public_id]
     */
    private function storesOf(array $link): array
    {
        $active = array_flip(model(BusinessStoreClientLinkModel::class)->storeIdsOf((int) $link['business_id'], (int) $link['client_id']));
        $stores = array_values(array_filter(model(BusinessStoreModel::class)->listOfBusiness((int) $link['business_id'], true),
            static fn (array $s): bool => isset($active[$s['id']])));
        $stores = array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['id', 'public_id', 'store_name', 'store_type', 'store_timezone', 'translations'])), $stores);

        return [$stores, array_column($stores, 'public_id', 'id')];
    }

    /**
     * A shift of the request, at one of the stores the person works in now, its times normalized
     * @param array              $item
     * @param string             $key      Its place in the request, for the errors
     * @param array<string, int> $storeIds public_id => id of those stores
     * @return array{0: array{store_id: int, start_time: string, end_time: string}|null, 1: array<string, string>}
     */
    private function shiftIn(array $item, string $key, array $storeIds): array
    {
        $publicId = $item['store_public_id'] ?? null;
        $storeId  = is_string($publicId) ? ($storeIds[$publicId] ?? null) : null;
        $start    = BusinessStoreHoursModel::normalizeTime($item['start_time'] ?? null);
        $end      = BusinessStoreHoursModel::normalizeTime($item['end_time'] ?? null);
        $errors   = array_filter([
            $key . '.store_public_id' => $storeId === null ? lang('Staff.notTheirStore') : null,
            $key . '.start_time'      => $start === null ? lang('Staff.invalidTime') : null,
            $key . '.end_time'        => $end === null ? lang('Staff.invalidTime') : null,
        ]);

        return [$errors === [] ? ['store_id' => $storeId, 'start_time' => $start, 'end_time' => $end] : null, $errors];
    }

    /**
     * Today's date where it is earliest (UTC-12): the first date that is not yet over anywhere
     * @return string Y-m-d
     */
    private static function earliestToday(): string
    {
        return gmdate('Y-m-d', time() - 12 * 3600);
    }

    /**
     * addSalary() and editSalary()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function saveSalary(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/staff/salary/edit' : 'v1/client/staff/salary/add';
        [$link, $input, $error] = $this->person($endpoint);
        if ($error !== null) {
            return $error;
        }
        $in = $input['salary'] ?? null;
        if (! is_array($in)) {
            return ApiResponse::error(lang('Staff.invalidRequest'), 422);
        }
        $salary = [
            'salary_amount'  => self::amount($in['salary_amount'] ?? null),
            'salary_period'  => $in['salary_period'] ?? null,
            'effective_date' => self::date($in['effective_date'] ?? null),
            'end_date'       => ($in['end_date'] ?? null) === null || $in['end_date'] === '' ? null : self::date($in['end_date']),
        ];
        $errors = array_filter([
            'salary_amount'  => $salary['salary_amount'] === null ? lang('Staff.invalidAmount') : null,
            'salary_period'  => in_array($salary['salary_period'], BusinessClientSalaryModel::PERIODS, true) ? null : lang('Staff.invalidPeriod'),
            'effective_date' => $salary['effective_date'] === null ? lang('Staff.invalidDate') : null,
            'end_date'       => match (true) {
                $salary['end_date'] === null && ($in['end_date'] ?? null) !== null && $in['end_date'] !== '' => lang('Staff.invalidDate'),
                $salary['end_date'] !== null && $salary['effective_date'] !== null && $salary['end_date'] < $salary['effective_date'] => lang('Staff.endBeforeStart'),
                default => null,
            },
        ]);
        if ($errors !== []) {
            return ApiResponse::error(lang('Staff.invalidFields'), 422, $errors);
        }

        $salaries = model(BusinessClientSalaryModel::class);
        try {
            $rows = $salaries->rowsOf((int) $link['business_id'], (int) $link['client_id']);
            if ($edit && $rows === []) {
                return ApiResponse::error(lang('Staff.noSalary'), 409);
            }
            $problem = $edit
                ? BusinessClientSalaryModel::editError($salary, $rows[1] ?? null, $link['start_date'])
                : BusinessClientSalaryModel::addError($salary, $rows[0] ?? null, $link['start_date']);
            if ($problem !== null) {
                return ApiResponse::error(lang('Staff.invalidFields'), 422, ['effective_date' => lang($problem)]);
            }
            $edit
                ? $salaries->editLatest($rows[0], $salary, $this->actorId(), $link['uid'])
                : $salaries->add((int) $link['business_id'], (int) $link['client_id'], $salary, $this->actorId(), $link['uid']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Staff.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $link['uid']], 200, lang($edit ? 'Staff.salaryUpdated' : 'Staff.salaryAdded'));
    }

    /**
     * The request's person of the request's business, once the signed-in client is found to be an active owner or
     * manager of it
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null}
     *         [their business_client_link row with uid and full_name, the request body, null] or [null, null, error response]
     */
    private function person(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('Staff.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Staff');
        if ($error !== null) {
            return [null, null, $error];
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return [null, null, ApiResponse::error(lang('Staff.uidRequired'), 400)];
        }
        try {
            $link = model(BusinessClientLinkModel::class)->findStaff((int) $business['id'], $uid);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, null, ApiResponse::error(lang('Staff.loadFailed'), 500)];
        }

        return $link === null ? [null, null, ApiResponse::error(lang('Staff.notFound'), 404)] : [$link, $input, null];
    }

    /**
     * @param array $link findStaff()
     * @return array What goes out of a person
     */
    private function personOut(array $link): array
    {
        $out = array_intersect_key($link, array_flip(['uid', 'full_name', 'client_role', 'client_status', 'start_date', 'termination_date', 'commission_threshold']));
        $out['commission_threshold'] = $link['commission_threshold'] === null ? null : number_format((float) $link['commission_threshold'], 2, '.', '');

        return $out;
    }

    /**
     * @param int $businessId
     * @return array{commission: array, currency_code: string}
     */
    private function policyOf(int $businessId): array
    {
        $row = model(BusinessMasterModel::class)->select('currency_code, ' . implode(', ', BusinessMasterModel::COMMISSION_FIELDS))->find($businessId);

        return [
            'commission'    => [
                'commission_status'    => $row['commission_status'],
                'commission_period'    => $row['commission_period'],
                'commission_threshold' => number_format((float) $row['commission_threshold'], 2, '.', ''),
            ],
            'currency_code' => $row['currency_code'],
        ];
    }

    /**
     * An amount as DECIMAL(19,2) takes it
     * @param mixed $value A JSON number or a string
     * @return string|null With two decimals, or null when it is not an amount of at most two decimals, not below zero
     */
    private static function amount(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
        }
        if (! is_string($value) || preg_match(self::AMOUNT_PATTERN, trim($value)) !== 1) {
            return null;
        }
        [$whole, $fraction] = explode('.', trim($value) . '.');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * @param mixed $value
     * @return string|null The Y-m-d date, or null when it is not one
     */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $parsed !== false && $parsed->format('Y-m-d') === trim($value) ? trim($value) : null;
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
