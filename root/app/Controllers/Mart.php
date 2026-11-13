<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BookingWindow;
use App\Libraries\MartCache;
use App\Models\ArtifactMasterModel;
use App\Models\BusinessMasterModel;
use App\Models\MartCatalogModel;
use App\Models\MartSessionModel;
use App\Models\MartSlotModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Mart as MartConfig;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The public pages of a business on the marketplace (otternova_mart): no sign-in needed, as the visitors are anyone.
 * GET only, every one with business_slug and lang in the query string; lang is a language_code, and the answer is in
 * the business's website language nearest to it (MartCatalogModel::languageOf()), named in the answer's "language".
 * Answers come from the cache (App\Libraries\MartCache) when they can; App\Filters\MartCacheRefresh clears the
 * business's when the Client app changes it.
 * - /mart/site:      the business and how its pages look
 * - /mart/artifacts: product cards, by public_ids, category or type
 * - /mart/artifact:  one product or service with its variants, options, images and reviews
 * - /mart/slots:     the times an appointment may be booked at, on one day (never cached)
 * - /mart/sessions:  the sessions of a group service that may be booked (never cached)
 */
class Mart extends BaseController
{
    /** listLanguages() */
    protected $helpers = ['list'];

    /** A public_id: CHAR(12), see PublicIdTrait */
    private const PUBLIC_ID_PATTERN = '/^[0-9A-Za-z_-]{1,12}$/';

    /**
     * GET /mart/site?business_slug=&lang=
     * { language, languages: [{code, name}], business: {slug, name, description, country_code, currency_code,
     *   contact: {...}, audience: {...}, rating: {average, count, breakdown}}, theme: {colors: {...}, fonts: {heading,
     *   nav, default: {name, query} or null}, google_fonts_url, logo: {url, shape} or null}, meta: {title, description,
     *   keywords}, hero: {badge, headline, paragraph}, featured: {best_sellers, trending, highest_rated: [card]},
     *   categories: [{public_id, name, artifact_count}], stores: [{public_id, name, type, phone_number, address...}] }
     * A card is as in /mart/artifacts.
     * @return ResponseInterface
     */
    public function site(): ResponseInterface
    {
        return $this->answer('v1/mart/site', function (int $businessId, string $lang): ResponseInterface {
            $data = service('martCache')->remember($businessId, 'site', [$lang], function () use ($businessId, $lang): array {
                $catalog              = model(MartCatalogModel::class);
                [$language, $rows]    = $catalog->languageOf($businessId, $lang);
                $business             = model(BusinessMasterModel::class)->find($businessId);

                return $catalog->site($business, $language, $rows);
            });

            return ApiResponse::success($data);
        });
    }

    /**
     * GET /mart/artifacts?business_slug=&lang=&ids=&category=&type=&sort=&page=&per_page=
     * - ids:      public_ids, comma-separated, at most Config\Mart::$maxPerPage; the cards come in that order, all on
     *             one page, and the other filters are not used
     * - category: public_id of an active category of the business (404 when it is not one)
     * - type:     P (products) or S (services)
     * - sort:     name (the default), price_asc, price_desc, rating, newest
     * - page, per_page: from 1, and up to Config\Mart::$maxPerPage
     * { language, artifacts: [{public_id, type, name, label, category: {public_id, name} or null, price: {active,
     *   compare, varies}, rating: {average, count}, cover_url}], total, page, per_page, pages }
     * price.varies: the active variants have more than one price, so price.active is the lowest ("from").
     * @return ResponseInterface
     */
    public function artifacts(): ResponseInterface
    {
        return $this->answer('v1/mart/artifacts', function (int $businessId, string $lang): ResponseInterface {
            $config = config(MartConfig::class);
            [$query, $errors] = $this->listQuery($config);
            if ($errors !== []) {
                return ApiResponse::error(lang('Mart.invalidQuery'), 422, $errors);
            }

            $data = service('martCache')->remember($businessId, 'artifacts', [$lang, $query], function () use ($businessId, $lang, $query): ?array {
                $catalog       = model(MartCatalogModel::class);
                [$language]    = $catalog->languageOf($businessId, $lang);
                if (isset($query['ids'])) {
                    $cards = $catalog->cardsByPublicIds($businessId, $language, $query['ids']);

                    return ['language' => $language, 'artifacts' => $cards, 'total' => count($cards), 'page' => 1, 'per_page' => count($query['ids']), 'pages' => 1];
                }
                if (isset($query['category'])) {
                    $categoryId = $catalog->categoryIdOf($businessId, $query['category']);
                    if ($categoryId === null) {
                        return null;
                    }
                    $query['category_id'] = $categoryId;
                }

                return ['language' => $language] + $catalog->cards($businessId, $language, $query);
            });

            return $data === null ? ApiResponse::error(lang('Mart.categoryNotFound'), 404) : ApiResponse::success($data);
        });
    }

