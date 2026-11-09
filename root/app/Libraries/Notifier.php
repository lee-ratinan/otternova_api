<?php

namespace App\Libraries;

use App\Models\BusinessClientLinkModel;
use App\Models\NotificationMasterModel;
use App\Models\StaffMasterModel;
use InvalidArgumentException;

/**
 * Sends in-app notifications: one notification_master row for each recipient. Called inside the caller's
 * transaction, so a change that is rolled back leaves no notification behind. Nothing shown is stored: the title
 * and message are the event's lines of the Notification language file, rendered in the reader's language when read
 * (see present()), and its icon and tone come from EVENTS.
 *
 * Adding an event: a line in EVENTS, its title and message in Language/{en-US,th}/Notification.php, and a call here.
 */
class Notifier
{
    public const PAYMENT_SLIP_SUBMITTED = 'payment.slip_submitted';
    public const PAYMENT_APPROVED       = 'payment.approved';
    public const PAYMENT_REJECTED       = 'payment.rejected';

    /**
     * Every event type, with its Bootstrap icon and tone. The tone is info, success, warning or danger, the
     * notification-icon classes of the apps' templates.
     */
    public const EVENTS = [
        self::PAYMENT_SLIP_SUBMITTED => ['icon' => 'bi-cash-coin', 'tone' => 'warning'],
        self::PAYMENT_APPROVED       => ['icon' => 'bi-check-circle', 'tone' => 'success'],
        self::PAYMENT_REJECTED       => ['icon' => 'bi-x-circle', 'tone' => 'danger'],
    ];

    /** Shown for an event_type no longer in EVENTS */
    private const UNKNOWN = ['icon' => 'bi-bell', 'tone' => 'info'];

    private NotificationMasterModel $notifications;

    public function __construct(?NotificationMasterModel $notifications = null)
    {
        $this->notifications = $notifications ?? model(NotificationMasterModel::class);
    }

    /**
     * Notify the staff of the departments who are still working (active or on probation, with an active account)
     * @param list<string>     $departments otternova_department.department_code values, such as ['EXE', 'FIN']
     * @param string           $eventType   One of EVENTS
     * @param array            $params      The placeholders of its message
     * @param string|null      $targetUrl   A path in the Admin app, such as /admin/clients/invoices/view/{public_id}
     * @param array{string, int}|null $subject [table, id] the notification is about
     * @param int|null         $exceptId    auth_master.id left out: the one who did it
     * @return int How many were notified
     */
    public function toDepartments(array $departments, string $eventType, array $params, ?string $targetUrl, ?array $subject = null, ?int $exceptId = null): int
    {
        $db = db_connect();
        $ids = $db->table('staff_master s')
            ->select('s.auth_id')
            ->join('otternova_department d', 'd.id = s.department_id', 'inner')
            ->join('auth_master a', 'a.id = s.auth_id', 'inner')
            ->whereIn('d.department_code', $departments === [] ? [''] : $departments)
            ->whereIn('s.employment_status', [StaffMasterModel::STATUS_ACTIVE, StaffMasterModel::STATUS_PROBATION])
            ->where('a.account_status', 'A')
            ->get()->getResultArray();

        return $this->send(array_column($ids, 'auth_id'), $eventType, $params, $targetUrl, $subject, null, $exceptId);
    }

    /**
     * Notify the business's active clients of these roles
     * @param int          $businessId
     * @param list<string> $roles       BusinessClientLinkModel::ROLE_ constants
     * @param string       $eventType   One of EVENTS
     * @param array        $params
     * @param string|null  $targetUrl   A path in the Client app, such as /admin/plans
     * @param array{string, int}|null $subject
     * @param int|null     $exceptId
     * @return int How many were notified
     */
    public function toBusiness(int $businessId, array $roles, string $eventType, array $params, ?string $targetUrl, ?array $subject = null, ?int $exceptId = null): int
    {
        $ids = db_connect()->table('business_client_link l')
            ->select('c.auth_id')
            ->join('client_master c', 'c.id = l.client_id', 'inner')
            ->join('auth_master a', 'a.id = c.auth_id', 'inner')
            ->where('l.business_id', $businessId)
            ->where('l.client_status', BusinessClientLinkModel::STATUS_ACTIVE)
            ->whereIn('l.client_role', $roles === [] ? [''] : $roles)
            ->where('a.account_status', 'A')
            ->get()->getResultArray();

        return $this->send(array_column($ids, 'auth_id'), $eventType, $params, $targetUrl, $subject, $businessId, $exceptId);
    }

