<?php

namespace App\Libraries;

use App\Models\OtternovaLedgerTaxCodeModel as TaxCode;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * The tax reports of one legal entity, from its ledger rows with a tax code (otternova_ledger_tax_code), in its base
 * currency:
 * - VAT: the output VAT (VO) and input VAT (VI) lines of a period, and what is payable;
 * - withholding tax: what was deducted from payees (WD), by payee for the filing, and what customers deducted (WS);
 * - revenue and expense registers: every row of a revenue or expense account, with the VAT and withholding tax of
 *   its transaction (journal_id);
 * - VAT threshold: revenue in the fiscal year and the last 12 months against the entity's registration threshold.
 * A tax line counts positive when it goes the usual way (a tax owed out of its account, a tax claimable into it) and
 * negative when it is reversed, as by a credit note. A 0% line is the sale or purchase itself: no tax, its base
 * amount is the taxable amount.
 * Amounts go out as strings with 4 decimal places.
 */
class LedgerTaxReports
{
    /** The share of the VAT threshold from which the entity is warned */
    public const APPROACHING = 0.8;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array{entity: array, date_from: string, date_to: string, output: array, input: array, net_payable: string}
     */
    public function vat(int $entityId, string $dateFrom, string $dateTo): array
    {
        $lines  = $this->taxLines($entityId, [TaxCode::KIND_VAT_OUTPUT, TaxCode::KIND_VAT_INPUT], $dateFrom, $dateTo);
        $output = self::section(array_values(array_filter($lines, static fn (array $line) => $line['tax_kind'] === TaxCode::KIND_VAT_OUTPUT)));
        $input  = self::section(array_values(array_filter($lines, static fn (array $line) => $line['tax_kind'] === TaxCode::KIND_VAT_INPUT)));

        return [
            'entity'      => $this->entity($entityId),
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'output'      => $output,
            'input'       => $input,
            'net_payable' => Decimal::fromUnits(Decimal::toUnits($output['tax']) - Decimal::toUnits($input['tax'])),
        ];
    }

    /**
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array{entity: array, date_from: string, date_to: string, deducted: array, suffered: array, deducted_by_payee: list<array>}
     */
    public function wht(int $entityId, string $dateFrom, string $dateTo): array
    {
        $lines    = $this->taxLines($entityId, [TaxCode::KIND_WHT_DEDUCTED, TaxCode::KIND_WHT_SUFFERED], $dateFrom, $dateTo);
        $deducted = array_values(array_filter($lines, static fn (array $line) => $line['tax_kind'] === TaxCode::KIND_WHT_DEDUCTED));
        $byPayee  = [];
        foreach ($deducted as $line) {
            $key            = $line['counterparty_name'] . "\0" . $line['counterparty_tax_id'] . "\0" . $line['tax_code'];
            $byPayee[$key] ??= [
                'counterparty_name' => $line['counterparty_name'], 'counterparty_tax_id' => $line['counterparty_tax_id'],
                'counterparty_tax_branch' => $line['counterparty_tax_branch'], 'tax_code' => $line['tax_code'], 'rate' => $line['rate'], 'base' => 0, 'tax' => 0,
            ];
            $byPayee[$key]['base'] += Decimal::toUnits($line['tax_base']);
            $byPayee[$key]['tax']  += Decimal::toUnits($line['tax_amount']);
        }
        ksort($byPayee, SORT_STRING | SORT_FLAG_CASE);

        return [
            'entity'            => $this->entity($entityId),
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            'deducted'          => self::section($deducted),
            'suffered'          => self::section(array_values(array_filter($lines, static fn (array $line) => $line['tax_kind'] === TaxCode::KIND_WHT_SUFFERED))),
            'deducted_by_payee' => array_map(static fn (array $payee) => ['base' => Decimal::fromUnits($payee['base']), 'tax' => Decimal::fromUnits($payee['tax'])] + $payee, array_values($byPayee)),
        ];
    }

    /**
     * Every row of a revenue account in the period: credits positive, debits (refunds) negative
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array{entity: array, date_from: string, date_to: string, rows: list<array>, totals: array}
     */
    public function revenueRegister(int $entityId, string $dateFrom, string $dateTo): array
    {
        return $this->register($entityId, 'R', TaxCode::KIND_VAT_OUTPUT, TaxCode::KIND_WHT_SUFFERED, $dateFrom, $dateTo);
    }

