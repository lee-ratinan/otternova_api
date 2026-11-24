<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Closing an entity's books month by month. Months close in order, from the month of the first entry, and only once
 * they are over in the entity's timezone; otternova_ledger_entity.closed_through is then the last day closed, and no
 * entry on or before it can change (see App\Controllers\Ledger). A month closes only when its reports are complete:
 * every entry up to its end has a base amount, every account with such entries has a class, and the debits and
 * credits agree. Closing keeps the month's reports as they are (otternova_ledger_report_snapshot), so what was
 * filed stays the same; reopening the last closed month keeps them too, as history, with the reason.
 */
class LedgerPeriods
{
    /** report_type of the snapshots, in the order they are listed */
    public const SNAPSHOTS = [
        'PL' => 'Profit and Loss (month)',
        'PY' => 'Profit and Loss (fiscal year to date)',
        'BS' => 'Balance Sheet',
        'TB' => 'Trial Balance',
        'GL' => 'General Ledger (month)',
        'VT' => 'VAT (month)',
        'WT' => 'Withholding Tax (month)',
        'RR' => 'Revenue Register (month)',
        'ER' => 'Expense Register (month)',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * The entity's months from its first entry to the current one, newest first, each closed or open with the
     * close that holds it, and what stands in the way of closing the next one
     * @param int $entityId
     * @return array{entity: array, closed_through: string|null, next: array|null, months: list<array>}
     */
    public function overview(int $entityId): array
    {
        $entity = $this->entity($entityId);
        $first  = $this->firstEntryDate($entityId);
        $today  = $this->today($entity);
        $months = [];
        if ($first !== null) {
            $periods = [];
            foreach ($this->db->table('otternova_ledger_period p')
                ->select('p.public_id, p.period_start, p.period_status, p.closed_at, p.reopened_at, p.reopen_reason, c.full_name AS closed_by_name, r.full_name AS reopened_by_name')
                ->join('auth_master c', 'c.id = p.closed_by', 'left')
                ->join('auth_master r', 'r.id = p.reopened_by', 'left')
                ->where('p.entity_id', $entityId)
                ->orderBy('p.id', 'ASC')
                ->get()->getResultArray() as $period) {
                $periods[$period['period_start']][] = $period;
            }
            $month = new DateTimeImmutable(substr($first, 0, 7) . '-01');
            $last  = new DateTimeImmutable($today->format('Y-m-01'));
            while ($month <= $last) {
                $start    = $month->format('Y-m-d');
                $end      = $month->format('Y-m-t');
                $history  = array_reverse($periods[$start] ?? []);
                $months[] = [
                    'period_start' => $start,
                    'period_end'   => $end,
                    'closed'       => $entity['closed_through'] !== null && $end <= $entity['closed_through'],
                    'history'      => $history, // newest first: the close in force, then those reopened
                ];
                $month = $month->modify('+1 month');
            }
        }

        $next = $this->nextMonth($entity, $first);

        return [
            'entity'         => $entity,
            'closed_through' => $entity['closed_through'],
            'next'           => $next === null ? null : $next + ['problems' => $this->problems($entityId, $entity, $next['period_start'], $next['period_end'])],
            'months'         => array_reverse($months),
        ];
    }

    /**
     * Closes the next month of the entity and keeps its reports
     * @param int    $entityId
     * @param string $periodStart Y-m-d, the first day of the month; it must be the next one to close
     * @param int    $actorId     auth_master.id
     * @return array{public_id: string, problems: list<string>} problems is empty when it closed
     * @throws Throwable When a write fails; nothing is written then
     */
    public function close(int $entityId, string $periodStart, int $actorId): array
    {
        $entity = $this->entity($entityId);
        $next   = $this->nextMonth($entity, $this->firstEntryDate($entityId));
        if ($next === null || $next['period_start'] !== $periodStart) {
            return ['public_id' => '', 'problems' => [lang('Ledger.periodNotNext')]];
        }
        $problems = $this->problems($entityId, $entity, $next['period_start'], $next['period_end']);
        if ($problems !== []) {
            return ['public_id' => '', 'problems' => $problems];
        }

        $reports   = new LedgerReports($this->db);
        $yearStart = LedgerReports::fiscalYearStart($next['period_end'], (int) $entity['fiscal_year_start_month']);
        $content   = [
            'PL' => [$next['period_start'], $reports->profitLoss($entityId, $next['period_start'], $next['period_end'])],
            'PY' => [$yearStart, $reports->profitLoss($entityId, $yearStart, $next['period_end'])],
            'BS' => [null, $reports->balanceSheet($entityId, $next['period_end'])],
            'TB' => [null, $reports->trialBalance($entityId, $next['period_end'])],
            'GL' => [$next['period_start'], $reports->generalLedger($entityId, $next['period_start'], $next['period_end'])],
        ];
        $tax      = new LedgerTaxReports($this->db);
        $content += [
            'VT' => [$next['period_start'], $tax->vat($entityId, $next['period_start'], $next['period_end'])],
            'WT' => [$next['period_start'], $tax->wht($entityId, $next['period_start'], $next['period_end'])],
            'RR' => [$next['period_start'], $tax->revenueRegister($entityId, $next['period_start'], $next['period_end'])],
            'ER' => [$next['period_start'], $tax->expenseRegister($entityId, $next['period_start'], $next['period_end'])],
        ];
        $period = [
            'entity_id'     => $entityId,
            'period_start'  => $next['period_start'],
            'period_end'    => $next['period_end'],
            'period_status' => 'C',
            'closed_by'     => $actorId,
            'closed_at'     => gmdate('Y-m-d H:i:s'),
        ];

        $publicId = $this->newPublicId();
        $this->inTransaction(function () use ($publicId, $period, $content, $next, $entity, $entityId, $actorId) {
            $this->db->table('otternova_ledger_period')->insert(['public_id' => $publicId] + $period);
            $periodId = (int) $this->db->insertID();
            foreach ($content as $type => [$from, $report]) {
                $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $this->db->table('otternova_ledger_report_snapshot')->insert([
                    'period_id' => $periodId, 'report_type' => $type, 'date_from' => $from, 'date_to' => $next['period_end'],
                    'content_json' => $json, 'content_sha256' => hash('sha256', $json),
                ]);
            }
            $this->db->table('otternova_ledger_entity')->where('id', $entityId)->update(['closed_through' => $next['period_end']]);
            $log = new ActivityLog();
            $log->inserted($actorId, 'Closed ' . substr($next['period_start'], 0, 7) . ' of ledger entity ' . $entity['entity_name'], 'otternova_ledger_period', $periodId, $period);
            $log->updated($actorId, 'Closed ' . substr($next['period_start'], 0, 7) . ' of ledger entity ' . $entity['entity_name'], 'otternova_ledger_entity', $entityId, $entity, ['closed_through' => $next['period_end']]);
        });

        return ['public_id' => $publicId, 'problems' => []];
    }

    /**
     * Reopens the entity's last closed month, keeping its reports as history
     * @param int    $entityId
     * @param int    $actorId
     * @param string $reason
     * @return string|null The month reopened (Y-m-d, its first day), null when none is closed
     * @throws Throwable When a write fails; nothing is written then
     */
    public function reopen(int $entityId, int $actorId, string $reason): ?string
    {
        $entity = $this->entity($entityId);
        if ($entity['closed_through'] === null) {
            return null;
        }
        $period = $this->db->table('otternova_ledger_period')
            ->where(['entity_id' => $entityId, 'period_end' => $entity['closed_through'], 'period_status' => 'C'])
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        if ($period === null) {
            throw new RuntimeException('No close of ' . $entity['closed_through'] . ' for ledger entity ' . $entityId);
        }
        $before   = (new DateTimeImmutable($period['period_start']))->modify('-1 day')->format('Y-m-d');
        $previous = $this->db->table('otternova_ledger_period')
            ->where(['entity_id' => $entityId, 'period_end' => $before, 'period_status' => 'C'])
            ->countAllResults();
        $through  = $previous > 0 ? $before : null;
        $changes  = ['period_status' => 'R', 'reopened_by' => $actorId, 'reopened_at' => gmdate('Y-m-d H:i:s'), 'reopen_reason' => $reason];
        $summary  = 'Reopened ' . substr($period['period_start'], 0, 7) . ' of ledger entity ' . $entity['entity_name'];

        $this->inTransaction(function () use ($period, $changes, $entityId, $through, $actorId, $summary, $entity) {
            $this->db->table('otternova_ledger_period')->where('id', $period['id'])->update($changes);
            $this->db->table('otternova_ledger_entity')->where('id', $entityId)->update(['closed_through' => $through]);
            $log = new ActivityLog();
            $log->updated($actorId, $summary, 'otternova_ledger_period', (int) $period['id'], $period, $changes);
            $log->updated($actorId, $summary, 'otternova_ledger_entity', $entityId, $entity, ['closed_through' => $through]);
        });

        return $period['period_start'];
    }

    /**
     * A report kept when a month closed
     * @param string $periodPublicId
     * @param string $type A key of SNAPSHOTS
     * @return array|null {period: array, report_type, date_from, date_to, sha256, created_at, report: array}
     */
    public function snapshot(string $periodPublicId, string $type): ?array
    {
        if (! isset(self::SNAPSHOTS[$type]) || ! preg_match('/^[0-9A-Za-z_-]{8}$/', $periodPublicId)) {
            return null;
        }
        $row = $this->db->table('otternova_ledger_report_snapshot s')
            ->select('s.report_type, s.date_from, s.date_to, s.content_json, s.content_sha256, s.created_at')
            ->select('p.public_id AS period_public_id, p.period_start, p.period_end, p.period_status, p.closed_at, c.full_name AS closed_by_name, n.public_id AS entity_public_id')
            ->join('otternova_ledger_period p', 'p.id = s.period_id', 'inner')
            ->join('otternova_ledger_entity n', 'n.id = p.entity_id', 'inner')
            ->join('auth_master c', 'c.id = p.closed_by', 'left')
            ->where('p.public_id', $periodPublicId)
            ->where('s.report_type', $type)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'period'      => [
                'public_id'        => $row['period_public_id'],
                'entity_public_id' => $row['entity_public_id'],
                'period_start'     => $row['period_start'],
                'period_end'       => $row['period_end'],
                'period_status'    => $row['period_status'],
                'closed_at'        => $row['closed_at'],
                'closed_by_name'   => $row['closed_by_name'],
            ],
            'report_type' => $row['report_type'],
            'date_from'   => $row['date_from'],
            'date_to'     => $row['date_to'],
            'sha256'      => $row['content_sha256'],
            'created_at'  => $row['created_at'],
            'report'      => json_decode($row['content_json'], true),
        ];
    }

