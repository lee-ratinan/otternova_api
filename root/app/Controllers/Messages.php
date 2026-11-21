<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessMessageModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The messages customers send a business through its contact form on otternova_mart (business_message, see
 * MartMessages), for the Client app's Messages page and the badge on its header: behind the jwt:C filter, for any of
 * the business's active people (owners, managers and employees alike), the business coming in by its business_slug.
 * Messages and comments are by their public_id; every time is UTC.
 *
 * Unread is shared by the business: the first of its people to open a message marks it read for everyone. Opening
 * does not resolve it: Resolved is clicked, and who clicked it is kept. The business answers the customer off the
 * platform; replying from the platform is a paid feature to come, reserved as POST /client/message/reply.
 */
class Messages extends BaseController
{
    use BillingRequestTrait;
    use ManagedBusinessTrait;

    private const LANG = 'Messages';

    /**
     * POST /client/messages { business_slug, start, length, search, filters: {status} }
     * One page of the messages of the business, newest first, each with a short excerpt instead of its text. status
     * is N (unread), R (read), D (resolved) or S (spam); every message but spam when left out. search matches the
     * sender's name, email address and telephone number, and the text.
     * { records_total, records_filtered, rows: [{public_id, sender_name, email_address, phone_number, excerpt, locale,
     *   message_status, spam_reason, first_read_at, first_read_by_name, resolved_at, resolved_by_name,
     *   response_seconds, created_at}], unread_count }
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/messages';
        [$business, $input, $error] = $this->business($endpoint);
        if ($error !== null) {
            return $error;
        }
        [$start, $length] = $this->page($input);
        $filters          = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $status           = strtoupper($this->text($filters, 'status'));
        if ($status !== '' && ! isset(BusinessMessageModel::STATUS_NAMES[$status])) {
            return ApiResponse::error(lang('Messages.invalidStatus'), 422, ['status' => lang('Messages.invalidStatus')]);
        }
        $messages = model(BusinessMessageModel::class);
        try {
            $data = $messages->listForBusiness((int) $business['id'], $start, $length, $this->text($input, 'search'), $status === '' ? null : $status);
            $data['unread_count'] = $messages->unreadCount((int) $business['id']);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Messages.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/messages/unread-count { business_slug }
     * { unread_count }: the messages nobody at the business has opened yet, spam left out. The badge shows it, as 9+
     * above 9.
     * @return ResponseInterface
     */
    public function unreadCount(): ResponseInterface
    {
        $endpoint = 'v1/client/messages/unread-count';
        [$business, , $error] = $this->business($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            return ApiResponse::success(['unread_count' => model(BusinessMessageModel::class)->unreadCount((int) $business['id'])]);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Messages.loadFailed');
        }
    }

    /**
     * POST /client/message { business_slug, public_id }
     * One message with its text and timeline; an unread one becomes read, for everyone at the business, by whoever
     * opens it.
     * { message: {public_id, sender_name, email_address, phone_number, message_text, locale, message_status,
     *   spam_reason, first_read_at, first_read_by_name, resolved_at, resolved_by_name, response_seconds, created_at},
     *   timeline: [{public_id, event_type (C comment, S status), event_text, from_status, to_status, channel,
     *   delivery_status, author_name, is_own (a comment of whoever asks: they may delete it), created_at}] (oldest
     *   first), can_reply }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        return $this->withMessage('v1/client/message', 'Messages.loadFailed', function (array $message): ResponseInterface {
            model(BusinessMessageModel::class)->open($message, $this->actorId());

            return ApiResponse::success($this->detail($message));
        });
    }

    /**
     * POST /client/message/status { business_slug, public_id, status }
     * Mark a message N (unread again), R (read: reopened), D (resolved, by whoever asks) or S (spam). The answer is
     * show()'s.
     * @return ResponseInterface
     */
    public function setStatus(): ResponseInterface
    {
        return $this->withMessage('v1/client/message/status', 'Messages.saveFailed', function (array $message, array $input): ResponseInterface {
            $status = strtoupper($this->text($input, 'status'));
            if (! isset(BusinessMessageModel::STATUS_NAMES[$status])) {
                return ApiResponse::error(lang('Messages.invalidStatus'), 422, ['status' => lang('Messages.invalidStatus')]);
            }
            model(BusinessMessageModel::class)->setStatus($message, $status, $this->actorId());

            return ApiResponse::success($this->detail($message), 200, lang('Messages.statusChanged'));
        });
    }

