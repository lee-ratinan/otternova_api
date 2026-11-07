<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_service_client_link table: which services (artifact_master, artifact_type S) each person of
 * a business may perform, and the commission they earn on them. The database keeps the service and the person in the
 * same business; that the artifact is a service is checked by the caller, as the database cannot.
 * commission_value is a percentage (commission_unit P, at most 100) or a fixed amount per service (F); 0 for none.
 */
class BusinessServiceClientLinkModel extends Model
{
    use TransactionTrait;

    // commission_unit
    public const UNIT_PERCENTAGE = 'P';
    public const UNIT_FIXED      = 'F';

    protected $table            = 'business_service_client_link';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'artifact_id', 'client_id', 'commission_value', 'commission_unit'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * @param int $businessId
     * @param int $clientId
     * @return array<int, array{commission_value: string, commission_unit: string}> artifact_id => the commission
     */
    public function servicesOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)->select('artifact_id, commission_value, commission_unit')
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['artifact_id']] = [
                'commission_value' => number_format((float) $row['commission_value'], 2, '.', ''),
                'commission_unit'  => $row['commission_unit'],
            ];
        }

        return $out;
    }

    /**
     * Make the person perform exactly the services of $services, at those commissions, and set their own commission
     * threshold, in one transaction, logged to log_activity in it. Only what changes is written.
     * @param array      $link      Their business_client_link row
     * @param array      $services  artifact_id => [commission_value, commission_unit], checked services of the business
     * @param string|null $threshold business_client_link.commission_threshold; null to use the business's
     * @param int        $actorId   auth_master.id of whoever makes the change
     * @param string     $uid       auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceServices(array $link, array $services, ?string $threshold, int $actorId, string $uid): void
    {
        $businessId = (int) $link['business_id'];
        $clientId   = (int) $link['client_id'];
        $log        = new ActivityLog();
        $this->inTransaction(function () use ($link, $businessId, $clientId, $services, $threshold, $actorId, $uid, $log) {
            $rows = $this->db->table($this->table)->where('business_id', $businessId)->where('client_id', $clientId)->get()->getResultArray();
            $have = [];
            foreach ($rows as $row) {
                $artifactId        = (int) $row['artifact_id'];
                $have[$artifactId] = true;
                if (! isset($services[$artifactId])) {
                    $this->db->table($this->table)->where('id', $row['id'])->delete();
                    $log->deleted($actorId, 'Took service ' . $artifactId . ' from ' . $uid, $this->table, (int) $row['id'], $row);
                    continue;
                }
                $changes = ActivityLog::changes(
                    ['commission_value' => number_format((float) $row['commission_value'], 2, '.', ''), 'commission_unit' => $row['commission_unit']],
                    $services[$artifactId],
                );
                if ($changes !== []) {
                    $this->db->table($this->table)->where('id', $row['id'])->update($changes);
                    $log->updated($actorId, 'Changed the commission of ' . $uid . ' on service ' . $artifactId, $this->table, (int) $row['id'], $row, $changes);
                }
            }
            foreach ($services as $artifactId => $commission) {
                if (! isset($have[$artifactId])) {
                    $row = ['business_id' => $businessId, 'artifact_id' => $artifactId, 'client_id' => $clientId] + $commission;
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, 'Gave service ' . $artifactId . ' to ' . $uid, $this->table, (int) $this->db->insertID(), $row);
                }
            }

            $stored = $link['commission_threshold'] === null ? null : number_format((float) $link['commission_threshold'], 2, '.', '');
            if ($stored !== $threshold) {
                $this->db->table('business_client_link')->where('id', $link['id'])->update(['commission_threshold' => $threshold]);
                $log->updated($actorId, 'Changed the commission threshold of ' . $uid, 'business_client_link', (int) $link['id'],
                    ['commission_threshold' => $link['commission_threshold']], ['commission_threshold' => $threshold]);
            }
        });
    }
}
