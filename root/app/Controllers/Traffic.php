<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\TrackerRollup;
use App\Libraries\TrafficReport;
use App\Models\BusinessMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use Config\Tracker;
use Throwable;

/**
 * The traffic the tracker counted (see App\Libraries\TrafficReport), for two apps:
 * - the Admin app (staff/traffic/*, any staff member): the website (site www, the default), the whole marketplace
 *   (site mart), or one business on it (site mart with its business_slug);
 * - the Client app (client/traffic/*): one business's marketplace pages, by business_slug, for its active owners
 *   and managers.
 * Every request takes a period of local days, from and to (YYYY-MM-DD, both in), the last 30 days when left out.
 * The days are the business's, in its country's timezone, or Bangkok's for the website and the whole marketplace;
 * the answer says which as timezone. Times in visits are UTC, as everywhere in the API.
 */
class Traffic extends BaseController
{
    use ManagedBusinessTrait;

    private const DEFAULT_DAYS  = 30;
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    /**
     * POST /staff/traffic/overview { site?, business_slug?, from?, to? }
     * POST /client/traffic/overview { business_slug, from?, to? }
     * totals (visits, pageviews, visitors, new_visitors, bounce_rate, avg_visit_s, pages_per_visit, and for the
     * marketplace orders, revenue and conversion_rate) of the period
     * and of the one as long before it (previous), series (each day's visits, pageviews, visitors, new_visitors,
     * orders and revenue), and for the marketplace funnel (each step's visits and rate_from_previous, orders,
     * revenue, conversion_rate). currency_code is the business's, for revenue; null for the website and the whole
     * marketplace, whose revenue mixes currencies.
     * @return ResponseInterface
     */
    public function staffOverview(): ResponseInterface
    {
        return $this->overview(true);
    }

    public function clientOverview(): ResponseInterface
    {
        return $this->overview(false);
    }

    /**
     * POST /staff/traffic/breakdown { site?, business_slug?, from?, to?, dimension, limit? }
     * POST /client/traffic/breakdown { business_slug, from?, to?, dimension, limit? }
     * The period's traffic by dimension: path (pages, most viewed first, with avg_time_on_page_s), country (a
     * country code, '' when unknown), source (direct, search, social, referral, campaign), device (desktop, mobile,
     * tablet) or referrer (the referring site's host). Each row has value, visits, pageviews and bounce_rate, and
     * but for path avg_visit_s, orders and revenue. limit: rows, 20 by default, at most 100.
     * @return ResponseInterface
     */
    public function staffBreakdown(): ResponseInterface
    {
        return $this->breakdown(true);
    }

    public function clientBreakdown(): ResponseInterface
    {
        return $this->breakdown(false);
    }

    /**
     * POST /staff/traffic/visits { site?, business_slug?, from?, to?, start, length }
     * POST /client/traffic/visits { business_slug, from?, to?, start, length }
     * One page of the period's visits, newest first: when (UTC) and how long, the pages seen, where from, where the
     * visitor was and on what, whether they had come before (is_returning), and how far into the funnel they got.
     * The Admin app also gets each visit's ip_address (its last part zeroed after 30 days) and business_slug.
     * @return ResponseInterface
     */
    public function staffVisits(): ResponseInterface
    {
        return $this->visits(true);
    }

    public function clientVisits(): ResponseInterface
    {
        return $this->visits(false);
    }

    /**
     * POST /staff/traffic/businesses { from?, to?, limit? }
     * The marketplace's businesses with the most visits in the period: business_slug, business_name, currency_code,
     * visits, pageviews, orders, revenue and conversion_rate
     * @return ResponseInterface
     */
    public function staffBusinesses(): ResponseInterface
    {
        $endpoint = 'v1/staff/traffic/businesses';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Traffic.invalidRequest'), 422);
        }
        $timezone = config(Tracker::class)->localTimezone;
        [$from, $to, $error] = $this->period($input, $timezone);
        if ($error !== null) {
            return $error;
        }
        try {
            $rows = (new TrafficReport())->topBusinesses($from, $to, $this->limit($input));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Traffic.loadFailed'), 500);
        }

