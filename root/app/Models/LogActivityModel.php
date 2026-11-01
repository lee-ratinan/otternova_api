<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the log_activity table: an append-only log of every change in the system.
 * Write through logBySystem(), logByCustomer() or logByAccount() so at most one actor is ever set.
 */
class LogActivityModel extends Model
{
    use FieldConfigurationTrait;

    // actor, derived from changed_by_cust / changed_by_acc
    public const ACTOR_SYSTEM   = 'system';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_ACCOUNT  = 'account';

    protected $table            = 'log_activity';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'activity_summary',
        'table_involved',
        'table_id_updated',
        'activity_detail',
        'changed_by_cust',
        'changed_by_acc',
    ];

    // created_at is maintained by the database; there is no updated_at
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';

    protected $validationRules = [
        'id'               => 'permit_empty|is_natural_no_zero',
        'activity_summary' => 'required',
        'table_involved'   => 'permit_empty|max_length[128]',
        'table_id_updated' => 'permit_empty|is_natural_no_zero',
        'activity_detail'  => 'permit_empty|valid_json',
        'changed_by_cust'  => 'permit_empty|is_natural_no_zero',
        'changed_by_acc'   => 'permit_empty|is_natural_no_zero',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Field configuration shared by the Form Builder (as 'fields') and the Table Builder (via toColumns()),
     * keyed by field name so a controller can unset() what it does not need. Log rows are view-only.
     * @return array
     */
    public function getConfiguration(): array
    {
        $fields = [
            'activity_summary' => ['type' => 'textarea', 'rows' => 2, 'readonly' => true],
            'table_involved'   => ['type' => 'text', 'readonly' => true],
            'table_id_updated' => ['type' => 'number', 'readonly' => true],
            'activity_detail'  => ['type' => 'textarea', 'rows' => 8, 'readonly' => true, 'orderable' => false, 'searchable' => false],
            'changed_by_cust'  => ['type' => 'number', 'readonly' => true],
            'changed_by_acc'   => ['type' => 'number', 'readonly' => true],
        ];

        return $this->buildConfiguration('LogActivity', $fields);
    }

    /**
     * Log a change made by the system, e.g. a cron job
     * @param string      $summary
     * @param string|null $table   Name of the table changed
     * @param int|null    $rowId   ID of the row changed
     * @param array|null  $detail  The changes, stored as JSON
     * @return int|false The log ID
     */
    public function logBySystem(string $summary, ?string $table = null, ?int $rowId = null, ?array $detail = null): int|false
    {
        return $this->write($summary, $table, $rowId, $detail, null, null);
    }

    /**
     * Log a change made by a customer
     * @param int         $customerId customer_master.id
     * @param string      $summary
     * @param string|null $table
     * @param int|null    $rowId
     * @param array|null  $detail
     * @return int|false
     */
    public function logByCustomer(int $customerId, string $summary, ?string $table = null, ?int $rowId = null, ?array $detail = null): int|false
    {
        return $this->write($summary, $table, $rowId, $detail, $customerId, null);
    }

    /**
     * Log a change made by an auth_master account
     * @param int         $authId auth_master.id
     * @param string      $summary
     * @param string|null $table
     * @param int|null    $rowId
     * @param array|null  $detail
     * @return int|false
     */
    public function logByAccount(int $authId, string $summary, ?string $table = null, ?int $rowId = null, ?array $detail = null): int|false
    {
        return $this->write($summary, $table, $rowId, $detail, null, $authId);
    }

    /**
     * The history of one row, newest first
     * @param string $table
     * @param int    $rowId
     * @return array
     */
    public function findByRecord(string $table, int $rowId): array
    {
        return $this->where('table_involved', $table)
            ->where('table_id_updated', $rowId)
            ->orderBy('id', 'DESC')
            ->findAll();
    }

    /**
     * Who made the change in a log row
     * @param array $row
     * @return string One of the ACTOR_* constants
     */
    public static function actorOf(array $row): string
    {
        if (! empty($row['changed_by_cust'])) {
            return self::ACTOR_CUSTOMER;
        }
        if (! empty($row['changed_by_acc'])) {
            return self::ACTOR_ACCOUNT;
        }

        return self::ACTOR_SYSTEM;
    }

    /**
     * @param string      $summary
     * @param string|null $table
     * @param int|null    $rowId
     * @param array|null  $detail
     * @param int|null    $customerId
     * @param int|null    $authId
     * @return int|false
     */
    private function write(string $summary, ?string $table, ?int $rowId, ?array $detail, ?int $customerId, ?int $authId): int|false
    {
        return $this->insert([
            'activity_summary' => $summary,
            'table_involved'   => $table,
            'table_id_updated' => $rowId,
            'activity_detail'  => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'changed_by_cust'  => $customerId,
            'changed_by_acc'   => $authId,
        ]);
    }
}
