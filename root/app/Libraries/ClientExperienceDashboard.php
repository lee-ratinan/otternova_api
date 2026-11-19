<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;

/**
 * The figures of the Client Experience (CXD) dashboard of the Admin app, read in one go:
 * - the inbox: what waits, and what came in over the last 30 days against the 30 before, by language
 * - how fast messages are answered: the median time to the first read and to done (contact_message.first_read_at,
 *   response_seconds), and how many were done within a day. Clock time: working hours and holidays are not taken out.
 * - what the messages turned out to be (message_category)
 * - the oldest messages still open
 * - the businesses' customers' trouble over the last 7 days: cancelled orders and their reasons, refunds still owed,
 *   no-shows and failed payments
 * - reviews waiting for approval, and low ratings with no reply
 * - the new businesses of the last 30 days that have not set up a website, a store or a way to be paid
 * Spam is left out of every figure but its own count. Every time is UTC.
 */
class ClientExperienceDashboard
{
    private const DATETIME = 'Y-m-d H:i:s';

    public const PERIOD_DAYS = 30;
    public const RECENT_DAYS = 7;

    /** A message done within this many seconds counts as answered within a day */
    public const WITHIN_SECONDS = 86400;

    public const CATEGORIES = ['I', 'C', 'U', 'M', 'X'];

    private const QUEUE_SIZE    = 8;
    private const TOP_REASONS   = 3;
    private const REASON_LENGTH = 80;

