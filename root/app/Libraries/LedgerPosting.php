<?php

namespace App\Libraries;

use App\Models\OtternovaLedgerCounterpartyModel;
use App\Models\OtternovaLedgerMasterModel;
use App\Models\SubscriptionInvoiceModel as Invoice;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Posts the subscription billing to the ledger, on the accrual basis, by the posting rules
 * (otternova_ledger_posting_rule) of the businesses' countries:
 * - an invoice issued: accounts receivable (debit) from deferred revenue (credit), for amount_due; a top-up, which
 *   bills the tax a customer withheld without sending the certificate, from the tax withheld account instead;
 * - an invoice voided: the same, back;
 * - an invoice paid: the cash account for amount_payable and the tax withheld account for wht_amount (with the WS
 *   tax code, on amount_due) from accounts receivable;
 * - revenue earned: each month of a paid period's service, by days, from deferred revenue into revenue, on the
 *   month's last day (or the period's), once that day is over.
 * An invoice belongs to the rule in effect for its business's country on the day it was made, in the rule's
 * entity's timezone, and all its rows to that entity; an invoice made before any rule is never posted, so what was
 * entered by hand stays as it is. A row's date is its event's day, or the first open day when that day's month is
 * closed. Each row has a source_key (inv:{id}:{event}:{line}), so sync() posts every event once whenever it runs:
 * after billing:run, before a month closes, and when the staff ask. Posted rows cannot be changed by hand.
 */