    /**
     * Whether $date is in the entity's closed months
     * @param int|null $entityId
     * @param string   $date Y-m-d
     * @return bool
     */
    public function isClosed(?int $entityId, string $date): bool
    {
        if ($entityId === null) {
            return false;
        }
        $through = $this->db->table('otternova_ledger_entity')->select('closed_through')->where('id', $entityId)->get()->getRow()->closed_through ?? null;

        return $through !== null && $date <= $through;
    }

    /**
     * The month after the last closed one, or the month of the first entry when none is closed; null when there is
     * nothing to close yet
     * @param array       $entity
     * @param string|null $firstEntry
     * @return array{period_start: string, period_end: string}|null
     */
    private function nextMonth(array $entity, ?string $firstEntry): ?array
    {
        if ($entity['closed_through'] !== null) {
            $month = (new DateTimeImmutable($entity['closed_through']))->modify('+1 day');
        } elseif ($firstEntry !== null) {
            $month = new DateTimeImmutable(substr($firstEntry, 0, 7) . '-01');
        } else {
            return null;
        }

        return ['period_start' => $month->format('Y-m-d'), 'period_end' => $month->format('Y-m-t')];
    }

    /**
     * What stands in the way of closing the month
     * @param int    $entityId
     * @param array  $entity
     * @param string $start
     * @param string $end
     * @return list<string>
     */
    private function problems(int $entityId, array $entity, string $start, string $end): array
    {
        $problems = [];
        if ($end >= $this->today($entity)->format('Y-m-d')) {
            $problems[] = lang('Ledger.periodNotOver');
        }
        $balance = (new LedgerReports($this->db))->trialBalance($entityId, $end);
        if ($balance['warnings']['missing_base_amount'] > 0) {
            $problems[] = lang('Ledger.periodMissingBase', [$balance['warnings']['missing_base_amount']]);
        }
        if ($balance['warnings']['unclassified_accounts'] !== []) {
            $problems[] = lang('Ledger.periodUnclassified', [implode(', ', array_column($balance['warnings']['unclassified_accounts'], 'account_name'))]);
        }
        if (! $balance['balanced']) {
            $problems[] = lang('Ledger.periodUnbalanced');
        }
        $waiting = array_filter((new LedgerPosting($this->db))->pending(),
            static fn (array $event): bool => $event['entity_id'] === $entityId && $event['date'] !== null && $event['date'] <= $end);
        if ($waiting !== []) {
            $why        = array_unique(array_map(static fn (array $event): string => lang('Ledger.posting_' . $event['problem'], [$event['country_code']]), $waiting));
            $problems[] = lang('Ledger.periodPostingWaiting', [count($waiting), implode(' ', $why)]);
        }

        return $problems;
    }

