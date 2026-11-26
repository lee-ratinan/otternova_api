<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\OtternovaLedgerEntityModel;
use App\Models\StaffSalaryModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

/**
 * The salary history and bank account of an OtterNova staff member, for the Admin app's account page:
 * the Executive Office only (the department filter on the routes). A staff member comes in by auth_master.uid,
 * a salary row and an entity by their public_id.
 */
class StaffSalaries extends BaseController
{
    private const AMOUNT_PATTERN = '/^\d{1,17}(\.\d{1,2})?$/';
    private const HOURS_PATTERN  = '/^\d{1,3}(\.\d{1,2})?$/';

    /** The longest each staff_pay_account column may be */
    private const PAY_ACCOUNT_LENGTHS = ['bank_name' => 128, 'bank_branch' => 128, 'bank_account_name' => 255, 'bank_account_number' => 64];

    /**
     * POST /staff/salary { uid }
     * { staff: {uid, full_name, employment_start_date, employment_end_date, work_country_code}, salaries (the latest
     * first; StaffSalaryModel::historyOf()), pay_account (null when none), entities: [{public_id, entity_name,
     * country_code, base_currency_code}] }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        [$staff, , $error] = $this->staff('v1/staff/salary');
        if ($error !== null) {
            return $error;
        }
        try {
            $salaries = model(StaffSalaryModel::class);
            $history  = $salaries->historyOf((int) $staff['id']);
            $account  = $salaries->payAccountOf((int) $staff['id']);
            $entities = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['public_id', 'entity_name', 'country_code', 'base_currency_code'])),
                model(OtternovaLedgerEntityModel::class)->listForLookup());
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/salary: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StaffSalary.loadFailed'), 500);
        }

        return ApiResponse::success([
            'staff'       => array_intersect_key($staff, array_flip(['uid', 'full_name', 'employment_start_date', 'employment_end_date', 'work_country_code'])),
            'salaries'    => $history,
            'pay_account' => $account,
            'entities'    => $entities,
        ]);
    }

    /**
     * POST /staff/salary/add { uid, salary: {entity (public_id or null), effective_date, end_date, currency_code,
     * base_amount, rate_unit, pay_frequency, standard_hours_week, social_security, change_reason, note} }
     * Adds a salary after the history; a current one is ended the day before it starts
     * @return ResponseInterface
     */
    public function add(): ResponseInterface
    {
        return $this->save(false);
    }

    /**
     * POST /staff/salary/edit { uid, public_id, salary: {...} }
     * Corrects the latest salary, the same fields as salary/add, while no payslip uses it
     * @return ResponseInterface
     */
    public function edit(): ResponseInterface
    {
        return $this->save(true);
    }

