<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\ContactMessageModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The messages of the public contact form (contact_message, sent through POST /v1/contact) for the Admin app's
 * inbox, behind the jwt:S filter: any staff member reads and handles them. Messages are by their id; every time is
 * UTC.
 */
class Inbox extends BaseController
{
    use BillingRequestTrait;

    /**
     * POST /staff/inbox/messages { start, length, search, filters: {status, category} }
     * One page of the messages, newest first, each with a short excerpt instead of its text. status is N (new),
     * R (read), D (done) or S (spam); category is I (issue), C (complaint), U (user problem), M (misunderstanding),
     * X (irrelevant) or NONE (not categorised yet); every message when left out. search matches the sender's name and
     * email address and the subject.
     * @return ResponseInterface
     */
    public function messages(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Inbox.invalidRequest'), 422);
        }
        [$start, $length] = $this->page($input);
        $filters          = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $status           = strtoupper($this->text($filters, 'status'));
        if ($status !== '' && ! isset(ContactMessageModel::STATUS_NAMES[$status])) {
            return ApiResponse::error(lang('Inbox.invalidStatus'), 422, ['status' => lang('Inbox.invalidStatus')]);
        }
        $category = strtoupper($this->text($filters, 'category'));
        if ($category !== '' && $category !== 'NONE' && ! isset(ContactMessageModel::CATEGORY_NAMES[$category])) {
            return ApiResponse::error(lang('Inbox.invalidCategory'), 422, ['category' => lang('Inbox.invalidCategory')]);
        }

        try {
            $data = model(ContactMessageModel::class)->listForStaff($start, $length, $this->text($input, 'search'), $status === '' ? null : $status,
                $category === '' ? null : ($category === 'NONE' ? '' : $category));
        } catch (Throwable $e) {
            return $this->failed('v1/staff/inbox/messages', $e, 'Inbox.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/inbox/message { id }
     * One message with its text; a new one is marked read by the staff member opening it
     * @return ResponseInterface
     */
    public function message(): ResponseInterface
    {
        $id = $this->messageId();
        if ($id === null) {
            return ApiResponse::error(lang('Inbox.notFound'), 404);
        }
        $messages = model(ContactMessageModel::class);
        try {
            $message = $messages->findForStaff($id);
            if ($message === null) {
                return ApiResponse::error(lang('Inbox.notFound'), 404);
            }
            if ($message['status'] === ContactMessageModel::STATUS_NEW) {
                $messages->setStatus($id, ContactMessageModel::STATUS_READ, $this->actorId());
                $message = $messages->findForStaff($id);
            }
        } catch (Throwable $e) {
            return $this->failed('v1/staff/inbox/message', $e, 'Inbox.loadFailed');
        }

        return ApiResponse::success(['message' => $message]);
    }

    /**
     * POST /staff/inbox/message/status { id, status }
     * Mark a message N (new again), R (read), D (done) or S (spam)
     * @return ResponseInterface
     */
    public function setStatus(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Inbox.invalidRequest'), 422);
        }
        $id = $this->messageId($input);
        if ($id === null) {
            return ApiResponse::error(lang('Inbox.notFound'), 404);
        }
        $status = strtoupper($this->text($input, 'status'));
        if (! isset(ContactMessageModel::STATUS_NAMES[$status])) {
            return ApiResponse::error(lang('Inbox.invalidStatus'), 422, ['status' => lang('Inbox.invalidStatus')]);
        }
        $messages = model(ContactMessageModel::class);
        try {
            if ($messages->findForStaff($id) === null) {
                return ApiResponse::error(lang('Inbox.notFound'), 404);
            }
            $messages->setStatus($id, $status, $this->actorId());
            $message = $messages->findForStaff($id);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/inbox/message/status', $e, 'Inbox.saveFailed');
        }

        return ApiResponse::success(['message' => $message], 200, lang('Inbox.statusChanged'));
    }

    /**
     * POST /staff/inbox/message/category { id, category }
     * Set what a message turned out to be: I (issue), C (complaint), U (user problem), M (misunderstanding) or
     * X (irrelevant); empty clears it
     * @return ResponseInterface
     */
    public function setCategory(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Inbox.invalidRequest'), 422);
        }
        $id = $this->messageId($input);
        if ($id === null) {
            return ApiResponse::error(lang('Inbox.notFound'), 404);
        }
        $category = strtoupper($this->text($input, 'category'));
        if ($category !== '' && ! isset(ContactMessageModel::CATEGORY_NAMES[$category])) {
            return ApiResponse::error(lang('Inbox.invalidCategory'), 422, ['category' => lang('Inbox.invalidCategory')]);
        }
        $messages = model(ContactMessageModel::class);
        try {
            if ($messages->findForStaff($id) === null) {
                return ApiResponse::error(lang('Inbox.notFound'), 404);
            }
            $messages->setCategory($id, $category === '' ? null : $category, $this->actorId());
            $message = $messages->findForStaff($id);
        } catch (Throwable $e) {
            return $this->failed('v1/staff/inbox/message/category', $e, 'Inbox.saveFailed');
        }

        return ApiResponse::success(['message' => $message], 200, lang('Inbox.categoryChanged'));
    }

    /**
     * The id of the body, a whole number from 1; null when there is none
     * @param array|null $input The body, read when left out
     * @return int|null
     */
    private function messageId(?array $input = null): ?int
    {
        $input ??= $this->jsonInput() ?? [];
        $id      = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);

        return $id === false ? null : $id;
    }
}
