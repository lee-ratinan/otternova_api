<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ArtifactMasterModel;
use App\Models\ArtifactVariantModel;
use App\Models\BusinessServiceResourceLinkModel;
use App\Models\BusinessStoreModel;
use App\Models\ServiceSessionModel;
use App\Models\SlotConflictException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The sessions of a business's group services (service_session): what customers book whole, the slots it meets in,
 * and the people and resources each slot holds. The Client app's, behind the jwt:C filter, and only for the active
 * owners and managers of the business. A business comes in by its business_slug, a person by their uid, everything
 * else by its public_id.
 *
 * Datetimes come in as "YYYY-MM-DDTHH:MM" (seconds optional) in the store's store_timezone, or with a Z or an offset
 * ("+07:00"); they go out in UTC (starts_at) and in the store's timezone (local_starts_at, as a datetime-local input
 * takes it).
 *
 * A slot of an open or closed session holds at least the variant's staff_required people and, of each type the
 * service needs, at least its quantity of resources; a draft may hold less until it is opened. Nobody and nothing is
 * held by two sessions at once: that is checked when saving, under a lock, and refused with the slot's error. Working
 * hours are not checked: a class may run outside the shifts and the opening hours.
 */
class ServiceSessions extends BaseController
{
    use ManagedBusinessTrait;
    use TranslationInputTrait;

    private const NAME_MAX_LENGTH        = 255;
    private const DESCRIPTION_MAX_LENGTH = 10000;
    private const MAX_CAPACITY           = 65535;
    private const MAX_SLOTS              = 200;
    private const MAX_SLOT_HOURS         = 24;
    private const PERIODS                = ['upcoming', 'past', 'all'];

    /** "2026-11-14T10:00", seconds optional, a space for the T, and a Z or an offset optional */
    private const MOMENT_PATTERN = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?(Z|[+-]\d{2}:\d{2})?$/';

