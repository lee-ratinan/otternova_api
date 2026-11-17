<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a business has booked, as its people see it on their calendar: read only. Both kinds of booking are service
 * sessions (see ServiceSessionModel): a group session that customers book seats of, and a private appointment, which
 * MartOrderModel::place() writes as a closed session of its own (its order_line has line_kind A). Each scheduled slot
 * of a session not cancelled is one entry of a day, with the people (service_slot_staff) and resources
 * (service_slot_resource) it holds. A day is a day of the store's own clock.
 * The people go out under their auth_master.uid (the key of the public profiles), never their ids.
 */
class CalendarModel extends Model
{
    protected $table      = 'service_session';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    public const KIND_APPOINTMENT = 'A';
    public const KIND_GROUP       = 'G';

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const UTC_FORMAT   = 'Y-m-d\TH:i:s\Z';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    /**
     * The slots of the business's sessions on a day, by the time they start, then the store
     * @param int      $businessId
     * @param string   $date      'Y-m-d', a day of each store's own clock
     * @param int|null $viewerId  client_master.id of the person looking, to mark them in the staff
     * @param string   $language  For the session names
     * @return list<array{session_public_id: string, kind: string, name: string, service_name: string, session_status: string,
     *         slot_number: int, slot_count: int, starts_at: string, ends_at: string, local_starts_at: string, local_ends_at: string,
     *         store: array, staff: list<array>, resources: list<array>, capacity: int, seats_booked: int, customers: list<array>, is_mine: bool}>
     *         customers (appointments only): [{ order_public_id, order_number, name, participants, no_show }]
     */
    public function day(int $businessId, string $date, ?int $viewerId, string $language): array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($day === false || $day->format('Y-m-d') !== $date) {
            return [];
        }
        // the widest the day can be on any clock (UTC-12 to UTC+14); each store's own day is kept below
        $from = $day->modify('-14 hours')->format(self::DB_FORMAT);
        $to   = $day->modify('+1 day +12 hours')->format(self::DB_FORMAT);

        $rows = $this->db->table(ServiceSessionModel::SLOTS . ' sl')
            ->select('sl.id AS slot_id, sl.slot_number, sl.starts_at, sl.ends_at, s.id AS session_id, s.public_id, s.session_name, s.capacity, '
                . 's.seats_booked, s.session_status, m.artifact_name, st.public_id AS store_public_id, st.store_name, st.store_timezone')
            ->join($this->table . ' s', 's.id = sl.session_id')
            ->join('artifact_master m', 'm.id = s.artifact_id')
            ->join('business_store st', 'st.id = s.store_id')
            ->where('sl.business_id', $businessId)
            ->where('sl.slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->where('s.session_status !=', ServiceSessionModel::STATUS_CANCELLED)
            ->where('sl.starts_at <', $to)->where('sl.ends_at >', $from)
            ->orderBy('sl.starts_at')->orderBy('st.store_name')->orderBy('s.id')->orderBy('sl.slot_number')
            ->get()->getResultArray();
        $rows = array_values(array_filter($rows, static function (array $row) use ($date): bool {
            $zone  = self::zone($row['store_timezone']);
            $start = (new DateTimeImmutable($date . ' 00:00:00', $zone))->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
            $end   = (new DateTimeImmutable($date . ' 00:00:00', $zone))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);

            return $row['starts_at'] < $end && $row['ends_at'] > $start;
        }));
        if ($rows === []) {
            return [];
        }

        $sessionIds = array_values(array_unique(array_map('intval', array_column($rows, 'session_id'))));
        $slotIds    = array_map('intval', array_column($rows, 'slot_id'));
        $staff      = $this->staffOf($slotIds, $viewerId);
        $resources  = $this->resourcesOf($slotIds);
        $bookings   = $this->bookingsOf($sessionIds);
        $names      = $this->namesIn($sessionIds, $language);
        $counts     = array_column($this->db->table(ServiceSessionModel::SLOTS)->select('session_id, COUNT(*) AS n')
            ->whereIn('session_id', $sessionIds)->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->groupBy('session_id')->get()->getResultArray(), 'n', 'session_id');

