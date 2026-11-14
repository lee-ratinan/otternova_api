<?php

namespace App\Models;

use App\Libraries\BookingWindow;
use CodeIgniter\Model;
use Config\Mart as MartConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The times a customer may book an appointment (a variant with booking_type A) on the mart: read only, never cached,
 * as every booking changes them. A start is offered at a physical store when
 * - it is on the store's opening hours of that business day, and the whole appointment fits in one opening
 * - it is on Config\Mart::$slotStepMinutes of the store's clock (10:00, 10:30 ...)
 * - the booking window allows it (App\Libraries\BookingWindow: the variant's rule, else the business's): from the
 *   notice on, and no later than booking_horizon_days days after the store's today
 * - at least staff_required people who may perform the service and are active at the store work there for the whole
 *   appointment (business_client_hours and its exceptions), with nothing else held then (service_slot_staff)
 * - for each resource type the service needs, quantity of the store's active resources are free from the start to
 *   the end plus the type's buffer_minutes (service_slot_resource), each taking its share of the customers the
 *   booking is for (the variant's capacity over the quantity: a couples massage needs one room for 2, not room 101
 *   for 1, or two beds for 1 each)
 * The people go out under their auth_master.public_id, never their ids or uids (a uid is made from the email address).
 */
class MartSlotModel extends Model
{
    protected $table      = 'business_store';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * The variant when a customer may book it by time: active, of an active service of the business, and an
     * appointment (A) with a duration
     * @param int    $businessId
     * @param string $publicId artifact_variant.public_id
     * @return array|null { id, public_id, artifact_id, artifact_public_id, duration_minutes, staff_required, people
     *                    (the customers one booking is for, its capacity, 1 when null), the BookingWindow::FIELDS }
     */
    public function bookableVariant(int $businessId, string $publicId): ?array
    {
        $row = $this->db->table('artifact_variant v')
            ->select('v.id, v.public_id, v.artifact_id, a.public_id AS artifact_public_id, v.duration_minutes, v.staff_required, v.capacity, '
                . 'v.booking_horizon_days, v.booking_notice_unit, v.booking_notice_value, v.booking_notice_time')
            ->join('artifact_master a', 'a.id = v.artifact_id')
            ->where('a.business_id', $businessId)->where('v.public_id', $publicId)
            ->where('a.artifact_type', ArtifactMasterModel::TYPE_SERVICE)->where('a.artifact_status', 'A')
            ->where('v.variant_status', 'A')->where('v.booking_type', 'A')
            ->get()->getRowArray();
        if ($row === null || (int) $row['duration_minutes'] < 1) {
            return null;
        }

        return ['id' => (int) $row['id'], 'artifact_id' => (int) $row['artifact_id'], 'duration_minutes' => (int) $row['duration_minutes'],
            'staff_required' => max(1, (int) $row['staff_required']), 'people' => max(1, (int) $row['capacity'])] + $row;
    }