        return ApiResponse::success(['from' => $from, 'to' => $to, 'timezone' => $timezone, 'businesses' => $rows]);
    }

    private function overview(bool $forStaff): ResponseInterface
    {
        $endpoint = 'v1/' . ($forStaff ? 'staff' : 'client') . '/traffic/overview';
        [$scope, $from, $to, $currency, $error] = $this->request($forStaff, $endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $report = (new TrafficReport())->overview($scope, $from, $to);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Traffic.loadFailed'), 500);
        }

        return ApiResponse::success(['currency_code' => $currency] + $report);
    }

    private function breakdown(bool $forStaff): ResponseInterface
    {
        $endpoint = 'v1/' . ($forStaff ? 'staff' : 'client') . '/traffic/breakdown';
        [$scope, $from, $to, , $error] = $this->request($forStaff, $endpoint);
        if ($error !== null) {
            return $error;
        }
        $input     = $this->jsonInput() ?? [];
        $dimension = $input['dimension'] ?? null;
        if (! in_array($dimension, TrafficReport::BREAKDOWNS, true)) {
            return ApiResponse::error(lang('Traffic.invalidDimension'), 422, ['dimension' => lang('Traffic.invalidDimension')]);
        }
        try {
            $rows = (new TrafficReport())->breakdown($scope, $from, $to, $dimension, $this->limit($input));
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Traffic.loadFailed'), 500);
        }

        return ApiResponse::success(['from' => $from, 'to' => $to, 'timezone' => $scope['timezone'], 'dimension' => $dimension, 'rows' => $rows]);
    }

    private function visits(bool $forStaff): ResponseInterface
    {
        $endpoint = 'v1/' . ($forStaff ? 'staff' : 'client') . '/traffic/visits';
        [$scope, $from, $to, , $error] = $this->request($forStaff, $endpoint);
        if ($error !== null) {
            return $error;
        }
        $input  = $this->jsonInput() ?? [];
        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        try {
            $data = (new TrafficReport())->visits($scope, $from, $to, $start === false ? 0 : max(0, $start),
                $length === false ? 25 : min(100, max(1, $length)), $forStaff);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Traffic.loadFailed'), 500);
        }

        return ApiResponse::success(['from' => $from, 'to' => $to, 'timezone' => $scope['timezone']] + $data);
    }

    /**
     * What every traffic request starts with: whose traffic, over which days
     * @param bool   $forStaff
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: string|null, 2: string|null, 3: string|null, 4: ResponseInterface|null}
     *         [scope, from, to, currency_code, null], or nulls and the error response
     */
    private function request(bool $forStaff, string $endpoint): array
    {
        $fail  = static fn (ResponseInterface $error): array => [null, null, null, null, $error];
        $input = $this->jsonInput();
        if ($input === null) {
            return $fail(ApiResponse::error(lang('Traffic.invalidRequest'), 422));
        }

        $config   = config(Tracker::class);
        $currency = null;
        if ($forStaff) {
            $site = $input['site'] ?? 'www';
            if (! in_array($site, ['www', 'mart'], true)) {
                return $fail(ApiResponse::error(lang('Traffic.invalidSite'), 422, ['site' => lang('Traffic.invalidSite')]));
            }
            $scope = ['site' => $site, 'business_id' => $site === 'www' ? 0 : null, 'timezone' => $config->localTimezone];
            $slug  = is_string($input['business_slug'] ?? null) ? trim($input['business_slug']) : '';
            if ($site === 'mart' && $slug !== '') {
                try {
                    $business = model(BusinessMasterModel::class)->select('id, currency_code')->where('business_slug', $slug)->first();
                } catch (Throwable $e) {
                    log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

                    return $fail(ApiResponse::error(lang('Traffic.loadFailed'), 500));
                }
                if ($business === null) {
                    return $fail(ApiResponse::error(lang('Traffic.businessNotFound'), 404));
                }
                [$scope, $currency] = $this->businessScope($business);
            }
        } else {
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Traffic');
            if ($error !== null) {
                return $fail($error);
            }
            $business['currency_code'] = model(BusinessMasterModel::class)->select('currency_code')->find($business['id'])['currency_code'] ?? null;
            [$scope, $currency] = $this->businessScope($business);
        }

        [$from, $to, $error] = $this->period($input, $scope['timezone']);
        if ($error !== null) {
            return $fail($error);
        }

        return [$scope, $from, $to, $currency, null];
    }

    /**
     * @param array $business id and currency_code
     * @return array{0: array, 1: string|null} [scope, currency_code]
     */
    private function businessScope(array $business): array
    {
        $id       = (int) $business['id'];
        $timezone = (new TrackerRollup(config(Tracker::class)))->timezonesOf([$id])[$id];

        return [['site' => 'mart', 'business_id' => $id, 'timezone' => $timezone], $business['currency_code'] ?? null];
    }

    /**
     * The from and to of the request, or the last DEFAULT_DAYS days in the timezone
     * @param array  $input
     * @param string $timezone
     * @return array{0: string|null, 1: string|null, 2: ResponseInterface|null}
     */
    private function period(array $input, string $timezone): array
    {
        $today = Time::today($timezone);
        $to    = $input['to'] ?? null;
        $from  = $input['from'] ?? null;
        $to    = $to === null || $to === '' ? $today->toDateString() : $to;
        $from  = $from === null || $from === '' ? Time::parse($to . ' 00:00:00', $timezone)->subDays(self::DEFAULT_DAYS - 1)->toDateString() : $from;

        $errors = [];
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (! $this->isDate($value)) {
                $errors[$field] = lang('Traffic.invalidDate');
            }
        }
        if ($errors !== []) {
            return [null, null, ApiResponse::error(lang('Traffic.invalidDate'), 422, $errors)];
        }
        if ($from > $to) {
            return [null, null, ApiResponse::error(lang('Traffic.invalidRange'), 422, ['from' => lang('Traffic.invalidRange')])];
        }
        $maxDays = config(Tracker::class)->maxReportDays;
        if ((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / DAY + 1 > $maxDays) {
            return [null, null, ApiResponse::error(lang('Traffic.rangeTooLong', [$maxDays]), 422, ['from' => lang('Traffic.rangeTooLong', [$maxDays])])];
        }

        return [$from, $to, null];
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) && $year >= 2000;
    }

    private function limit(array $input): int
    {
        $limit = filter_var($input['limit'] ?? null, FILTER_VALIDATE_INT);

        return $limit === false ? self::DEFAULT_LIMIT : min(self::MAX_LIMIT, max(1, $limit));
    }

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
