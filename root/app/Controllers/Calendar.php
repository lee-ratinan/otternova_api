<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\OrderNumber;
use App\Models\CalendarModel;
use App\Models\ClientMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use Throwable;

/**
 * The calendar of a business: what is booked on a day (its group sessions and private appointments, with the people
 * and resources they hold) and one session of it in full. The Client app's, behind the jwt:C filter, for any active
 * client of the business, its employees included (ManagedBusinessTrait::ALL_ROLES), so everyone can see whom they work
 * with; read only. See App\Models\CalendarModel. A business comes in by its business_slug, a session by its public_id.
 */
class Calendar extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/calendar { business_slug, date }
     * The slots of the business's sessions on that day ("Y-m-d" of each store's clock; the business's today when left
     * out): { date, timezone (the business's), entries } as CalendarModel::day() gives them
     * @return ResponseInterface
     */
    public function day(): ResponseInterface
    {
        $endpoint = 'v1/client/calendar';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Calendar.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Calendar', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $date = is_string($input['date'] ?? null) ? trim($input['date']) : '';
        try {
            $timezone = OrderNumber::timezoneOf(db_connect(), $business);
            if ($date === '') {
                $date = Time::now($timezone->getName())->format('Y-m-d');
            } else {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                    return ApiResponse::error(lang('Calendar.invalidDate'), 422);
                }
            }
            $entries = model(CalendarModel::class)->day((int) $business['id'], $date, $this->viewerId(), $this->language());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e);
        }

        return ApiResponse::success(['date' => $date, 'timezone' => $timezone->getName(), 'entries' => $entries]);
    }

    /**
     * POST /client/calendar/session { business_slug, public_id }
     * One session of the business, as CalendarModel::session() gives it; 404 when it is not the business's
     * @return ResponseInterface
     */
    public function session(): ResponseInterface
    {
        $endpoint = 'v1/client/calendar/session';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Calendar.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Calendar', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $publicId = is_string($input['public_id'] ?? null) ? trim($input['public_id']) : '';
        if ($publicId === '') {
            return ApiResponse::error(lang('Calendar.sessionIdRequired'), 400);
        }
        try {
            $session = model(CalendarModel::class)->session((int) $business['id'], $publicId, $this->viewerId(), $this->language());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e);
        }
        if ($session === null) {
            return ApiResponse::error(lang('Calendar.sessionNotFound'), 404);
        }

        return ApiResponse::success(['session' => $session]);
    }

    /** client_master.id of the signed-in client, to mark them among the staff */
    private function viewerId(): ?int
    {
        $client = model(ClientMasterModel::class)->findByAuthId($this->actorId());

        return $client === null ? null : (int) $client['id'];
    }

    /** The language of the request, for the session names */
    private function language(): string
    {
        return (string) $this->request->getLocale();
    }

    private function failed(string $endpoint, Throwable $e): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang('Calendar.loadFailed'), 500);
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