    /**
     * The physical stores where someone may perform the service, each with those people, by name
     * @param int    $businessId
     * @param int    $artifactId
     * @param string $language For the store names
     * @return list<array{id: int, public_id: string, name: string, address: array, phone_number: string|null, timezone: string, staff: array<int, array{public_id: string, name: string, restrictions: array|null}>}>
     *         staff: client_id => person, by name; restrictions: the customers they do not serve, which the customer is
     *         told before booking them ({excluded_genders: [M|F|NB], age_min, age_max}), null for none
     */
    public function storesFor(int $businessId, int $artifactId, string $language): array
    {
        $rows = $this->db->table('business_service_client_link s')
            ->select('w.store_id, s.client_id, a.public_id, c.display_name, c.legal_first_name, a.full_name')
            ->join('business_client_link l', 'l.business_id = s.business_id AND l.client_id = s.client_id')
            ->join('business_store_client_link w', 'w.business_id = s.business_id AND w.client_id = s.client_id')
            ->join('client_master c', 'c.id = s.client_id')
            ->join('auth_master a', 'a.id = c.auth_id')
            ->where('s.business_id', $businessId)->where('s.artifact_id', $artifactId)
            ->where('l.client_status', BusinessClientLinkModel::STATUS_ACTIVE)
            ->where('w.link_status', BusinessStoreClientLinkModel::STATUS_ACTIVE)
            ->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $restrictions = $this->restrictionsOf($businessId, array_map('intval', array_column($rows, 'client_id')));
        $staff        = [];
        foreach ($rows as $row) {
            $staff[(int) $row['store_id']][(int) $row['client_id']] = ['public_id' => $row['public_id'], 'name' => self::publicName($row),
                'restrictions' => $restrictions[(int) $row['client_id']] ?? null];
        }

        $stores = $this->db->table('business_store')
            ->select('id, public_id, store_name, store_address_line_1, store_address_line_2, store_city, store_subdivision_code, store_postal_code, store_phone_number, store_timezone')
            ->where('business_id', $businessId)->where('store_type', BusinessStoreModel::TYPE_PHYSICAL)
            ->whereIn('id', array_keys($staff))
            ->orderBy('store_name')->orderBy('id')
            ->get()->getResultArray();
        $names = [];
        if ($stores !== []) {
            foreach ($this->db->table('business_store_translation')->select('store_id, store_name')
                ->whereIn('store_id', array_map('intval', array_column($stores, 'id')))->where('language_code', $language)
                ->get()->getResultArray() as $row) {
                if ((string) $row['store_name'] !== '') {
                    $names[(int) $row['store_id']] = $row['store_name'];
                }
            }
        }

        return array_map(static function (array $row) use ($staff, $names): array {
            $people = $staff[(int) $row['id']];
            uasort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return [
                'id'        => (int) $row['id'],
                'public_id' => $row['public_id'],
                'name'      => $names[(int) $row['id']] ?? $row['store_name'],
                'address'   => [
                    'line_1'           => $row['store_address_line_1'],
                    'line_2'           => $row['store_address_line_2'],
                    'city'             => $row['store_city'],
                    'subdivision_code' => $row['store_subdivision_code'],
                    'postal_code'      => $row['store_postal_code'],
                ],
                'phone_number' => $row['store_phone_number'],
                'timezone'  => $row['store_timezone'],
                'staff'     => $people,
            ];
        }, $stores);
    }

    /**
     * The store-local dates a customer may book on now under $rule: from the date of the earliest start the notice
     * allows to the horizon's last day
     * @param array             $rule BookingWindow::effective()
     * @param DateTimeZone      $zone The store's
     * @param DateTimeImmutable $now
     * @return array{first_date: string, last_date: string}|null null when nothing can be booked
     */
    public static function datesOf(array $rule, DateTimeZone $zone, DateTimeImmutable $now): ?array
    {
        $window = BookingWindow::window($rule, $zone, $now);
        $first  = $window['earliest']->setTimezone($zone)->format('Y-m-d');
        $last   = $window['latestBefore']->setTimezone($zone)->modify('-1 day')->format('Y-m-d');

        return $first <= $last ? ['first_date' => $first, 'last_date' => $last] : null;
    }