    /**
     * Every row of an expense account in the period: debits positive, credits (refunds) negative
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return array{entity: array, date_from: string, date_to: string, rows: list<array>, totals: array}
     */
    public function expenseRegister(int $entityId, string $dateFrom, string $dateTo): array
    {
        return $this->register($entityId, 'X', TaxCode::KIND_VAT_INPUT, TaxCode::KIND_WHT_DEDUCTED, $dateFrom, $dateTo);
    }

    /**
     * Revenue against the VAT registration threshold, as of the end of $dateTo. status: registered (on or before
     * $dateTo), none (no threshold set), exceeded, approaching (from APPROACHING of it) or below; the higher of the
     * fiscal year's and the last 12 months' revenue counts, as the rules differ between countries.
     * @param int    $entityId
     * @param string $dateTo
     * @return array
     */
    public function vatThreshold(int $entityId, string $dateTo): array
    {
        $entity      = $this->entity($entityId);
        $yearStart   = LedgerReports::fiscalYearStart($dateTo, (int) $entity['fiscal_year_start_month']);
        $twelveStart = (new DateTimeImmutable($dateTo))->modify('-1 year +1 day')->format('Y-m-d');
        $fiscal      = $this->revenue($entityId, $yearStart, $dateTo);
        $twelve      = $this->revenue($entityId, $twelveStart, $dateTo);
        $threshold   = $entity['vat_threshold_amount'] === null ? null : Decimal::toUnits($entity['vat_threshold_amount']);
        $highest     = max($fiscal, $twelve);

        $status = match (true) {
            $entity['vat_registered_on'] !== null && $entity['vat_registered_on'] <= $dateTo => 'registered',
            $threshold === null || $threshold === 0                                          => 'none',
            $highest >= $threshold                                                           => 'exceeded',
            $highest >= $threshold * self::APPROACHING                                       => 'approaching',
            default                                                                          => 'below',
        };

        return [
            'entity'                  => $entity,
            'date_to'                 => $dateTo,
            'fiscal_year_start'       => $yearStart,
            'fiscal_year_revenue'     => Decimal::fromUnits($fiscal),
            'twelve_months_start'     => $twelveStart,
            'twelve_months_revenue'   => Decimal::fromUnits($twelve),
            'threshold'               => $threshold === null ? null : Decimal::fromUnits($threshold),
            'share'                   => $threshold ? round($highest / $threshold, 4) : null,
            'status'                  => $status,
        ];
    }

    /**
     * Revenue (credits less debits of revenue accounts) of the entity, in units
     * @param int    $entityId
     * @param string $dateFrom
     * @param string $dateTo
     * @return int
     */
    private function revenue(int $entityId, string $dateFrom, string $dateTo): int
    {
        $units = 0;
        foreach (['from_account_id' => 1, 'to_account_id' => -1] as $column => $sign) {
            $sum = $this->db->table('otternova_ledger_master e')
                ->selectSum('e.base_amount', 'amount')
                ->join('otternova_ledger_account a', 'a.id = e.' . $column, 'inner')
                ->where(['a.entity_id' => $entityId, 'a.account_class' => 'R'])
                ->where('e.transaction_date >=', $dateFrom)
                ->where('e.transaction_date <=', $dateTo)
                ->get()->getRow()->amount;
            $units += $sign * Decimal::toUnits($sum);
        }

        return $units;
    }