        return array_map(function (array $row) use ($staff, $resources, $bookings, $names, $counts): array {
            $sessionId   = (int) $row['session_id'];
            $appointment = self::isAppointment($bookings[$sessionId] ?? []);
            $people      = $staff[(int) $row['slot_id']] ?? [];

            return [
                'session_public_id' => $row['public_id'],
                'kind'              => $appointment ? self::KIND_APPOINTMENT : self::KIND_GROUP,
                'name'              => $appointment ? $row['artifact_name'] : ($names[$sessionId] ?? $row['session_name']),
                'service_name'      => $row['artifact_name'],
                'session_status'    => $row['session_status'],
                'slot_number'       => (int) $row['slot_number'],
                'slot_count'        => (int) ($counts[$sessionId] ?? 1),
                'store'             => ['public_id' => $row['store_public_id'], 'store_name' => $row['store_name'], 'store_timezone' => $row['store_timezone']],
                'staff'             => $people,
                'resources'         => $resources[(int) $row['slot_id']] ?? [],
                'capacity'          => (int) $row['capacity'],
                'seats_booked'      => (int) $row['seats_booked'],
                'customers'         => $appointment ? self::customersOf($bookings[$sessionId]) : [],
                'is_mine'           => in_array(true, array_column($people, 'is_me'), true),
            ] + self::moments($row, $row['store_timezone']);
        }, $rows);
    }

    /**
     * One session of the business, with all its scheduled slots (each with whom and what it holds) and who booked it
     * @param int      $businessId
     * @param string   $publicId service_session.public_id
     * @param int|null $viewerId client_master.id of the person looking
     * @param string   $language For the session name
     * @return array|null { public_id, kind, name, service_name, variant_label, session_status, capacity, seats_booked,
     *         store, starts_at, ends_at (and local_), slots: [{ slot_number, starts_at, ends_at (and local_), staff,
     *         resources }], bookings: [{ order_public_id, order_number, name, quantity, participants, no_show }],
     *         staff (everyone held by any slot, by name) }; null when the business has no such session
     */
    public function session(int $businessId, string $publicId, ?int $viewerId, string $language): ?array
    {
        $row = $this->db->table($this->table . ' s')
            ->select('s.id, s.public_id, s.session_name, s.session_description, s.capacity, s.seats_booked, s.session_status, s.starts_at, s.ends_at, '
                . 'm.artifact_name, st.public_id AS store_public_id, st.store_name, st.store_timezone')
            ->join('artifact_master m', 'm.id = s.artifact_id')
            ->join('business_store st', 'st.id = s.store_id')
            ->where('s.business_id', $businessId)->where('s.public_id', $publicId)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $sessionId = (int) $row['id'];
        $slotRows  = $this->db->table(ServiceSessionModel::SLOTS)->select('id, slot_number, starts_at, ends_at')
            ->where('session_id', $sessionId)->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->orderBy('starts_at')->orderBy('slot_number')->get()->getResultArray();
        $slotIds   = array_map('intval', array_column($slotRows, 'id'));
        $staff     = $this->staffOf($slotIds, $viewerId);
        $resources = $this->resourcesOf($slotIds);
        $bookings  = $this->bookingsOf([$sessionId])[$sessionId] ?? [];
        $label     = $this->db->table('order_line')->select('variant_label')->where('session_id', $sessionId)
            ->where('variant_label IS NOT NULL', null, false)->get(1)->getRowArray();

        $slots = [];
        $everyone = [];
        foreach ($slotRows as $slot) {
            $people = $staff[(int) $slot['id']] ?? [];
            foreach ($people as $person) {
                $everyone[$person['uid']] = $person;
            }
            $slots[] = [
                'slot_number' => (int) $slot['slot_number'],
                'staff'       => $people,
                'resources'   => $resources[(int) $slot['id']] ?? [],
            ] + self::moments($slot, $row['store_timezone']);
        }
        uasort($everyone, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $appointment = self::isAppointment($bookings);

        return [
            'public_id'           => $row['public_id'],
            'kind'                => $appointment ? self::KIND_APPOINTMENT : self::KIND_GROUP,
            'name'                => $appointment ? $row['artifact_name'] : ($this->namesIn([$sessionId], $language)[$sessionId] ?? $row['session_name']),
            'service_name'        => $row['artifact_name'],
            'variant_label'       => $label['variant_label'] ?? null,
            'session_description' => $appointment ? null : $row['session_description'],
            'session_status'      => $row['session_status'],
            'capacity'            => (int) $row['capacity'],
            'seats_booked'        => (int) $row['seats_booked'],
            'store'               => ['public_id' => $row['store_public_id'], 'store_name' => $row['store_name'], 'store_timezone' => $row['store_timezone']],
            'slots'               => $slots,
            'bookings'            => self::customersOf($bookings),
            'staff'               => array_values($everyone),
        ] + self::moments($row, $row['store_timezone']);
    }

    /**
     * The people each slot holds, by name
     * @param list<int> $slotIds
     * @return array<int, list<array{uid: string, name: string, full_name: string, avatar_path: string|null, is_me: bool}>> slot_id => people
     */
    private function staffOf(array $slotIds, ?int $viewerId): array
    {
        if ($slotIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table(ServiceSessionModel::STAFF . ' h')
            ->select('h.slot_id, h.client_id, a.uid, a.full_name, a.avatar_path, c.display_name, c.legal_first_name')
            ->join('client_master c', 'c.id = h.client_id')->join('auth_master a', 'a.id = c.auth_id')
            ->whereIn('h.slot_id', $slotIds)->orderBy('a.full_name')->orderBy('a.id')->get()->getResultArray() as $row) {
            $out[(int) $row['slot_id']][] = [
                'uid'         => $row['uid'],
                'name'        => trim((string) $row['display_name']) !== '' ? trim((string) $row['display_name']) : $row['full_name'],
                'full_name'   => $row['full_name'],
                'avatar_path' => $row['avatar_path'],
                'is_me'       => $viewerId !== null && (int) $row['client_id'] === $viewerId,
            ];
        }
        foreach ($out as &$people) {
            usort($people, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        }
        unset($people);

        return $out;
    }

    /**
     * The resources each slot holds, by type then name
     * @param list<int> $slotIds
     * @return array<int, list<array{public_id: string, resource_name: string, type_name: string}>> slot_id => resources
     */
    private function resourcesOf(array $slotIds): array
    {
        if ($slotIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table(ServiceSessionModel::RESOURCES . ' h')
            ->select('h.slot_id, r.public_id, r.resource_name, t.type_name')
            ->join('resource_master r', 'r.id = h.resource_id')->join('resource_type t', 't.id = r.resource_type_id')
            ->whereIn('h.slot_id', $slotIds)->orderBy('t.type_name')->orderBy('r.sort_order')->orderBy('r.resource_name')
            ->get()->getResultArray() as $row) {
            $out[(int) $row['slot_id']][] = ['public_id' => $row['public_id'], 'resource_name' => $row['resource_name'], 'type_name' => $row['type_name']];
        }

        return $out;
    }

    /**
     * The order lines that booked the sessions, of orders not cancelled, each with its participants
     * @param list<int> $sessionIds
     * @return array<int, list<array>> session_id => lines, oldest order first
     */
    private function bookingsOf(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $lines = $this->db->table('order_line l')
            ->select('l.id, l.session_id, l.line_kind, l.quantity, o.public_id, o.order_number, o.first_name, o.last_name, o.no_show_at')
            ->join('order_master o', 'o.id = l.order_id')
            ->whereIn('l.session_id', $sessionIds)->where('o.cancelled_at IS NULL', null, false)
            ->orderBy('o.created_at')->orderBy('o.id')->orderBy('l.line_number')->get()->getResultArray();
        if ($lines === []) {
            return [];
        }
        $participants = [];
        foreach ($this->db->table('order_line_participant')->select('line_id, participant_name')
            ->whereIn('line_id', array_map('intval', array_column($lines, 'id')))->orderBy('seat_number')->get()->getResultArray() as $row) {
            $participants[(int) $row['line_id']][] = $row['participant_name'];
        }
        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line['session_id']][] = $line + ['participants' => $participants[(int) $line['id']] ?? []];
        }

        return $out;
    }

    /** The session names in $language, where the session has them */
    private function namesIn(array $sessionIds, string $language): array
    {
        if ($sessionIds === [] || $language === '') {
            return [];
        }

        return array_column($this->db->table('service_session_translation')->select('session_id, session_name')
            ->whereIn('session_id', $sessionIds)->where('language_code', $language)->get()->getResultArray(), 'session_name', 'session_id');
    }

    /** A session is an appointment when an order line booked it as one */
    private static function isAppointment(array $lines): bool
    {
        return in_array(self::KIND_APPOINTMENT, array_column($lines, 'line_kind'), true);
    }

    /**
     * The bookings as they go out: who ordered, for whom, how many
     * @return list<array{order_public_id: string, order_number: string|null, name: string, quantity: int, participants: list<string>, no_show: bool}>
     */
    private static function customersOf(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'order_public_id' => $line['public_id'],
            'order_number'    => $line['order_number'],
            'name'            => trim($line['first_name'] . ' ' . $line['last_name']),
            'quantity'        => (int) $line['quantity'],
            'participants'    => $line['participants'],
            'no_show'         => $line['no_show_at'] !== null,
        ], $lines);
    }

    /** starts_at and ends_at in UTC, and in the store's clock as local_starts_at, local_ends_at */
    private static function moments(array $row, ?string $timezone): array
    {
        $utc   = new DateTimeZone('UTC');
        $local = self::zone($timezone);
        $out   = [];
        foreach (['starts_at', 'ends_at'] as $field) {
            if (($row[$field] ?? null) === null || $row[$field] === '') {
                $out[$field] = $out['local_' . $field] = null;

                continue;
            }
            $moment                 = new DateTimeImmutable($row[$field], $utc);
            $out[$field]            = $moment->format(self::UTC_FORMAT);
            $out['local_' . $field] = $moment->setTimezone($local)->format(self::LOCAL_FORMAT);
        }

        return $out;
    }

    private static function zone(?string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone ?: 'UTC');
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
