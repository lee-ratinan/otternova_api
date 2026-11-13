<?php

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The sessions of a group service (a variant with booking_type G) a customer may book on the mart: read only, never
 * cached, as every booking changes them. A session is offered when
 * - it is open (session_status O) and has a scheduled slot, at a physical store
 * - its booking has not closed: booking_closes_at, or its start when that is NULL, is after now
 * - it has a seat left (seats_booked under capacity)
 * The customer books the whole session, every scheduled slot of it. The people go out under their
 * auth_master.public_id, never their ids or uids (a uid is made from the email address).
 */
class MartSessionModel extends Model
{
    protected $table      = 'service_session';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    private const DB_FORMAT    = 'Y-m-d H:i:s';
    private const LOCAL_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * The variant when a customer may book a session of it: active, of an active service of the business, and a
     * group one (G)
     * @param int    $businessId
     * @param string $publicId artifact_variant.public_id
     * @return array|null { id, public_id, artifact_id, artifact_public_id, capacity, duration_minutes }
     */
    public function groupVariant(int $businessId, string $publicId): ?array
    {
        $row = $this->db->table('artifact_variant v')
            ->select('v.id, v.public_id, v.artifact_id, a.public_id AS artifact_public_id, v.capacity, v.duration_minutes')
            ->join('artifact_master a', 'a.id = v.artifact_id')
            ->where('a.business_id', $businessId)->where('v.public_id', $publicId)
            ->where('a.artifact_type', ArtifactMasterModel::TYPE_SERVICE)->where('a.artifact_status', ArtifactMasterModel::STATUS_ACTIVE)
            ->where('v.variant_status', 'A')->where('v.booking_type', ArtifactMasterModel::BOOKING_GROUP)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return ['id' => (int) $row['id'], 'artifact_id' => (int) $row['artifact_id'],
            'capacity' => $row['capacity'] === null ? null : (int) $row['capacity'],
            'duration_minutes' => $row['duration_minutes'] === null ? null : (int) $row['duration_minutes']] + $row;
    }

    /**
     * The sessions of the variant a customer may book now, by start, each with its scheduled slots and whom they hold
     * @param int               $businessId
     * @param int               $variantId
     * @param string            $language For the session and store names
     * @param DateTimeImmutable $now
     * @return array{sessions: list<array>, stores: array<int, array>, staff: array<int, array>}
     *         sessions: { public_id, name, description, store_id, timezone, starts_at, ends_at, booking_closes_at (ISO
     *         8601 with the store's offset; booking_closes_at the effective one), local_start_date (Y-m-d on the
     *         store's clock), capacity, seats_booked, seats_available, staff: list<client_id>, slots: [{slot_number,
     *         starts_at, ends_at, staff: list<client_id>}] };
     *         stores: store id => storeRows(); staff: client_id => { public_id, name, restrictions }, by name
     */
    public function bookable(int $businessId, int $variantId, string $language, DateTimeImmutable $now): array
    {
        $at   = $now->setTimezone(new DateTimeZone('UTC'))->format(self::DB_FORMAT);
        $rows = $this->db->table($this->table . ' s')
            ->select('s.id, s.public_id, s.store_id, s.session_name, s.session_description, s.capacity, s.seats_booked, s.starts_at, s.ends_at, '
                . 's.booking_closes_at, st.store_timezone')
            ->join('business_store st', 'st.id = s.store_id')
            ->where('s.business_id', $businessId)->where('s.variant_id', $variantId)
            ->where('s.session_status', ServiceSessionModel::STATUS_OPEN)
            ->where('st.store_type', BusinessStoreModel::TYPE_PHYSICAL)
            ->where('s.starts_at IS NOT NULL', null, false)
            ->where('s.seats_booked < s.capacity', null, false)
            ->groupStart()
                ->groupStart()->where('s.booking_closes_at', null)->where('s.starts_at >', $at)->groupEnd()
                ->orWhere('s.booking_closes_at >', $at)
            ->groupEnd()
            ->orderBy('s.starts_at')->orderBy('s.id')
            ->get()->getResultArray();
        if ($rows === []) {
            return ['sessions' => [], 'stores' => [], 'staff' => []];
        }
        $ids = array_map('intval', array_column($rows, 'id'));

        $names = [];
        foreach ($this->db->table('service_session_translation')->select('session_id, session_name, session_description')
            ->whereIn('session_id', $ids)->where('language_code', $language)->get()->getResultArray() as $row) {
            $names[(int) $row['session_id']] = $row;
        }

        $slots = [];
        foreach ($this->db->table(ServiceSessionModel::SLOTS)->select('id, session_id, slot_number, starts_at, ends_at')
            ->whereIn('session_id', $ids)->where('slot_status', ServiceSessionModel::SLOT_SCHEDULED)
            ->orderBy('starts_at')->orderBy('slot_number')->get()->getResultArray() as $row) {
            $slots[(int) $row['session_id']][(int) $row['id']] = ['slot_number' => (int) $row['slot_number'], 'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'], 'staff' => []];
        }
        $slotIds = array_merge([], ...array_map('array_keys', array_values($slots)));
        $held    = [];
        if ($slotIds !== []) {
            foreach ($this->db->table(ServiceSessionModel::STAFF)->select('slot_id, client_id')->whereIn('slot_id', $slotIds)->get()->getResultArray() as $row) {
                $held[(int) $row['slot_id']][] = (int) $row['client_id'];
            }
        }
        $staff = $this->people($businessId, array_values(array_unique(array_merge([], ...array_values($held)))));