    /**
     * The starts a customer may book at a store on one of its business days
     * @param array             $business  business_master, for its booking rule
     * @param array             $variant   bookableVariant()
     * @param array             $store     One of storesFor()
     * @param string            $date      The store's business day, Y-m-d
     * @param int|null          $staffId   Only the starts this person (client_id, one of the store's staff) is free for
     * @param DateTimeImmutable $now
     * @param bool              $counter At the counter (a walk-in, a call-in): the booking window does not apply, only
     *                                   no start before the step now is in (a walk-in at 10:10 may have 10:00)
     * @param int|null          $except  A session whose own holds do not count: the booking being moved
     * @return list<array{store_public_id: string, starts_at: string, ends_at: string, time: string, end_time: string, staff: list<string>}>
     *         by start; starts_at and ends_at in ISO 8601 with the store's offset; staff: the public_ids of whom the
     *         booking would hold. A start comes once for each person free then, by name, when one person is needed
     *         (only for $staffId when given); once, with the first of them by name (and $staffId), when more are
     */
    public function slotsOn(array $business, array $variant, array $store, string $date, ?int $staffId, DateTimeImmutable $now, bool $counter = false, ?int $except = null): array
    {
        $zone     = new DateTimeZone($store['timezone']);
        $rule     = BookingWindow::effective($business, $variant);
        $openings = array_map(static fn (array $hours): array => [new DateTimeImmutable($hours['open']), new DateTimeImmutable($hours['close'])],
            model(BusinessStoreHoursModel::class)->getBusinessHoursOf($store['id'], $date, 'UTC')['hours']);
        if ($openings === []) {
            return [];
        }
        $from = $openings[0][0];
        $to   = end($openings)[1];

        $shifts = $this->shiftsAt((int) $business['id'], array_keys($store['staff']), $store['id'], $date);
        $held   = $this->staffHolds((int) $business['id'], array_keys($store['staff']), $from, $to, $except);
        $needs  = $this->resourceNeeds((int) $business['id'], $variant['artifact_id'], $store['id'], $from, $to, $variant['people'] ?? 1, $except);
        if ($needs === null) {
            return [];
        }

        $step     = max(1, config(MartConfig::class)->slotStepMinutes);
        $duration = $variant['duration_minutes'];
        $slots    = [];
        foreach ($openings as [$open, $close]) {
            // the first start on the step of the store's clock at or after the opening
            $local   = $open->setTimezone($zone);
            $minutes = (int) $local->format('G') * 60 + (int) $local->format('i') + ($local->format('s') === '00' ? 0 : 1);
            $start   = $local->setTime(0, 0)->modify('+' . ($minutes + ($step - $minutes % $step) % $step) . ' minutes');
            for (; ($end = $start->modify('+' . $duration . ' minutes')) <= $close; $start = $start->modify('+' . $step . ' minutes')) {
                if ($counter ? $start->modify('+' . $step . ' minutes') <= $now : ! BookingWindow::allows($rule, $start, $zone, $now)) {
                    continue;
                }
                $free = [];
                foreach ($store['staff'] as $clientId => $person) {
                    if (self::covers($shifts[$clientId] ?? [], $start, $end) && ! self::overlaps($held[$clientId] ?? [], $start, $end)) {
                        $free[$clientId] = $person['public_id'];
                    }
                }
                if (count($free) < $variant['staff_required'] || ($staffId !== null && ! isset($free[$staffId])) || ! self::resourcesFree($needs, $start, $end)) {
                    continue;
                }
                foreach (self::teams($free, $variant['staff_required'], $staffId) as $team) {
                    $slots[] = [
                        'store_public_id' => $store['public_id'],
                        'starts_at'       => $start->setTimezone($zone)->format(self::LOCAL_FORMAT),
                        'ends_at'         => $end->setTimezone($zone)->format(self::LOCAL_FORMAT),
                        'time'            => $start->setTimezone($zone)->format('H:i'),
                        'end_time'        => $end->setTimezone($zone)->format('H:i'),
                        'staff'           => $team,
                    ];
                }
            }
        }
        return $slots;
    }

    /**
     * Whom a booking at a start would hold, from the people free then (by name)
     * @param array<int, string> $free     client_id => public_id
     * @param int                $required staff_required
     * @param int|null           $staffId  The person asked for
     * @return list<list<string>> One team per offer
     */
    private static function teams(array $free, int $required, ?int $staffId): array
    {
        if ($required === 1) {
            return $staffId === null ? array_map(static fn (string $id): array => [$id], array_values($free)) : [[$free[$staffId]]];
        }
        $team = $staffId === null ? [] : [$staffId => $free[$staffId]];
        foreach ($free as $clientId => $publicId) {
            if (count($team) < $required) {
                $team[$clientId] ??= $publicId;
            }
        }

        return [array_values($team)];
    }

    /**
     * The restrictions of each person who has any
     * @param int       $businessId
     * @param list<int> $clientIds
     * @return array<int, array{excluded_genders: list<string>, age_min: int|null, age_max: int|null}>
     */
    public function restrictionsOf(int $businessId, array $clientIds): array
    {
        $out = [];
        foreach ($this->db->table('business_client_restriction')->select('client_id, restriction_type, excluded_gender, age_min, age_max')
            ->where('business_id', $businessId)->whereIn('client_id', array_values(array_unique($clientIds)))
            ->get()->getResultArray() as $row) {
            $out[(int) $row['client_id']] ??= ['excluded_genders' => [], 'age_min' => null, 'age_max' => null];
            if ($row['restriction_type'] === BusinessClientRestrictionModel::TYPE_GENDER) {
                $out[(int) $row['client_id']]['excluded_genders'][] = $row['excluded_gender'];
            } else {
                $out[(int) $row['client_id']]['age_min'] = $row['age_min'] === null ? null : (int) $row['age_min'];
                $out[(int) $row['client_id']]['age_max'] = $row['age_max'] === null ? null : (int) $row['age_max'];
            }
        }
        foreach ($out as &$restriction) {
            $restriction['excluded_genders'] = array_values(array_intersect(BusinessClientRestrictionModel::GENDERS, $restriction['excluded_genders']));
        }

        return $out;
    }