class LedgerPosting
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Posts every billing event not posted yet that can be
     * @param string|null $now UTC; now by default
     * @return array{posted: int, rows: int, waiting: int, failed: int}
     */
    public function sync(?string $now = null): array
    {
        $counts = ['posted' => 0, 'rows' => 0, 'waiting' => 0, 'failed' => 0];
        foreach ($this->events($now ?? SubscriptionBilling::now()) as $event) {
            if ($event['problem'] !== null) {
                $counts['waiting']++;
                continue;
            }
            try {
                $rows            = $this->post($event);
                $counts['rows'] += $rows;
                $counts['posted'] += $rows > 0 ? 1 : 0;
            } catch (Throwable $e) {
                $counts['failed']++;
                log_message('error', 'Ledger posting of {key} failed: {message}', ['key' => $event['key'], 'message' => $e->getMessage()]);
            }
        }

        return $counts;
    }

    /**
     * The billing events waiting to be posted, and why: no_rule (no rule for the business's country on the day the
     * invoice was made, after that country's posting started), no_rate (no exchange rate for an invoice not in the
     * entity's base currency), no_wht_account (tax withheld, but the rule has no account for it)
     * @param string|null $now
     * @return list<array{key: string, problem: string, invoice_no: string, country_code: string, entity_id: int|null, date: string|null}>
     */
    public function pending(?string $now = null): array
    {
        $out = [];
        foreach ($this->events($now ?? SubscriptionBilling::now()) as $event) {
            if ($event['problem'] !== null) {
                $out[] = [
                    'key'          => $event['key'],
                    'problem'      => $event['problem'],
                    'invoice_no'   => $event['invoice']['invoice_no'],
                    'country_code' => $event['invoice']['country_code'],
                    'entity_id'    => $event['rule'] === null ? null : (int) $event['rule']['entity_id'],
                    'date'         => $event['date'],
                ];
            }
        }

        return $out;
    }

    /**
     * The events not posted yet, oldest first, each with its rows or its problem
     * @param string $now UTC
     * @return list<array>
     */
    private function events(string $now): array
    {
        $rules = $this->rules();
        if ($rules === []) {
            return [];
        }
        $posted = array_flip(array_map(
            static fn (string $key): string => substr($key, 0, (int) strrpos($key, ':')),
            array_column($this->db->table('otternova_ledger_master')->select('source_key')->like('source_key', 'inv:', 'after')->get()->getResultArray(), 'source_key')
        ));
        $invoices = $this->db->table('subscription_invoice i')
            ->select('i.*, s.business_id, b.country_code, b.business_name, b.legal_name')
            ->join('subscription_master s', 's.id = i.subscription_id', 'inner')
            ->join('business_master b', 'b.id = s.business_id', 'inner')
            ->whereIn('b.country_code', array_keys($rules))
            ->orderBy('i.id', 'ASC')
            ->get()->getResultArray();

        $events = [];
        foreach ($invoices as $invoice) {
            [$rule, $problem] = $this->ruleOf($rules[$invoice['country_code']], $invoice['created_at']);
            if ($rule === null && $problem === null) {
                continue; // made before this country's posting started
            }
            $type = $invoice['invoice_type'];
            $add  = function (string $event, string $at, ?string $day = null) use (&$events, $invoice, $rule, $problem, $posted) {
                $key = 'inv:' . $invoice['id'] . ':' . $event;
                if (! isset($posted[$key])) {
                    $events[] = ['key' => $key, 'event' => $event, 'invoice' => $invoice, 'rule' => $rule, 'problem' => $problem, 'at' => $at, 'day' => $day, 'date' => null];
                }
            };
            $add('issue', $invoice['created_at']);
            if ($invoice['invoice_status'] === Invoice::STATUS_VOID && $invoice['voided_at'] !== null) {
                $add('void', $invoice['voided_at']);
            }
            if ($invoice['invoice_status'] === Invoice::STATUS_PAID && $invoice['paid_at'] !== null) {
                $add('paid', $invoice['paid_at']);
                if ($type === Invoice::TYPE_PERIOD && $rule !== null && $invoice['period_start'] !== null && $invoice['period_end'] !== null) {
                    foreach ($this->earnedMonths($invoice, $rule, $now) as $month => $day) {
                        $add('rec:' . $month, $now, $day);
                    }
                }
            }
        }

        foreach ($events as &$event) {
            if ($event['problem'] === null) {
                $event['date']    = $event['day'] ?? self::localDate($event['at'], $event['rule']['timezone']);
                $event['problem'] = $this->rowsOf($event);
            }
        }
        unset($event);
        usort($events, static fn (array $a, array $b): int => [$a['date'] ?? '', $a['key']] <=> [$b['date'] ?? '', $b['key']]);

        return $events;
    }

    /**
     * Works out the event's rows into $event['rows'], or why it cannot be posted
     * @param array $event By reference
     * @return string|null The problem
     */
    private function rowsOf(array &$event): ?string
    {
        $invoice = $event['invoice'];
        $rule    = $event['rule'];
        $topUp   = $invoice['invoice_type'] === Invoice::TYPE_TOP_UP;
        $due     = Decimal::toUnits($invoice['amount_due']);
        $wht     = Decimal::toUnits($invoice['wht_amount']);
        $payable = Decimal::toUnits($invoice['amount_payable']);
        if (($topUp || ($event['event'] === 'paid' && $wht > 0)) && $rule['wht_receivable_account_id'] === null) {
            return 'no_wht_account';
        }
        $other = $topUp ? $rule['wht_receivable_account_id'] : $rule['deferred_revenue_account_id'];
        $label = ($topUp ? 'Top-up ' : 'Invoice ') . $invoice['invoice_no'];

        // [from, to, units, detail, tax]
        $rows = match (true) {
            $event['event'] === 'issue' => [[$other, $rule['receivable_account_id'], $due, $label . ' issued', false]],
            $event['event'] === 'void'  => [[$rule['receivable_account_id'], $other, $due, $label . ' voided', false]],
            $event['event'] === 'paid'  => [
                [$rule['receivable_account_id'], $rule['cash_account_id'], $payable, $label . ' paid', false],
                [$rule['receivable_account_id'], $rule['wht_receivable_account_id'], $wht, 'Tax withheld from ' . $label, true],
            ],
            default                     => [[$rule['revenue_account_id'], $rule['deferred_revenue_account_id'], $this->earned($event), 'Revenue earned on ' . $label . ' in ' . substr($event['day'], 0, 7), false]],
        };
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row[2] > 0));

        $rate = null;
        if ($invoice['currency_code'] !== $rule['base_currency_code']) {
            $rate = service('exchangeRates')->rate($event['date'], $invoice['currency_code'], $rule['base_currency_code']);
            if ($rate === null) {
                return 'no_rate';
            }
        }
        $event['rate'] = $rate;
        $event['rows'] = $rows;

        return null;
    }

    /**
     * Writes the event's rows, in one transaction with their log_activity rows; nothing when it had none to write
     * (an invoice of 0). Returns how many it wrote.
     * @param array $event
     * @return int
     * @throws Throwable
     */
    private function post(array $event): int
    {
        if ($event['rows'] === []) {
            return 0;
        }
        $invoice = $event['invoice'];
        $rule    = $event['rule'];
        $date    = $event['date'];
        $late    = '';
        $through = $this->db->table('otternova_ledger_entity')->select('closed_through')->where('id', $rule['entity_id'])->get()->getRow()->closed_through ?? null;
        if ($through !== null && $date <= $through) {
            $late = ' (of ' . $date . ', after its month closed)';
            $date = (new DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d');
        }
        $reference = $event['event'] === 'paid' && $invoice['receipt_no'] !== null ? $invoice['receipt_no'] : $invoice['invoice_no'];
        $entries   = model(OtternovaLedgerMasterModel::class);
        $log       = new ActivityLog();

        $this->db->resetTransStatus()->transException(true)->transBegin();
        try {
            $counterparty = $this->counterpartyOf($invoice);
            $journal      = null;
            foreach ($event['rows'] as $i => [$from, $to, $units, $detail, $tax]) {
                $amount   = Decimal::fromUnits($units);
                $publicId = $entries->newPublicId();
                $journal ??= $publicId;
                $row      = [
                    'public_id'                 => $publicId,
                    'transaction_date'          => $date,
                    'transaction_detail'        => $detail . $late,
                    'reference_number'          => $reference,
                    'category_id'               => (int) $rule['category_id'],
                    'transaction_currency_code' => $invoice['currency_code'],
                    'transaction_amount'        => $amount,
                    'billed_currency_code'      => $invoice['currency_code'],
                    'billed_amount'             => $amount,
                    'base_rate'                 => $event['rate'],
                    'base_amount'               => $event['rate'] === null ? $amount : Decimal::multiply($amount, $event['rate']),
                    'tax_code_id'               => $tax ? $rule['wht_tax_code_id'] : null,
                    'tax_base_amount'           => $tax && $rule['wht_tax_code_id'] !== null
                        ? ($event['rate'] === null ? Decimal::fromUnits(Decimal::toUnits($invoice['amount_due'])) : Decimal::multiply($invoice['amount_due'], $event['rate'])) : null,
                    'journal_id'                => $journal,
                    'source_key'                => $event['key'] . ':' . ($i + 1),
                    'from_account_id'           => (int) $from,
                    'to_account_id'             => (int) $to,
                    'counterparty_id'           => $counterparty,
                    'created_by'                => null,
                ];
                $this->db->table('otternova_ledger_master')->insert($row);
                $log->inserted(null, 'Posted ' . $detail . ' to the ledger', 'otternova_ledger_master', (int) $this->db->insertID(), $row);
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        return count($event['rows']);
    }

    /**
     * The ledger counterparty of the invoice's business, made the first time: its legal name, or its name. Its tax ID
     * is not copied, as the business's is kept encrypted; Finance may fill it in.
     * @param array $invoice With business_id, business_name, legal_name
     * @return int
     */
    private function counterpartyOf(array $invoice): int
    {
        $id = $this->db->table('otternova_ledger_counterparty')->select('id')->where('business_id', $invoice['business_id'])->get()->getRow()->id ?? null;
        if ($id !== null) {
            return (int) $id;
        }
        $row = [
            'public_id'                => model(OtternovaLedgerCounterpartyModel::class)->newPublicId(),
            'counterparty_name'        => $invoice['legal_name'] ?: $invoice['business_name'],
            'counterparty_description' => 'Subscriber: ' . $invoice['business_name'],
            'business_id'              => (int) $invoice['business_id'],
        ];
        $this->db->table('otternova_ledger_counterparty')->insert($row);
        $id = (int) $this->db->insertID();
        (new ActivityLog())->inserted(null, 'Made ledger counterparty for business ' . $invoice['business_id'], 'otternova_ledger_counterparty', $id, $row);

        return $id;
    }

    /**
     * The rules by country, each with its entity's timezone and base currency, oldest first
     * @return array<string, list<array>>
     */
    private function rules(): array
    {
        $rules = [];
        foreach ($this->db->table('otternova_ledger_posting_rule r')
            ->select('r.*, n.timezone, n.base_currency_code')
            ->join('otternova_ledger_entity n', 'n.id = r.entity_id', 'inner')
            ->orderBy('r.effective_from', 'ASC')
            ->get()->getResultArray() as $rule) {
            $rules[$rule['country_code']][] = $rule;
        }

        return $rules;
    }

    /**
     * The rule in effect on the day an invoice was made
     * @param list<array> $rules Of its country, oldest first
     * @param string      $at    UTC
     * @return array{0: array|null, 1: string|null} The rule, or the problem; neither when the invoice is from before the country's posting started
     */
    private function ruleOf(array $rules, string $at): array
    {
        $before = true;
        foreach ($rules as $rule) {
            $day = self::localDate($at, $rule['timezone']);
            if ($day >= $rule['effective_from']) {
                $before = false;
                if ($rule['effective_to'] === null || $day <= $rule['effective_to']) {
                    return [$rule, null];
                }
            }
        }

        return [null, $before ? null : 'no_rule'];
    }

    /**
     * The months of a paid period whose service is over by today, in the rule's entity's timezone: month (Y-m) =>
     * the last day of service in it
     * @param array  $invoice
     * @param array  $rule
     * @param string $now UTC
     * @return array<string, string>
     */
    private function earnedMonths(array $invoice, array $rule, string $now): array
    {
        $start  = new DateTimeImmutable(self::localDate($invoice['period_start'], $rule['timezone']));
        $end    = new DateTimeImmutable(self::localDate($invoice['period_end'], $rule['timezone'])); // the first day after
        $today  = self::localDate($now, $rule['timezone']);
        $months = [];
        for ($month = $start->modify('first day of this month'); $month < $end; $month = $month->modify('+1 month')) {
            $last = min($month->format('Y-m-t'), $end->modify('-1 day')->format('Y-m-d'));
            if ($last >= $today) {
                break;
            }
            $months[$month->format('Y-m')] = $last;
        }

        return $months;
    }

    /**
     * The revenue earned in the event's month, in units: amount_due shared by days over the period, the rounding
     * left to the last month, so the months add up to amount_due
     * @param array $event
     * @return int
     */
    private function earned(array $event): int
    {
        $invoice = $event['invoice'];
        $tz      = $event['rule']['timezone'];
        $start   = new DateTimeImmutable(self::localDate($invoice['period_start'], $tz));
        $end     = new DateTimeImmutable(self::localDate($invoice['period_end'], $tz));
        $total   = max(1, (int) $start->diff($end)->days);
        $units   = Decimal::toUnits($invoice['amount_due']);
        $upTo    = static function (DateTimeImmutable $day) use ($start, $end, $total, $units): int {
            $days = (int) $start->diff(min($day, $end))->days;

            return $day >= $end ? $units : intdiv($units * $days * 2 + $total, $total * 2); // rounded half up
        };
        $last  = (new DateTimeImmutable($event['day']))->modify('+1 day');
        $first = new DateTimeImmutable(substr($event['day'], 0, 7) . '-01');

        return $upTo($last) - ($first <= $start ? 0 : $upTo($first));
    }

    /**
     * @param string $at       UTC
     * @param string $timezone
     * @return string Y-m-d there
     */
    private static function localDate(string $at, string $timezone): string
    {
        return (new DateTimeImmutable($at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    }
}
