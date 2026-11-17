<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\OrderNumber;
use App\Models\BusinessClientLinkModel;
use App\Models\PayrollModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use Throwable;

/**
 * What the people of a business earn in a pay period: salary (pro rata when they start, leave or get a raise inside
 * the period) and commission, as App\Models\PayrollModel works them out. The Client app's, behind the jwt:C filter,
 * for the active owners of the business only; read only. A business comes in by its business_slug, a person by
 * their auth_master.uid.
 */
class Payroll extends BaseController
{
    use ManagedBusinessTrait;

    /** Only the owners see what everyone earns */
    private const OWNERS = [BusinessClientLinkModel::ROLE_OWNER];

    /**
     * POST /client/payroll { business_slug, cycle (W, S or M; M when left out), date ("Y-m-d"; today when left out) }
     * Everyone of the cycle who earns anything in its pay period that holds date: { cycle, from, to, timezone,
     * currency_code, commission, people } with each person's totals (PayrollModel::report() without the lines)
     * @return ResponseInterface
     */
    public function report(): ResponseInterface
    {
        return $this->respond('v1/client/payroll', null);
    }

    /**
     * POST /client/payroll/person { business_slug, cycle, date, uid }
     * One person's pay in that period, with how it is worked out: { cycle, from, to, timezone, currency_code,
     * commission, person } (with its salary_lines and commission_lines); 404 when they earn nothing in it or are
     * not of the business
     * @return ResponseInterface
     */
    public function person(): ResponseInterface
    {
        return $this->respond('v1/client/payroll/person', '');
    }

    /**
     * @param string      $endpoint
     * @param string|null $uid null for everyone; '' to take it from the body
     */
    private function respond(string $endpoint, ?string $uid): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Payroll.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Payroll', self::OWNERS);
        if ($error !== null) {
            return $error;
        }
        $text  = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        $cycle = $text('cycle') === '' ? 'M' : $text('cycle');
        if (! in_array($cycle, PayrollModel::CYCLES, true)) {
            return ApiResponse::error(lang('Payroll.invalidCycle'), 422);
        }
        if ($uid === '') {
            $uid = $text('uid');
            if ($uid === '') {
                return ApiResponse::error(lang('Payroll.personRequired'), 400);
            }
        }
        try {
            $timezone = OrderNumber::timezoneOf(db_connect(), $business);
            $date     = $text('date') === '' ? Time::now($timezone->getName())->format('Y-m-d') : $text('date');
            $day      = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($day === false || $day->format('Y-m-d') !== $date) {
                return ApiResponse::error(lang('Payroll.invalidDate'), 422);
            }
            [$first, $last] = PayrollModel::periodOf($cycle, $day);
            $from   = $first->format('Y-m-d');
            $to     = $last->format('Y-m-d');
            $report = model(PayrollModel::class)->report((int) $business['id'], $cycle, $from, $to, $timezone, $uid);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Payroll.loadFailed'), 500);
        }
        $out = ['cycle' => $cycle, 'from' => $from, 'to' => $to, 'timezone' => $timezone->getName(),
            'currency_code' => $report['currency_code'], 'commission' => $report['commission']];
        if ($uid === null) {
            return ApiResponse::success($out + ['people' => array_map(static function (array $person): array {
                unset($person['salary_lines'], $person['commission_lines']);

                return $person;
            }, $report['people'])]);
        }
        if ($report['people'] === []) {
            return ApiResponse::error(lang('Payroll.personNotFound'), 404);
        }

        return ApiResponse::success($out + ['person' => $report['people'][0]]);
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