    /**
     * The rows of the entity's accounts of one class in the period, with the tax of their transactions
     * @param int    $entityId
     * @param string $class    R or X
     * @param string $vatKind  The VAT that goes with them
     * @param string $whtKind  The withholding tax that goes with them
     * @param string $dateFrom
     * @param string $dateTo
     * @return array
     */
    private function register(int $entityId, string $class, string $vatKind, string $whtKind, string $dateFrom, string $dateTo): array
    {
        $rows = $this->db->table('otternova_ledger_master e')
            ->select('e.id, e.public_id, e.journal_id, e.transaction_date, e.reference_number, e.transaction_detail, e.base_amount, e.receipt_file_path')
            ->select('c.category_code, c.category_name, f.account_class AS from_class, f.account_name AS from_account_name, t.account_name AS to_account_name')
            ->select('p.counterparty_name, p.tax_id AS counterparty_tax_id, p.tax_branch AS counterparty_tax_branch, x.tax_code')
            ->join('otternova_ledger_account f', 'f.id = e.from_account_id', 'inner')
            ->join('otternova_ledger_account t', 't.id = e.to_account_id', 'inner')
            ->join('otternova_ledger_category c', 'c.id = e.category_id', 'inner')
            ->join('otternova_ledger_counterparty p', 'p.id = e.counterparty_id', 'left')
            ->join('otternova_ledger_tax_code x', 'x.id = e.tax_code_id', 'left')
            ->where('f.entity_id', $entityId)
            ->groupStart()->where('f.account_class', $class)->orWhere('t.account_class', $class)->groupEnd()
            ->where('e.transaction_date >=', $dateFrom)
            ->where('e.transaction_date <=', $dateTo)
            ->orderBy('e.transaction_date', 'ASC')
            ->orderBy('e.id', 'ASC')
            ->get()->getResultArray();

        // the tax and receipts of their transactions, given to the first of their rows only
        $journals = array_values(array_unique(array_filter(array_column($rows, 'journal_id'))));
        $taxes    = [];
        $receipts = [];
        if ($journals !== []) {
            foreach ($this->taxLines($entityId, [$vatKind, $whtKind], null, null, $journals) as $line) {
                $taxes[$line['journal_id']][$line['tax_kind']] = ($taxes[$line['journal_id']][$line['tax_kind']] ?? 0) + Decimal::toUnits($line['tax_amount']);
                $taxes[$line['journal_id']]['codes'][]         = $line['tax_code'];
            }
            foreach ($this->db->table('otternova_ledger_master')->select('journal_id')->whereIn('journal_id', $journals)
                ->where('receipt_file_path IS NOT NULL', null, false)->where('receipt_file_path !=', '')->get()->getResultArray() as $row) {
                $receipts[$row['journal_id']] = true;
            }
        }

        $out    = [];
        $totals = ['amount' => 0, 'vat' => 0, 'wht' => 0];
        $seen   = [];
        foreach ($rows as $row) {
            if ($row['base_amount'] === null) {
                continue; // no amount in the base currency yet; the reports warn about these
            }
            $sign    = ($class === 'R') === ($row['from_class'] === $class) ? 1 : -1;
            $amount  = $sign * Decimal::toUnits($row['base_amount']);
            $journal = $row['journal_id'];
            $first   = $journal !== null && ! isset($seen[$journal]);
            $seen[$journal ?? ''] = true;
            $vat     = $first ? ($taxes[$journal][$vatKind] ?? 0) : 0;
            $wht     = $first ? ($taxes[$journal][$whtKind] ?? 0) : 0;
            $codes   = array_values(array_unique(array_filter([$row['tax_code'], ...($first ? ($taxes[$journal]['codes'] ?? []) : [])])));
            $totals['amount'] += $amount;
            $totals['vat']    += $vat;
            $totals['wht']    += $wht;
            $out[] = [
                'public_id'               => $row['public_id'],
                'journal_id'              => $journal,
                'transaction_date'        => $row['transaction_date'],
                'reference_number'        => $row['reference_number'],
                'transaction_detail'      => $row['transaction_detail'],
                'category_code'           => $row['category_code'],
                'category_name'           => $row['category_name'],
                'account_name'            => $row['from_class'] === $class ? $row['from_account_name'] : $row['to_account_name'],
                'counterparty_name'       => $row['counterparty_name'],
                'counterparty_tax_id'     => $row['counterparty_tax_id'],
                'counterparty_tax_branch' => $row['counterparty_tax_branch'],
                'amount'                  => Decimal::fromUnits($amount),
                'vat'                     => Decimal::fromUnits($vat),
                'wht'                     => Decimal::fromUnits($wht),
                'tax_codes'               => $codes,
                'has_receipt'             => ($row['receipt_file_path'] !== null && $row['receipt_file_path'] !== '') || isset($receipts[$journal]),
            ];
        }

        return [
            'entity'    => $this->entity($entityId),
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'rows'      => $out,
            'totals'    => array_map(Decimal::fromUnits(...), $totals),
        ];
    }

