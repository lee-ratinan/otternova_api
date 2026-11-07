<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessStoreHoursModel;
use App\Models\BusinessStoreModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

/**
 * The opening hours of a store (business_store_hours, business_store_hours_exceptions; see BusinessStoreHoursModel):
 * the Client app's, behind the jwt:C filter (clients only), and only for the active owners and managers of the
 * store's business. A store comes in by its public_id. Times are HH:MM in the store's store_timezone; a closing time
 * not after the opening time is on the next day (10:00-02:00).
 */
class StoreHours extends BaseController
{
    use ManagedBusinessTrait;

    /** business_store_hours_exceptions.exception_reason */
    private const REASON_MAX_LENGTH = 255;

    /**
     * POST /client/store/hours { public_id }
     * The store's store_timezone, today there, its "weekly" hours (day_of_week 1 Monday to 7 Sunday, open_time,
     * close_time), and its "exceptions" from today on (exception_date, exception_reason, closed, hours: [{open_time,
     * close_time}]), by date
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/store/hours';
        [$store, , $error] = $this->store($endpoint);
        if ($error !== null) {
            return $error;
        }
        $hours = model(BusinessStoreHoursModel::class);
        try {
            $today = $hours->localToday((int) $store['id']);
            $data  = [
                'store_timezone' => $store['store_timezone'],
                'today'          => $today,
                'weekly'         => $hours->weeklyOf((int) $store['id']),
                'exceptions'     => $hours->exceptionsFrom((int) $store['id'], $today),
                'max_openings'   => BusinessStoreHoursModel::MAX_OPENINGS_PER_DAY,
            ];
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StoreHours.loadFailed'), 500);
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/store/hours/save { public_id, weekly: [{day_of_week, open_time, close_time}, ...] }
     * Replaces the store's weekly hours; a day left out is closed, [] closes every day but the exceptions. Openings
     * of a day must not overlap, a late one running into the next day's first included (Sunday's into Monday's).
     * @return ResponseInterface
     */
    public function save(): ResponseInterface
    {
        $endpoint = 'v1/client/store/hours/save';
        [$store, $input, $error] = $this->store($endpoint);
        if ($error !== null) {
            return $error;
        }
        $weekly = $input['weekly'] ?? null;
        if (! is_array($weekly) || ! array_is_list($weekly)) {
            return ApiResponse::error(lang('StoreHours.invalidFields'), 422, ['weekly' => lang('StoreHours.invalidList')]);
        }
        $rows   = [];
        $errors = [];
        foreach ($weekly as $i => $item) {
            $day = is_array($item) ? ($item['day_of_week'] ?? null) : null;
            if (! is_int($day) && ! (is_string($day) && ctype_digit($day))) {
                $day = 0;
            }
            $day = (int) $day;
            if ($day < 1 || $day > 7) {
                $errors['weekly.' . $i . '.day_of_week'] = lang('StoreHours.invalidDay');
                continue;
            }
            [$row, $rowErrors] = $this->opening(is_array($item) ? $item : [], 'weekly.' . $i);
            $errors += $rowErrors;
            if ($row !== null) {
                $rows[] = ['day_of_week' => $day] + $row;
            }
        }
        $problem = $errors === [] ? BusinessStoreHoursModel::weeklyError($rows) : null;
        if ($problem !== null) {
            $errors['weekly'] = lang($problem, [BusinessStoreHoursModel::MAX_OPENINGS_PER_DAY]);
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('StoreHours.invalidFields'), 422, $errors);
        }
        try {
            model(BusinessStoreHoursModel::class)->replaceWeekly((int) $store['id'], $rows, $this->actorId(), $store['public_id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StoreHours.saveFailed'), 500);
        }

