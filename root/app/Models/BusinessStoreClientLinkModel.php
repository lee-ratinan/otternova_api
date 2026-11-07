<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_store_client_link table: which stores of a business each of its people works in. A person
 * may work in several. The database keeps the store and the person in the same business.
 */
class BusinessStoreClientLinkModel extends Model
{
    use TransactionTrait;

    protected $table            = 'business_store_client_link';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'store_id', 'client_id'];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * @param int $businessId
     * @param int $clientId
     * @return list<int> business_store.id of the stores the person works in
     */
    public function storeIdsOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)->select('store_id')
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->get()->getResultArray();

        return array_map('intval', array_column($rows, 'store_id'));
    }

    /**
     * Make the person work in exactly $storeIds, in one transaction, logged to log_activity in it: the stores left
     * out are removed, the new ones added, the others left as they are
     * @param int       $businessId
     * @param int       $clientId
     * @param list<int> $storeIds Stores of $businessId (the database refuses others)
     * @param int       $actorId  auth_master.id of whoever makes the change
     * @param string    $uid      auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceStores(int $businessId, int $clientId, array $storeIds, int $actorId, string $uid): void
    {
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        $log      = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $storeIds, $actorId, $uid, $log) {
            $rows = $this->db->table($this->table)->where('business_id', $businessId)->where('client_id', $clientId)->get()->getResultArray();
            $have = [];
            foreach ($rows as $row) {
                $have[(int) $row['store_id']] = true;
                if (! in_array((int) $row['store_id'], $storeIds, true)) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Took ' . $uid . ' off store ' . $row['store_id'], $this->table, (int) $row['id'], $row);
                }
            }
            foreach ($storeIds as $storeId) {
                if (! isset($have[$storeId])) {
                    $row = ['business_id' => $businessId, 'store_id' => $storeId, 'client_id' => $clientId];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Put ' . $uid . ' in store ' . $storeId, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }
}
