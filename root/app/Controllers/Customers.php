<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\BusinessClientLinkModel;
use App\Models\BusinessCustomerModel;
use App\Models\BusinessCustomerNoteModel;
use App\Models\ClientMasterModel;
use App\Models\CustomerMasterModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The customers of a business (BusinessCustomerModel: the customers its orders point to) and the notes its people write
 * about them (BusinessCustomerNoteModel): the Client app's, behind the jwt:C filter, for any active client of the
 * business, its employees included (ManagedBusinessTrait::ALL_ROLES). The customers are read only. Anyone may add a
 * note; a note is deleted by whoever wrote it, or by an owner or manager. A customer who never ordered from the
 * business is as unknown to it as one that is not there.
 */
class Customers extends BaseController
{
    use ManagedBusinessTrait;

    /**
     * POST /client/customers { business_slug, start, length, search }
     * One page of the business's customers, the one who ordered last first: { records_total, records_filtered, rows },
     * rows as BusinessCustomerModel::summaries() gives them. start is 0 and length 25 when left out (at most 500);
     * search is optional.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $endpoint = 'v1/client/customers';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Customer.invalidRequest'), 422);
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Customer', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $search = is_string($input['search'] ?? null) ? trim($input['search']) : '';
        try {
            $data = model(BusinessCustomerModel::class)->listOfBusiness(
                (int) $business['id'],
                $start === false ? 0 : max(0, $start),
                $length === false ? 25 : min(500, max(1, $length)),
                $search
            );
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.loadFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /client/customer { business_slug, public_id }
     * One customer of the business: { customer (as summaries() gives it), orders (their latest, newest first),
     * notes: [{ public_id, note_text, created_by_name, created_at, can_delete }] (newest first) }
     * @return ResponseInterface
     */
    public function show(): ResponseInterface
    {
        $endpoint = 'v1/client/customer';
        [$business, $customer, $error] = $this->customerOfBusiness($endpoint, 'public_id');
        if ($error !== null) {
            return $error;
        }
        $businessId = (int) $business['id'];
        $customerId = (int) $customer['id'];
        try {
            $customers = model(BusinessCustomerModel::class);
            $summary   = $customers->summaries($businessId, [$customerId])[0];
            $orders    = $customers->recentOrders($businessId, $customerId);
            $notes     = model(BusinessCustomerNoteModel::class)->notesOf($businessId, $customerId);
            $manager   = in_array($this->role($businessId), self::MANAGING_ROLES, true);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.loadFailed');
        }
        $actorId = $this->actorId();

        return ApiResponse::success([
            'customer' => $summary,
            'orders'   => $orders,
            'notes'    => array_map(static fn (array $note): array => [
                'public_id'       => $note['public_id'],
                'note_text'       => $note['note_text'],
                'created_by_name' => $note['created_by_name'],
                'created_at'      => $note['created_at'],
                'can_delete'      => $manager || $note['created_by'] === $actorId,
            ], $notes),
        ]);
    }