    /**
     * GET /mart/artifact?business_slug=&lang=&public_id=
     * { language, artifact: {card, with rating.breakdown, description, tax: {calculation, percentage}},
     *   options: [{public_id, name, values: [{public_id, value, color_code}]}] (the attributes a variant is chosen by;
     *   color_code: #rrggbb or null),
     *   variants: [{public_id, sku, price: {active, compare}, attribute_values: [{attribute_public_id,
     *   value_public_id}], weight_grams, duration_minutes, booking_type, capacity, stock}],
     *   images: [{public_id, url, variant_public_id}] (the cover first), reviews: [{public_id, rating, title, message,
     *   reviewer_name, created_at, reply: {message, replied_at} or null}] (the newest approved ones) }
     * Only an active artifact, and only its active variants; 404 otherwise.
     * @return ResponseInterface
     */
    public function artifact(): ResponseInterface
    {
        return $this->answer('v1/mart/artifact', function (int $businessId, string $lang): ResponseInterface {
            $publicId = $this->request->getGet('public_id');
            if (! is_string($publicId) || ! preg_match(self::PUBLIC_ID_PATTERN, $publicId)) {
                return ApiResponse::error(lang('Mart.artifactNotFound'), 404);
            }

            $data = $this->artifactData($businessId, $lang, $publicId);

            return $data === null ? ApiResponse::error(lang('Mart.artifactNotFound'), 404) : ApiResponse::success($data);
        });
    }

