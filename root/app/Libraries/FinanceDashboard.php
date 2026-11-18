<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * The figures of the Finance dashboard of the Admin app, read in one go: what waits for the staff (payments and
 * withholding tax certificates to review, businesses asking to withhold tax, unpaid invoices overdue or due soon),
 * this month's collections by currency, renewals and cancellations, upcoming price changes and the internal
 * ledger's accounts. "This month" is the calendar month in UTC, as every time in the database is UTC.
 * Amounts go out as strings with 2 decimals; records go out by their public_id.
 */
class FinanceDashboard
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** How far ahead an invoice counts as due soon and a subscription as renewing soon */
    private const SOON_DAYS = 7;

    /** How many rows the lists of the dashboard hold */
    private const LIST_LENGTH = 5;

    private string $now;
    private string $monthStart;
    private string $soon;

    public function __construct(private readonly BaseConnection $db)
    {
        $now              = Time::now('UTC');
        $this->now        = $now->format(self::DATETIME);
        $this->monthStart = $now->format('Y-m-01 00:00:00');
        $this->soon       = $now->addDays(self::SOON_DAYS)->format(self::DATETIME);
    }

    /**
     * Every figure of the dashboard
     * @return array
     */
    public function summary(): array
    {
        return [
            'as_of'            => $this->now,
            'month_start'      => $this->monthStart,
            'soon_days'        => self::SOON_DAYS,
            'queue'            => $this->queue(),
            'pending_payments' => $this->pendingPayments(),
            'overdue_invoices' => $this->overdueInvoices(),
            'collections'      => $this->collections(),
            'subscriptions'    => $this->subscriptions(),
            'price_changes'    => $this->priceChanges(),
            'ledger'           => $this->ledger(),
        ];
    }

    /**
     * How many of each thing wait for the staff
     * @return array<string, int>
     */
    public function queue(): array
    {
        return [
            'payments_to_review'     => $this->db->table('payment_transaction')->where('txn_status', 'P')->countAllResults(),
            'certificates_to_review' => $this->db->table('withholding_tax_certificate')->where('cert_status', 'P')->countAllResults(),
            'withholding_requests'   => $this->db->table('business_master')->where('wht_status', 'R')->countAllResults(),
            'invoices_overdue'       => $this->db->table('subscription_invoice')->where('invoice_status', 'P')
                ->where('due_at <', $this->now)->countAllResults(),
            'invoices_due_soon'      => $this->db->table('subscription_invoice')->where('invoice_status', 'P')
                ->where('due_at >=', $this->now)->where('due_at <', $this->soon)->countAllResults(),
        ];
    }

    /**
     * The payments waiting longest for review
     * @return list<array>
     */
    private function pendingPayments(): array
    {
        $rows = $this->db->table('payment_transaction t')
            ->select('t.public_id, t.currency_code, t.amount, t.created_at, i.public_id AS invoice_public_id, i.invoice_no, b.business_name')
            ->join('subscription_invoice i', 'i.id = t.invoice_id', 'inner')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner')
            ->where('t.txn_status', 'P')
            ->orderBy('t.created_at', 'ASC')->orderBy('t.id', 'ASC')
            ->limit(self::LIST_LENGTH)->get()->getResultArray();

        return array_map(static fn (array $row): array => ['amount' => self::money($row['amount'])] + $row, $rows);
    }

    /**
     * The unpaid invoices overdue longest
     * @return list<array>
     */
    private function overdueInvoices(): array
    {
        $rows = $this->db->table('subscription_invoice i')
            ->select('i.public_id, i.invoice_no, i.currency_code, i.amount_payable, i.due_at, b.business_name')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner')
            ->where('i.invoice_status', 'P')->where('i.due_at <', $this->now)
            ->orderBy('i.due_at', 'ASC')->orderBy('i.id', 'ASC')
            ->limit(self::LIST_LENGTH)->get()->getResultArray();

        return array_map(static fn (array $row): array => ['amount_payable' => self::money($row['amount_payable'])] + $row, $rows);
    }

    /**
     * By currency: invoiced this month (not voided), collected this month (paid), tax withheld on what was paid this
     * month, and what is unpaid now, of it overdue
     * @return list<array{currency_code: string, invoiced: string, collected: string, withheld: string, outstanding: string, overdue: string}>
     */
    private function collections(): array
    {
        $now   = $this->db->escape($this->now);
        $month = $this->db->escape($this->monthStart);
        $rows  = $this->db->table('subscription_invoice')
            ->select('currency_code')
            ->select("SUM(CASE WHEN invoice_status <> 'V' AND created_at >= {$month} THEN amount_payable ELSE 0 END) AS invoiced", false)
            ->select("SUM(CASE WHEN invoice_status = 'D' AND paid_at >= {$month} THEN amount_payable ELSE 0 END) AS collected", false)
            ->select("SUM(CASE WHEN invoice_status = 'D' AND paid_at >= {$month} THEN wht_amount ELSE 0 END) AS withheld", false)
            ->select("SUM(CASE WHEN invoice_status = 'P' THEN amount_payable ELSE 0 END) AS outstanding", false)
            ->select("SUM(CASE WHEN invoice_status = 'P' AND due_at < {$now} THEN amount_payable ELSE 0 END) AS overdue", false)
            ->groupBy('currency_code')->orderBy('currency_code', 'ASC')
            ->get()->getResultArray();

        $currencies = [];
        foreach ($rows as $row) {
            $amounts = array_map(self::money(...), array_diff_key($row, ['currency_code' => true]));
            if (array_filter($amounts, static fn (string $amount): bool => $amount !== '0.00') !== []) {
                $currencies[] = ['currency_code' => $row['currency_code']] + $amounts;
            }
        }

        return $currencies;
    }

    /**
     * Active subscriptions billed again soon (not ending at the period's end), and those cancelled this month
     * @return array{renewals_due: int, cancelled_this_month: int}
     */
    private function subscriptions(): array
    {
        return [
            'renewals_due'         => $this->db->table('subscription_master')->where('subscription_status', 'A')
                ->where('cancel_at_period_end', 'N')
                ->where('next_billing_at >=', $this->now)->where('next_billing_at <', $this->soon)->countAllResults(),
            'cancelled_this_month' => $this->db->table('subscription_master')->where('subscription_status', 'C')
                ->where('cancelled_at >=', $this->monthStart)->countAllResults(),
        ];
    }

    /**
     * The plan prices that take effect next, soonest first
     * @return list<array>
     */
    private function priceChanges(): array
    {
        $rows = $this->db->table('plan_version v')
            ->select('v.public_id, p.plan_code, v.country_code, v.billing_cycle, v.currency_code, v.price, v.effective_from')
            ->join('plan_master p', 'p.id = v.plan_id', 'inner')
            ->where('v.effective_from >', $this->now)
            ->orderBy('v.effective_from', 'ASC')->orderBy('v.id', 'ASC')
            ->limit(self::LIST_LENGTH)->get()->getResultArray();

        return array_map(static fn (array $row): array => ['price' => self::money($row['price'])] + $row, $rows);
    }

    /**
     * This month's entries, the entries without a receipt, and every internal account with what came in, what went
     * out and the balance, in the account's currency. An entry counts at its billed amount when that is in the
     * account's currency, otherwise at its transaction amount when that is; an entry in neither is left out.
     * @return array{entries_this_month: int, missing_receipts: int, accounts: list<array>}
     */
    public function ledger(): array
    {
        $amount = 'CASE WHEN e.billed_currency_code = a.currency_code THEN e.billed_amount'
            . ' WHEN e.transaction_currency_code = a.currency_code THEN e.transaction_amount ELSE 0 END';
        $sums   = [];
        foreach (['to_account_id' => 'money_in', 'from_account_id' => 'money_out'] as $column => $side) {
            $rows = $this->db->table('otternova_ledger_master e')
                ->select('a.id')->select("SUM({$amount}) AS total", false)
                ->join('otternova_ledger_account a', 'a.id = e.' . $column, 'inner')
                ->where('a.account_type', 'I')
                ->groupBy('a.id')->get()->getResultArray();
            foreach ($rows as $row) {
                $sums[(int) $row['id']][$side] = (float) $row['total'];
            }
        }

        $accounts = [];
        $rows     = $this->db->table('otternova_ledger_account')
            ->select('id, public_id, account_name, currency_code')
            ->where('account_type', 'I')->orderBy('account_name', 'ASC')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $in         = $sums[(int) $row['id']]['money_in'] ?? 0.0;
            $out        = $sums[(int) $row['id']]['money_out'] ?? 0.0;
            $accounts[] = [
                'public_id'     => $row['public_id'],
                'account_name'  => $row['account_name'],
                'currency_code' => $row['currency_code'],
                'money_in'      => self::money($in),
                'money_out'     => self::money($out),
                'balance'       => self::money($in - $out),
            ];
        }

        return [
            'entries_this_month' => $this->db->table('otternova_ledger_master')
                ->where('transaction_date >=', substr($this->monthStart, 0, 10))->countAllResults(),
            'missing_receipts'   => $this->db->table('otternova_ledger_master')->where('receipt_file_path', null)->countAllResults(),
            'accounts'           => $accounts,
        ];
    }

    /**
     * An amount as a string with 2 decimals, as the database's DECIMAL gives it on MySQL
     * @param mixed $amount
     * @return string
     */
    private static function money(mixed $amount): string
    {
        $value = round((float) $amount, 2);

        return number_format($value == 0 ? 0.0 : $value, 2, '.', '');
    }
}