    /**
     * Notify one account
     * @return int 1, or 0 when it is $exceptId
     */
    public function toAccount(int $authId, string $eventType, array $params, ?string $targetUrl, ?array $subject = null, ?int $businessId = null, ?int $exceptId = null): int
    {
        return $this->send([$authId], $eventType, $params, $targetUrl, $subject, $businessId, $exceptId);
    }

    /**
     * Mark every unread notification of the subject read, for everyone: someone has handled it (another FIN staff
     * member approved the payment)
     * @param string      $table
     * @param int         $id
     * @param string|null $eventType Only these; every event of the subject when null
     * @return int How many were marked
     */
    public function markSubjectRead(string $table, int $id, ?string $eventType = null): int
    {
        return $this->notifications->markSubjectRead($table, $id, $eventType);
    }

    /**
     * A row as the apps show it, its title and message in the request's language (Accept-Language), else the
     * default locale's
     * @param array $row notification_master row, with business_slug and business_name when it has a business
     * @return array
     */
    public static function present(array $row): array
    {
        $event  = self::EVENTS[$row['event_type']] ?? self::UNKNOWN;
        $params = json_decode((string) ($row['message_params'] ?? ''), true);
        $params = is_array($params) ? $params : [];

        return [
            'public_id'  => $row['public_id'],
            'event_type' => $row['event_type'],
            'title'      => self::line($row['event_type'] . '.title', $params),
            'message'    => self::line($row['event_type'] . '.message', $params),
            'icon'       => $event['icon'],
            'tone'       => $event['tone'],
            'target_url' => $row['target_url'],
            'business'   => empty($row['business_id']) ? null : [
                'business_slug' => $row['business_slug'] ?? null,
                'business_name' => $row['business_name'] ?? null,
            ],
            'is_read'    => $row['read_at'] !== null,
            'read_at'    => $row['read_at'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * @param list<int|string> $authIds
     * @return int
     */
    private function send(array $authIds, string $eventType, array $params, ?string $targetUrl, ?array $subject, ?int $businessId, ?int $exceptId): int
    {
        if (! isset(self::EVENTS[$eventType])) {
            throw new InvalidArgumentException('Unknown notification event: ' . $eventType);
        }
        if ($targetUrl !== null && ! str_starts_with($targetUrl, '/')) {
            throw new InvalidArgumentException('target_url must be a path in the app: ' . $targetUrl);
        }
        $authIds = array_values(array_unique(array_map('intval', $authIds)));
        $authIds = array_values(array_filter($authIds, static fn (int $id): bool => $id !== $exceptId));
        if ($authIds === []) {
            return 0;
        }

        $rows = [];
        foreach ($authIds as $authId) {
            $rows[] = [
                'public_id'      => $this->notifications->newPublicId(),
                'auth_id'        => $authId,
                'business_id'    => $businessId,
                'event_type'     => $eventType,
                'message_params' => $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'target_url'     => $targetUrl,
                'subject_table'  => $subject[0] ?? null,
                'subject_id'     => isset($subject[1]) ? (int) $subject[1] : null,
            ];
        }
        $this->notifications->insertRows($rows);

        return count($rows);
    }

    /**
     * Notification.<key> in the request's locale, or in the default one while it has no translation there yet
     */
    private static function line(string $key, array $params): string
    {
        $line = lang('Notification.' . $key, $params, service('request')->getLocale());
        if ($line === 'Notification.' . $key) {
            $line = lang('Notification.' . $key, $params, config('App')->defaultLocale);
        }

        return $line;
    }
}
