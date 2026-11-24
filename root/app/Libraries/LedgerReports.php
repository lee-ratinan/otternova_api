<?php

namespace App\Libraries;

use App\Models\OtternovaLedgerAccountModel as Account;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * The financial reports of one legal entity (otternova_ledger_entity), worked out from its ledger entries in its
 * base currency (otternova_ledger_master.base_amount):
 * - trial balance: every account's debits, credits and balance up to a day;
 * - profit and loss: revenue (R) and expense (X) accounts over a period, by account and category;
 * - balance sheet: asset (A), liability (L) and equity (E) accounts at the end of a day, with the retained earnings
 *   (profit before the fiscal year) and the fiscal year's profit so far worked out, not posted.
 * Every entry debits its to account and credits its from account by the same base_amount, so the debits and credits
 * always agree, and assets = liabilities + equity + earnings once every account has a class. An entry without a
 * base_amount (one from before the reports, in a foreign currency) is left out and counted under warnings, as are
 * accounts without a class.
 * Amounts go out as strings with 4 decimal places ("1234.5000"); dates are Y-m-d, the entity's own days.
 */
class LedgerReports
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param int    $entityId
     * @param string $dateTo Y-m-d, included
     * @return array{entity: array, date_to: string, accounts: list<array>, totals: array, balanced: bool, warnings: array}
     */
    public function trialBalance(int $entityId, string $dateTo): array
    {
        $accounts = $this->accounts($entityId);
        $moves    = $this->movements($entityId, null, $dateTo);
        $rows     = [];
        $totals   = ['debit' => 0, 'credit' => 0, 'balance_debit' => 0, 'balance_credit' => 0];
        foreach ($accounts as $id => $account) {
            [$debit, $credit] = $moves[$id] ?? [0, 0];
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            $balance = $debit - $credit;
            $row     = [
                'debit'          => $debit,
                'credit'         => $credit,
                'balance_debit'  => max($balance, 0),
                'balance_credit' => max(-$balance, 0),
            ];
            foreach ($row as $key => $units) {
                $totals[$key] += $units;
            }
            $rows[] = self::accountFields($account) + array_map(Decimal::fromUnits(...), $row);
        }

        return [
            'entity'   => $this->entity($entityId),
            'date_to'  => $dateTo,
            'accounts' => $rows,
            'totals'   => array_map(Decimal::fromUnits(...), $totals),
            'balanced' => $totals['debit'] === $totals['credit'],
            'warnings' => $this->warnings($entityId, null, $dateTo, $accounts, $moves),
        ];
    }

    /**
     * @param int    $entityId
     * @param string $dateFrom Y-m-d, included
     * @param string $dateTo   Y-m-d, included
     * @return array{entity: array, date_from: string, date_to: string, revenue: array, expenses: array, net_profit: string, warnings: array}
     */
    public function profitLoss(int $entityId, string $dateFrom, string $dateTo): array
    {
        $accounts = $this->accounts($entityId);
        $moves    = $this->movements($entityId, $dateFrom, $dateTo);
        $byCat    = $this->movementsByCategory($entityId, $dateFrom, $dateTo);
        $section  = function (string $class) use ($accounts, $moves, $byCat): array {
            $lines = [];
            $total = 0;
            foreach ($accounts as $id => $account) {
                if ($account['account_class'] !== $class || ! isset($moves[$id])) {
                    continue;
                }
                $amount     = self::signed($class, ...$moves[$id]);
                $total     += $amount;
                $categories = [];
                foreach ($byCat[$id] ?? [] as $category) {
                    $categories[] = [
                        'category_public_id' => $category['public_id'],
                        'category_code'      => $category['category_code'],
                        'category_name'      => $category['category_name'],
                        'amount'             => Decimal::fromUnits(self::signed($class, $category['debit'], $category['credit'])),
                    ];
                }
                $lines[] = self::accountFields($account) + ['amount' => Decimal::fromUnits($amount), 'categories' => $categories];
            }

            return ['accounts' => $lines, 'total' => $total];
        };
        $revenue  = $section(Account::CLASS_REVENUE);
        $expenses = $section(Account::CLASS_EXPENSE);

        return [
            'entity'     => $this->entity($entityId),
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'revenue'    => ['accounts' => $revenue['accounts'], 'total' => Decimal::fromUnits($revenue['total'])],
            'expenses'   => ['accounts' => $expenses['accounts'], 'total' => Decimal::fromUnits($expenses['total'])],
            'net_profit' => Decimal::fromUnits($revenue['total'] - $expenses['total']),
            'warnings'   => $this->warnings($entityId, $dateFrom, $dateTo, $accounts, $moves),
        ];
    }

    /**
     * @param int    $entityId
     * @param string $dateTo Y-m-d, included
     * @return array{entity: array, date_to: string, fiscal_year_start: string, assets: array, liabilities: array, equity: array, total_liabilities_equity: string, difference: string, warnings: array}
     */
    public function balanceSheet(int $entityId, string $dateTo): array
    {
        $entity    = $this->entity($entityId);
        $yearStart = self::fiscalYearStart($dateTo, (int) $entity['fiscal_year_start_month']);
        $accounts  = $this->accounts($entityId);
        $moves     = $this->movements($entityId, null, $dateTo);
        $section   = static function (string $class) use ($accounts, $moves): array {
            $lines = [];
            $total = 0;
            foreach ($accounts as $id => $account) {
                if ($account['account_class'] !== $class || ! isset($moves[$id])) {
                    continue;
                }
                $amount  = self::signed($class, ...$moves[$id]);
                $total  += $amount;
                $lines[] = self::accountFields($account) + ['amount' => Decimal::fromUnits($amount)];
            }

            return [$lines, $total];
        };

        [$assets, $totalAssets]           = $section(Account::CLASS_ASSET);
        [$liabilities, $totalLiabilities] = $section(Account::CLASS_LIABILITY);
        [$equity, $totalEquity]           = $section(Account::CLASS_EQUITY);
        $before                           = (new DateTimeImmutable($yearStart))->modify('-1 day')->format('Y-m-d');
        $retained                         = $this->profit($accounts, $this->movements($entityId, null, $before));
        $current                          = $this->profit($accounts, $this->movements($entityId, $yearStart, $dateTo));
        $totalEquity                     += $retained + $current;

        return [
            'entity'                   => $entity,
            'date_to'                  => $dateTo,
            'fiscal_year_start'        => $yearStart,
            'assets'                   => ['accounts' => $assets, 'total' => Decimal::fromUnits($totalAssets)],
            'liabilities'              => ['accounts' => $liabilities, 'total' => Decimal::fromUnits($totalLiabilities)],
            'equity'                   => [
                'accounts'            => $equity,
                'retained_earnings'   => Decimal::fromUnits($retained),
                'current_year_profit' => Decimal::fromUnits($current),
                'total'               => Decimal::fromUnits($totalEquity),
            ],
            'total_liabilities_equity' => Decimal::fromUnits($totalLiabilities + $totalEquity),
            // not 0 only while an account with entries has no class
            'difference'               => Decimal::fromUnits($totalAssets - $totalLiabilities - $totalEquity),
            'warnings'                 => $this->warnings($entityId, null, $dateTo, $accounts, $moves),
        ];
    }

    /**
     * General ledger: every entry of the entity from $dateFrom to $dateTo, oldest first, with what it points at and
     * its base amount (null for one that has none yet)
     * @param int    $entityId
     * @param string $dateFrom Y-m-d, included
     * @param string $dateTo   Y-m-d, included
     * @return array{entity: array, date_from: string, date_to: string, entries: list<array>, total: string}
     */
    public function generalLedger(int $entityId, string $dateFrom, string $dateTo): array
    {
        $rows = $this->db->table('otternova_ledger_master e')
            ->select('e.public_id, e.transaction_date, e.reference_number, e.transaction_detail, c.category_code, c.category_name')
            ->select('f.public_id AS from_account_public_id, f.account_name AS from_account_name, f.account_class AS from_account_class')
            ->select('t.public_id AS to_account_public_id, t.account_name AS to_account_name, t.account_class AS to_account_class')
            ->select('p.counterparty_name, e.transaction_currency_code, e.transaction_amount, e.billed_currency_code, e.billed_amount')
            ->select('e.base_rate, e.base_amount, e.receipt_file_path')
            ->join('otternova_ledger_account f', 'f.id = e.from_account_id', 'inner')
            ->join('otternova_ledger_account t', 't.id = e.to_account_id', 'inner')
            ->join('otternova_ledger_category c', 'c.id = e.category_id', 'inner')
            ->join('otternova_ledger_counterparty p', 'p.id = e.counterparty_id', 'left')
            ->where('f.entity_id', $entityId)
            ->where('e.transaction_date >=', $dateFrom)
            ->where('e.transaction_date <=', $dateTo)
            ->orderBy('e.transaction_date', 'ASC')
            ->orderBy('e.id', 'ASC')
            ->get()->getResultArray();
        $total   = 0;
        $entries = [];
        foreach ($rows as $row) {
            $row['has_receipt'] = $row['receipt_file_path'] !== null && $row['receipt_file_path'] !== '';
            unset($row['receipt_file_path']);
            foreach (['transaction_amount', 'billed_amount', 'base_amount'] as $column) {
                $row[$column] = $row[$column] === null ? null : Decimal::fromUnits(Decimal::toUnits($row[$column]));
            }
            $row['base_rate'] = $row['base_rate'] === null ? null : (string) $row['base_rate'];
            $total           += Decimal::toUnits($row['base_amount']);
            $entries[]        = $row;
        }

        return [
            'entity'    => $this->entity($entityId),
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'entries'   => $entries,
            'total'     => Decimal::fromUnits($total),
        ];
    }

    /**
     * The first day of the fiscal year $date is in
     * @param string $date       Y-m-d
     * @param int    $startMonth 1-12
     * @return string Y-m-d
     */
    public static function fiscalYearStart(string $date, int $startMonth): string
    {
        $day  = new DateTimeImmutable($date);
        $year = (int) $day->format('Y') - ((int) $day->format('n') < $startMonth ? 1 : 0);

        return sprintf('%04d-%02d-01', $year, $startMonth);
    }

    /**
     * The entity as the reports show it at the top
     * @param int $entityId
     * @return array
     */
    private function entity(int $entityId): array
    {
        $row = $this->db->table('otternova_ledger_entity')
            ->select('public_id, entity_name, legal_form, country_code, tax_id, base_currency_code, timezone, fiscal_year_start_month, closed_through')
            ->where('id', $entityId)
            ->get()->getRowArray();
        $row['fiscal_year_start_month'] = (int) $row['fiscal_year_start_month'];

        return $row;
    }

    /**
     * The entity's accounts by id, in the order the reports list them
     * @param int $entityId
     * @return array<int, array>
     */
    private function accounts(int $entityId): array
    {
        $rows = $this->db->table('otternova_ledger_account')
            ->select('id, public_id, account_name, account_type, currency_code, account_class')
            ->where('entity_id', $entityId)
            ->orderBy('account_name', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_column($rows, null, 'id');
    }

    /**
     * Debits (as the to account) and credits (as the from account) of each of the entity's accounts, in units of
     * 0.0001 of the base currency
     * @param int         $entityId
     * @param string|null $dateFrom Included; null from the first entry
     * @param string      $dateTo   Included
     * @return array<int, array{0: int, 1: int}> account id => [debit, credit]
     */
    private function movements(int $entityId, ?string $dateFrom, string $dateTo): array
    {
        $moves = [];
        foreach (['to_account_id' => 0, 'from_account_id' => 1] as $column => $side) {
            foreach ($this->entries($entityId, $column, $dateFrom, $dateTo)->select('e.' . $column . ' AS account_id')->selectSum('e.base_amount', 'amount')
                ->groupBy('e.' . $column)->get()->getResultArray() as $row) {
                $moves[(int) $row['account_id']] ??= [0, 0];
                $moves[(int) $row['account_id']][$side] += Decimal::toUnits($row['amount']);
            }
        }

        return $moves;
    }

    /**
     * movements() split by category
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array<int, list<array{public_id: string, category_code: string, category_name: string, debit: int, credit: int}>> account id => categories by name
     */
    private function movementsByCategory(int $entityId, string $dateFrom, string $dateTo): array
    {
        $byCat = [];
        foreach (['to_account_id' => 'debit', 'from_account_id' => 'credit'] as $column => $side) {
            $rows = $this->entries($entityId, $column, $dateFrom, $dateTo)
                ->select('e.' . $column . ' AS account_id, c.id AS category_id, c.public_id, c.category_code, c.category_name')
                ->selectSum('e.base_amount', 'amount')
                ->join('otternova_ledger_category c', 'c.id = e.category_id', 'inner')
                ->groupBy(['e.' . $column, 'c.id', 'c.public_id', 'c.category_code', 'c.category_name'])
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $category = &$byCat[(int) $row['account_id']][(int) $row['category_id']];
                $category ??= ['public_id' => $row['public_id'], 'category_code' => $row['category_code'], 'category_name' => $row['category_name'], 'debit' => 0, 'credit' => 0];
                $category[$side] += Decimal::toUnits($row['amount']);
                unset($category);
            }
        }
        foreach ($byCat as &$categories) {
            usort($categories, static fn (array $a, array $b): int => strcasecmp($a['category_name'], $b['category_name']));
        }
        unset($categories);

        return $byCat;
    }

    /**
     * The entity's entries with a base amount, through one side's account
     * @param int         $entityId
     * @param string      $column   to_account_id or from_account_id
     * @param string|null $dateFrom
     * @param string      $dateTo
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function entries(int $entityId, string $column, ?string $dateFrom, string $dateTo)
    {
        $builder = $this->db->table('otternova_ledger_master e')
            ->join('otternova_ledger_account a', 'a.id = e.' . $column, 'inner')
            ->where('a.entity_id', $entityId)
            ->where('e.base_amount IS NOT NULL', null, false)
            ->where('e.transaction_date <=', $dateTo);
        if ($dateFrom !== null) {
            $builder->where('e.transaction_date >=', $dateFrom);
        }

        return $builder;
    }

    /**
     * Revenue less expenses, in units
     * @param array<int, array> $accounts
     * @param array<int, array> $moves
     * @return int
     */
    private function profit(array $accounts, array $moves): int
    {
        $profit = 0;
        foreach ($moves as $id => [$debit, $credit]) {
            $class = $accounts[$id]['account_class'] ?? null;
            if ($class === Account::CLASS_REVENUE || $class === Account::CLASS_EXPENSE) {
                $profit += $credit - $debit;
            }
        }

        return $profit;
    }

    /**
     * What the report leaves out or cannot place: entries without a base amount in its period, and accounts with
     * entries but no class
     * @param int               $entityId
     * @param string|null       $dateFrom
     * @param string            $dateTo
     * @param array<int, array> $accounts
     * @param array<int, array> $moves
     * @return array{missing_base_amount: int, unclassified_accounts: list<array{public_id: string, account_name: string}>}
     */
    private function warnings(int $entityId, ?string $dateFrom, string $dateTo, array $accounts, array $moves): array
    {
        $missing = $this->db->table('otternova_ledger_master e')
            ->join('otternova_ledger_account a', 'a.id = e.from_account_id', 'inner')
            ->where('a.entity_id', $entityId)
            ->where('e.base_amount IS NULL', null, false)
            ->where('e.transaction_date <=', $dateTo);
        if ($dateFrom !== null) {
            $missing->where('e.transaction_date >=', $dateFrom);
        }
        $unclassified = [];
        foreach ($accounts as $id => $account) {
            if ($account['account_class'] === null && isset($moves[$id])) {
                $unclassified[] = ['public_id' => $account['public_id'], 'account_name' => $account['account_name']];
            }
        }

        return ['missing_base_amount' => $missing->countAllResults(), 'unclassified_accounts' => $unclassified];
    }

    /**
     * An account's amount the way its class reads it: debits less credits for assets and expenses, credits less
     * debits for the rest
     * @param string|null $class
     * @param int         $debit
     * @param int         $credit
     * @return int
     */
    private static function signed(?string $class, int $debit, int $credit): int
    {
        return in_array($class, [Account::CLASS_ASSET, Account::CLASS_EXPENSE], true) ? $debit - $credit : $credit - $debit;
    }

    /**
     * @param array $account
     * @return array{public_id: string, account_name: string, account_type: string, account_class: string|null}
     */
    private static function accountFields(array $account): array
    {
        return [
            'public_id'     => $account['public_id'],
            'account_name'  => $account['account_name'],
            'account_type'  => $account['account_type'],
            'account_class' => $account['account_class'],
        ];
    }
}
