<?php

namespace App\Models;

use App\Libraries\ActivityLog;
use App\Libraries\FieldEncryption;
use CodeIgniter\Model;
use DateTimeImmutable;
use Throwable;

/**
 * Model for staff_salary, the pay terms of each OtterNova staff member over time, and staff_pay_account, where their
 * pay goes. Salary rows never overlap: each starts after the previous one's end_date, and only the latest may have no
 * end_date (the current terms). The history is only ever added to, or its latest row corrected while no payslip uses
 * it: rows a payslip was worked out from are payroll records. The Executive Office only (the routes' filter).
 */
class StaffSalaryModel extends Model
{
    use PublicIdTrait;
    use TransactionTrait;

    public const RATE_UNITS     = ['M', 'W', 'D', 'H']; // per month, week, day, hour
    public const FREQUENCIES    = ['M', 'S', 'W'];      // paid monthly, semi-monthly, weekly
    public const CHANGE_REASONS = ['H', 'P', 'A', 'C', 'O']; // hired, promotion, annual review, correction, other

    /** The columns a salary row is written with, entity_id as staff_salary.entity_id */
    public const FIELDS = [
        'entity_id', 'effective_date', 'end_date', 'currency_code', 'base_amount', 'rate_unit', 'pay_frequency',
        'standard_hours_week', 'social_security', 'change_reason', 'note',
    ];

    /** The columns of staff_pay_account a save writes */
    public const PAY_ACCOUNT_FIELDS = ['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number'];

    protected $table            = 'staff_salary';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = ['public_id', 'staff_id', 'created_by', ...self::FIELDS];
    protected $beforeInsert     = ['fillPublicId'];
    protected int $publicIdLength = 8;

    // created_at and updated_at are maintained by the database
    protected $useTimestamps = false;

    /**
     * The staff member with this auth_master.uid: their staff_master row with uid and full_name, or null when the
     * uid is not a staff account with a profile
     * @param string $uid
     * @return array|null
     */
    public function findStaff(string $uid): ?array
    {
        return $this->db->table('staff_master')
            ->select('staff_master.id, staff_master.employment_start_date, staff_master.employment_end_date, '
                . 'staff_master.work_country_code, auth_master.uid, auth_master.full_name')
            ->join('auth_master', 'auth_master.id = staff_master.auth_id', 'inner')
            ->where('auth_master.uid', $uid)
            ->where('auth_master.account_type', AuthMasterModel::TYPE_STAFF)
            ->get()->getRowArray();
    }