        $sessions = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (! isset($slots[$id])) {
                continue;
            }
            $zone = new DateTimeZone($row['store_timezone'] ?: 'UTC');
            $out  = [];
            $all  = [];
            foreach ($slots[$id] as $slotId => $slot) {
                // by name, as the staff list is
                $people = array_values(array_filter(array_keys($staff), static fn (int $clientId): bool => in_array($clientId, $held[$slotId] ?? [], true)));
                $all    = array_merge($all, $people);
                $out[]  = ['slot_number' => $slot['slot_number'], 'starts_at' => self::local($slot['starts_at'], $zone),
                    'ends_at' => self::local($slot['ends_at'], $zone), 'staff' => $people];
            }
            $translated = $names[$id] ?? null;
            $sessions[] = [
                'public_id'         => $row['public_id'],
                'name'              => trim((string) ($translated['session_name'] ?? '')) !== '' ? $translated['session_name'] : $row['session_name'],
                'description'       => trim((string) ($translated['session_description'] ?? '')) !== '' ? $translated['session_description'] : $row['session_description'],
                'store_id'          => (int) $row['store_id'],
                'timezone'          => $zone->getName(),
                'starts_at'         => self::local($row['starts_at'], $zone),
                'ends_at'           => self::local($row['ends_at'], $zone),
                'booking_closes_at' => self::local($row['booking_closes_at'] ?? $row['starts_at'], $zone),
                'local_start_date'  => (new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d'),
                'capacity'          => (int) $row['capacity'],
                'seats_booked'      => (int) $row['seats_booked'],
                'seats_available'   => max(0, (int) $row['capacity'] - (int) $row['seats_booked']),
                'staff'             => array_values(array_filter(array_keys($staff), static fn (int $clientId): bool => in_array($clientId, $all, true))),
                'slots'             => $out,
            ];
        }

        return [
            'sessions' => $sessions,
            'stores'   => $this->storeRows(array_values(array_unique(array_column($sessions, 'store_id'))), $language),
            'staff'    => $staff,
        ];
    }

    /**
     * Stores by name, each in the language where it has its name or address in it
     * @param list<int> $storeIds
     * @param string    $language
     * @return array<int, array{public_id: string, name: string, address: array, phone_number: string|null, timezone: string}> id => store
     */
    private function storeRows(array $storeIds, string $language): array
    {
        if ($storeIds === []) {
            return [];
        }
        $texts = [];
        foreach ($this->db->table('business_store_translation')->select('store_id, store_name, store_address_line_1, store_address_line_2, store_city')
            ->whereIn('store_id', $storeIds)->where('language_code', $language)->get()->getResultArray() as $row) {
            $texts[(int) $row['store_id']] = $row;
        }
        $pick = static fn (?array $text, array $row, string $column): ?string => trim((string) ($text[$column] ?? '')) !== '' ? $text[$column] : $row[$column];
        $out  = [];
        foreach ($this->db->table('business_store')
            ->select('id, public_id, store_name, store_address_line_1, store_address_line_2, store_city, store_subdivision_code, store_postal_code, store_phone_number, store_timezone')
            ->whereIn('id', $storeIds)->get()->getResultArray() as $row) {
            $text                  = $texts[(int) $row['id']] ?? null;
            $out[(int) $row['id']] = [
                'public_id'    => $row['public_id'],
                'name'         => $pick($text, $row, 'store_name'),
                'address'      => [
                    'line_1'           => $pick($text, $row, 'store_address_line_1'),
                    'line_2'           => $pick($text, $row, 'store_address_line_2'),
                    'city'             => $pick($text, $row, 'store_city'),
                    'subdivision_code' => $row['store_subdivision_code'],
                    'postal_code'      => $row['store_postal_code'],
                ],
                'phone_number' => $row['store_phone_number'],
                'timezone'     => $row['store_timezone'],
            ];
        }
        uasort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The people the sessions hold, as a customer sees them, by name
     * @param int       $businessId
     * @param list<int> $clientIds
     * @return array<int, array{public_id: string, name: string, restrictions: array|null}> client_id => person
     */
    private function people(int $businessId, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $restrictions = model(MartSlotModel::class)->restrictionsOf($businessId, $clientIds);
        $out          = [];
        foreach ($this->db->table('client_master c')->select('c.id, a.public_id, c.display_name, c.legal_first_name, a.full_name')
            ->join('auth_master a', 'a.id = c.auth_id')->whereIn('c.id', $clientIds)->get()->getResultArray() as $row) {
            $out[(int) $row['id']] = ['public_id' => $row['public_id'], 'name' => MartSlotModel::publicName($row), 'restrictions' => $restrictions[(int) $row['id']] ?? null];
        }
        uasort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** A UTC datetime as stored, in ISO 8601 with the store's offset */
    private static function local(?string $at, DateTimeZone $zone): ?string
    {
        return $at === null || $at === '' ? null : (new DateTimeImmutable($at, new DateTimeZone('UTC')))->setTimezone($zone)->format(self::LOCAL_FORMAT);
    }
}