    /**
     * POST /client/customer-note/create { business_slug, customer_public_id, note_text }
     * note_text: 1 to BusinessCustomerNoteModel::MAX_LENGTH characters, trimmed
     * @return ResponseInterface { public_id }
     */
    public function createNote(): ResponseInterface
    {
        $endpoint = 'v1/client/customer-note/create';
        [$business, $customer, $error] = $this->customerOfBusiness($endpoint, 'customer_public_id');
        if ($error !== null) {
            return $error;
        }
        $input = (array) $this->jsonInput();
        $text  = is_string($input['note_text'] ?? null) ? trim($input['note_text']) : '';
        if ($text === '') {
            return ApiResponse::error(lang('Customer.noteRequired'), 400, ['note_text' => lang('Customer.noteRequired')]);
        }
        if (mb_strlen($text) > BusinessCustomerNoteModel::MAX_LENGTH) {
            $message = lang('Customer.noteTooLong', [BusinessCustomerNoteModel::MAX_LENGTH]);

            return ApiResponse::error($message, 400, ['note_text' => $message]);
        }
        try {
            $publicId = model(BusinessCustomerNoteModel::class)->add((int) $business['id'], (int) $customer['id'], $text, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.saveFailed');
        }

        return ApiResponse::success(['public_id' => $publicId], 200, lang('Customer.noteAdded'));
    }

    /**
     * POST /client/customer-note/delete { public_id }
     * By whoever wrote the note, or an owner or manager of its business
     * @return ResponseInterface
     */
    public function deleteNote(): ResponseInterface
    {
        $endpoint = 'v1/client/customer-note/delete';
        $input    = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Customer.invalidRequest'), 422);
        }
        $notes = model(BusinessCustomerNoteModel::class);
        try {
            $note = $notes->findByPublicId($input['public_id'] ?? null);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.loadFailed');
        }
        if ($note === null) {
            return ApiResponse::error(lang('Customer.noteNotFound'), 404);
        }
        $businessId = (int) $note['business_id'];
        $error      = $this->roleError($businessId, $endpoint, lang('Customer.noteNotFound'), 'Customer', self::ALL_ROLES);
        if ($error !== null) {
            return $error;
        }
        try {
            $manager = in_array($this->role($businessId), self::MANAGING_ROLES, true);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.loadFailed');
        }
        if (! $manager && (int) ($note['created_by'] ?? 0) !== $this->actorId()) {
            return ApiResponse::error(lang('Customer.notYourNote'), 403);
        }
        try {
            $notes->remove($note, $this->actorId());
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Customer.saveFailed');
        }

        return ApiResponse::success(null, 200, lang('Customer.noteDeleted'));
    }

    /**
     * The business of the request's business_slug (the client active in it) and its customer of the public_id in $key
     * @param string $endpoint
     * @param string $key
     * @return array{0: array|null, 1: array|null, 2: ResponseInterface|null} [business, customer, null] or [null, null, error]
     */
    private function customerOfBusiness(string $endpoint, string $key): array
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return [null, null, ApiResponse::error(lang('Customer.invalidRequest'), 422)];
        }
        [$business, $error] = $this->managedBusinessBySlug($input, $endpoint, 'Customer', self::ALL_ROLES);
        if ($error !== null) {
            return [null, null, $error];
        }
        if (! is_string($input[$key] ?? null) || $input[$key] === '') {
            return [null, null, ApiResponse::error(lang('Customer.customerIdRequired'), 400)];
        }
        try {
            $customer = model(CustomerMasterModel::class)->findByPublicId($input[$key]);
            $known    = $customer !== null && model(BusinessCustomerModel::class)->isCustomerOf((int) $business['id'], (int) $customer['id']);
        } catch (Throwable $e) {
            return [null, null, $this->failed($endpoint, $e, 'Customer.loadFailed')];
        }

        return $known ? [$business, $customer, null] : [null, null, ApiResponse::error(lang('Customer.notFound'), 404)];
    }

    /**
     * The signed-in client's client_role in the business; null when not active in it
     * @param int $businessId
     * @return string|null
     */
    private function role(int $businessId): ?string
    {
        $client = model(ClientMasterModel::class)->findByAuthId($this->actorId());

        return $client === null ? null : model(BusinessClientLinkModel::class)->activeRole($businessId, (int) $client['id']);
    }

    /**
     * auth_master.id of the signed-in account
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The JSON body as an array: an empty body counts as {}, and anything that is not a JSON object is null
     * @return array|null
     */
    private function jsonInput(): ?array
    {
        if (trim((string) $this->request->getBody()) === '') {
            return [];
        }

        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return null;
        }

        return is_array($input) && ($input === [] || ! array_is_list($input)) ? $input : null;
    }

    /**
     * @param string    $endpoint For the error log
     * @param Throwable $e
     * @param string    $message  Language key shown to the user
     * @return ResponseInterface
     */
    private function failed(string $endpoint, Throwable $e, string $message): ResponseInterface
    {
        log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

        return ApiResponse::error(lang($message), 500);
    }
}
