<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use CodeIgniter\Model;
use DateTimeImmutable;
use Throwable;

/**
 * Model for the business_client_salary table: the base salary history of each person of a business. Rows never
 * overlap: each starts after the previous one's end_date, and only the latest may have no end_date (the current
 * salary). The history is only ever added to, or its latest row corrected; past rows are payroll records.
 */
class BusinessClientSalaryModel extends Model
{
    use TransactionTrait;

    // salary_period, as business_master.commission_period
    public const PERIOD_WEEKLY       = 'W';
    public const PERIOD_SEMI_MONTHLY = 'S';
    public const PERIOD_MONTHLY      = 'M';
    public const PERIODS             = [self::PERIOD_WEEKLY, self::PERIOD_SEMI_MONTHLY, self::PERIOD_MONTHLY];

    /** The columns a salary row is written with */
    public const FIELDS = ['salary_amount', 'salary_period', 'effective_date', 'end_date'];

    protected $table            = 'business_client_salary';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['business_id', 'client_id', ...self::FIELDS];

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * @param int $businessId
     * @param int $clientId
     * @return list<array{salary_amount: string, salary_period: string, effective_date: string, end_date: string|null}>
     *         The latest first; no ids
     */
    public function historyOf(int $businessId, int $clientId): array
    {
        return array_map(static fn (array $row): array => self::out($row), $this->rowsOf($businessId, $clientId));
    }

    /**
     * Why $salary cannot be added after the history, or null when it can: it must start after the latest row ends,
     * or, when the latest has no end_date, after the latest starts (the latest is then ended the day before)
     * @param array       $salary    Checked FIELDS
     * @param array|null  $latest    The latest row, if any
     * @param string      $startDate business_client_link.start_date: no salary before it
     * @return string|null Language key of Staff
     */
    public static function addError(array $salary, ?array $latest, string $startDate): ?string
    {
        if ($salary['effective_date'] < $startDate) {
            return 'Staff.salaryBeforeStart';
        }
        if ($latest === null) {
            return null;
        }
        if ($latest['end_date'] === null) {
            return $salary['effective_date'] > $latest['effective_date'] ? null : 'Staff.salaryNotAfterCurrent';
        }

        return $salary['effective_date'] > $latest['end_date'] ? null : 'Staff.salaryOverlaps';
    }

    /**
     * Why the latest row cannot become $salary, or null when it can: it must still start after the row before ends
     * @param array      $salary    Checked FIELDS
     * @param array|null $previous  The row before the latest, if any
     * @param string     $startDate business_client_link.start_date
     * @return string|null Language key of Staff
     */
    public static function editError(array $salary, ?array $previous, string $startDate): ?string
    {
        if ($salary['effective_date'] < $startDate) {
            return 'Staff.salaryBeforeStart';
        }

        return $previous === null || $salary['effective_date'] > $previous['end_date'] ? null : 'Staff.salaryOverlaps';
    }

    /**
     * The person's rows, the latest first, with their ids, for the checks and writes here
     * @param int $businessId
     * @param int $clientId
     * @return list<array>
     */
    public function rowsOf(int $businessId, int $clientId): array
    {
        return $this->db->table($this->table)
            ->where('business_id', $businessId)->where('client_id', $clientId)
            ->orderBy('effective_date', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Add a salary after the history, in one transaction, logged to log_activity in it. A current salary (no
     * end_date) is ended the day before the new one starts. addError() must have passed.
     * @param int    $businessId
     * @param int    $clientId
     * @param array  $salary  Checked FIELDS
     * @param int    $actorId auth_master.id of whoever makes the change
     * @param string $uid     auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function add(int $businessId, int $clientId, array $salary, int $actorId, string $uid): void
    {
        $log = new ActivityLog();
        $this->inTransaction(function () use ($businessId, $clientId, $salary, $actorId, $uid, $log) {
            $latest = $this->rowsOf($businessId, $clientId)[0] ?? null;
            if ($latest !== null && $latest['end_date'] === null) {
                $end = (new DateTimeImmutable($salary['effective_date']))->modify('-1 day')->format('Y-m-d');
                $this->db->table($this->table)->where('id', $latest['id'])->update(['end_date' => $end]);
                $log->updated($actorId, 'Ended the salary of ' . $uid . ' from ' . $latest['effective_date'], $this->table, (int) $latest['id'], $latest, ['end_date' => $end]);
            }
            $row = ['business_id' => $businessId, 'client_id' => $clientId] + $salary;
            $this->db->table($this->table)->insert($row);
            $log->inserted($actorId, 'Set the salary of ' . $uid . ' from ' . $salary['effective_date'], $this->table, (int) $this->db->insertID(), $row);
        });
    }

    /**
     * Correct the latest row, logged to log_activity. editError() must have passed.
     * @param array  $latest  The latest row
     * @param array  $salary  Checked FIELDS
     * @param int    $actorId
     * @param string $uid     auth_master.uid of the person, for the log
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function editLatest(array $latest, array $salary, int $actorId, string $uid): void
    {
        $changes = ActivityLog::changes(self::out($latest), $salary);
        if ($changes === []) {
            return;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($latest, $changes, $actorId, $uid, $log) {
            $this->db->table($this->table)->where('id', $latest['id'])->update($changes);
            $log->updated($actorId, 'Corrected the salary of ' . $uid . ' from ' . $latest['effective_date'], $this->table, (int) $latest['id'], $latest, $changes);
        });
    }

    /**
     * A row as it goes out: FIELDS only, the amount with two decimals
     * @param array $row
     * @return array
     */
    private static function out(array $row): array
    {
        $row = array_intersect_key($row, array_flip(self::FIELDS));
        $row['salary_amount'] = number_format((float) $row['salary_amount'], 2, '.', '');

        return $row;
    }
}