    /**
     * The shifts of each person at the store that may hold part of the business day: those of the day before (a
     * late one runs past midnight), the day and the day after, with the ones that meet made one
     * @param int       $businessId
     * @param list<int> $clientIds
     * @param int       $storeId
     * @param string    $date Y-m-d
     * @return array<int, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>> client_id => shifts, by start
     */
    private function shiftsAt(int $businessId, array $clientIds, int $storeId, string $date): array
    {
        $hours = model(BusinessClientHoursModel::class);
        $day   = new DateTimeImmutable($date);
        $out   = [];
        foreach ($clientIds as $clientId) {
            $shifts = [];
            foreach (['-1 day', '+0 days', '+1 day'] as $offset) {
                foreach ($hours->getWorkingHoursOf($businessId, $clientId, $day->modify($offset)->format('Y-m-d'), 'UTC')['shifts'] as $shift) {
                    if ($shift['store_id'] === $storeId) {
                        $shifts[] = [new DateTimeImmutable($shift['start']), new DateTimeImmutable($shift['end'])];
                    }
                }
            }
            usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($shifts as $shift) {
                $last = count($merged) - 1;
                if ($last >= 0 && $shift[0] <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $shift[1]);
                } else {
                    $merged[] = $shift;
                }
            }
            $out[$clientId] = $merged;
        }

