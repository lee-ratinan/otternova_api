<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the business_client_restriction table: the customers a person of a business does not serve, at any of
 * its services. Customers declare who they are themselves, so this is never enforced: it is told to a customer before
 * they book the person, who acknowledges it.
 * - restriction_type G: one row per gender they do not serve (excluded_gender)
 * - restriction_type A: the ages they serve, age_min and/or age_max; at most one such row per person
 */
class BusinessClientRestrictionModel extends Model
{
    use TransactionTrait;

    public const TYPE_GENDER = 'G';
    public const TYPE_AGE    = 'A';

    /** excluded_gender: male, female, non-binary; the other genders of client_master are never excluded */
    public const GENDERS = ['M', 'F', 'NB'];

    protected $table            = 'business_client_restriction';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'client_id', 'restriction_type', 'excluded_gender', 'age_min', 'age_max'];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * @param int $businessId
     * @param int $clientId
     * @return array{excluded_genders: list<string>, age_min: int|null, age_max: int|null} Genders in the order of GENDERS
     */
    public function restrictionsOf(int $businessId, int $clientId): array
    {
        $rows = $this->db->table($this->table)
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->get()->getResultArray();
        $out = ['excluded_genders' => [], 'age_min' => null, 'age_max' => null];
        foreach ($rows as $row) {
            if ($row['restriction_type'] === self::TYPE_GENDER) {
                $out['excluded_genders'][] = $row['excluded_gender'];
            } else {
                $out['age_min'] = $row['age_min'] === null ? null : (int) $row['age_min'];
                $out['age_max'] = $row['age_max'] === null ? null : (int) $row['age_max'];
            }
        }
        $out['excluded_genders'] = array_values(array_intersect(self::GENDERS, $out['excluded_genders']));

        return $out;
    }

    /**
     * Make these the person's restrictions, in one transaction, logged to log_activity in it
     * @param int          $businessId
     * @param int          $clientId
     * @param list<string> $genders Of GENDERS, the ones they do not serve
     * @param int|null     $ageMin
     * @param int|null     $ageMax  Both null for no age restriction
     * @param int          $actorId
     * @param string       $uid     auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function replaceRestrictions(int $businessId, int $clientId, array $genders, ?int $ageMin, ?int $ageMax, int $actorId, string $uid): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $genders, $ageMin, $ageMax, $actorId, $uid, $log) {
            $rows    = $this->db->table($this->table)->where('business_id', $businessId)->where('client_id', $clientId)->get()->getResultArray();
            $have    = [];
            $wantAge = $ageMin !== null || $ageMax !== null;
            $hasAge  = false;
            foreach ($rows as $row) {
                if ($row['restriction_type'] === self::TYPE_GENDER) {
                    $have[$row['excluded_gender']] = true;
                    if (in_array($row['excluded_gender'], $genders, true)) {
                        continue;
                    }
                } elseif ($wantAge && ! $hasAge) {
                    $hasAge  = true;
                    $changes = ActivityLog::changes(
                        ['age_min' => $row['age_min'] === null ? null : (int) $row['age_min'], 'age_max' => $row['age_max'] === null ? null : (int) $row['age_max']],
                        ['age_min' => $ageMin, 'age_max' => $ageMax],
                    );
                    if ($changes !== []) {
                        $this->db->table($this->table)->where('id', $row['id'])->update(['age_min' => $ageMin, 'age_max' => $ageMax]);
                        $log->updated($actorId, 'Changed the ages ' . $uid . ' serves', $this->table, (int) $row['id'], $row, ['age_min' => $ageMin, 'age_max' => $ageMax]);
                    }

                    continue;
                }
                $this->db->table($this->table)->where('id', $row['id'])->delete();
                $log->deleted($actorId, 'Removed a restriction of ' . $uid, $this->table, (int) $row['id'], $row);
            }
            $base = ['business_id' => $businessId, 'client_id' => $clientId];
            foreach (array_unique($genders) as $gender) {
                if (! isset($have[$gender])) {
                    $row = $base + ['restriction_type' => self::TYPE_GENDER, 'excluded_gender' => $gender, 'age_min' => null, 'age_max' => null];
                    $this->db->table($this->table)->insert($row);
                    $log->inserted($actorId, $uid . ' does not serve gender ' . $gender, $this->table, (int) $this->db->insertID(), $row);
                }
            }
            if ($wantAge && ! $hasAge) {
                $row = $base + ['restriction_type' => self::TYPE_AGE, 'excluded_gender' => null, 'age_min' => $ageMin, 'age_max' => $ageMax];
                $this->db->table($this->table)->insert($row);
                $log->inserted($actorId, 'Set the ages ' . $uid . ' serves', $this->table, (int) $this->db->insertID(), $row);
            }
        });
    }
}