    /**
     * @param int $entityId
     * @return array The stored row
     */
    private function entity(int $entityId): array
    {
        return $this->db->table('otternova_ledger_entity')->where('id', $entityId)->get()->getRowArray();
    }

    /**
     * @param int $entityId
     * @return string|null Y-m-d
     */
    private function firstEntryDate(int $entityId): ?string
    {
        return $this->db->table('otternova_ledger_master e')
            ->selectMin('e.transaction_date', 'first_date')
            ->join('otternova_ledger_account a', 'a.id = e.from_account_id', 'inner')
            ->where('a.entity_id', $entityId)
            ->get()->getRow()->first_date ?? null;
    }

    /**
     * Today in the entity's timezone
     * @param array $entity
     * @return DateTimeImmutable
     */
    private function today(array $entity): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable('now', new DateTimeZone((string) $entity['timezone']));
        } catch (Throwable) {
            return new DateTimeImmutable('now');
        }
    }

    /**
     * Run $work in a transaction, as App\Models\TransactionTrait does: committed when it returns, rolled back when
     * it throws
     * @param callable $work
     * @return void
     * @throws Throwable
     */
    private function inTransaction(callable $work): void
    {
        $this->db->resetTransStatus()->transException(true)->transBegin();
        try {
            $work();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }
        $this->db->transCommit();
    }

    /**
     * A public_id no otternova_ledger_period row has yet (CHAR(8), see PublicIdTrait)
     * @return string
     */
    private function newPublicId(): string
    {
        helper('id');
        do {
            $publicId = generateNanoId(8);
        } while ($this->db->table('otternova_ledger_period')->where('public_id', $publicId)->countAllResults() > 0);

        return $publicId;
    }
}
