<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_service_resource_link table: which resource types a service (artifact_master, artifact_type
 * S) needs, and how many of each one booking holds. The database keeps the service and the type in the same
 * business; that the artifact is a service is checked by the caller, as the database cannot.
 */
class BusinessServiceResourceLinkModel extends Model
{
    use TransactionTrait;

    /** The most of one type a booking may hold (quantity, TINYINT UNSIGNED) */
    public const MAX_QUANTITY = 255;

    protected $table            = 'business_service_resource_link';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'artifact_id', 'resource_type_id', 'quantity'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * What a service needs, by type name: each type by public_id with its name, status and the quantity
     * @param int $businessId
     * @param int $artifactId
     * @return list<array{public_id: string, type_name: string, type_status: string, quantity: int}>
     */
    public function needsOf(int $businessId, int $artifactId): array
    {
        $rows = $this->db->table($this->table . ' l')
            ->select('t.public_id, t.type_name, t.type_status, l.quantity')
            ->join('resource_type t', 't.id = l.resource_type_id')
            ->where('l.business_id', $businessId)->where('l.artifact_id', $artifactId)
            ->orderBy('t.type_name')->orderBy('t.id')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => ['quantity' => (int) $row['quantity']] + $row, $rows);
    }

    /**
     * @param int $businessId
     * @param int $artifactId
     * @return array<int, int> resource_type_id => quantity
     */
    public function quantitiesOf(int $businessId, int $artifactId): array
    {
        $out = [];
        foreach ($this->db->table($this->table)->select('resource_type_id, quantity')
            ->where('business_id', $businessId)->where('artifact_id', $artifactId)->get()->getResultArray() as $row) {
            $out[(int) $row['resource_type_id']] = (int) $row['quantity'];
        }

        return $out;
    }

    /**
     * Make the service need exactly the types of $needs, in those quantities, in one transaction, logged to
     * log_activity in it. Only what changes is written.
     * @param array           $artifact The artifact_master row (id, public_id, business_id)
     * @param array<int, int> $needs    resource_type_id => quantity, checked types of the business
     * @param int             $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceNeeds(array $artifact, array $needs, int $actorId): void
    {
        $businessId = (int) $artifact['business_id'];
        $artifactId = (int) $artifact['id'];
        $what       = 'service ' . $artifact['public_id'];
        $log        = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $artifactId, $what, $needs, $actorId, $log) {
            $rows = $this->db->table($this->table)->where('business_id', $businessId)->where('artifact_id', $artifactId)->get()->getResultArray();
            $have = [];
            foreach ($rows as $row) {
                $typeId        = (int) $row['resource_type_id'];
                $have[$typeId] = true;
                if (! isset($needs[$typeId])) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Took resource type ' . $typeId . ' off ' . $what, $this->table, (int) $row['id'], $row);
                    continue;
                }
                $changes = ActivityLog::changes(['quantity' => (int) $row['quantity']], ['quantity' => $needs[$typeId]]);
                if ($changes !== []) {
                    $this->db->table($this->table)->where('id', $row['id'])->update($changes);
                    $log->updated($actorId, 'Changed how many of resource type ' . $typeId . ' ' . $what . ' needs', $this->table, (int) $row['id'], $row, $changes);
                }
            }
            foreach ($needs as $typeId => $quantity) {
                if (! isset($have[$typeId])) {
                    $row = ['business_id' => $businessId, 'artifact_id' => $artifactId, 'resource_type_id' => $typeId, 'quantity' => $quantity];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Gave resource type ' . $typeId . ' to ' . $what, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }
}