        return $out;
    }

    /**
     * What each person is already held for between $from and $to (at any store)
     * @return array<int, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>> client_id => holds
     */
    private function staffHolds(int $businessId, array $clientIds, DateTimeImmutable $from, DateTimeImmutable $to, ?int $except = null): array
    {
        $out     = [];
        $builder = $this->db->table(ServiceSessionModel::STAFF)->select('client_id, starts_at, ends_at')
            ->where('business_id', $businessId)->whereIn('client_id', $clientIds)
            ->where('starts_at <', self::db($to))->where('ends_at >', self::db($from));
        $this->exceptSession($builder, $except);
        foreach ($builder->get()->getResultArray() as $row) {
            $out[(int) $row['client_id']][] = [self::utc($row['starts_at']), self::utc($row['ends_at'])];
        }

        return $out;
    }

    /**
     * The resources the service needs at the store, each type with its free resources' holds
     * @return list<array{quantity: int, buffer: int, resources: array<int, list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>>}>|null
     *         null when the store has too few of a type to ever hold the service
     */
    private function resourceNeeds(int $businessId, int $artifactId, int $storeId, DateTimeImmutable $from, DateTimeImmutable $to, int $people = 1, ?int $except = null): ?array
    {
        $links = $this->db->table('business_service_resource_link')->select('resource_type_id, quantity')
            ->where('business_id', $businessId)->where('artifact_id', $artifactId)->get()->getResultArray();
        if ($links === []) {
            return [];
        }
        // each resource held takes its share of the customers: a room for 2 when one is held, a bed for 1 when two are
        $least = [];
        foreach ($links as $link) {
            $least[(int) $link['resource_type_id']] = (int) ceil($people / max(1, (int) $link['quantity']));
        }
        $byType = [];
        foreach (model(ServiceSessionModel::class)->eligibleResources($businessId, $artifactId, $storeId) as $resource) {
            if ($resource['capacity'] < ($least[$resource['type_id']] ?? 1)) {
                continue;
            }
            $byType[$resource['type_id']]['buffer']                       = $resource['buffer_minutes'];
            $byType[$resource['type_id']]['resources'][$resource['id']] = [];
        }
        $needs = [];
        $ids   = [];
        foreach ($links as $link) {
            $type     = $byType[(int) $link['resource_type_id']] ?? ['buffer' => 0, 'resources' => []];
            $quantity = max(1, (int) $link['quantity']);
            if (count($type['resources']) < $quantity) {
                return null;
            }
            $needs[] = ['quantity' => $quantity] + $type;
            $ids     = array_merge($ids, array_keys($type['resources']));
        }
        $maxBuffer = max(array_column($needs, 'buffer'));
        $holds     = [];
        $builder = $this->db->table(ServiceSessionModel::RESOURCES)->select('resource_id, starts_at, held_until')
            ->where('business_id', $businessId)->whereIn('resource_id', $ids)
            ->where('starts_at <', self::db($to->modify('+' . $maxBuffer . ' minutes')))->where('held_until >', self::db($from));
        $this->exceptSession($builder, $except);
        foreach ($builder->get()->getResultArray() as $row) {
            $holds[(int) $row['resource_id']][] = [self::utc($row['starts_at']), self::utc($row['held_until'])];
        }
        foreach ($needs as &$need) {
            foreach (array_keys($need['resources']) as $id) {
                $need['resources'][$id] = $holds[$id] ?? [];
            }
        }

        return $needs;
    }

    /** Leave out the holds of a session's slots: the booking being moved does not stand in its own way */
    private function exceptSession($builder, ?int $sessionId): void
    {
        if ($sessionId !== null) {
            $builder->where('slot_id NOT IN (SELECT id FROM ' . $this->db->prefixTable(ServiceSessionModel::SLOTS) . ' WHERE session_id = ' . $sessionId . ')', null, false);
        }
    }

    /** Whether each type has its quantity free from $start until $end plus its buffer */
    private static function resourcesFree(array $needs, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($needs as $need) {
            $until = $end->modify('+' . $need['buffer'] . ' minutes');
            $free  = count(array_filter($need['resources'], static fn (array $holds): bool => ! self::overlaps($holds, $start, $until)));
            if ($free < $need['quantity']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The resources an appointment from $start to $end would hold at the store: for each type the service needs, its
     * quantity of the free ones that take $people customers, the first by order; for an order of the mart (MartOrderModel)
     * @param int               $businessId
     * @param int               $artifactId
     * @param int               $storeId
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     * @param int               $people The customers the booking is for
     * @param int|null          $except A session whose own holds do not count: the booking being moved
     * @return list<array{id: int, buffer_minutes: int}>|null null when too few are free
     */
    public function freeResources(int $businessId, int $artifactId, int $storeId, DateTimeImmutable $start, DateTimeImmutable $end, int $people = 1, ?int $except = null): ?array
    {
        $needs = $this->resourceNeeds($businessId, $artifactId, $storeId, $start, $end, $people, $except);
        if ($needs === null) {
            return null;
        }
        $held = [];
        foreach ($needs as $need) {
            $until = $end->modify('+' . $need['buffer'] . ' minutes');
            $free  = array_keys(array_filter($need['resources'], static fn (array $holds): bool => ! self::overlaps($holds, $start, $until)));
            $free  = array_values(array_diff($free, array_column($held, 'id')));
            if (count($free) < $need['quantity']) {
                return null;
            }
            foreach (array_slice($free, 0, $need['quantity']) as $id) {
                $held[] = ['id' => (int) $id, 'buffer_minutes' => (int) $need['buffer']];
            }
        }

        return $held;
    }

    /** Whether one of $intervals holds all of $start to $end */
    private static function covers(array $intervals, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($intervals as [$from, $to]) {
            if ($from <= $start && $end <= $to) {
                return true;
            }
        }

        return false;
    }

    /** Whether one of $intervals meets $start to $end; one ending as it starts does not */
    private static function overlaps(array $intervals, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        foreach ($intervals as [$from, $to]) {
            if ($from < $end && $to > $start) {
                return true;
            }
        }

        return false;
    }

    /** The name a customer sees: the person's display_name, else their first name */
    public static function publicName(array $row): string
    {
        foreach (['display_name', 'legal_first_name'] as $column) {
            if (trim((string) $row[$column]) !== '') {
                return trim((string) $row[$column]);
            }
        }

        return strtok(trim((string) $row['full_name']), ' ') ?: '';
    }

    private static function db(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }

    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }
}