    /**
     * POST /staff/salary/pay-account { uid, pay_account: {bank_name, bank_branch, bank_account_name, bank_account_number} }
     * Where the staff member's pay goes; an empty value clears that field
     * @return ResponseInterface
     */
    public function savePayAccount(): ResponseInterface
    {
        [$staff, $input, $error] = $this->staff('v1/staff/salary/pay-account');
        if ($error !== null) {
            return $error;
        }
        $in = $input['pay_account'] ?? null;
        if (! is_array($in)) {
            return ApiResponse::error(lang('StaffSalary.invalidRequest'), 422);
        }
        $account = [];
        $errors  = [];
        foreach (self::PAY_ACCOUNT_LENGTHS as $field => $max) {
            $value = $in[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                $errors[$field] = lang('StaffSalary.invalidText', [$max]);
                continue;
            }
            $value = $value === null ? '' : trim($value);
            if (mb_strlen($value) > $max) {
                $errors[$field] = lang('StaffSalary.invalidText', [$max]);
            }
            $account[$field] = $value === '' ? null : $value;
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('StaffSalary.invalidFields'), 422, $errors);
        }
        try {
            model(StaffSalaryModel::class)->savePayAccount($staff, $account, $this->actorId());
        } catch (Throwable $e) {
            log_message('error', 'v1/staff/salary/pay-account: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StaffSalary.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $staff['uid']], 200, lang('StaffSalary.payAccountSaved'));
    }

    /**
     * add() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function save(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/staff/salary/edit' : 'v1/staff/salary/add';
        [$staff, $input, $error] = $this->staff($endpoint);
        if ($error !== null) {
            return $error;
        }
        $in = $input['salary'] ?? null;
        if (! is_array($in)) {
            return ApiResponse::error(lang('StaffSalary.invalidRequest'), 422);
        }

        $salaries = model(StaffSalaryModel::class);
        try {
            [$salary, $errors] = $this->checkSalary($in);
            if ($errors !== []) {
                return ApiResponse::error(lang('StaffSalary.invalidFields'), 422, $errors);
            }
            $rows = $salaries->rowsOf((int) $staff['id']);
            if ($edit) {
                $latest = $rows[0] ?? null;
                if ($latest === null || $latest['public_id'] !== ($input['public_id'] ?? null)) {
                    return ApiResponse::error(lang('StaffSalary.notLatest'), 409);
                }
                if ($salaries->isUsedByPayslip((int) $latest['id'])) {
                    return ApiResponse::error(lang('StaffSalary.usedByPayslip'), 409);
                }
            }
            $problem = $edit
                ? StaffSalaryModel::editError($salary, $rows[1] ?? null, (string) $staff['employment_start_date'])
                : StaffSalaryModel::addError($salary, $rows[0] ?? null, (string) $staff['employment_start_date']);
            if ($problem !== null) {
                return ApiResponse::error(lang('StaffSalary.invalidFields'), 422, ['effective_date' => lang($problem)]);
            }
            $publicId = $edit
                ? $rows[0]['public_id']
                : $salaries->add($staff, $salary, $this->actorId());
            if ($edit) {
                $salaries->editLatest($staff, $rows[0], $salary, $this->actorId());
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StaffSalary.saveFailed'), 500);
        }

        return ApiResponse::success(['uid' => $staff['uid'], 'public_id' => $publicId], 200, lang($edit ? 'StaffSalary.updated' : 'StaffSalary.added'));
    }

    /**
     * The request's salary, checked
     * @param array $in
     * @return array{0: array, 1: array<string, string>} [StaffSalaryModel::FIELDS, errors by field]
     */
    private function checkSalary(array $in): array
    {
        $entityId = null;
        $entityIn = $in['entity'] ?? null;
        $entityOk = true;
        if ($entityIn !== null && $entityIn !== '') {
            $entityId = is_string($entityIn) ? model(OtternovaLedgerEntityModel::class)->where('public_id', $entityIn)->first()['id'] ?? null : null;
            $entityOk = $entityId !== null;
        }
        $endIn   = $in['end_date'] ?? null;
        $hoursIn = $in['standard_hours_week'] ?? null;
        $noteIn  = $in['note'] ?? null;
        $social  = $in['social_security'] ?? false;
        $salary  = [
            'entity_id'           => $entityId === null ? null : (int) $entityId,
            'effective_date'      => self::date($in['effective_date'] ?? null),
            'end_date'            => $endIn === null || $endIn === '' ? null : self::date($endIn),
            'currency_code'       => is_string($in['currency_code'] ?? null) ? strtoupper(trim($in['currency_code'])) : null,
            'base_amount'         => self::decimal($in['base_amount'] ?? null, self::AMOUNT_PATTERN),
            'rate_unit'           => $in['rate_unit'] ?? null,
            'pay_frequency'       => $in['pay_frequency'] ?? null,
            'standard_hours_week' => $hoursIn === null || $hoursIn === '' ? null : self::decimal($hoursIn, self::HOURS_PATTERN),
            'social_security'     => in_array($social, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
            'change_reason'       => $in['change_reason'] ?? null,
            'note'                => is_string($noteIn) && trim($noteIn) !== '' ? trim($noteIn) : null,
        ];
        $errors = array_filter([
            'entity'              => $entityOk ? null : lang('StaffSalary.invalidEntity'),
            'effective_date'      => $salary['effective_date'] === null ? lang('StaffSalary.invalidDate') : null,
            'end_date'            => match (true) {
                $salary['end_date'] === null && $endIn !== null && $endIn !== '' => lang('StaffSalary.invalidDate'),
                $salary['end_date'] !== null && $salary['effective_date'] !== null && $salary['end_date'] < $salary['effective_date'] => lang('StaffSalary.endBeforeStart'),
                default => null,
            },
            'currency_code'       => $salary['currency_code'] !== null && isset(listCurrencies()[$salary['currency_code']]) ? null : lang('StaffSalary.invalidCurrency'),
            'base_amount'         => $salary['base_amount'] === null ? lang('StaffSalary.invalidAmount') : null,
            'rate_unit'           => in_array($salary['rate_unit'], StaffSalaryModel::RATE_UNITS, true) ? null : lang('StaffSalary.invalidRateUnit'),
            'pay_frequency'       => in_array($salary['pay_frequency'], StaffSalaryModel::FREQUENCIES, true) ? null : lang('StaffSalary.invalidFrequency'),
            'standard_hours_week' => match (true) {
                $hoursIn === null || $hoursIn === '' => null,
                $salary['standard_hours_week'] === null, (float) $salary['standard_hours_week'] <= 0, (float) $salary['standard_hours_week'] > 168 => lang('StaffSalary.invalidHours'),
                default => null,
            },
            'change_reason'       => in_array($salary['change_reason'], StaffSalaryModel::CHANGE_REASONS, true) ? null : lang('StaffSalary.invalidReason'),
            'note'                => ($noteIn !== null && ! is_string($noteIn)) || mb_strlen((string) $salary['note']) > 255 ? lang('StaffSalary.invalidNote') : null,
        ]);

        return [$salary, $errors];
    }

    /**
     * The request's staff member
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null}
     *         [StaffSalaryModel::findStaff(), the request body, null] or [null, null, error response]
     */
    private function staff(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('StaffSalary.invalidRequest'), 422)];
        }
        $uid = is_string($input['uid'] ?? null) ? trim($input['uid']) : '';
        if ($uid === '') {
            return [null, null, ApiResponse::error(lang('StaffSalary.uidRequired'), 400)];
        }
        try {
            $staff = model(StaffSalaryModel::class)->findStaff($uid);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, null, ApiResponse::error(lang('StaffSalary.loadFailed'), 500)];
        }

        return $staff === null ? [null, null, ApiResponse::error(lang('StaffSalary.notFound'), 404)] : [$staff, $input, null];
    }

    /**
     * A non-negative decimal with two decimals, or null when $value is not one
     * @param mixed  $value
     * @param string $pattern
     * @return string|null
     */
    private static function decimal(mixed $value, string $pattern): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
        }
        if (! is_string($value) || preg_match($pattern, trim($value)) !== 1) {
            return null;
        }
        [$whole, $fraction] = explode('.', trim($value) . '.');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * @param mixed $value
     * @return string|null Y-m-d, or null when $value is not a real date
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

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }
}
