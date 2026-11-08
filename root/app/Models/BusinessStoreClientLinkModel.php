<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_store_client_link table: which stores of a business each of its people works in. A person
 * may work in several. The database keeps the store and the person in the same business. Rows are never deleted: a
 * person taken off a store keeps the row as link_status I, and their shifts there (business_client_hours, and
 * business_client_hours_exceptions from the store's today on) become inactive with it.
 */
class BusinessStoreClientLinkModel extends Model
{
    use TransactionTrait;

    // link_status, and active_status of business_client_hours and business_client_hours_exceptions
    public const STATUS_ACTIVE   = 'A';
    public const STATUS_INACTIVE = 'I';

    protected $table            = 'business_store_client_link';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'store_id', 'client_id', 'link_status'];

    // created_at is maintained by the database
    protected $useTimestamps = false;

    /**
     * @param int $businessId
     * @param int $clientId
     * @return list<int> business_store.id of the stores the person works in now (the active links)
     */
    public function storeIdsOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)->select('store_id')
            ->where('business_id', $businessId)->where('client_id', $clientId)->where('link_status', self::STATUS_ACTIVE)
            ->get()->getResultArray();

        return array_map('intval', array_column($rows, 'store_id'));
    }

    /**
     * Make the person work in exactly $storeIds, in one transaction, logged to log_activity in it: the stores left
     * out become inactive, with the person's shifts there (the exceptions from the store's today on; the past ones are
     * what happened); the new ones are added, or made active again. Shifts made inactive stay so when the person is
     * put back: their old hours there are kept for reference, not brought back.
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
                $storeId        = (int) $row['store_id'];
                $have[$storeId] = true;
                $wanted         = in_array($storeId, $storeIds, true);
                $status         = $wanted ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
                if ($row['link_status'] === $status) {
                    continue;
                }
                $this->db->table($this->table)->where('id', $row['id'])->update(['link_status' => $status]);
                $log->updated($actorId, ($wanted ? 'Put ' . $uid . ' back in store ' : 'Took ' . $uid . ' off store ') . $storeId,
                    $this->table, (int) $row['id'], $row, ['link_status' => $status]);
                if (! $wanted) {
                    $this->deactivateShifts($businessId, $clientId, $storeId, $actorId, $uid, $log);
                }
            }
            foreach ($storeIds as $storeId) {
                if (! isset($have[$storeId])) {
                    $row = ['business_id' => $businessId, 'store_id' => $storeId, 'client_id' => $clientId, 'link_status' => self::STATUS_ACTIVE];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Put ' . $uid . ' in store ' . $storeId, $this->table, (int) $this->db->insertID(), $row);
                }
            }
        });
    }

    /**
     * Make the person's active shifts at a store inactive: the regular ones, and the exceptions from the store's today on
     * @return void
     */
    private function deactivateShifts(int $businessId, int $clientId, int $storeId, int $actorId, string $uid, ActivityLog $log): void
    {
        $today = model(BusinessStoreHoursModel::class)->localToday($storeId);
        foreach (['business_client_hours' => null, 'business_client_hours_exceptions' => $today] as $table => $from) {
            $builder = $this->db->table($table)->where('business_id', $businessId)->where('client_id', $clientId)
                ->where('store_id', $storeId)->where('active_status', self::STATUS_ACTIVE);
            if ($from !== null) {
                $builder->where('exception_date >=', $from);
            }
            foreach ($builder->get()->getResultArray() as $row) {
                $this->db->table($table)->where('id', $row['id'])->update(['active_status' => self::STATUS_INACTIVE]);
                $log->updated($actorId, 'Ended a shift of ' . $uid . ' at store ' . $storeId, $table, (int) $row['id'], $row, ['active_status' => self::STATUS_INACTIVE]);
            }
        }
    }
}