    /**
     * The entity's rows with a tax code of these kinds, oldest first
     * @param int               $entityId
     * @param list<string>      $kinds
     * @param string|null       $dateFrom
     * @param string|null       $dateTo
     * @param list<string>|null $journals Only these transactions, whatever their dates
     * @return list<array>
     */
    private function taxLines(int $entityId, array $kinds, ?string $dateFrom, ?string $dateTo, ?array $journals = null): array
    {
        $builder = $this->db->table('otternova_ledger_master e')
            ->select('e.public_id, e.journal_id, e.transaction_date, e.reference_number, e.transaction_detail, e.base_amount, e.tax_base_amount, e.from_account_id, e.to_account_id, e.receipt_file_path')
            ->select('x.tax_code, x.tax_name, x.tax_kind, x.rate, x.account_id')
            ->select('p.counterparty_name, p.tax_id AS counterparty_tax_id, p.tax_branch AS counterparty_tax_branch')
            ->join('otternova_ledger_tax_code x', 'x.id = e.tax_code_id', 'inner')
            ->join('otternova_ledger_account f', 'f.id = e.from_account_id', 'inner')
            ->join('otternova_ledger_counterparty p', 'p.id = e.counterparty_id', 'left')
            ->where('f.entity_id', $entityId)
            ->whereIn('x.tax_kind', $kinds)
            ->where('e.base_amount IS NOT NULL', null, false);
        if ($dateFrom !== null) {
            $builder->where('e.transaction_date >=', $dateFrom)->where('e.transaction_date <=', $dateTo);
        }
        if ($journals !== null) {
            $builder->whereIn('e.journal_id', $journals);
        }
        $lines = [];
        foreach ($builder->orderBy('e.transaction_date', 'ASC')->orderBy('e.id', 'ASC')->get()->getResultArray() as $row) {
            $zero = (float) $row['rate'] === 0.0;
            if ($zero) {
                $sign = 1;
            } elseif (in_array($row['tax_kind'], TaxCode::OWED_KINDS, true)) {
                $sign = (string) $row['from_account_id'] === (string) $row['account_id'] ? 1 : -1;
            } else {
                $sign = (string) $row['to_account_id'] === (string) $row['account_id'] ? 1 : -1;
            }
            $lines[] = [
                'public_id'               => $row['public_id'],
                'journal_id'              => $row['journal_id'],
                'transaction_date'        => $row['transaction_date'],
                'reference_number'        => $row['reference_number'],
                'transaction_detail'      => $row['transaction_detail'],
                'counterparty_name'       => $row['counterparty_name'],
                'counterparty_tax_id'     => $row['counterparty_tax_id'],
                'counterparty_tax_branch' => $row['counterparty_tax_branch'],
                'tax_code'                => $row['tax_code'],
                'tax_name'                => $row['tax_name'],
                'tax_kind'                => $row['tax_kind'],
                'rate'                    => number_format((float) $row['rate'], 2, '.', ''),
                'tax_base'                => Decimal::fromUnits($sign * Decimal::toUnits($row['tax_base_amount'] ?? $row['base_amount'])),
                'tax_amount'              => Decimal::fromUnits($zero ? 0 : $sign * Decimal::toUnits($row['base_amount'])),
                'has_receipt'             => $row['receipt_file_path'] !== null && $row['receipt_file_path'] !== '',
            ];
        }

        return $lines;
    }

    /**
     * Lines with their totals
     * @param list<array> $lines
     * @return array{lines: list<array>, base: string, tax: string}
     */
    private static function section(array $lines): array
    {
        $base = 0;
        $tax  = 0;
        foreach ($lines as $line) {
            $base += Decimal::toUnits($line['tax_base']);
            $tax  += Decimal::toUnits($line['tax_amount']);
        }

        return ['lines' => $lines, 'base' => Decimal::fromUnits($base), 'tax' => Decimal::fromUnits($tax)];
    }

    /**
     * @param int $entityId
     * @return array
     */
    private function entity(int $entityId): array
    {
        $row = $this->db->table('otternova_ledger_entity')
            ->select('public_id, entity_name, legal_form, country_code, tax_id, base_currency_code, timezone, fiscal_year_start_month, vat_threshold_amount, vat_registered_on, closed_through')
            ->where('id', $entityId)
            ->get()->getRowArray();
        $row['fiscal_year_start_month'] = (int) $row['fiscal_year_start_month'];

        return $row;
    }
}