    /**
     * GET /mart/slots?business_slug=&lang=&variant=&store=&date=&staff=
     * The times a customer may book an appointment (a variant with booking_type A) at one store, on one day; never
     * cached.
     * - variant: public_id of the variant (404 when it is not an active appointment variant of an active service)
     * - store:   public_id of one of the stores; the first of them by name when not given
     * - date:    Y-m-d, a business day of the store in its window: the booking rule (the variant's
     *            booking_horizon_days and notice, else the business's) counted in the store's timezone. When not
     *            given, the first day of the window with a free time (looking Config\Mart::$firstDateSearchDays days
     *            ahead), else the window's first day
     * - staff:   public_id of one of the store's people (auth_master's); anyone when not given
     * { language, service: {public_id, name, cover_url, variant: {public_id, label, duration_minutes, staff_required,
     *   price: {active, compare}}}, rule: {horizon_days, notice_unit, notice_value, notice_time},
     *   store (the public_id answered for, null when no store can take a booking), window: {first_date, last_date} of
     *   that store or null, date (null with no window),
     *   stores: [{public_id, name, address: {line_1, line_2, city, subdivision_code, postal_code}, phone_number,
     *   timezone, first_date, last_date}],
     *   staff: [{public_id, name, restrictions: {excluded_genders: [M|F|NB], age_min, age_max} or null}] (the store's),
     *   slots: [{store_public_id, starts_at, ends_at, time, end_time, staff: [public_id]}] }
     * starts_at and ends_at are in ISO 8601 with the store's offset, time and end_time HH:MM on its clock. A slot's
     * staff are whom the booking would hold: when one person is needed, a start comes once for each person free then
     * (by start, then name); a person's restrictions are to be told to the customer before booking them.
     * @return ResponseInterface
     */
    public function slots(): ResponseInterface
    {
        return $this->answer('v1/mart/slots', function (int $businessId, string $lang): ResponseInterface {
            $publicId = $this->request->getGet('variant');
            $model    = model(MartSlotModel::class);
            $variant  = is_string($publicId) && preg_match(self::PUBLIC_ID_PATTERN, $publicId) ? $model->bookableVariant($businessId, $publicId) : null;
            $artifact = $variant === null ? null : $this->artifactData($businessId, $lang, $variant['artifact_public_id']);
            if ($artifact === null) {
                return ApiResponse::error(lang('Mart.variantNotBookable'), 404);
            }

            $get      = fn (string $name): string => is_string($value = $this->request->getGet($name)) ? trim($value) : '';
            $business = model(BusinessMasterModel::class)->find($businessId);
            $rule     = BookingWindow::effective($business, $variant);
            $now      = DateTimeImmutable::createFromInterface(Time::now('UTC'));
            $stores   = [];
            foreach ($model->storesFor($businessId, $variant['artifact_id'], $artifact['language']) as $store) {
                $dates = MartSlotModel::datesOf($rule, new DateTimeZone($store['timezone']), $now);
                if ($dates !== null) {
                    $stores[$store['public_id']] = $store + $dates;
                }
            }

            $errors = [];
            $store  = $stores === [] ? null : reset($stores);
            if (($storePublicId = $get('store')) !== '') {
                if (! isset($stores[$storePublicId])) {
                    $errors['store'] = lang('Mart.storeNotFound');
                } else {
                    $store = $stores[$storePublicId];
                }
            }
            $staff = [];
            foreach ($store['staff'] ?? [] as $clientId => $person) {
                $staff[$person['public_id']] = ['client_id' => $clientId] + $person;
            }
            $staffId = null;
            if (($staffPublicId = $get('staff')) !== '') {
                if (! isset($staff[$staffPublicId])) {
                    $errors['staff'] = lang('Mart.staffNotFound');
                } else {
                    $staffId = $staff[$staffPublicId]['client_id'];
                }
            }
            $window = $store === null ? null : ['first_date' => $store['first_date'], 'last_date' => $store['last_date']];
            $date   = $get('date');
            if ($date !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                $errors['date'] = lang('Mart.invalidDate');
            } elseif ($date !== '' && $window !== null && ($date < $window['first_date'] || $date > $window['last_date'])) {
                $errors['date'] = lang('Mart.dateOutsideWindow', [$window['first_date'], $window['last_date']]);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Mart.invalidQuery'), 422, $errors);
            }

            $slots = [];
            if ($store === null) {
                $date = null;
            } elseif ($date !== '') {
                $slots = $model->slotsOn($business, $variant, $store, $date, $staffId, $now);
            } else {
                // the first day with a free time, so the customer starts on one they can book
                $day  = new DateTimeImmutable($window['first_date']);
                $last = min(new DateTimeImmutable($window['last_date']), $day->modify('+' . (config(MartConfig::class)->firstDateSearchDays - 1) . ' days'));
                $date = $window['first_date'];
                for (; $day <= $last; $day = $day->modify('+1 day')) {
                    $slots = $model->slotsOn($business, $variant, $store, $day->format('Y-m-d'), $staffId, $now);
                    if ($slots !== []) {
                        $date = $day->format('Y-m-d');
                        break;
                    }
                }
            }

            return ApiResponse::success([
                'language' => $artifact['language'],
                'service'  => self::service($artifact, $variant['public_id'], ['duration_minutes' => $variant['duration_minutes'], 'staff_required' => $variant['staff_required']]),
                'rule'   => ['horizon_days' => $rule['booking_horizon_days'], 'notice_unit' => $rule['booking_notice_unit'],
                    'notice_value' => $rule['booking_notice_value'], 'notice_time' => $rule['booking_notice_time'] === null ? null : substr($rule['booking_notice_time'], 0, 5)],
                'store'  => $store['public_id'] ?? null,
                'window' => $window,
                'date'   => $date,
                'stores' => array_values(array_map(static fn (array $s): array => ['public_id' => $s['public_id'], 'name' => $s['name'], 'address' => $s['address'],
                    'phone_number' => $s['phone_number'], 'timezone' => $s['timezone'], 'first_date' => $s['first_date'], 'last_date' => $s['last_date']], $stores)),
                'staff'  => array_values(array_map(static fn (array $p): array => ['public_id' => $p['public_id'], 'name' => $p['name'],
                    'restrictions' => $p['restrictions']], $staff)),
                'slots'  => $slots,
            ]);
        });
    }