        return ApiResponse::success(['public_id' => $store['public_id']], 200, lang('StoreHours.weeklySaved'));
    }

    /**
     * POST /client/store/hours-exception/save { public_id, exception_date, exception_reason, hours: [{open_time, close_time}, ...] }
     * Sets the store's hours on a date (Y-m-d, today or later where the store is) in place of its weekly ones; hours
     * [] closes it all day. Whatever the date had is replaced. The hours must not overlap each other, the late opening
     * of the day before, or the first opening of the day after.
     * @return ResponseInterface
     */
    public function saveException(): ResponseInterface
    {
        $endpoint = 'v1/client/store/hours-exception/save';
        [$store, $input, $error] = $this->store($endpoint);
        if ($error !== null) {
            return $error;
        }
        $hours  = model(BusinessStoreHoursModel::class);
        $errors = [];
        $date   = $this->date($input['exception_date'] ?? null);
        try {
            if ($date === null) {
                $errors['exception_date'] = lang('StoreHours.invalidDate');
            } elseif ($date < $hours->localToday((int) $store['id'])) {
                $errors['exception_date'] = lang('StoreHours.pastDate');
            }
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StoreHours.loadFailed'), 500);
        }
        $reason = is_string($input['exception_reason'] ?? null) ? trim($input['exception_reason']) : '';
        if ($reason === '') {
            $errors['exception_reason'] = lang('StoreHours.reasonRequired');
        } elseif (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            $errors['exception_reason'] = lang('StoreHours.reasonTooLong', [self::REASON_MAX_LENGTH]);
        }
        $list = $input['hours'] ?? [];
        if (! is_array($list) || ! array_is_list($list)) {
            return ApiResponse::error(lang('StoreHours.invalidFields'), 422, $errors + ['hours' => lang('StoreHours.invalidList')]);
        }
        $rows = [];
        foreach ($list as $i => $item) {
            [$row, $rowErrors] = $this->opening(is_array($item) ? $item : [], 'hours.' . $i);
            $errors += $rowErrors;
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        try {
            $problem = $errors === [] ? $hours->exceptionError((int) $store['id'], $date, $rows) : null;
            if ($problem !== null) {
                $errors['hours'] = lang($problem, [BusinessStoreHoursModel::MAX_OPENINGS_PER_DAY]);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('StoreHours.invalidFields'), 422, $errors);
            }
            $hours->saveException((int) $store['id'], $date, $reason, $rows, $this->actorId(), $store['public_id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StoreHours.saveFailed'), 500);
        }

        return ApiResponse::success(['public_id' => $store['public_id'], 'exception_date' => $date], 200, lang('StoreHours.exceptionSaved'));
    }

    /**
     * POST /client/store/hours-exception/delete { public_id, exception_date }
     * Gives the date back its weekly hours. 404 when it has no exception.
     * @return ResponseInterface
     */
    public function deleteException(): ResponseInterface
    {
        $endpoint = 'v1/client/store/hours-exception/delete';
        [$store, $input, $error] = $this->store($endpoint);
        if ($error !== null) {
            return $error;
        }
        $date = $this->date($input['exception_date'] ?? null);
        if ($date === null) {
            return ApiResponse::error(lang('StoreHours.invalidFields'), 422, ['exception_date' => lang('StoreHours.invalidDate')]);
        }
        try {
            $deleted = model(BusinessStoreHoursModel::class)->deleteException((int) $store['id'], $date, $this->actorId(), $store['public_id']);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('StoreHours.saveFailed'), 500);
        }
        if (! $deleted) {
            return ApiResponse::error(lang('StoreHours.exceptionNotFound'), 404);
        }

        return ApiResponse::success(['public_id' => $store['public_id'], 'exception_date' => $date], 200, lang('StoreHours.exceptionDeleted'));
    }

    /**
     * An opening of the request, its times normalized
     * @param array  $item
     * @param string $key  Its place in the request, for the errors
     * @return array{0: array{open_time: string, close_time: string}|null, 1: array<string, string>}
     */
    private function opening(array $item, string $key): array
    {
        $open   = BusinessStoreHoursModel::normalizeTime($item['open_time'] ?? null);
        $close  = BusinessStoreHoursModel::normalizeTime($item['close_time'] ?? null);
        $errors = array_filter([
            $key . '.open_time'  => $open === null ? lang('StoreHours.invalidTime') : null,
            $key . '.close_time' => $close === null ? lang('StoreHours.invalidTime') : null,
        ]);

        return [$errors === [] ? ['open_time' => $open, 'close_time' => $close] : null, $errors];
    }

    /**
     * The request's store, once the signed-in client is found to be an active owner or manager of its business
     * @param string $endpoint for the error log
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [business_store row, the request body, null] or [null, null, error response]
     */
    private function store(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('StoreHours.invalidRequest'), 422)];
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, null, ApiResponse::error(lang('StoreHours.storeIdRequired'), 400)];
        }
        try {
            $store = model(BusinessStoreModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return [null, null, ApiResponse::error(lang('StoreHours.loadFailed'), 500)];
        }
        if ($store === null) {
            return [null, null, ApiResponse::error(lang('StoreHours.notFound'), 404)];
        }
        $error = $this->roleError((int) $store['business_id'], $endpoint, lang('StoreHours.notFound'), 'StoreHours');

        return $error === null ? [$store, $input, null] : [null, null, $error];
    }

    /**
     * @param mixed $value
     * @return string|null The Y-m-d date, or null when it is not one
     */
    private function date(mixed $value): ?string
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
