<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\OrderMailer;
use App\Models\BusinessMasterModel;
use App\Models\BusinessStoreModel;
use App\Models\MartCatalogModel;
use App\Models\MartOrderModel;
use App\Models\MartSessionModel;
use App\Models\MartSlotModel;
use App\Models\OrderRefusedException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Orders taken at the counter of a store by the people of a business (walk-ins and call-ins): the Client app's, behind
 * the jwt:C filter, for any active client of the business (ManagedBusinessTrait::ALL_ROLES). They are priced and
 * placed as the mart's are (App\Models\MartOrderModel, with its counter choice), with what changes at the counter:
 * - the booking window does not apply: a walk-in from the step now is in, a call-in further ahead than the mart takes;
 *   a group session may be joined until it ends. The opening hours, the people and the resources still apply.
 * - the customer gives a first name, and an email address or a telephone number.
 * - the products are handed over at the counter by default (out of that store's stock, fulfilled), or shipped.
 * - the total may be paid there and then (paid_now): its payment is recorded as succeeded, by whom took the order.
 * Every request names the business (business_slug) and the counter's store (store_public_id), a physical store of it.
 */
class CounterOrders extends BaseController
{
    use ManagedBusinessTrait;
    use OrderInputTrait;

    /**
     * POST /client/counter/stores { business_slug }
     * The physical stores of the business, where orders are taken at the counter, by name; mine: whether the
     * signed-in client works there (business_store_client_link), so the page can start at theirs
     * @return ResponseInterface { stores: [{public_id, store_name, timezone, mine}] }
     */
    public function stores(): ResponseInterface
    {
        $endpoint = 'v1/client/counter/stores';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('CounterOrder.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'CounterOrder', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        try {
            $db     = db_connect();
            $client = $db->table('client_master')->select('id')->where('auth_id', $this->actorId())->get()->getRow();
            $mine   = $client === null ? [] : array_map('intval', array_column($db->table('business_store_client_link')->select('store_id')
                ->where('business_id', $business['id'])->where('client_id', $client->id)->where('link_status', 'A')->get()->getResultArray(), 'store_id'));
            $rows   = $db->table('business_store')->select('id, public_id, store_name, store_timezone')->where('business_id', $business['id'])
                ->where('store_type', BusinessStoreModel::TYPE_PHYSICAL)->orderBy('store_name')->orderBy('id')->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('CounterOrder.loadFailed'), 500);
        }

        return ApiResponse::success(['stores' => array_map(static fn (array $r): array => ['public_id' => $r['public_id'], 'store_name' => $r['store_name'],
            'timezone' => $r['store_timezone'], 'mine' => in_array((int) $r['id'], $mine, true)], $rows)]);
    }

    /**
     * POST /client/counter/catalog { business_slug, store_public_id, search }
     * What can be sold at the counter: { items: MartOrderModel::counterCatalog() }, at most 50, by name
     * @return ResponseInterface
     */
    public function catalog(): ResponseInterface
    {
        return $this->withCounter('v1/client/counter/catalog', function (array $business, array $store, string $language, array $input): ResponseInterface {
            $search = is_string($input['search'] ?? null) ? mb_substr(trim($input['search']), 0, 100) : '';

            return ApiResponse::success(['items' => model(MartOrderModel::class)->counterCatalog((int) $business['id'], (int) $store['id'], $language, $search)]);
        });
    }

    /**
     * POST /client/counter/slots { business_slug, store_public_id, variant, date }
     * The times an appointment variant can be booked at the counter's store on date (Y-m-d on the store's clock, today
     * when left out), the booking window aside: { date, staff: [{public_id, name, restrictions}], slots: MartSlotModel::slotsOn() }
     * @return ResponseInterface
     */
    public function slots(): ResponseInterface
    {
        return $this->withCounter('v1/client/counter/slots', function (array $business, array $store, string $language, array $input): ResponseInterface {
            $slots   = model(MartSlotModel::class);
            $variant = is_string($input['variant'] ?? null) ? $slots->bookableVariant((int) $business['id'], $input['variant']) : null;
            if ($variant === null) {
                return ApiResponse::error(lang('CounterOrder.unknownVariant'), 404);
            }
            $here = current(array_filter($slots->storesFor((int) $business['id'], $variant['artifact_id'], $language),
                static fn (array $s): bool => $s['id'] === (int) $store['id'])) ?: null;
            $zone = new DateTimeZone($store['store_timezone'] ?: 'UTC');
            $date = is_string($input['date'] ?? null) && $input['date'] !== '' ? $input['date'] : self::now()->setTimezone($zone)->format('Y-m-d');
            $day  = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            if ($day === false || $day->format('Y-m-d') !== $date) {
                return ApiResponse::error(lang('CounterOrder.invalidFields'), 422, ['date' => lang('CounterOrder.invalidDate')]);
            }
            if ($here === null) {
                // nobody who performs it works at this store
                return ApiResponse::success(['date' => $date, 'staff' => [], 'slots' => []]);
            }

            return ApiResponse::success([
                'date'  => $date,
                'staff' => array_values(array_map(static fn (array $p): array => ['public_id' => $p['public_id'], 'name' => $p['name'], 'restrictions' => $p['restrictions']], $here['staff'])),
                'slots' => $slots->slotsOn($business, $variant, $here, $date, null, self::now(), true),
            ]);
        });
    }

    /**
     * POST /client/counter/sessions { business_slug, store_public_id, variant }
     * The sessions of a group variant at the counter's store with a seat left, until each ends: { sessions: [{public_id,
     * name, starts_at, ends_at, seats_available}] }
     * @return ResponseInterface
     */
    public function sessions(): ResponseInterface
    {
        return $this->withCounter('v1/client/counter/sessions', function (array $business, array $store, string $language, array $input): ResponseInterface {
            $model   = model(MartSessionModel::class);
            $variant = is_string($input['variant'] ?? null) ? $model->groupVariant((int) $business['id'], $input['variant']) : null;
            if ($variant === null) {
                return ApiResponse::error(lang('CounterOrder.unknownVariant'), 404);
            }
            $sessions = array_values(array_filter($model->bookable((int) $business['id'], $variant['id'], $language, self::now(), true)['sessions'],
                static fn (array $s): bool => (int) $s['store_id'] === (int) $store['id']));

            return ApiResponse::success(['sessions' => array_map(static fn (array $s): array => ['public_id' => $s['public_id'], 'name' => $s['name'],
                'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'], 'seats_available' => $s['seats_available']], $sessions)]);
        });
    }

    /**
     * POST /client/counter/quote { business_slug, store_public_id, lines, shipping, pickup_store, discount, payment,
     * handed_over }
     * What the order would cost now, and what may be chosen for it, as /mart/quote answers it. lines as /mart/quote
     * takes them; handed_over (true when left out) gives the products at the counter instead of shipping them.
     * @return ResponseInterface
     */
    public function quote(): ResponseInterface
    {
        return $this->withCounter('v1/client/counter/quote', function (array $business, array $store, string $language, array $input): ResponseInterface {
            [$cart, $choice, $errors] = $this->checkoutInput($input);
            if ($errors !== []) {
                return ApiResponse::error(lang('CounterOrder.invalidFields'), 422, $errors);
            }
            $choice['counter'] = $this->counterChoice($store, $input);

            return ApiResponse::success(model(MartOrderModel::class)->quote($business, $language, $cart, $choice, self::now()));
        });
    }

    /**
     * POST /client/counter/order { ...quote's, lines with each booking's participants, customer: {first_name,
     * last_name, email, telephone}, delivery: {...} when shipped home, pickup_point, paid_now }
     * Places the order at the counter. The customer needs a first name, and an email address or a telephone number
     * (customer.contact). paid_now records the total as paid by the payment method chosen.
     * 201 { public_id, order_number }; 409 when it cannot be placed as it is, with errors by field and data: {reason,
     * quote}, as /mart/order answers; 422 when the request is not well formed.
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return $this->withCounter('v1/client/counter/order', function (array $business, array $store, string $language, array $input): ResponseInterface {
            [$cart, $choice, $errors] = $this->checkoutInput($input, true);
            [$customer, $delivery, $more] = $this->customerInput($input, true);
            $errors += $more;
            if ($cart === [] && ! isset($errors['lines'])) {
                $errors['lines'] = 'required';
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('CounterOrder.invalidFields'), 422, $errors);
            }
            $choice['counter'] = $this->counterChoice($store, $input) + ['actor' => $this->actorId()];
            try {
                $placed = model(MartOrderModel::class)->place($business, $language, $cart, $choice, $customer, $delivery, self::now());
            } catch (OrderRefusedException $e) {
                return service('response')->setStatusCode(409)->setJSON(['status' => 'error', 'message' => lang('CounterOrder.refused'),
                    'errors' => $e->errors, 'data' => ['reason' => $e->reason, 'quote' => $e->quote]]);
            }
            service('martCache')->forget((int) $business['id']);
            OrderMailer::placed($placed['id'], $placed['key']);

            return ApiResponse::success(['public_id' => $placed['public_id'], 'order_number' => $placed['order_number']], 201, lang('CounterOrder.created'));
        });
    }

    /**
     * The counter choice of MartOrderModel: the store, and whether the products are handed over and the total paid now
     */
    private function counterChoice(array $store, array $input): array
    {
        $no = static fn (mixed $value): bool => in_array($value, [false, 0, '0', 'N', 'n', 'false'], true);

        return [
            'store_id'    => (int) $store['id'],
            'handed_over' => ! $no($input['handed_over'] ?? true),
            'paid_now'    => in_array($input['paid_now'] ?? false, [true, 1, '1', 'Y', 'y', 'true'], true),
        ];
    }

    /**
     * Run $answer with the business (business_master), the counter's store (business_store) and the language, when the
     * signed-in client is active in the business and the store is a physical one of it
     */
    private function withCounter(string $endpoint, callable $answer): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('CounterOrder.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'CounterOrder', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        try {
            $store = model(BusinessStoreModel::class)->findByPublicId($input['store_public_id'] ?? null);
            if ($store === null || (int) $store['business_id'] !== (int) $business['id'] || $store['store_type'] !== BusinessStoreModel::TYPE_PHYSICAL) {
                return ApiResponse::error(lang('CounterOrder.invalidFields'), 422, ['store_public_id' => lang('CounterOrder.unknownStore')]);
            }
            $row        = model(BusinessMasterModel::class)->find((int) $business['id']);
            [$language] = model(MartCatalogModel::class)->languageOf((int) $business['id'], (string) $this->request->getLocale());

            return $answer($row, $store, $language, $input);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('CounterOrder.loadFailed'), 500);
        }
    }

    /** Now, which a test can set (Time::setTestNow()) */
    private static function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(Time::now('UTC'));
    }

    /**
     * auth_master.id of the signed-in account, for ManagedBusinessTrait and created_by
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
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
