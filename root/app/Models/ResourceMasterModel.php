<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the resource_master table: the real resources of a store ("101", "102", "103"), each of a resource_type
 * of the store's business, which the database checks. That the store is a physical one is checked by the caller, as
 * the database cannot. store_id and resource_type_id are fixed once created. Resources are never deleted;
 * resource_status I takes one out of service.
 */
class ResourceMasterModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    // resource_status
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    protected $table            = 'resource_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'business_id', 'store_id', 'resource_type_id', 'resource_name', 'capacity', 'resource_status', 'sort_order'];

    /** public_id: CHAR(12), see PublicIdTrait */
    protected int $publicIdLength = 12;
    protected $beforeInsert       = ['fillPublicId'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'resource_name'   => 'required|max_length[255]',
        'capacity'        => 'required|is_natural_no_zero|less_than_equal_to[65535]', // customers at once
        'resource_status' => 'required|in_list[A,I]',
        'sort_order'      => 'required|is_natural|less_than_equal_to[65535]',
    ];

    /**
     * The resources of a business, of one store and one type when given, by store, type, sort_order and name; each
     * with its store and type by public_id and name. The ids stay inside.
     * @param int      $businessId
     * @param int|null $storeId
     * @param int|null $typeId
     * @return list<array>
     */
    public function listOfBusiness(int $businessId, ?int $storeId = null, ?int $typeId = null): array
    {
        $builder = $this->db->table($this->table . ' r')
            ->select('r.public_id, r.resource_name, r.capacity, r.resource_status, r.sort_order, s.public_id AS store_public_id, s.store_name, '
                . 't.public_id AS type_public_id, t.type_name, t.type_status')
            ->join('business_store s', 's.id = r.store_id')
            ->join('resource_type t', 't.id = r.resource_type_id')
            ->where('r.business_id', $businessId);
        if ($storeId !== null) {
            $builder->where('r.store_id', $storeId);
        }
        if ($typeId !== null) {
            $builder->where('r.resource_type_id', $typeId);
        }
        $rows = $builder->orderBy('s.store_name')->orderBy('t.type_name')->orderBy('r.sort_order')->orderBy('r.resource_name')->orderBy('r.id')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'public_id'       => $row['public_id'],
            'resource_name'   => $row['resource_name'],
            'capacity'        => (int) $row['capacity'],
            'resource_status' => $row['resource_status'],
            'sort_order'      => (int) $row['sort_order'],
            'store'           => ['public_id' => $row['store_public_id'], 'store_name' => $row['store_name']],
            'resource_type'   => ['public_id' => $row['type_public_id'], 'type_name' => $row['type_name'], 'type_status' => $row['type_status']],
        ], $rows);
    }

    /**
     * Whether another resource of the same type at the store has this name (the database refuses it; this tells the
     * form first)
     * @param int      $storeId
     * @param int      $typeId
     * @param string   $name
     * @param int|null $exceptId The resource being edited
     * @return bool
     */
    public function nameTaken(int $storeId, int $typeId, string $name, ?int $exceptId = null): bool
    {
        $builder = $this->db->table($this->table)->where('store_id', $storeId)->where('resource_type_id', $typeId)->where('resource_name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Create a resource, logged to log_activity in the same transaction
     * @param array $resource Checked resource_master columns: business_id, store_id, resource_type_id included
     * @param int   $actorId
     * @return string The new resource's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function createLogged(array $resource, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($resource, $actorId, $log, $publicId) {
            $row = ['public_id' => $publicId] + $resource;
            $this->db->table($this->table)->insert($row);
            $log->inserted($actorId, 'Created resource ' . $publicId . ' at store ' . $row['store_id'], $this->table, (int) $this->db->insertID(), $row);

            return $publicId;
        }));
    }

    /**
     * Update a resource's name, capacity, status and order; only what changes is written and logged
     * @param array $stored   The resource_master row
     * @param array $resource Checked resource_name, capacity, resource_status, sort_order
     * @param int   $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function updateLogged(array $stored, array $resource, int $actorId): void
    {
        $changes = ActivityLog::changes($stored, array_intersect_key($resource, array_flip(['resource_name', 'capacity', 'resource_status', 'sort_order'])));
        if ($changes === []) {
            return;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($stored, $changes, $actorId, $log) {
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            $log->updated($actorId, 'Updated resource ' . $stored['public_id'], $this->table, (int) $stored['id'], $stored, $changes);
        });
    }
}
