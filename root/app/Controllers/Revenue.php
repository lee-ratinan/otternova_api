<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\OrderNumber;
use App\Models\BusinessClientLinkModel;
use App\Models\RevenueModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use Throwable;

/**
 * The revenue of a business: what the orders placed in a period come to (sales, what was collected and refunded,
 * and their breakdowns). The Client app's, behind the jwt:C filter, for the active owners of the business only; read
 * only. See App\Models\RevenueModel. A business comes in by its business_slug.
 */
class Revenue extends BaseController
{
    use ManagedBusinessTrait;

    /** Only the owners see the money of the business */
    private const OWNERS = [BusinessClientLinkModel::ROLE_OWNER];

    /** The periods a range can be asked as, around a date */
    private const PERIODS = ['day', 'week', 'month'];

    /** The longest range, in days */
    public const MAX_DAYS = 366;

    /**
     * POST /client/revenue { business_slug, from, to } or { business_slug, period, date }
     * The orders placed from the day "from" to the day "to" (both "Y-m-d" of the business's clock, both included;
     * the business's today when left out), or in the day, week (Monday to Sunday) or month (period) that holds "date"
     * (today when left out): { from, to, timezone } and what RevenueModel::report() gives. 422 when a day is not a
     * date, the period is not one of PERIODS, "to" is before "from" or the range is longer than MAX_DAYS
     * @return ResponseInterface
     */
    public function report(): ResponseInterface
    {
        $endpoint = 'v1/client/revenue';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Revenue.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Revenue', self::OWNERS);
        if ($error !== null) {
            return $error;
        }
        try {
            $timezone = OrderNumber::timezoneOf(db_connect(), $business);
            $today    = Time::now($timezone->getName())->format('Y-m-d');
            $text     = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
            $period   = $text('period');
            if ($period !== '') {
                // the day, week (Monday to Sunday) or month of "date"
                $anchor = self::date($text('date') === '' ? $today : $text('date'));
                if (! in_array($period, self::PERIODS, true) || $anchor === null) {
                    return ApiResponse::error(lang('Revenue.invalidDate'), 422);
                }
                [$start, $end] = match ($period) {
                    'day'   => [$anchor, $anchor],
                    'week'  => [$anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days'), $anchor->modify('+' . (7 - (int) $anchor->format('N')) . ' days')],
                    'month' => [$anchor->modify('first day of this month'), $anchor->modify('last day of this month')],
                };
                $from = $start->format('Y-m-d');
                $to   = $end->format('Y-m-d');
            } else {
                $from = $text('from') === '' ? $today : $text('from');
                $to   = $text('to') === '' ? $from : $text('to');
            }
            $first    = self::date($from);
            $last     = self::date($to);
            if ($first === null || $last === null) {
                return ApiResponse::error(lang('Revenue.invalidDate'), 422);
            }
            if ($last < $first) {
                return ApiResponse::error(lang('Revenue.toBeforeFrom'), 422);
            }
            if ((int) $first->diff($last)->days + 1 > self::MAX_DAYS) {
                return ApiResponse::error(lang('Revenue.rangeTooLong', [self::MAX_DAYS]), 422);
            }
            $report = model(RevenueModel::class)->report((int) $business['id'], $from, $to, $timezone);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Revenue.loadFailed'), 500);
        }

        return ApiResponse::success(['from' => $from, 'to' => $to, 'timezone' => $timezone->getName()] + $report);
    }

    private static function date(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /**
     * auth_master.id of the signed-in account, for ManagedBusinessTrait
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