    /**
     * POST /client/message/comment { business_slug, public_id, text }
     * Add a comment to a message, for the business's people only: the customer never sees it. 201, the answer show()'s.
     * @return ResponseInterface
     */
    public function addComment(): ResponseInterface
    {
        return $this->withMessage('v1/client/message/comment', 'Messages.saveFailed', function (array $message, array $input): ResponseInterface {
            $text = is_string($input['text'] ?? null) && mb_check_encoding($input['text'], 'UTF-8')
                ? trim(str_replace(["\r\n", "\r"], "\n", $input['text'])) : '';
            if ($text === '') {
                return ApiResponse::error(lang('Messages.commentRequired'), 422, ['text' => lang('Messages.commentRequired')]);
            }
            if (mb_strlen($text, 'UTF-8') > BusinessMessageModel::COMMENT_MAX_LENGTH) {
                $error = lang('Messages.commentTooLong', [BusinessMessageModel::COMMENT_MAX_LENGTH]);

                return ApiResponse::error($error, 422, ['text' => $error]);
            }
            model(BusinessMessageModel::class)->addComment($message, $text, $this->actorId());

            return ApiResponse::success($this->detail($message), 201, lang('Messages.commentAdded'));
        });
    }

    /**
     * POST /client/message/comment/delete { business_slug, public_id, comment_public_id }
     * Delete a comment, by its author only: 403 for someone else's, 404 when the message has no such comment. The
     * answer is show()'s.
     * @return ResponseInterface
     */
    public function deleteComment(): ResponseInterface
    {
        return $this->withMessage('v1/client/message/comment/delete', 'Messages.saveFailed', function (array $message, array $input): ResponseInterface {
            $refused = model(BusinessMessageModel::class)->deleteComment($message, $input['comment_public_id'] ?? null, $this->actorId());
            if ($refused === 'not_author') {
                return ApiResponse::error(lang('Messages.commentNotYours'), 403);
            }
            if ($refused !== null) {
                return ApiResponse::error(lang('Messages.commentNotFound'), 404);
            }

            return ApiResponse::success($this->detail($message), 200, lang('Messages.commentDeleted'));
        });
    }

    /**
     * POST /client/message/reply { business_slug, public_id, text, channel }
     * Reserved for replying to the customer from the platform, a paid feature to come: refused with 403 for now. When
     * it comes, a reply is a business_message_event of type O, sent by email or SMS (channel E or S), and canReply()
     * says which businesses' plans include it.
     * @return ResponseInterface
     */
    public function reply(): ResponseInterface
    {
        return $this->withMessage('v1/client/message/reply', 'Messages.saveFailed', function (array $message): ResponseInterface {
            return ApiResponse::error(lang('Messages.replyNotIncluded'), 403);
        });
    }

    /**
     * What show() and the changes send back, read again after the change
     * @param array $message From BusinessMessageModel::findForBusiness()
     * @return array{message: array, timeline: list<array>, can_reply: bool}
     */
    private function detail(array $message): array
    {
        $messages = model(BusinessMessageModel::class);
        $fresh    = $messages->findForBusiness((int) $message['business_id'], $message['public_id']) ?? $message;
        $timeline = $messages->timeline($fresh, $this->actorId());
        unset($fresh['id'], $fresh['business_id']);

        return ['message' => $fresh, 'timeline' => $timeline, 'can_reply' => $this->canReply((int) $message['business_id'])];
    }

    /**
     * Whether the business may reply to its customers from the platform: no plan includes it yet. The Client app
     * shows the reply box only when this is true.
     * @param int $businessId
     * @return bool
     */
    private function canReply(int $businessId): bool
    {
        return false;
    }

    /**
     * Finds the message of the request, within its business, then answers with $answer; a failure is logged and
     * answered 500 with $failMessage
     * @param string                                    $endpoint    For the error log
     * @param string                                    $failMessage Language key
     * @param callable(array, array): ResponseInterface $answer      Given the message and the body
     * @return ResponseInterface
     */
    private function withMessage(string $endpoint, string $failMessage, callable $answer): ResponseInterface
    {
        [$business, $input, $error] = $this->business($endpoint);
        if ($error !== null) {
            return $error;
        }
        try {
            $message = model(BusinessMessageModel::class)->findForBusiness((int) $business['id'], $input['public_id'] ?? null);
            if ($message === null) {
                return ApiResponse::error(lang('Messages.notFound'), 404);
            }

            return $answer($message, $input);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, $failMessage);
        }
    }

    /**
     * The business of the request, when the signed-in client is one of its active people
     * @param string $endpoint For the error log
     * @return array{0: array|null, 1: array, 2: ResponseInterface|null} [business, input, null] or [null, [], error]
     */
    private function business(string $endpoint): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, [], ApiResponse::error(lang('Messages.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, self::LANG, self::ALL_ROLES);

        return $error === null ? [$business, $input, null] : [null, [], $error];
    }
}