    /**
     * The staff member's rows, the latest first, with their ids, for the checks and writes here
     * @param int $staffId staff_master.id
     * @return list<array>
     */
    public function rowsOf(int $staffId): array
    {
        return $this->db->table($this->table)
            ->where('staff_id', $staffId)
            ->orderBy('effective_date', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * The salary history as it goes out, the latest first: no internal ids; entity as its public_id and name;
     * locked is whether a payslip uses the row (it can no longer be corrected)
     * @param int $staffId
     * @return list<array>
     */
    public function historyOf(int $staffId): array
    {
        $rows = $this->db->table($this->table)
            ->select('staff_salary.*, otternova_ledger_entity.public_id AS entity_public_id, '
                . 'otternova_ledger_entity.entity_name, auth_master.full_name AS created_by_name')
            ->join('otternova_ledger_entity', 'otternova_ledger_entity.id = staff_salary.entity_id', 'left')
            ->join('auth_master', 'auth_master.id = staff_salary.created_by', 'left')
            ->where('staff_salary.staff_id', $staffId)
            ->orderBy('staff_salary.effective_date', 'DESC')
            ->get()->getResultArray();
        $locked = $rows === [] ? [] : array_flip(array_map('intval', array_column(
            $this->db->table('staff_payslip')->select('salary_id')->distinct()
                ->whereIn('salary_id', array_column($rows, 'id'))->get()->getResultArray(),
            'salary_id'
        )));

        return array_map(static fn (array $row): array => [
            'public_id'           => $row['public_id'],
            'entity'              => $row['entity_public_id'] === null ? null
                : ['public_id' => $row['entity_public_id'], 'entity_name' => $row['entity_name']],
            'effective_date'      => $row['effective_date'],
            'end_date'            => $row['end_date'],
            'currency_code'       => $row['currency_code'],
            'base_amount'         => number_format((float) $row['base_amount'], 2, '.', ''),
            'rate_unit'           => $row['rate_unit'],
            'pay_frequency'       => $row['pay_frequency'],
            'standard_hours_week' => $row['standard_hours_week'] === null ? null : number_format((float) $row['standard_hours_week'], 2, '.', ''),
            'social_security'     => (bool) (int) $row['social_security'],
            'change_reason'       => $row['change_reason'],
            'note'                => $row['note'],
            'created_by'          => $row['created_by_name'],
            'created_at'          => $row['created_at'],
            'locked'              => isset($locked[(int) $row['id']]),
        ], $rows);
    }

    /**
     * Why $salary cannot be added after the history, or null when it can: not before they started work, and after
     * the latest row ends, or, when the latest has no end_date, after the latest starts (it is then ended the day before)
     * @param array      $salary    Checked FIELDS
     * @param array|null $latest    The latest row, if any
     * @param string     $startDate staff_master.employment_start_date
     * @return string|null Language key of StaffSalary
     */
    public static function addError(array $salary, ?array $latest, string $startDate): ?string
    {
        if ($salary['effective_date'] < $startDate) {
            return 'StaffSalary.beforeStart';
        }
        if ($latest === null) {
            return null;
        }
        if ($latest['end_date'] === null) {
            return $salary['effective_date'] > $latest['effective_date'] ? null : 'StaffSalary.notAfterCurrent';
        }

        return $salary['effective_date'] > $latest['end_date'] ? null : 'StaffSalary.overlaps';
    }

    /**
     * Why the latest row cannot become $salary, or null when it can: it must still start after the row before ends
     * @param array      $salary    Checked FIELDS
     * @param array|null $previous  The row before the latest, if any
     * @param string     $startDate staff_master.employment_start_date
     * @return string|null Language key of StaffSalary
     */
    public static function editError(array $salary, ?array $previous, string $startDate): ?string
    {
        if ($salary['effective_date'] < $startDate) {
            return 'StaffSalary.beforeStart';
        }

        return $previous === null || $salary['effective_date'] > $previous['end_date'] ? null : 'StaffSalary.overlaps';
    }

    /**
     * Whether a payslip was worked out from this salary row
     * @param int $salaryId
     * @return bool
     */
    public function isUsedByPayslip(int $salaryId): bool
    {
        return $this->db->table('staff_payslip')->where('salary_id', $salaryId)->countAllResults() > 0;
    }

    /**
     * Whether the staff member has payroll records (salary rows or payslips), which keep their staff_master row
     * from being deleted
     * @param int $authId auth_master.id
     * @return bool
     */
    public function hasPayHistory(int $authId): bool
    {
        foreach (['staff_salary', 'staff_payslip'] as $table) {
            $count = $this->db->table($table)
                ->join('staff_master', 'staff_master.id = ' . $table . '.staff_id', 'inner')
                ->where('staff_master.auth_id', $authId)
                ->countAllResults();
            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a salary after the history, in one transaction, logged to log_activity in it. A current salary (no
     * end_date) is ended the day before the new one starts. addError() must have passed.
     * @param array $staff   findStaff()
     * @param array $salary  Checked FIELDS
     * @param int   $actorId auth_master.id of whoever makes the change
     * @return string The new row's public_id
     * @throws Throwable When a write fails; nothing is written then
     */
    public function add(array $staff, array $salary, int $actorId): string
    {
        $log = new ActivityLog();

        return $this->withPublicId(fn (string $publicId) => $this->inTransaction(function () use ($staff, $salary, $actorId, $log, $publicId) {
            $latest = $this->rowsOf((int) $staff['id'])[0] ?? null;
            if ($latest !== null && $latest['end_date'] === null) {
                $end = (new DateTimeImmutable($salary['effective_date']))->modify('-1 day')->format('Y-m-d');
                $this->db->table($this->table)->where('id', $latest['id'])->update(['end_date' => $end]);
                $log->updated($actorId, 'Ended the salary of ' . $staff['uid'] . ' from ' . $latest['effective_date'], $this->table, (int) $latest['id'], $latest, ['end_date' => $end]);
            }
            $row = ['public_id' => $publicId, 'staff_id' => (int) $staff['id']] + $salary + ['created_by' => $actorId];
            $this->db->table($this->table)->insert($row);
            $log->inserted($actorId, 'Set the salary of ' . $staff['uid'] . ' from ' . $salary['effective_date'], $this->table, (int) $this->db->insertID(), $row);

            return $publicId;
        }));
    }

    /**
     * Correct the latest row, logged to log_activity. editError() must have passed and no payslip may use it.
     * @param array $staff   findStaff()
     * @param array $latest  The latest row
     * @param array $salary  Checked FIELDS
     * @param int   $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function editLatest(array $staff, array $latest, array $salary, int $actorId): void
    {
        $old     = array_intersect_key($latest, array_flip(self::FIELDS));
        $changes = ActivityLog::changes($old, $salary);
        if ($changes === []) {
            return;
        }
        $log = new ActivityLog();
        $this->inTransaction(function () use ($staff, $latest, $changes, $actorId, $log) {
            $this->db->table($this->table)->where('id', $latest['id'])->update($changes);
            $log->updated($actorId, 'Corrected the salary of ' . $staff['uid'] . ' from ' . $latest['effective_date'], $this->table, (int) $latest['id'], $latest, $changes);
        });
    }

    /**
     * Where the staff member's pay goes, decrypted, or null when nothing is saved
     * @param int $staffId
     * @return array|null PAY_ACCOUNT_FIELDS and updated_at
     * @throws \CodeIgniter\Encryption\Exceptions\EncryptionException When encryption.key is not set
     */
    public function payAccountOf(int $staffId): ?array
    {
        $row = $this->db->table('staff_pay_account')->where('staff_id', $staffId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row = (new FieldEncryption())->decryptRow('staff_pay_account', $row);

        return array_intersect_key($row, array_flip([...self::PAY_ACCOUNT_FIELDS, 'updated_at']));
    }

    /**
     * Save where the staff member's pay goes (insert or update), logged to log_activity with the account number masked
     * @param array $staff      findStaff()
     * @param array $payAccount Checked PAY_ACCOUNT_FIELDS, plaintext
     * @param int   $actorId
     * @return void
     * @throws Throwable When a write fails; nothing is written then
     */
    public function savePayAccount(array $staff, array $payAccount, int $actorId): void
    {
        $log   = new ActivityLog();
        $crypt = new FieldEncryption();
        $this->inTransaction(function () use ($staff, $payAccount, $actorId, $log, $crypt) {
            $table  = $this->db->table('staff_pay_account');
            $stored = $table->where('staff_id', $staff['id'])->get()->getRowArray();
            if ($stored === null) {
                $row = ['staff_id' => (int) $staff['id']] + $payAccount + ['updated_by' => $actorId];
                $this->db->table('staff_pay_account')->insert($crypt->encryptRow('staff_pay_account', $row));
                $log->inserted($actorId, 'Set the bank account of ' . $staff['uid'], 'staff_pay_account', (int) $this->db->insertID(), $row);

                return;
            }
            $old     = $crypt->decryptRow('staff_pay_account', $stored);
            $changes = ActivityLog::changes(array_intersect_key($old, array_flip(self::PAY_ACCOUNT_FIELDS)), $payAccount);
            if ($changes === []) {
                return;
            }
            $this->db->table('staff_pay_account')->where('id', $stored['id'])
                ->update($crypt->encryptRow('staff_pay_account', $changes) + ['updated_by' => $actorId]);
            $log->updated($actorId, 'Changed the bank account of ' . $staff['uid'], 'staff_pay_account', (int) $stored['id'], $old, $changes);
        });
    }
}
