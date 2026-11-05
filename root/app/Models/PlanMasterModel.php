<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use Throwable;

/**
 * Model for the plan_master table: the plans (free, boost, velocity, quantum), known by plan_code everywhere.
 * The free plan is the one with plan_tier 0; it has no prices, as a business with no live subscription is on it.
 */
class PlanMasterModel extends Model
{
    use TransactionTrait;

    /** Columns /staff/plans/plan/create writes; /staff/plans/plan/edit writes them all but plan_code */
    public const FORM_FIELDS   = ['plan_code', 'plan_description', 'plan_tier'];
    public const FORM_NULLABLE = ['plan_description'];

    /** plan_tier of the free plan */
    public const FREE_TIER = 0;

    protected $table            = 'plan_master';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = self::FORM_FIELDS;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural_no_zero',
        'plan_code'        => 'required|regex_match[/^[a-z][a-z0-9_]{1,19}$/]|is_unique[plan_master.plan_code,id,{id}]',
        'plan_description' => 'permit_empty|max_length[2000]',
        'plan_tier'        => 'required|is_natural|less_than_equal_to[255]|is_unique[plan_master.plan_tier,id,{id}]',
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * @param mixed $code From the request
     * @return array|null
     */
    public function findByCode(mixed $code): ?array
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $this->db->table($this->table)->where('plan_code', $code)->get()->getRowArray();
    }

    /**
     * Every plan, cheapest tier first
     * @return list<array{plan_code: string, plan_description: string|null, plan_tier: int}>
     */
    public function listPlans(): array
    {
        $rows = $this->db->table($this->table)
            ->select('plan_code, plan_description, plan_tier, created_at, updated_at')
            ->orderBy('plan_tier', 'ASC')
            ->get()
            ->getResultArray();
        foreach ($rows as &$row) {
            $row['plan_tier'] = (int) $row['plan_tier'];
        }

        return $rows;
    }

    /**
     * @param array  $row     Checked columns
     * @param int    $actorId
     * @param string $summary
     * @return int
     * @throws Throwable
     */
    public function createLogged(array $row, int $actorId, string $summary): int
    {
        return $this->inTransaction(function () use ($row, $actorId, $summary) {
            $this->db->table($this->table)->insert($row);
            $id = (int) $this->db->insertID();
            (new ActivityLog())->inserted($actorId, $summary, $this->table, $id, $row);

            return $id;
        });
    }

    /**
     * Only the columns whose value changes are written and logged
     * @param array  $stored
     * @param array  $row
     * @param int    $actorId
     * @param string $summary
     * @return void
     * @throws Throwable
     */
    public function updateLogged(array $stored, array $row, int $actorId, string $summary): void
    {
        $changes = ActivityLog::changes($stored, $row);
        if ($changes === []) {
            return;
        }
        $this->inTransaction(function () use ($stored, $changes, $actorId, $summary) {
            $this->db->table($this->table)->where('id', $stored['id'])->update($changes);
            (new ActivityLog())->updated($actorId, $summary, $this->table, (int) $stored['id'], $stored, $changes);
        });
    }
}
