<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\Notifier;
use App\Models\NotificationMasterModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Notifications as NotificationsConfig;
use Throwable;

/**
 * The signed-in account's own notifications (notification_master), for both apps: under /staff/notifications behind
 * jwt:S and /client/notifications behind jwt:C. Notifications are by their public_id; title and message come in the
 * request's language (Accept-Language); every time is UTC. target_url is a path in the app the account signs in to.
 */
class Notifications extends BaseController
{
    use BillingRequestTrait;

    /**
     * POST .../notifications/latest
     * The newest few (Config\Notifications::$latestLimit), read or not, and the unread count: the header's dropdown
     * @return ResponseInterface
     */
    public function latest(): ResponseInterface
    {
        $authId = $this->actorId();
        $model  = model(NotificationMasterModel::class);
        try {
            $page   = $model->listFor($authId, 0, config(NotificationsConfig::class)->latestLimit);
            $unread = $model->unreadCount($authId);
        } catch (Throwable $e) {
            return $this->failed('v1/notifications/latest', $e, 'Notification.loadFailed');
        }

        return ApiResponse::success([
            'unread_count' => $unread,
            'rows'         => array_map(Notifier::present(...), $page['rows']),
        ]);
    }

    /**
     * POST .../notifications/list { start, length, filters: {status} }
     * One page, newest first; status is unread or read, both when left out. length is at most
     * Config\Notifications::$maxPageLength.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Notification.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $length           = min($length, config(NotificationsConfig::class)->maxPageLength);
        $status           = strtolower($this->text(is_array($input['filters'] ?? null) ? $input['filters'] : [], 'status'));
        if (! in_array($status, ['', 'unread', 'read'], true)) {
            return ApiResponse::error(lang('Notification.invalidStatus'), 422, ['status' => lang('Notification.invalidStatus')]);
        }

        $authId = $this->actorId();
        $model  = model(NotificationMasterModel::class);
        try {
            $page   = $model->listFor($authId, $start, $length, $status === '' ? null : $status === 'read');
            $unread = $model->unreadCount($authId);
        } catch (Throwable $e) {
            return $this->failed('v1/notifications/list', $e, 'Notification.loadFailed');
        }

        return ApiResponse::success([
            'records_total'    => $page['records_total'],
            'records_filtered' => $page['records_filtered'],
            'unread_count'     => $unread,
            'rows'             => array_map(Notifier::present(...), $page['rows']),
        ]);
    }

    /**
     * POST .../notifications/read { public_id }
     * Mark one read, as when it is opened; gives it back, with its target_url to go to
     * @return ResponseInterface
     */
    public function read(): ResponseInterface
    {
        return $this->onOwn('v1/notifications/read', static function (NotificationMasterModel $model, int $authId, array $row): ResponseInterface {
            $model->markRead($authId, (int) $row['id']);

            return ApiResponse::success([
                'notification' => Notifier::present($model->findOwn($authId, $row['public_id'])),
                'unread_count' => $model->unreadCount($authId),
            ]);
        });
    }

    /**
     * POST .../notifications/read-all
     * @return ResponseInterface
     */
    public function readAll(): ResponseInterface
    {
        $authId = $this->actorId();
        try {
            $count = model(NotificationMasterModel::class)->markAllRead($authId);
        } catch (Throwable $e) {
            return $this->failed('v1/notifications/read-all', $e, 'Notification.saveFailed');
        }

        return ApiResponse::success(['marked' => $count, 'unread_count' => 0], 200, lang('Notification.allRead'));
    }

    /**
     * POST .../notifications/delete { public_id }
     * @return ResponseInterface
     */
    public function delete(): ResponseInterface
    {
        return $this->onOwn('v1/notifications/delete', static function (NotificationMasterModel $model, int $authId, array $row): ResponseInterface {
            $model->deleteOwn($authId, (int) $row['id']);

            return ApiResponse::success(['unread_count' => $model->unreadCount($authId)], 200, lang('Notification.deleted'));
        });
    }

    /**
     * POST .../notifications/delete-read
     * Delete every read one
     * @return ResponseInterface
     */
    public function deleteRead(): ResponseInterface
    {
        $authId = $this->actorId();
        $model  = model(NotificationMasterModel::class);
        try {
            $count  = $model->deleteRead($authId);
            $unread = $model->unreadCount($authId);
        } catch (Throwable $e) {
            return $this->failed('v1/notifications/delete-read', $e, 'Notification.saveFailed');
        }

        return ApiResponse::success(['deleted' => $count, 'unread_count' => $unread], 200, lang('Notification.readDeleted', [$count]));
    }

    /**
     * Run $work on the account's own notification of the body's public_id; 404 when it has none such
     * @param string   $endpoint For the error log
     * @param callable $work     fn (NotificationMasterModel, int $authId, array $row): ResponseInterface
     * @return ResponseInterface
     */
    private function onOwn(string $endpoint, callable $work): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Notification.invalidRequest'), 422);
        }
        $authId = $this->actorId();
        $model  = model(NotificationMasterModel::class);
        try {
            $row = $model->findOwn($authId, $input['public_id'] ?? null);
            if ($row === null) {
                return ApiResponse::error(lang('Notification.notFound'), 404);
            }

            return $work($model, $authId, $row);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Notification.saveFailed');
        }
    }
}