    /**
     * POST /client/sessions { business_slug, store_public_id, artifact_public_id, period }
     * The sessions of that business, of one store and one service when given, by when they start: for each,
     * public_id, session_name, capacity, seats_booked, slot_count (scheduled slots), session_status, starts_at,
     * ends_at (and local_ ones), store { public_id, store_name, store_timezone }, artifact { public_id,
     * artifact_name } and variant { public_id }. period: upcoming (not over yet, the default), past or all.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/sessions';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ServiceSession.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ServiceSession');
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $period     = is_string($input['period'] ?? null) && in_array($input['period'], self::PERIODS, true) ? $input['period'] : 'upcoming';
        $errors     = [];
        try {
            $storeId = $artifactId = null;
            if (($input['store_public_id'] ?? null) !== null) {
                $store   = $this->ofBusiness(model(BusinessStoreModel::class)->findByPublicId($input['store_public_id']), $businessId);
                $storeId = $store === null ? null : (int) $store['id'];
                if ($store === null) {
                    $errors['store_public_id'] = lang('ServiceSession.unknownStore');
                }
            }
            if (($input['artifact_public_id'] ?? null) !== null) {
                $artifact   = $this->ofBusiness(model(ArtifactMasterModel::class)->findByPublicId($input['artifact_public_id']), $businessId);
                $artifactId = $artifact === null ? null : (int) $artifact['id'];
                if ($artifact === null) {
                    $errors['artifact_public_id'] = lang('ServiceSession.unknownService');
                }
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('ServiceSession.invalidFields'), 422, $errors);
            }
            $rows = model(ServiceSessionModel::class)->listOfBusiness($businessId, $storeId, $artifactId, $period, Time::now('UTC')->toDateTimeString());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ServiceSession.loadFailed');
        }

        return ApiResponse::success(['sessions' => $rows]);
    }

    /**
     * POST /client/session { public_id }
     * One session: session { public_id, session_name, session_description, capacity, seats_booked, session_status,
     * starts_at, ends_at, booking_closes_at (and local_ ones), store, artifact, variant { public_id, capacity,
     * staff_required, duration_minutes }, translations }, and slots by starts_at [{ slot_number, slot_status,
     * starts_at, ends_at (and local_ ones), staff: [{ uid, full_name }], resources: [{ public_id, resource_name,
     * type_public_id, type_name }] }]
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/session';
        [$session, , $error] = $this->managedSession($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $detail = model(ServiceSessionModel::class)->detail($session);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ServiceSession.loadFailed');
        }

        return ApiResponse::success($detail);
    }

    /**
     * POST /client/session/options { business_slug, variant_public_id, store_public_id }
     * What a session form offers: variants, the group variants of the business's services that are not discontinued
     * [{ public_id, artifact { public_id, artifact_name, artifact_status }, attribute_values, capacity,
     * staff_required, duration_minutes, variant_status }], and stores, its physical stores [{ public_id, store_name,
     * store_timezone }]. With a variant and a store, also what a slot of it there may hold: staff [{ uid, full_name }]
     * (allowed to perform the service, active at the store), resources [{ public_id, resource_name, capacity,
     * type_public_id, type_name }] (active, of the types the service needs) and needs [{ public_id (of the type),
     * type_name, type_status, quantity }].
     * @return ResponseInterface
     */
    public function options(): ResponseInterface
    {
        $endpoint = 'v1/client/session/options';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('ServiceSession.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ServiceSession');
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $sessions   = model(ServiceSessionModel::class);
        try {
            $data = [
                'variants' => $sessions->groupVariantsOf($businessId),
                'stores'   => $this->physicalStores($businessId),
            ];
            $variant = ($input['variant_public_id'] ?? null) === null ? null : $this->ofBusiness(model(ArtifactVariantModel::class)->findByPublicId($input['variant_public_id']), $businessId);
            $store   = ($input['store_public_id'] ?? null) === null ? null : $this->ofBusiness(model(BusinessStoreModel::class)->findByPublicId($input['store_public_id']), $businessId);
            if ($variant !== null && $store !== null) {
                $artifactId        = (int) $variant['artifact_id'];
                $data['staff']     = array_values(array_map(static fn (array $p): array => ['uid' => $p['uid'], 'full_name' => $p['full_name']],
                    $sessions->eligibleStaff($businessId, $artifactId, (int) $store['id'])));
                $data['resources'] = array_values(array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['public_id', 'resource_name', 'capacity', 'type_public_id', 'type_name'])),
                    $sessions->eligibleResources($businessId, $artifactId, (int) $store['id'])));
                $data['needs']     = model(BusinessServiceResourceLinkModel::class)->needsOf($businessId, $artifactId);
            }
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ServiceSession.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/session/create { business_slug, session: { variant_public_id, store_public_id, session_name,
     * session_description, capacity, booking_closes_at, session_status }, translations: {...}, slots: [{ starts_at,
     * ends_at, staff: [uid, ...], resources: [public_id, ...] }] }
     * - variant: a group variant (booking_type G) of a service of the business that is not discontinued, on sale;
     *   store: a physical store of it. Both are fixed once created.
     * - capacity 1-65535, the variant's when left out; session_status D (the default), O, C or X
     * - slots: 0-200, numbered in the order they meet; each ends after it starts and within 24 hours, and the slots
     *   of a session do not overlap. An open or closed session needs at least one.
     * @return ResponseInterface The session, as show() gives it
     */
    public function create(): ResponseInterface
    {
        return $this->save(false);
    }

    /**
     * POST /client/session/edit { public_id, session: {...}, translations: {...}, slots: [{ slot_number, starts_at,
     * ends_at, slot_status, staff, resources }] }
     * A field left out of "session" keeps its value; the variant and the store cannot change. "translations", when
     * given, replaces the texts. "slots", when given, is every slot: one with a slot_number is that stored slot, one
     * without is added with the next number, and a stored slot left out is cancelled (slot_status X; it holds
     * nothing). A cancelled session cannot change any more.
     * @return ResponseInterface The session, as show() gives it
     */
    public function edit(): ResponseInterface
    {
        return $this->save(true);
    }

    /**
     * create() and edit()
     * @param bool $edit
     * @return ResponseInterface
     */
    private function save(bool $edit): ResponseInterface
    {
        $endpoint = $edit ? 'v1/client/session/edit' : 'v1/client/session/create';
        $sessions = model(ServiceSessionModel::class);
        $stored   = null;
        if ($edit) {
            [$stored, $input, $error] = $this->managedSession($endpoint);
            if ($error !== null) {
                return $error;
            }
            if ($stored['session_status'] === ServiceSessionModel::STATUS_CANCELLED) {
                return ApiResponse::error(lang('ServiceSession.cancelledFixed'), 409);
            }
            $businessId = (int) $stored['business_id'];
        } else {
            $input = $this->jsonInput();
            if ($input === null) {
                return ApiResponse::error(lang('ServiceSession.invalidRequest'), 422);
            }
            [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'ServiceSession');
            if ($error !== null) {
                return $error;
            }
            $businessId = (int) $business['id'];
        }
        $in = $input['session'] ?? [];
        if (! is_array($in)) {
            return ApiResponse::error(lang('ServiceSession.invalidRequest'), 422);
        }

        try {
            [$variant, $store, $errors] = $stored === null ? $this->placeOf($businessId, $in) : $this->storedPlace($stored, $in);
            if ($errors !== []) {
                return ApiResponse::error(lang('ServiceSession.invalidFields'), 422, $errors);
            }
            $zone       = new DateTimeZone($store['store_timezone'] ?: 'UTC');
            $artifactId = (int) $variant['artifact_id'];
            [$session, $errors] = $this->sessionIn($in, $stored, $variant, $zone);

            $names = null;
            if (array_key_exists('translations', $input) || ! $edit) {
                [$names, $nameErrors] = $this->translationsIn($input['translations'] ?? [], ['session_name' => self::NAME_MAX_LENGTH, 'session_description' => self::DESCRIPTION_MAX_LENGTH]);
                $errors += $nameErrors;
            }

            $staff     = $sessions->eligibleStaff($businessId, $artifactId, (int) $store['id']);
            $resources = $sessions->eligibleResources($businessId, $artifactId, (int) $store['id']);
            $needs     = model(BusinessServiceResourceLinkModel::class)->quantitiesOf($businessId, $artifactId);
            $storedSlots = $stored === null ? [] : $sessions->slotsOf((int) $stored['id']);
            if ($stored !== null) {
                // what the session holds already may stay held, even if it would no longer be offered
                [$staff, $resources] = $this->withHeld($storedSlots, $staff, $resources, $businessId);
            }

            $slots = null;
            if (array_key_exists('slots', $input) || ! $edit) {
                [$slots, $slotErrors] = $this->slotsIn($input['slots'] ?? [], $storedSlots, $staff, $resources, $zone);
                $errors += $slotErrors;
            }
            // a session being cancelled releases what it holds, so what it holds is not checked
            if ($errors === [] && $session['session_status'] !== ServiceSessionModel::STATUS_CANCELLED) {
                $status = $session['session_status'];
                $check  = $slots ?? $this->storedAsInput($storedSlots, $resources);
                $errors = $this->holdErrors($check, $status, $session['capacity'], (int) ($variant['staff_required'] ?? 1), $needs);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('ServiceSession.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                $publicId = $sessions->createSession(['business_id' => $businessId, 'store_id' => (int) $store['id'], 'artifact_id' => $artifactId,
                    'variant_id' => (int) $variant['id']] + $session, $names ?? [], $slots ?? [], $this->actorId());
                $saved = $sessions->findByPublicId($publicId);
            } else {
                $sessions->updateSession($stored, $session, $names, $slots, $this->actorId());
                $saved = $sessions->find((int) $stored['id']);
            }
            $detail = $sessions->detail($saved);
        } catch (SlotConflictException $e) {
            return ApiResponse::error(lang('ServiceSession.heldConflict'), 409, $e->errors);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'ServiceSession.saveFailed');
        }

        return ApiResponse::success($detail, 200, lang($edit ? 'ServiceSession.sessionUpdated' : 'ServiceSession.sessionCreated'));
    }

    /**
     * The variant and store of a new session, checked
     * @param int   $businessId
     * @param array $in
     * @return array{0: array|null, 1: array|null, 2: array<string, string>}
     */
    private function placeOf(int $businessId, array $in): array
    {
        $errors  = [];
        $variant = $this->ofBusiness(model(ArtifactVariantModel::class)->findByPublicId($in['variant_public_id'] ?? null), $businessId);
        $artifact = $variant === null ? null : model(ArtifactMasterModel::class)->find((int) $variant['artifact_id']);
        if ($variant === null || $artifact === null || $artifact['artifact_type'] !== ArtifactMasterModel::TYPE_SERVICE) {
            $errors['variant_public_id'] = lang('ServiceSession.unknownVariant');
        } elseif ($variant['booking_type'] !== ArtifactMasterModel::BOOKING_GROUP) {
            $errors['variant_public_id'] = lang('ServiceSession.groupOnly');
        } elseif ($artifact['artifact_status'] === ArtifactMasterModel::STATUS_DISCONTINUED || $variant['variant_status'] !== 'A') {
            $errors['variant_public_id'] = lang('ServiceSession.notOnSale');
        }
        $store = $this->ofBusiness(model(BusinessStoreModel::class)->findByPublicId($in['store_public_id'] ?? null), $businessId);
        if ($store === null) {
            $errors['store_public_id'] = lang('ServiceSession.unknownStore');
        } elseif ($store['store_type'] !== BusinessStoreModel::TYPE_PHYSICAL) {
            $errors['store_public_id'] = lang('ServiceSession.physicalStoreOnly');
        }

        return [$variant, $store, $errors];
    }

    /**
     * The variant and store of a stored session; a request naming others is refused
     * @param array $stored
     * @param array $in
     * @return array{0: array, 1: array, 2: array<string, string>}
     */
    private function storedPlace(array $stored, array $in): array
    {
        $variant = model(ArtifactVariantModel::class)->find((int) $stored['variant_id']);
        $store   = model(BusinessStoreModel::class)->find((int) $stored['store_id']);
        $errors  = [];
        foreach (['variant_public_id' => $variant, 'store_public_id' => $store] as $field => $row) {
            if (array_key_exists($field, $in) && $in[$field] !== $row['public_id']) {
                $errors[$field] = lang('ServiceSession.placeFixed');
            }
        }

        return [$variant, $store, $errors];
    }

    /**
     * The service_session columns of the request, checked; a field left out keeps its stored value
     * @param array        $in
     * @param array|null   $stored
     * @param array        $variant
     * @param DateTimeZone $zone
     * @return array{0: array, 1: array<string, string>}
     */
    private function sessionIn(array $in, ?array $stored, array $variant, DateTimeZone $zone): array
    {
        $errors  = [];
        $value   = static fn (string $field, mixed $default = null): mixed => array_key_exists($field, $in) ? $in[$field] : ($stored[$field] ?? $default);
        $session = [];

        $name = $value('session_name', '');
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $errors['session_name'] = lang('ServiceSession.nameRequired');
        } elseif (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            $errors['session_name'] = lang('ServiceSession.tooLong', [self::NAME_MAX_LENGTH]);
        }
        $session['session_name'] = $name;

        $description = $value('session_description');
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $errors['session_description'] = lang('ServiceSession.tooLong', [self::DESCRIPTION_MAX_LENGTH]);
        }
        $session['session_description'] = $description;

        $capacity = self::wholeNumber($value('capacity', $variant['capacity']));
        $booked   = (int) ($stored['seats_booked'] ?? 0);
        if ($capacity === null || $capacity < 1 || $capacity > self::MAX_CAPACITY) {
            $errors['capacity'] = lang('ServiceSession.invalidNumber', [1, self::MAX_CAPACITY]);
        } elseif ($capacity < $booked) {
            $errors['capacity'] = lang('ServiceSession.belowBooked', [$booked]);
        }
        $session['capacity'] = $capacity ?? 0;

        $status = $value('session_status', ServiceSessionModel::STATUS_DRAFT);
        $status = is_string($status) ? strtoupper(trim($status)) : '';
        if (! in_array($status, ServiceSessionModel::STATUSES, true)) {
            $errors['session_status'] = lang('ServiceSession.invalidStatus');
        }
        $session['session_status'] = $status;

        if (array_key_exists('booking_closes_at', $in)) {
            $closes = $this->moment($in['booking_closes_at'], $zone);
            if ($closes === false) {
                $errors['booking_closes_at'] = lang('ServiceSession.invalidMoment');
            }
            $session['booking_closes_at'] = is_string($closes) ? $closes : null;
        } elseif ($stored === null) {
            $session['booking_closes_at'] = null;
        }

        return [$session, $errors];
    }

    /**
     * The slots of the request, checked, in the order they meet: each { index, slot_number, starts_at, ends_at (UTC),
     * slot_status, staff: list<client_id>, resources: list<{ id, type_id, capacity, buffer_minutes }> }
     * @param mixed             $in
     * @param array<int, array> $storedSlots slot_number => slot
     * @param array             $staff       uid => person who may be held
     * @param array             $resources   public_id => resource that may be held
     * @param DateTimeZone      $zone
     * @return array{0: list<array>|null, 1: array<string, string>}
     */
    private function slotsIn(mixed $in, array $storedSlots, array $staff, array $resources, DateTimeZone $zone): array
    {
        if (! is_array($in) || ! array_is_list($in)) {
            return [null, ['slots' => lang('ServiceSession.invalidList')]];
        }
        if (count($in) > self::MAX_SLOTS) {
            return [null, ['slots' => lang('ServiceSession.tooManySlots', [self::MAX_SLOTS])]];
        }
        $errors  = [];
        $slots   = [];
        $numbers = [];
        foreach ($in as $i => $item) {
            $key = 'slots.' . $i;
            if (! is_array($item)) {
                $errors[$key] = lang('ServiceSession.invalidRequest');
                continue;
            }
            $slot = ['index' => $i, 'slot_number' => null];
            if (($item['slot_number'] ?? null) !== null) {
                $number = self::wholeNumber($item['slot_number']);
                if ($number === null || ! isset($storedSlots[$number])) {
                    $errors[$key . '.slot_number'] = lang('ServiceSession.unknownSlot');
                } elseif (isset($numbers[$number])) {
                    $errors[$key . '.slot_number'] = lang('ServiceSession.duplicateSlot');
                } else {
                    $numbers[$number]    = true;
                    $slot['slot_number'] = $number;
                }
            }
            $status = strtoupper(is_string($item['slot_status'] ?? null) ? trim($item['slot_status']) : ServiceSessionModel::SLOT_SCHEDULED);
            if (! in_array($status, [ServiceSessionModel::SLOT_SCHEDULED, ServiceSessionModel::SLOT_CANCELLED], true)) {
                $errors[$key . '.slot_status'] = lang('ServiceSession.invalidSlotStatus');
            }
            $slot['slot_status'] = $status;

            $starts = $this->moment($item['starts_at'] ?? null, $zone);
            $ends   = $this->moment($item['ends_at'] ?? null, $zone);
            if (! is_string($starts)) {
                $errors[$key . '.starts_at'] = lang('ServiceSession.invalidMoment');
            }
            if (! is_string($ends)) {
                $errors[$key . '.ends_at'] = lang('ServiceSession.invalidMoment');
            } elseif (is_string($starts) && ($ends <= $starts || strtotime($ends . ' UTC') - strtotime($starts . ' UTC') > self::MAX_SLOT_HOURS * 3600)) {
                $errors[$key . '.ends_at'] = lang('ServiceSession.invalidLength', [self::MAX_SLOT_HOURS]);
            }
            $slot['starts_at'] = is_string($starts) ? $starts : '';
            $slot['ends_at']   = is_string($ends) ? $ends : '';

            $slot['staff'] = [];
            foreach (is_array($item['staff'] ?? null) ? array_values($item['staff']) : [] as $uid) {
                $person = is_string($uid) ? ($staff[$uid] ?? null) : null;
                if ($person === null) {
                    $errors[$key . '.staff'] = lang('ServiceSession.unknownStaff');
                    continue;
                }
                $slot['staff'][$person['client_id']] = $person['client_id'];
            }
            $slot['staff'] = array_values($slot['staff']);
            $slot['resources'] = [];
            foreach (is_array($item['resources'] ?? null) ? array_values($item['resources']) : [] as $publicId) {
                $resource = is_string($publicId) ? ($resources[$publicId] ?? null) : null;
                if ($resource === null) {
                    $errors[$key . '.resources'] = lang('ServiceSession.unknownResource');
                    continue;
                }
                $slot['resources'][$resource['id']] = array_intersect_key($resource, array_flip(['id', 'type_id', 'capacity', 'buffer_minutes', 'resource_name']));
            }
            $slot['resources'] = array_values($slot['resources']);
            $slots[]           = $slot;
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        // in the order they meet, and the scheduled ones of a session never overlap
        usort($slots, static fn (array $a, array $b): int => [$a['starts_at'], $a['index']] <=> [$b['starts_at'], $b['index']]);
        $previous = null;
        foreach ($slots as $slot) {
            if ($slot['slot_status'] !== ServiceSessionModel::SLOT_SCHEDULED) {
                continue;
            }
            if ($previous !== null && $slot['starts_at'] < $previous['ends_at']) {
                $errors['slots.' . $slot['index'] . '.starts_at'] = lang('ServiceSession.slotsOverlap', [$previous['index'] + 1]);
            }
            $previous = $slot;
        }

        return [$errors === [] ? $slots : null, $errors];
    }

    /**
     * Whether each scheduled slot holds what it needs: for an open or closed session, at least staff_required people,
     * the needed quantity of each type, and a slot at all; for any session, resources of a type holding together at
     * least the session's capacity
     * @param list<array>     $slots
     * @param string          $status
     * @param int             $capacity
     * @param int             $staffRequired
     * @param array<int, int> $needs resource_type_id => quantity
     * @return array<string, string>
     */
    private function holdErrors(array $slots, string $status, int $capacity, int $staffRequired, array $needs): array
    {
        $errors   = [];
        $complete = in_array($status, [ServiceSessionModel::STATUS_OPEN, ServiceSessionModel::STATUS_CLOSED], true);
        $any      = false;
        foreach ($slots as $slot) {
            if ($slot['slot_status'] !== ServiceSessionModel::SLOT_SCHEDULED) {
                continue;
            }
            $any = true;
            $key = 'slots.' . $slot['index'];
            if ($complete && count($slot['staff']) < $staffRequired) {
                $errors[$key . '.staff'] = lang('ServiceSession.staffShort', [$staffRequired]);
            }
            $count = $room = [];
            foreach ($slot['resources'] as $resource) {
                $count[$resource['type_id']] = ($count[$resource['type_id']] ?? 0) + 1;
                $room[$resource['type_id']]  = ($room[$resource['type_id']] ?? 0) + $resource['capacity'];
            }
            foreach ($needs as $typeId => $quantity) {
                if ($complete && ($count[$typeId] ?? 0) < $quantity) {
                    $errors[$key . '.resources'] = lang('ServiceSession.resourcesShort');
                } elseif (isset($room[$typeId]) && $room[$typeId] < $capacity) {
                    $errors[$key . '.resources'] = lang('ServiceSession.roomTooSmall', [$room[$typeId], $capacity]);
                }
            }
        }
        if ($complete && ! $any) {
            $errors['slots'] = lang('ServiceSession.slotRequired');
        }

        return $errors;
    }

    /**
     * The stored slots as slotsIn() gives them, to check a session whose slots the request leaves as they are
     * @param array<int, array> $storedSlots
     * @param array             $resources public_id => resource, the held ones included
     * @return list<array>
     */
    private function storedAsInput(array $storedSlots, array $resources): array
    {
        $byId = array_column($resources, null, 'id');
        $out  = [];
        foreach (array_values($storedSlots) as $i => $slot) {
            $out[] = ['index' => $i, 'slot_number' => $slot['slot_number'], 'slot_status' => $slot['slot_status'],
                'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'staff' => array_values($slot['staff']),
                'resources' => array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $slot['resources'])))];
        }

        return $out;
    }

    /**
     * The people and resources that may be held, with those the session holds already added (one taken off the
     * service or the store since may stay where they are)
     * @param array<int, array> $storedSlots
     * @param array             $staff
     * @param array             $resources
     * @param int               $businessId
     * @return array{0: array, 1: array}
     */
    private function withHeld(array $storedSlots, array $staff, array $resources, int $businessId): array
    {
        foreach ($storedSlots as $slot) {
            foreach ($slot['staff_out'] as $clientId => $person) {
                $staff[$person['uid']] ??= ['client_id' => (int) $clientId] + $person;
            }
            foreach ($slot['resources_out'] as $resourceId => $resource) {
                if (! isset($resources[$resource['public_id']])) {
                    $row = $this->db()->table('resource_master r')->select('r.capacity, t.id AS type_id, t.buffer_minutes')
                        ->join('resource_type t', 't.id = r.resource_type_id')->where('r.id', $resourceId)->where('r.business_id', $businessId)->get()->getRowArray();
                    $resources[$resource['public_id']] = ['id' => (int) $resourceId, 'capacity' => (int) $row['capacity'], 'type_id' => (int) $row['type_id'],
                        'buffer_minutes' => (int) $row['buffer_minutes']] + $resource;
                }
            }
        }

        return [$staff, $resources];
    }

    /**
     * A datetime of the request in UTC 'Y-m-d H:i:s': null when empty, false when it is not one
     * @param mixed        $value "2026-11-14T10:00" in $zone, or with a Z or an offset
     * @param DateTimeZone $zone
     * @return string|false|null
     */
    private function moment(mixed $value, DateTimeZone $zone): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match(self::MOMENT_PATTERN, trim($value), $m)) {
            return false;
        }
        $text = $m[1] . ' ' . $m[2] . ($m[3] ?? '' ?: ':00');
        $at   = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, ($m[4] ?? '') === '' ? $zone : new DateTimeZone($m[4] === 'Z' ? 'UTC' : $m[4]));
        // a date that does not exist (2026-02-30) is not one
        if ($at === false || $at->format('Y-m-d H:i:s') !== $text) {
            return false;
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The physical stores of a business, by name
     * @param int $businessId
     * @return list<array{public_id: string, store_name: string, store_timezone: string|null}>
     */
    private function physicalStores(int $businessId): array
    {
        return $this->db()->table('business_store')->select('public_id, store_name, store_timezone')
            ->where('business_id', $businessId)->where('store_type', BusinessStoreModel::TYPE_PHYSICAL)
            ->orderBy('store_name')->orderBy('id')->get()->getResultArray();
    }

    /**
     * The session of the request's public_id, when the signed-in client manages its business
     * @param string $endpoint
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [session, input, null] or [null, null, error]
     */
    private function managedSession(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('ServiceSession.invalidRequest'), 422)];
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return [null, null, ApiResponse::error(lang('ServiceSession.sessionIdRequired'), 400)];
        }
        try {
            $session = model(ServiceSessionModel::class)->findByPublicId($publicId);
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'ServiceSession.loadFailed')];
        }
        $error = $session === null
            ? ApiResponse::error(lang('ServiceSession.sessionNotFound'), 404)
            : $this->roleError((int) $session['business_id'], $endpoint, lang('ServiceSession.sessionNotFound'), 'ServiceSession');

        return $error === null ? [$session, $input, null] : [null, null, $error];
    }

    /**
     * @param array|null $row
     * @param int        $businessId
     * @return array|null The row when it is of the business
     */
    private function ofBusiness(?array $row, int $businessId): ?array
    {
        return $row !== null && (int) $row['business_id'] === $businessId ? $row : null;
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }

    /**
     * @param mixed $value
     * @return int|null The whole number of an int or a string of digits, else null
     */
    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
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

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