    /**
     * GET /mart/sessions?business_slug=&lang=&variant=&store=&staff=&date_from=&date_to=
     * The sessions of a group service (a variant with booking_type G) a customer may book now: open, at a physical
     * store, with a scheduled slot, a seat left, and their booking not closed (booking_closes_at, else their start, is
     * after now); never cached. A customer books a whole session, every slot of it.
     * - variant:   public_id of the variant (404 when it is not an active group variant of an active service)
     * - store:     public_id of one of the stores; every store when not given
     * - staff:     public_id of one of the people the sessions (at the store, when given) hold; anyone when not given
     * - date_from, date_to: Y-m-d, either or both; the sessions starting on those days, on the store's clock (a
     *              session lasting days is found by its first)
     * { language, service: {public_id, name, cover_url, variant: {public_id, label, duration_minutes, capacity,
     *   price: {active, compare}}},
     *   stores: [{public_id, name, address: {line_1, line_2, city, subdivision_code, postal_code}, phone_number,
     *   timezone}] (those with a session to book, by name; the name and address in the answer's language where it has
     *   them),
     *   staff: [{public_id, name, restrictions: {excluded_genders: [M|F|NB], age_min, age_max} or null}] (whom the
     *   sessions of the store, or of every store, hold; by name),
     *   sessions: [{public_id, name, description, store_public_id, starts_at, ends_at, booking_closes_at, capacity,
     *   seats_booked, seats_available, staff: [public_id], slots: [{slot_number, starts_at, ends_at, staff: [public_id]}]}] }
     * The datetimes are in ISO 8601 with the store's offset; sessions by start, slots by start. The filters leave out
     * sessions, never stores or people, so the lists can still be chosen from.
     * @return ResponseInterface
     */
    public function sessions(): ResponseInterface
    {
        return $this->answer('v1/mart/sessions', function (int $businessId, string $lang): ResponseInterface {
            $publicId = $this->request->getGet('variant');
            $model    = model(MartSessionModel::class);
            $variant  = is_string($publicId) && preg_match(self::PUBLIC_ID_PATTERN, $publicId) ? $model->groupVariant($businessId, $publicId) : null;
            $artifact = $variant === null ? null : $this->artifactData($businessId, $lang, $variant['artifact_public_id']);
            if ($artifact === null) {
                return ApiResponse::error(lang('Mart.variantNotGroup'), 404);
            }

            $get   = fn (string $name): string => is_string($value = $this->request->getGet($name)) ? trim($value) : '';
            $now   = DateTimeImmutable::createFromInterface(Time::now('UTC'));
            $found = $model->bookable($businessId, $variant['id'], $artifact['language'], $now);

            $errors  = [];
            $storeId = null;
            if (($storePublicId = $get('store')) !== '') {
                $storeId = self::idOf($found['stores'], $storePublicId);
                if ($storeId === null) {
                    $errors['store'] = lang('Mart.storeNotFound');
                }
            }
            // the people of the store's sessions, or of every store's
            $inStore = $storeId === null ? $found['sessions'] : array_filter($found['sessions'], static fn (array $s): bool => $s['store_id'] === $storeId);
            $held    = array_unique(array_merge([], ...array_column($inStore, 'staff')));
            $staff   = array_filter($found['staff'], static fn (int $clientId): bool => in_array($clientId, $held, true), ARRAY_FILTER_USE_KEY);
            $staffId = null;
            if (($staffPublicId = $get('staff')) !== '') {
                $staffId = self::idOf($staff, $staffPublicId);
                if ($staffId === null) {
                    $errors['staff'] = lang('Mart.staffNotFound');
                }
            }
            $dates = [];
            foreach (['date_from', 'date_to'] as $name) {
                $date = $get($name);
                if ($date === '') {
                    continue;
                }
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                    $errors[$name] = lang('Mart.invalidDate');
                } else {
                    $dates[$name] = $date;
                }
            }
            if (isset($dates['date_from'], $dates['date_to']) && $dates['date_to'] < $dates['date_from']) {
                $errors['date_to'] = lang('Mart.dateToBeforeFrom');
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Mart.invalidQuery'), 422, $errors);
            }

            $sessions = array_filter($inStore, static fn (array $s): bool => ($staffId === null || in_array($staffId, $s['staff'], true))
                && (! isset($dates['date_from']) || $s['local_start_date'] >= $dates['date_from'])
                && (! isset($dates['date_to']) || $s['local_start_date'] <= $dates['date_to']));
            $ref = static fn (array $clientIds): array => array_values(array_map(static fn (int $id): string => $found['staff'][$id]['public_id'], $clientIds));

            return ApiResponse::success([
                'language' => $artifact['language'],
                'service'  => self::service($artifact, $variant['public_id'], ['duration_minutes' => $variant['duration_minutes'], 'capacity' => $variant['capacity']]),
                'stores'   => array_values($found['stores']),
                'staff'    => array_values($staff),
                'sessions' => array_values(array_map(static fn (array $s): array => [
                    'public_id'         => $s['public_id'],
                    'name'              => $s['name'],
                    'description'       => $s['description'],
                    'store_public_id'   => $found['stores'][$s['store_id']]['public_id'],
                    'starts_at'         => $s['starts_at'],
                    'ends_at'           => $s['ends_at'],
                    'booking_closes_at' => $s['booking_closes_at'],
                    'capacity'          => $s['capacity'],
                    'seats_booked'      => $s['seats_booked'],
                    'seats_available'   => $s['seats_available'],
                    'staff'             => $ref($s['staff']),
                    'slots'             => array_map(static fn (array $slot): array => array_merge($slot, ['staff' => $ref($slot['staff'])]), $s['slots']),
                ], $sessions)),
            ]);
        });
    }

    /**
     * The key of the row with the public_id
     * @param array<int, array{public_id: string}> $rows id => row
     * @return int|null
     */
    private static function idOf(array $rows, string $publicId): ?int
    {
        return array_key_first(array_filter($rows, static fn (array $row): bool => $row['public_id'] === $publicId));
    }

    /**
     * The service and the variant being booked, as /mart/slots and /mart/sessions send them
     * @param array  $artifact        artifactData()
     * @param string $variantPublicId
     * @param array  $extra           More of the variant
     * @return array { public_id, name, cover_url, variant: {public_id, label, ...$extra, price: {active, compare} or null} }
     */
    private static function service(array $artifact, string $variantPublicId, array $extra): array
    {
        $card   = $artifact['artifact'];
        $values = [];
        foreach ($artifact['options'] as $option) {
            foreach ($option['values'] as $value) {
                $values[$value['public_id']] = $value['value'];
            }
        }
        $row = current(array_filter($artifact['variants'], static fn (array $v): bool => $v['public_id'] === $variantPublicId)) ?: null;

        return [
            'public_id' => $card['public_id'],
            'name'      => $card['name'],
            'cover_url' => $card['cover_url'],
            'variant'   => [
                'public_id' => $variantPublicId,
                'label'     => implode(' / ', array_filter(array_map(static fn (array $v): ?string => $values[$v['value_public_id']] ?? null, $row['attribute_values'] ?? []))),
            ] + $extra + ['price' => $row['price'] ?? null],
        ];
    }

    /**
     * /mart/artifact's answer, from the cache when it can
     * @return array|null null when there is no such active artifact
     */
    private function artifactData(int $businessId, string $lang, string $publicId): ?array
    {
        return service('martCache')->remember($businessId, 'artifact', [$lang, $publicId], function () use ($businessId, $lang, $publicId): ?array {
            $catalog    = model(MartCatalogModel::class);
            [$language] = $catalog->languageOf($businessId, $lang);
            $artifact   = $catalog->artifact($businessId, $language, $publicId);

            return $artifact === null ? null : ['language' => $language] + $artifact;
        });
    }

    /**
     * Finds the business and the asked language, then answers with $answer; a failure is logged and answered 500
     * @param string                                     $endpoint for the error log
     * @param callable(int, string): ResponseInterface   $answer   given the business id and the asked lang ('' for none)
     * @return ResponseInterface
     */
    private function answer(string $endpoint, callable $answer): ResponseInterface
    {
        $slug = $this->request->getGet('business_slug');
        if (! is_string($slug) || trim($slug) === '') {
            return ApiResponse::error(lang('Mart.slugRequired'), 400);
        }
        $lang = $this->request->getGet('lang');
        $lang = is_string($lang) && array_key_exists($lang, listLanguages()) ? $lang : '';

        try {
            $businessId = service('martCache')->businessIdOf(trim($slug));
            if ($businessId === null) {
                return ApiResponse::error(lang('Mart.businessNotFound'), 404);
            }

            return $answer($businessId, $lang);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Mart.loadFailed'), 500);
        }
    }

    /**
     * The checked filters of /mart/artifacts, in a fixed shape so equal queries share a cache key
     * @param MartConfig $config
     * @return array{0: array, 1: array<string, string>} [query, errors by parameter]
     */
    private function listQuery(MartConfig $config): array
    {
        $get    = fn (string $name): string => is_string($value = $this->request->getGet($name)) ? trim($value) : '';
        $errors = [];

        $ids = $get('ids');
        if ($ids !== '') {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $ids)), static fn (string $id): bool => $id !== '')));
            if (count($list) > $config->maxPerPage) {
                $errors['ids'] = lang('Mart.tooManyIds', [$config->maxPerPage]);
            } elseif ($list === [] || array_filter($list, static fn (string $id): bool => ! preg_match(self::PUBLIC_ID_PATTERN, $id)) !== []) {
                $errors['ids'] = lang('Mart.invalidIds');
            }

            return [['ids' => $list], $errors];
        }

        $query = ['sort' => $get('sort') === '' ? 'name' : $get('sort')];
        if (! array_key_exists($query['sort'], MartCatalogModel::SORTS)) {
            $errors['sort'] = lang('Mart.invalidSort');
        }
        $type = $get('type');
        if ($type !== '') {
            if (! in_array($type, [ArtifactMasterModel::TYPE_PRODUCT, ArtifactMasterModel::TYPE_SERVICE], true)) {
                $errors['type'] = lang('Mart.invalidType');
            }
            $query['type'] = $type;
        }
        $category = $get('category');
        if ($category !== '') {
            if (! preg_match(self::PUBLIC_ID_PATTERN, $category)) {
                $errors['category'] = lang('Mart.categoryNotFound');
            }
            $query['category'] = $category;
        }
        foreach (['page' => [1, PHP_INT_MAX, 1], 'per_page' => [1, $config->maxPerPage, $config->defaultPerPage]] as $name => [$min, $max, $default]) {
            $value = $get($name);
            if ($value === '') {
                $query[$name] = $default;
            } elseif (ctype_digit($value) && strlen($value) <= 9 && (int) $value >= $min && (int) $value <= $max) {
                $query[$name] = (int) $value;
            } else {
                $errors[$name] = $name === 'page' ? lang('Mart.invalidPage') : lang('Mart.invalidPerPage', [$max]);
            }
        }
        ksort($query);

        return [$query, $errors];
    }
}
