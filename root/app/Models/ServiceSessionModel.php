<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Model for service_session and the tables that go with it (see CreateServiceSessionTables): a session of a group
 * service that customers book whole, the slots it meets in, and the people (service_slot_staff) and resources
 * (service_slot_resource) each slot holds. Datetimes are stored in UTC ('Y-m-d H:i:s'); the API sends them in UTC
 * and in the store's store_timezone.
 *
 * What the caller has checked before a write: the store, the service and the variant are the business's, and every
 * person and resource may be held by this session at this store (eligibleStaff(), eligibleResources()). What this
 * model checks itself, in the transaction that writes, having locked the people's and resources' rows: that none of
 * them is held by another session at the same time (SlotConflictException).
 * Nothing here is deleted but a hold: a session or a slot is cancelled (X) instead.
 */
class ServiceSessionModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;
    use TranslationWriteTrait;

    // session_status
    public const STATUS_DRAFT     = 'D';
    public const STATUS_OPEN      = 'O';
    public const STATUS_CLOSED    = 'C';
    public const STATUS_CANCELLED = 'X';
    public const STATUSES         = [self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_CANCELLED];

    // service_session_slot.slot_status
    public const SLOT_SCHEDULED = 'S';
    public const SLOT_CANCELLED = 'X';

    public const SLOTS     = 'service_session_slot';
    public const STAFF     = 'service_slot_staff';
    public const RESOURCES = 'service_slot_resource';

    private const TRANSLATIONS = 'service_session_translation';
    private const TEXTS        = ['session_name', 'session_description'];

    /** How the API sends a datetime: in UTC, and in the store's timezone (for a datetime-local input) */
    private const UTC_FORMAT   = 'Y-m-d\TH:i:s\Z';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';
    private const DB_FORMAT    = 'Y-m-d H:i:s';

    protected $table            = 'service_session';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The sessions of a business, of one store and one service when given, by starts_at (those with no slot last)
     * @param int         $businessId
     * @param int|null    $storeId
     * @param int|null    $artifactId
     * @param string      $period upcoming (not over by $now, the default), past (over by $now) or all
     * @param string      $now    UTC 'Y-m-d H:i:s'
     * @return list<array>
     */
    public function listOfBusiness(int $businessId, ?int $storeId, ?int $artifactId, string $period, string $now): array
    {
        $builder = $this->db->table($this->table . ' s')
            ->select('s.id, s.public_id, s.session_name, s.capacity, s.seats_booked, s.starts_at, s.ends_at, s.session_status, '
                . 'st.public_id AS store_public_id, st.store_name, st.store_timezone, m.public_id AS artifact_public_id, m.artifact_name, '
                . 'v.public_id AS variant_public_id')
            ->join('business_store st', 'st.id = s.store_id')
            ->join('artifact_master m', 'm.id = s.artifact_id')
            ->join('artifact_variant v', 'v.id = s.variant_id')
            ->where('s.business_id', $businessId);
        if ($storeId !== null) {
            $builder->where('s.store_id', $storeId);
        }
        if ($artifactId !== null) {
            $builder->where('s.artifact_id', $artifactId);
        }
        if ($period === 'past') {
            $builder->where('s.ends_at <', $now);
        } elseif ($period !== 'all') {
            $builder->groupStart()->where('s.ends_at', null)->orWhere('s.ends_at >=', $now)->groupEnd();
        }
        $rows = $builder->orderBy('s.starts_at IS NULL', '', false)->orderBy('s.starts_at')->orderBy('s.id')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $counts = array_column($this->db->table(self::SLOTS)->select('session_id, COUNT(*) AS n')
            ->where('business_id', $businessId)->where('slot_status', self::SLOT_SCHEDULED)
            ->whereIn('session_id', array_map('intval', array_column($rows, 'id')))
            ->groupBy('session_id')->get()->getResultArray(), 'n', 'session_id');

        return array_map(fn (array $row): array => [
            'public_id'      => $row['public_id'],
            'session_name'   => $row['session_name'],
            'capacity'       => (int) $row['capacity'],
            'seats_booked'   => (int) $row['seats_booked'],
            'slot_count'     => (int) ($counts[$row['id']] ?? 0),
            'session_status' => $row['session_status'],
            'store'          => ['public_id' => $row['store_public_id'], 'store_name' => $row['store_name'], 'store_timezone' => $row['store_timezone']],
            'artifact'       => ['public_id' => $row['artifact_public_id'], 'artifact_name' => $row['artifact_name']],
            'variant'        => ['public_id' => $row['variant_public_id']],
        ] + self::moments($row, ['starts_at', 'ends_at'], $row['store_timezone']), $rows);
    }

    /**
     * A session as the API sends it, with its store, service, variant, translations and slots (by starts_at), each
     * slot with whom and what it holds
     * @param array $session The service_session row
     * @return array
     */
    public function detail(array $session): array
    {
        $id    = (int) $session['id'];
        $store = $this->db->table('business_store')->select('public_id, store_name, store_timezone')->where('id', $session['store_id'])->get()->getRowArray();
        $zone  = $store['store_timezone'];
        $art   = $this->db->table('artifact_master')->select('public_id, artifact_name')->where('id', $session['artifact_id'])->get()->getRowArray();
        $var   = $this->db->table('artifact_variant')->select('public_id, capacity, staff_required, duration_minutes')->where('id', $session['variant_id'])->get()->getRowArray();

        $slots = [];
        foreach ($this->slotsOf($id) as $slot) {
            $slots[] = [
                'slot_number' => $slot['slot_number'],
                'slot_status' => $slot['slot_status'],
                'staff'       => array_values($slot['staff_out']),
                'resources'   => array_values($slot['resources_out']),
            ] + self::moments($slot, ['starts_at', 'ends_at'], $zone);
        }

        return [
            'session' => [
                'public_id'           => $session['public_id'],
                'session_name'        => $session['session_name'],
                'session_description' => $session['session_description'],
                'capacity'            => (int) $session['capacity'],
                'seats_booked'        => (int) $session['seats_booked'],
                'session_status'      => $session['session_status'],
                'store'               => $store,
                'artifact'            => $art,
                'variant'             => [
                    'public_id'        => $var['public_id'],
                    'capacity'         => $var['capacity'] === null ? null : (int) $var['capacity'],
                    'staff_required'   => (int) ($var['staff_required'] ?? 1),
                    'duration_minutes' => $var['duration_minutes'] === null ? null : (int) $var['duration_minutes'],
                ],
                'translations'        => $this->translationsOf(self::TRANSLATIONS, 'session_id', [$id], self::TEXTS)[$id] ?? [],
            ] + self::moments($session, ['starts_at', 'ends_at', 'booking_closes_at'], $zone),
            'slots'   => $slots,
        ];
    }

    /**
     * The slots of a session as stored, by starts_at, each with
     * - staff:     client_id => client_id, and staff_out: [{ uid, full_name }]
     * - resources: resource_id => resource_id, and resources_out: [{ public_id, resource_name, type_public_id, type_name }]
     * @param int $sessionId
     * @return array<int, array> slot_number => slot
     */
    public function slotsOf(int $sessionId): array
    {
        $slots = [];
        foreach ($this->db->table(self::SLOTS)->where('session_id', $sessionId)->orderBy('starts_at')->orderBy('slot_number')->get()->getResultArray() as $row) {
            $slots[(int) $row['id']] = ['slot_number' => (int) $row['slot_number'], 'staff' => [], 'staff_out' => [], 'resources' => [], 'resources_out' => []] + $row;
        }
        if ($slots !== []) {
            $ids = array_keys($slots);
            foreach ($this->db->table(self::STAFF . ' h')->select('h.slot_id, h.client_id, a.uid, a.full_name')
                ->join('client_master c', 'c.id = h.client_id')->join('auth_master a', 'a.id = c.auth_id')
                ->whereIn('h.slot_id', $ids)->orderBy('a.full_name')->get()->getResultArray() as $hold) {
                $slots[(int) $hold['slot_id']]['staff'][(int) $hold['client_id']]     = (int) $hold['client_id'];
                $slots[(int) $hold['slot_id']]['staff_out'][(int) $hold['client_id']] = ['uid' => $hold['uid'], 'full_name' => $hold['full_name']];
            }
            foreach ($this->db->table(self::RESOURCES . ' h')
                ->select('h.slot_id, h.resource_id, r.public_id, r.resource_name, t.public_id AS type_public_id, t.type_name')
                ->join('resource_master r', 'r.id = h.resource_id')->join('resource_type t', 't.id = r.resource_type_id')
                ->whereIn('h.slot_id', $ids)->orderBy('t.type_name')->orderBy('r.sort_order')->orderBy('r.resource_name')->get()->getResultArray() as $hold) {
                $slots[(int) $hold['slot_id']]['resources'][(int) $hold['resource_id']]     = (int) $hold['resource_id'];
                $slots[(int) $hold['slot_id']]['resources_out'][(int) $hold['resource_id']] = array_diff_key($hold, ['slot_id' => true, 'resource_id' => true]);
            }
        }

        return array_column($slots, null, 'slot_number');
    }

    /**
     * The group variants of a business's services that are not discontinued, for a new session: each with its
     * service, the names of its attribute values, and what one session of it is by default
     * @param int $businessId
     * @return list<array>
     */
    public function groupVariantsOf(int $businessId): array
    {
        $rows = $this->db->table('artifact_variant v')
            ->select('v.id, v.public_id, v.capacity, v.staff_required, v.duration_minutes, v.variant_status, m.public_id AS artifact_public_id, '
                . 'm.artifact_name, m.artifact_status')
            ->join('artifact_master m', 'm.id = v.artifact_id')
            ->where('v.business_id', $businessId)->where('m.artifact_type', ArtifactMasterModel::TYPE_SERVICE)
            ->where('m.artifact_status !=', ArtifactMasterModel::STATUS_DISCONTINUED)
            ->where('v.booking_type', ArtifactMasterModel::BOOKING_GROUP)
            ->orderBy('m.artifact_name')->orderBy('m.id')->orderBy('v.sort_order')->orderBy('v.id')
            ->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $values = [];
        foreach ($this->db->table('artifact_variant_attribute_value l')->select('l.variant_id, av.attribute_value')
            ->join('artifact_attribute_value av', 'av.id = l.attribute_value_id')
            ->join('artifact_attribute a', 'a.id = l.attribute_id')
            ->whereIn('l.variant_id', array_map('intval', array_column($rows, 'id')))
            ->orderBy('a.attribute_name')->get()->getResultArray() as $row) {
            $values[(int) $row['variant_id']][] = $row['attribute_value'];
        }

        return array_map(static fn (array $row): array => [
            'public_id'        => $row['public_id'],
            'artifact'         => ['public_id' => $row['artifact_public_id'], 'artifact_name' => $row['artifact_name'], 'artifact_status' => $row['artifact_status']],
            'attribute_values' => $values[(int) $row['id']] ?? [],
            'capacity'         => $row['capacity'] === null ? null : (int) $row['capacity'],
            'staff_required'   => (int) ($row['staff_required'] ?? 1),
            'duration_minutes' => $row['duration_minutes'] === null ? null : (int) $row['duration_minutes'],
            'variant_status'   => $row['variant_status'],
        ], $rows);
    }

    /**
     * The people a session of the service at the store may hold: active in the business, allowed to perform the
     * service, and working at the store
     * @param int $businessId
     * @param int $artifactId
     * @param int $storeId
     * @return array<string, array{client_id: int, uid: string, full_name: string}> uid => person, by name
     */
    public function eligibleStaff(int $businessId, int $artifactId, int $storeId): array
    {
        $out = [];
        foreach ($this->db->table('business_service_client_link s')
            ->select('s.client_id, a.uid, a.full_name')
            ->join('business_client_link l', 'l.business_id = s.business_id AND l.client_id = s.client_id')
            ->join('business_store_client_link w', 'w.business_id = s.business_id AND w.client_id = s.client_id')
            ->join('client_master c', 'c.id = s.client_id')
            ->join('auth_master a', 'a.id = c.auth_id')
            ->where('s.business_id', $businessId)->where('s.artifact_id', $artifactId)
            ->where('l.client_status', BusinessClientLinkModel::STATUS_ACTIVE)
            ->where('w.store_id', $storeId)->where('w.link_status', BusinessStoreClientLinkModel::STATUS_ACTIVE)
            ->orderBy('a.full_name')->orderBy('a.id')->get()->getResultArray() as $row) {
            $out[$row['uid']] = ['client_id' => (int) $row['client_id'], 'uid' => $row['uid'], 'full_name' => $row['full_name']];
        }

        return $out;
    }

    /**
     * The resources a session of the service at the store may hold: the store's active resources of the active types
     * the service needs
     * @param int $businessId
     * @param int $artifactId
     * @param int $storeId
     * @return array<string, array> public_id => { id, public_id, resource_name, capacity, type_id, type_public_id, type_name, buffer_minutes }
     */
    public function eligibleResources(int $businessId, int $artifactId, int $storeId): array
    {
        $out = [];
        foreach ($this->db->table('business_service_resource_link n')
            ->select('r.id, r.public_id, r.resource_name, r.capacity, t.id AS type_id, t.public_id AS type_public_id, t.type_name, t.buffer_minutes')
            ->join('resource_type t', 't.id = n.resource_type_id')
            ->join('resource_master r', 'r.resource_type_id = n.resource_type_id')
            ->where('n.business_id', $businessId)->where('n.artifact_id', $artifactId)
            ->where('t.type_status', ResourceTypeModel::STATUS_ACTIVE)
            ->where('r.store_id', $storeId)->where('r.resource_status', ResourceMasterModel::STATUS_ACTIVE)
            ->orderBy('t.type_name')->orderBy('r.sort_order')->orderBy('r.resource_name')->orderBy('r.id')->get()->getResultArray() as $row) {
            $out[$row['public_id']] = ['id' => (int) $row['id'], 'capacity' => (int) $row['capacity'], 'type_id' => (int) $row['type_id'],
                'buffer_minutes' => (int) $row['buffer_minutes']] + $row;
        }

        return $out;
    }

    /**
     * Create a session with its translations and slots, and the holds of each slot, in one transaction logged to
     * log_activity in it
     * @param array $session Checked service_session columns: business_id, store_id, artifact_id, variant_id included
     * @param array $names   language_code => [session_name, session_description]
     * @param list<array> $slots Checked slots in the order they meet: { index, starts_at, ends_at (UTC), slot_status,
     *                           staff: list<client_id>, resources: list<{ id, buffer_minutes }> }
     * @param int   $actorId
     * @return string The new session's public_id
     * @throws SlotConflictException When a person or a resource is held by another session then; nothing is written
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createSession(array $session, array $names, array $slots, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($session, $names, $slots, $actorId, $log, $publicId) {
            $businessId = (int) $session['business_id'];
            $this->lockHeld($businessId, $slots);
            $row = ['public_id' => $publicId] + $session;
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            $log->inserted($actorId, 'Created session ' . $publicId . ' of business ' . $businessId, $this->table, $id, $row);
            $this->writeTranslations(self::TRANSLATIONS, 'session_id', $id, 'session ' . $publicId, [], $names, $actorId, $log);

            $slotIndex = $this->writeSlots(['id' => $id, 'public_id' => $publicId, 'business_id' => $businessId], [], $slots, $actorId, $log);
            $this->updateSpan($id, $actorId, $log);
            $this->checkConflicts($id, $slotIndex);

            return $publicId;
        }));
    }

    /**
     * Update a session, and its translations and slots when given, in one transaction; only what changes is written
     * and logged. Cancelling the session (session_status X) releases every hold.
     * @param array      $stored  The service_session row
     * @param array      $session Checked columns to save
     * @param array|null $names   language_code => [session_name, session_description]; a language left out loses its
     *                            texts. Null leaves them as they are
     * @param list<array>|null $slots As createSession() takes them, a stored one with its slot_number; a stored slot
     *                            left out is cancelled. Null leaves the slots as they are
     * @param int        $actorId
     * @return void
     * @throws SlotConflictException When a person or a resource is held by another session then; nothing is written
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateSession(array $stored, array $session, ?array $names, ?array $slots, int $actorId): void
    {
        $id      = (int) $stored['id'];
        $changes = ActivityLog::changes($stored, $session);
        $log     = new ActivityLog();
        $this->inTransaction(function () use ($stored, $id, $changes, $names, $slots, $actorId, $log) {
            $businessId = (int) $stored['business_id'];
            if ($slots !== null) {
                $this->lockHeld($businessId, $slots);
            }
            if ($changes !== []) {
                $this->db->table($this->table)->where('id', $id)->update($changes);
                $log->updated($actorId, 'Updated session ' . $stored['public_id'], $this->table, $id, $stored, $changes);
            }
            if ($names !== null) {
                $storedNames = $this->translationsOf(self::TRANSLATIONS, 'session_id', [$id], self::TEXTS)[$id] ?? [];
                $this->writeTranslations(self::TRANSLATIONS, 'session_id', $id, 'session ' . $stored['public_id'], $storedNames, $names, $actorId, $log, true);
            }
            $slotIndex = [];
            if ($slots !== null) {
                $slotIndex = $this->writeSlots($stored, $this->slotsOf($id), $slots, $actorId, $log);
            }
            if (($changes['session_status'] ?? null) === self::STATUS_CANCELLED) {
                $this->releaseHolds($id, null, $stored['public_id'], $actorId, $log);
            }
            $this->updateSpan($id, $actorId, $log);
            $this->checkConflicts($id, $slotIndex);
        });
    }

    /**
     * Lock the business_client_link and resource_master rows of everyone and everything the slots will hold, so no
     * other transaction can hold them until this one ends (MySQL; SQLite, the test database, runs one writer at a time)
     * @param int         $businessId
     * @param list<array> $slots
     * @return void
     */
    private function lockHeld(int $businessId, array $slots): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }
        $clients   = array_values(array_unique(array_merge([], ...array_map(static fn (array $slot): array => $slot['staff'], $slots))));
        $resources = array_values(array_unique(array_merge([], ...array_map(static fn (array $slot): array => array_column($slot['resources'], 'id'), $slots))));
        if ($clients !== []) {
            $this->db->query('SELECT id FROM business_client_link WHERE business_id = ? AND client_id IN ? ORDER BY id FOR UPDATE', [$businessId, $clients]);
        }
        if ($resources !== []) {
            $this->db->query('SELECT id FROM resource_master WHERE id IN ? ORDER BY id FOR UPDATE', [$resources]);
        }
    }

    /**
     * Write the slots and their holds: a slot with a stored slot_number is updated, one without is added with the
     * next number, and a stored one left out is cancelled. A cancelled slot holds nothing.
     * @param array               $session id, public_id, business_id
     * @param array<int, array>   $stored  slot_number => slot, from slotsOf()
     * @param list<array>         $slots
     * @param int                 $actorId
     * @param ActivityLog         $log
     * @return array<int, int> slot id => its index in $slots, for the errors of a conflict
     */
    private function writeSlots(array $session, array $stored, array $slots, int $actorId, ActivityLog $log): array
    {
        $sessionId  = (int) $session['id'];
        $businessId = (int) $session['business_id'];
        $next       = $stored === [] ? 1 : max(array_keys($stored)) + 1;
        $listed     = [];
        $index      = [];
        foreach ($slots as $slot) {
            $columns = ['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'slot_status' => $slot['slot_status']];
            $number  = $slot['slot_number'] ?? null;
            if ($number !== null && isset($stored[$number])) {
                $old     = $stored[$number];
                $slotId  = (int) $old['id'];
                $changes = ActivityLog::changes(array_intersect_key($old, $columns), $columns);
                if ($changes !== []) {
                    $this->db->table(self::SLOTS)->where('id', $slotId)->update($changes);
                    $log->updated($actorId, 'Updated slot ' . $number . ' of session ' . $session['public_id'], self::SLOTS, $slotId, $old, $changes);
                }
                $listed[$number] = true;
            } else {
                $number = $next++;
                $row    = ['business_id' => $businessId, 'session_id' => $sessionId, 'slot_number' => $number] + $columns;
                $this->db->table(self::SLOTS)->insert($row);
                $slotId = (int) $this->db->insertID();
                $log->inserted($actorId, 'Added slot ' . $number . ' to session ' . $session['public_id'], self::SLOTS, $slotId, $row);
                $old = ['staff' => [], 'resources' => []];
            }
            $index[$slotId] = (int) $slot['index'];
            $what           = 'slot ' . $number . ' of session ' . $session['public_id'];
            if ($slot['slot_status'] === self::SLOT_CANCELLED) {
                $this->releaseHolds($sessionId, $slotId, $what, $actorId, $log);

                continue;
            }
            $this->writeHolds($businessId, $slotId, $what, $old, $slot, $actorId, $log);
        }
        foreach ($stored as $number => $old) {
            if (isset($listed[$number]) || $old['slot_status'] === self::SLOT_CANCELLED) {
                continue;
            }
            $this->db->table(self::SLOTS)->where('id', $old['id'])->update(['slot_status' => self::SLOT_CANCELLED]);
            $log->updated($actorId, 'Cancelled slot ' . $number . ' of session ' . $session['public_id'], self::SLOTS, (int) $old['id'], $old, ['slot_status' => self::SLOT_CANCELLED]);
            $this->releaseHolds($sessionId, (int) $old['id'], 'slot ' . $number . ' of session ' . $session['public_id'], $actorId, $log);
        }

        return $index;
    }

    /**
     * Make a slot hold exactly the slot's people and resources, at its times: a hold no longer wanted is deleted, a
     * new one inserted, and a kept one given the slot's times when they changed
     * @param int         $businessId
     * @param int         $slotId
     * @param string      $what   The slot in the log
     * @param array       $old    The stored slot (staff, resources: id => id), or one with none
     * @param array       $slot   The slot to write
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function writeHolds(int $businessId, int $slotId, string $what, array $old, array $slot, int $actorId, ActivityLog $log): void
    {
        $tables = [
            self::STAFF     => ['client_id', $slot['staff'], static fn (int $id): array => ['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']], $old['staff']],
            self::RESOURCES => ['resource_id', array_column($slot['resources'], 'id'), static function (int $id) use ($slot): array {
                $buffer = (int) (array_column($slot['resources'], 'buffer_minutes', 'id')[$id] ?? 0);

                return ['starts_at' => $slot['starts_at'], 'held_until' => self::plusMinutes($slot['ends_at'], $buffer)];
            }, $old['resources']],
        ];
        foreach ($tables as $table => [$column, $wanted, $times, $held]) {
            $stored = [];
            if ($held !== []) {
                foreach ($this->db->table($table)->where('slot_id', $slotId)->get()->getResultArray() as $row) {
                    $stored[(int) $row[$column]] = $row;
                }
            }
            foreach ($stored as $heldId => $row) {
                if (! in_array($heldId, $wanted, true)) {
                    $this->db->table($table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Released ' . $column . ' ' . $heldId . ' from ' . $what, $table, (int) $row['id'], $row);
                }
            }
            foreach ($wanted as $heldId) {
                $columns = $times($heldId);
                if (isset($stored[$heldId])) {
                    $changes = ActivityLog::changes($stored[$heldId], $columns);
                    if ($changes !== []) {
                        $this->db->table($table)->where('id', $stored[$heldId]['id'])->update($changes);
                        $log->updated($actorId, 'Moved ' . $column . ' ' . $heldId . ' with ' . $what, $table, (int) $stored[$heldId]['id'], $stored[$heldId], $changes);
                    }

                    continue;
                }
                $row = ['business_id' => $businessId, 'slot_id' => $slotId, $column => $heldId] + $columns;
                $this->db->table($table)->insert($row);
                $log->inserted($actorId, 'Held ' . $column . ' ' . $heldId . ' for ' . $what, $table, (int) $this->db->insertID(), $row);
            }
        }
    }

    /**
     * Delete the holds of one slot, or of every slot of the session
     * @param int         $sessionId
     * @param int|null    $slotId
     * @param string      $what The slot or session in the log
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function releaseHolds(int $sessionId, ?int $slotId, string $what, int $actorId, ActivityLog $log): void
    {
        $slotIds = $slotId !== null ? [$slotId]
            : array_map('intval', array_column($this->db->table(self::SLOTS)->select('id')->where('session_id', $sessionId)->get()->getResultArray(), 'id'));
        if ($slotIds === []) {
            return;
        }
        foreach ([self::STAFF, self::RESOURCES] as $table) {
            foreach ($this->db->table($table)->whereIn('slot_id', $slotIds)->get()->getResultArray() as $row) {
                $this->db->table($table)->where('id', $row['id'])->delete();
                $log->deleted($actorId, 'Released a hold of ' . $what, $table, (int) $row['id'], $row);
            }
        }
    }

    /**
     * Set the session's starts_at and ends_at from its scheduled slots (NULL with none)
     * @param int         $sessionId
     * @param int         $actorId
     * @param ActivityLog $log
     * @return void
     */
    private function updateSpan(int $sessionId, int $actorId, ActivityLog $log): void
    {
        $span    = $this->db->table(self::SLOTS)->select('MIN(starts_at) AS starts_at, MAX(ends_at) AS ends_at')
            ->where('session_id', $sessionId)->where('slot_status', self::SLOT_SCHEDULED)->get()->getRowArray();
        $stored  = $this->db->table($this->table)->where('id', $sessionId)->get()->getRowArray();
        $changes = ActivityLog::changes($stored, ['starts_at' => $span['starts_at'] ?? null, 'ends_at' => $span['ends_at'] ?? null]);
        if ($changes !== []) {
            $this->db->table($this->table)->where('id', $sessionId)->update($changes);
            $log->updated($actorId, 'Session ' . $stored['public_id'] . ' now runs from its first to its last slot', $this->table, $sessionId, $stored, $changes);
        }
    }

    /**
     * Throw when a person or a resource this session holds is held by another session at an overlapping time
     * @param int             $sessionId
     * @param array<int, int> $slotIndex slot id => its index in the request
     * @return void
     * @throws SlotConflictException
     */
    private function checkConflicts(int $sessionId, array $slotIndex): void
    {
        $errors = [];
        $checks = [
            'staff'     => [self::STAFF, 'client_id', 'ends_at', 'auth_master w', 'w.full_name',
                static fn ($builder) => $builder->join('client_master c', 'c.id = a.client_id')->join('auth_master w', 'w.id = c.auth_id')],
            'resources' => [self::RESOURCES, 'resource_id', 'held_until', 'resource_master w', 'w.resource_name',
                static fn ($builder) => $builder->join('resource_master w', 'w.id = a.resource_id')],
        ];
        foreach ($checks as $field => [$table, $column, $until, , $name, $join]) {
            $builder = $this->db->table($table . ' a')
                ->select('a.slot_id, ' . $name . ' AS held_name, o.session_name AS other_name')
                ->join($table . ' b', 'b.business_id = a.business_id AND b.' . $column . ' = a.' . $column . ' AND b.id <> a.id'
                    . ' AND b.starts_at < a.' . $until . ' AND b.' . $until . ' > a.starts_at')
                ->join(self::SLOTS . ' sa', 'sa.id = a.slot_id')
                ->join(self::SLOTS . ' sb', 'sb.id = b.slot_id')
                ->join($this->table . ' o', 'o.id = sb.session_id')
                ->where('sa.session_id', $sessionId)->where('sb.session_id !=', $sessionId);
            foreach ($join($builder)->get()->getResultArray() as $row) {
                $key          = 'slots.' . ($slotIndex[(int) $row['slot_id']] ?? 0) . '.' . $field;
                $errors[$key] = lang('ServiceSession.heldElsewhere', [$row['held_name'], $row['other_name']]);
            }
        }
        if ($errors !== []) {
            throw new SlotConflictException($errors);
        }
    }

    /**
     * The datetimes of a row in UTC (field) and in the store's timezone (local_<field>), null where empty
     * @param array       $row
     * @param list<string> $fields
     * @param string|null $timezone
     * @return array<string, string|null>
     */
    private static function moments(array $row, array $fields, ?string $timezone): array
    {
        $utc   = new DateTimeZone('UTC');
        $local = new DateTimeZone($timezone ?: 'UTC');
        $out   = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') {
                $out[$field] = $out['local_' . $field] = null;

                continue;
            }
            $moment               = new DateTimeImmutable($value, $utc);
            $out[$field]          = $moment->format(self::UTC_FORMAT);
            $out['local_' . $field] = $moment->setTimezone($local)->format(self::LOCAL_FORMAT);
        }

        return $out;
    }

    /**
     * @param string $at      UTC 'Y-m-d H:i:s'
     * @param int    $minutes
     * @return string UTC 'Y-m-d H:i:s'
     */
    private static function plusMinutes(string $at, int $minutes): string
    {
        return (new DateTimeImmutable($at, new DateTimeZone('UTC')))->modify('+' . $minutes . ' minutes')->format(self::DB_FORMAT);
    }
}