    private Time $now;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->now = Time::now('UTC');
    }

    /**
     * Every figure of the dashboard
     * @return array
     */
    public function summary(): array
    {
        return [
            'as_of'       => $this->now->format(self::DATETIME),
            'period_days' => self::PERIOD_DAYS,
            'recent_days' => self::RECENT_DAYS,
            'inbox'       => $this->inbox(),
            'response'    => $this->response(),
            'categories'  => $this->categories(),
            'queue'       => $this->queue(),
            'orders'      => $this->orders(),
            'reviews'     => $this->reviews(),
            'onboarding'  => $this->onboarding(),
        ];
    }

    /**
     * What waits (new, and new or read), the oldest of it, what came in over the period against the one before, the
     * spam of the last 7 days, and the period's messages by language
     * @return array
     */
    private function inbox(): array
    {
        $start    = $this->daysAgo(self::PERIOD_DAYS);
        $previous = $this->daysAgo(self::PERIOD_DAYS * 2);
        $oldest   = $this->db->table('contact_message')->selectMin('created_at', 'oldest')->whereIn('status', ['N', 'R'])->get()->getRowArray();
        $locales  = $this->db->table('contact_message')->select('locale, COUNT(*) AS messages')
            ->where('status !=', 'S')->where('created_at >=', $start)
            ->groupBy('locale')->orderBy('messages', 'DESC')->orderBy('locale', 'ASC')->get()->getResultArray();

        return [
            'new'               => $this->db->table('contact_message')->where('status', 'N')->countAllResults(),
            'open'              => $this->db->table('contact_message')->whereIn('status', ['N', 'R'])->countAllResults(),
            'oldest_open_at'    => $oldest['oldest'] ?? null,
            'received'          => $this->db->table('contact_message')->where('status !=', 'S')->where('created_at >=', $start)->countAllResults(),
            'received_previous' => $this->db->table('contact_message')->where('status !=', 'S')
                ->where('created_at >=', $previous)->where('created_at <', $start)->countAllResults(),
            'spam_recent'       => $this->db->table('contact_message')->where('status', 'S')
                ->where('created_at >=', $this->daysAgo(self::RECENT_DAYS))->countAllResults(),
            'by_locale'         => array_map(static fn (array $row): array => ['locale' => $row['locale'], 'messages' => (int) $row['messages']], $locales),
        ];
    }

    /**
     * The messages done over the period: how many, the median time to done against the period before, how many
     * within a day; and the median time to the first read of the messages received over the period
     * @return array
     */
    private function response(): array
    {
        $start    = $this->daysAgo(self::PERIOD_DAYS);
        $resolved = array_map('intval', array_column($this->db->table('contact_message')->select('response_seconds')
            ->where('resolved_at >=', $start)->where('response_seconds IS NOT NULL', null, false)->get()->getResultArray(), 'response_seconds'));
        $before = array_map('intval', array_column($this->db->table('contact_message')->select('response_seconds')
            ->where('resolved_at >=', $this->daysAgo(self::PERIOD_DAYS * 2))->where('resolved_at <', $start)
            ->where('response_seconds IS NOT NULL', null, false)->get()->getResultArray(), 'response_seconds'));
        $reads = array_map(
            static fn (array $row): int => max(0, strtotime($row['first_read_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC')),
            $this->db->table('contact_message')->select('created_at, first_read_at')->where('status !=', 'S')
                ->where('created_at >=', $start)->where('first_read_at IS NOT NULL', null, false)->get()->getResultArray()
        );

        return [
            'resolved'                  => count($resolved),
            'median_seconds'            => self::median($resolved),
            'previous_median_seconds'   => self::median($before),
            'within_day'                => count(array_filter($resolved, static fn (int $seconds): bool => $seconds <= self::WITHIN_SECONDS)),
            'first_read_median_seconds' => self::median($reads),
        ];
    }

    /**
     * What the period's messages turned out to be, with none for the ones not categorised yet
     * @return array<string, int>
     */
    private function categories(): array
    {
        $counts = array_fill_keys([...self::CATEGORIES, 'none'], 0);
        $rows   = $this->db->table('contact_message')->select('message_category, COUNT(*) AS messages')
            ->where('status !=', 'S')->where('created_at >=', $this->daysAgo(self::PERIOD_DAYS))
            ->groupBy('message_category')->get()->getResultArray();
        foreach ($rows as $row) {
            $counts[$row['message_category'] ?? 'none'] += (int) $row['messages'];
        }

        return $counts;
    }

    /**
     * The oldest messages still new or read
     * @return list<array>
     */
    private function queue(): array
    {
        $rows = $this->db->table('contact_message m')
            ->select('m.id, m.name, m.subject, m.locale, m.status, m.message_category, m.first_read_at, m.created_at, a.full_name AS handled_by_name')
            ->join('auth_master a', 'a.id = m.handled_by', 'left')
            ->whereIn('m.status', ['N', 'R'])->orderBy('m.created_at', 'ASC')->orderBy('m.id', 'ASC')
            ->limit(self::QUEUE_SIZE)->get()->getResultArray();

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];

            return $row;
        }, $rows);
    }

    /**
     * The businesses' orders over the last 7 days that went wrong: cancelled (with the commonest reasons, the same
     * reason told apart from case and spacing), no-shows and failed payments; and the cancelled orders of any time
     * that are still owed a refund
     * @return array
     */
    private function orders(): array
    {
        $recent  = $this->daysAgo(self::RECENT_DAYS);
        $reasons = [];
        $rows    = $this->db->table('order_master')->select('cancel_reason')->where('cancelled_at >=', $recent)->get()->getResultArray();
        foreach ($rows as $row) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $row['cancel_reason']));
            if ($text === '') {
                continue;
            }
            $key = mb_strtolower($text);
            $reasons[$key] ??= ['reason' => mb_strlen($text) > self::REASON_LENGTH ? mb_substr($text, 0, self::REASON_LENGTH) . '…' : $text, 'orders' => 0];
            $reasons[$key]['orders']++;
        }
        uasort($reasons, static fn (array $a, array $b): int => [$b['orders'], $a['reason']] <=> [$a['orders'], $b['reason']]);

        return [
            'cancelled'       => count($rows),
            'top_reasons'     => array_slice(array_values($reasons), 0, self::TOP_REASONS),
            'refunds_owed'    => $this->db->table('order_master')->where('cancelled_at IS NOT NULL', null, false)->where('refund_due_amount >', 0)->countAllResults(),
            'no_shows'        => $this->db->table('order_master')->where('no_show_at >=', $recent)->countAllResults(),
            'failed_payments' => $this->db->table('order_payment')->where('transaction_type', 'payment')->where('transaction_status', 'failed')
                ->where('created_at >=', $recent)->countAllResults(),
        ];
    }

    /**
     * Reviews waiting for a business to approve them, low ratings (1 or 2) not hidden and with no reply, and the
     * period's reviews' average rating
     * @return array
     */
    private function reviews(): array
    {
        $period = $this->db->table('artifact_review')->select('COUNT(*) AS reviews, AVG(rating) AS average')
            ->where('review_status !=', 'H')->where('created_at >=', $this->daysAgo(self::PERIOD_DAYS))->get()->getRowArray();

        return [
            'pending'        => $this->db->table('artifact_review')->where('review_status', 'P')->countAllResults(),
            'low_unanswered' => $this->db->table('artifact_review')->where('review_status !=', 'H')->where('rating <=', 2)
                ->where('reply_message IS NULL', null, false)->countAllResults(),
            'reviews'        => (int) ($period['reviews'] ?? 0),
            'average'        => ($period['reviews'] ?? 0) > 0 ? number_format((float) $period['average'], 1, '.', '') : null,
        ];
    }

    /**
     * The businesses created over the period, and how many of them have no website, no store and no active way to
     * be paid yet: the ones to reach out to
     * @return array
     */
    private function onboarding(): array
    {
        $ids = array_map('intval', array_column($this->db->table('business_master')->select('id')
            ->where('created_at >=', $this->daysAgo(self::PERIOD_DAYS))->get()->getResultArray(), 'id'));
        if ($ids === []) {
            return ['new' => 0, 'without_website' => 0, 'without_store' => 0, 'without_payment_method' => 0];
        }
        $having = fn (string $table, array $where = []): int => count(array_unique(array_column(
            $this->db->table($table)->select('business_id')->whereIn('business_id', $ids)->where($where)->get()->getResultArray(),
            'business_id'
        )));

        return [
            'new'                    => count($ids),
            'without_website'        => count($ids) - $having('business_website'),
            'without_store'          => count($ids) - $having('business_store'),
            'without_payment_method' => count($ids) - $having('business_payment_method', ['is_active' => 'Y']),
        ];
    }

    /**
     * @param int $days
     * @return string The time that many days before now
     */
    private function daysAgo(int $days): string
    {
        return $this->now->subDays($days)->format(self::DATETIME);
    }

    /**
     * @param list<int> $values
     * @return int|null The middle value, or the average of the two middle ones rounded down; null for none
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
