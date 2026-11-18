<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use SplFileObject;

/**
 * The figures of the Engineering dashboard of the Admin app, read in one go:
 * - the API's errors, from its own log files (writable/logs/log-Y-m-d.log, written in UTC): the last 24 hours
 *   against the 24 before, by level, and the most frequent messages
 * - the emails that failed to send, as EmailService logs them
 * - the visitor tracker's queue: raw events the merge job has not processed yet, and the oldest of them
 * - the platform's use: orders, by source, business websites, and the inbox's new messages
 * The messages are shown to staff, so their e-mail addresses are masked and numbers folded to "#", which also
 * groups the same error together. Only the API's own log is read: the other apps keep their logs to themselves.
 */
class EngineeringDashboard
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** The levels counted as errors, worst first; CodeIgniter writes them in capitals at the start of a line */
    public const LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'];

    /** How many days of log files are read: the 7 days of failed emails, and today */
    private const LOG_DAYS = 8;

    private const TOP_ERRORS     = 5;
    private const MESSAGE_LENGTH = 160;

    /** A log entry's first line: "ERROR - 2026-11-17 07:38:16 --> message" */
    private const ENTRY = '/^([A-Z]+) - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --> (.*)$/';

    /** EmailService's line for an email that could not be sent */
    private const EMAIL_FAILED = '/^Email to .+ failed: /';

    private Time $now;
    private string $logPath;

    /**
     * @param BaseConnection $db
     * @param string|null    $logPath The log directory; WRITEPATH/logs/ when null
     */
    public function __construct(private readonly BaseConnection $db, ?string $logPath = null)
    {
        $this->now     = Time::now('UTC');
        $this->logPath = rtrim($logPath ?? WRITEPATH . 'logs', '/') . '/';
    }

    /**
     * Every figure of the dashboard
     * @return array
     */
    public function summary(): array
    {
        return [
            'as_of'   => $this->now->format(self::DATETIME),
            'errors'  => $this->errors(),
            'tracker' => $this->tracker(),
            'usage'   => $this->usage(),
        ];
    }

    /**
     * The errors of the last 24 hours against the 24 before, by level, the most frequent messages of the last
     * 24 hours, and the failed emails of the last 24 hours and 7 days
     * @return array
     */
    private function errors(): array
    {
        $dayAgo     = $this->now->subHours(24)->format(self::DATETIME);
        $twoDaysAgo = $this->now->subHours(48)->format(self::DATETIME);
        $weekAgo    = $this->now->subDays(7)->format(self::DATETIME);
        $now        = $this->now->format(self::DATETIME);

        $levels   = array_fill_keys(self::LEVELS, 0);
        $previous = 0;
        $emails   = ['last_24h' => 0, 'last_7d' => 0];
        $messages = [];
        foreach ($this->entries($weekAgo) as [$level, $at, $message]) {
            if ($at > $now) {
                continue;
            }
            if (preg_match(self::EMAIL_FAILED, $message)) {
                $emails['last_7d']++;
                $emails['last_24h'] += $at >= $dayAgo ? 1 : 0;
            }
            if ($at >= $dayAgo) {
                $levels[$level]++;
                $key = self::normalise($message);
                $messages[$key] ??= ['message' => $key, 'level' => $level, 'count' => 0, 'last_seen' => $at];
                $messages[$key]['count']++;
                $messages[$key]['last_seen'] = max($messages[$key]['last_seen'], $at);
            } elseif ($at >= $twoDaysAgo) {
                $previous++;
            }
        }
        usort($messages, static fn (array $a, array $b): int => [$b['count'], $b['last_seen']] <=> [$a['count'], $a['last_seen']]);

        return [
            'last_24h'      => array_sum($levels),
            'previous_24h'  => $previous,
            'by_level'      => $levels,
            'top'           => array_slice($messages, 0, self::TOP_ERRORS),
            'email_failures' => $emails,
        ];
    }

    /**
     * Every error entry of the log files since a time, as [level, time, first line of the message]; the lines of a
     * stack trace under an entry are skipped
     * @param string $since UTC, Y-m-d H:i:s
     * @return iterable<array{string, string, string}>
     */
    private function entries(string $since): iterable
    {
        for ($day = self::LOG_DAYS - 1; $day >= 0; $day--) {
            $file = $this->logPath . 'log-' . $this->now->subDays($day)->format('Y-m-d') . '.log';
            if (! is_file($file) || ! is_readable($file)) {
                continue;
            }
            $lines = new SplFileObject($file);
            foreach ($lines as $line) {
                if (! is_string($line) || ! preg_match(self::ENTRY, rtrim($line, "\r\n"), $parts)) {
                    continue;
                }
                if (in_array($parts[1], self::LEVELS, true) && $parts[2] >= $since) {
                    yield [$parts[1], $parts[2], $parts[3]];
                }
            }
        }
    }

    /**
     * A message as staff see it, and as it is grouped: e-mail addresses masked, numbers folded to "#", cut short
     * @param string $message
     * @return string
     */
    public static function normalise(string $message): string
    {
        $message = preg_replace('/[^\s@\'"<>(),;]+@[^\s@\'"<>(),;]+/', '…@…', $message);
        $message = preg_replace('/\d+(\.\d+)?/', '#', (string) $message);
        $message = trim((string) $message);

        return mb_strlen($message) > self::MESSAGE_LENGTH ? mb_substr($message, 0, self::MESSAGE_LENGTH - 1) . '…' : $message;
    }

    /**
     * The tracker's raw events waiting for the merge job, the oldest of them, and the events received in the last
     * 24 hours
     * @return array{unprocessed: int, oldest_unprocessed_at: string|null, received_24h: int}
     */
    private function tracker(): array
    {
        $oldest = $this->db->table('tracker_event_raw')->selectMin('received_at', 'oldest')
            ->where('processed_at', null)->get()->getRowArray();

        return [
            'unprocessed'           => $this->db->table('tracker_event_raw')->where('processed_at', null)->countAllResults(),
            'oldest_unprocessed_at' => $oldest['oldest'] ?? null,
            'received_24h'          => $this->db->table('tracker_event_raw')
                ->where('received_at >=', $this->now->subHours(24)->format(self::DATETIME))->countAllResults(),
        ];
    }

    /**
     * Orders of the last 24 hours and 7 days, the 7 days' by source, business websites and the inbox's new messages
     * @return array{orders_24h: int, orders_7d: int, orders_7d_by_source: array<string, int>, websites: int, businesses: int, inbox_new: int}
     */
    private function usage(): array
    {
        $weekAgo = $this->now->subDays(7)->format(self::DATETIME);
        $sources = ['mart' => 0, 'counter' => 0];
        $rows    = $this->db->table('order_master')->select('order_source, COUNT(*) AS orders')
            ->where('created_at >=', $weekAgo)->groupBy('order_source')->get()->getResultArray();
        foreach ($rows as $row) {
            $sources[$row['order_source'] ?? 'mart'] = ($sources[$row['order_source'] ?? 'mart'] ?? 0) + (int) $row['orders'];
        }

        return [
            'orders_24h'          => $this->db->table('order_master')
                ->where('created_at >=', $this->now->subHours(24)->format(self::DATETIME))->countAllResults(),
            'orders_7d'           => array_sum($sources),
            'orders_7d_by_source' => $sources,
            'websites'            => $this->db->table('business_website')->countAllResults(),
            'businesses'          => $this->db->table('business_master')->countAllResults(),
            'inbox_new'           => $this->db->table('contact_message')->where('status', 'N')->countAllResults(),
        ];
    }
}
