<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\OtternovaLedgerAccountModel;
use App\Models\OtternovaLedgerCategoryModel;
use App\Models\OtternovaLedgerCounterpartyModel;
use App\Models\OtternovaLedgerMasterModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use Config\LedgerReceipt as LedgerReceiptConfig;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * The internal ledger of the Admin app, behind the jwt:S and department:EXE,FIN filters: the entries
 * (otternova_ledger_master) with their PDF receipts, and the accounts, categories and counterparties they point at.
 * Each .../update endpoint creates the record when the body has no id and updates the record with that id otherwise.
 */
class Ledger extends BaseController
{
    private const DEFAULT_LENGTH = 25;
    private const MAX_LENGTH     = 500;

    /** Fields saved in capitals, so "thb" is accepted as THB */
    private const UPPERCASE_FIELDS = ['currency_code', 'transaction_currency_code', 'billed_currency_code', 'category_code'];

    /** Amounts saved with all 4 decimal places, as DECIMAL(19,4) gives them back, so an unchanged one is not logged as a change */
    private const AMOUNT_FIELDS = ['transaction_amount', 'billed_amount'];

    /** A receipt_file_path as uploadReceipt() names it; anything else is never served or deleted */
    private const RECEIPT_PATTERN = '/^\d+-[0-9a-f]{16}\.pdf$/';

    /**
     * POST /staff/ledger/entries { start, length, search, filters: {date_from, date_to, account_id, category_id, counterparty_id} }
     * One page of entries, newest transaction first. Every part of the body is optional, and a filter with a value
     * that is not a date (Y-m-d) or an ID is ignored rather than rejected. account_id matches either side.
     * @return ResponseInterface
     */
    public function entries(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }

        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $start  = $start === false ? 0 : max(0, $start);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $length = $length === false ? self::DEFAULT_LENGTH : min(self::MAX_LENGTH, max(1, $length));
        $search = $input['search'] ?? null;
        $search = is_string($search) || is_int($search) ? trim((string) $search) : '';

        try {
            $data = model(OtternovaLedgerMasterModel::class)->listEntries($start, $length, $search, $this->listFilters($input['filters'] ?? null));
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/entries', $e, 'Ledger.listFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/ledger/entry { id }
     * One entry with the names of its category, accounts, counterparty and creator. has_receipt says whether
     * GET /staff/ledger/receipt/{id} has a PDF.
     * @return ResponseInterface
     */
    public function entry(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $id = $this->validId($input['id'] ?? null);
        if ($id === null) {
            return ApiResponse::error(lang('Ledger.idRequired'), 400);
        }

        try {
            $entry = model(OtternovaLedgerMasterModel::class)->findEntry($id);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/entry', $e, 'Ledger.loadFailed');
        }
        if ($entry === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }

        return ApiResponse::success(['entry' => $entry]);
    }

    /**
     * POST /staff/ledger/entry/update { id, transaction_date, transaction_detail, reference_number, category_id,
     * transaction_currency_code, transaction_amount, billed_currency_code, billed_amount, from_account_id,
     * to_account_id, counterparty_id }
     * Amounts are best sent as strings ("1234.5"), up to 4 decimal places. counterparty_id is required when either
     * account is external. The receipt has its own endpoints.
     * @return ResponseInterface
     */
    public function saveEntry(): ResponseInterface
    {
        return $this->saveRecord(
            model(OtternovaLedgerMasterModel::class),
            OtternovaLedgerMasterModel::FORM_FIELDS,
            OtternovaLedgerMasterModel::FORM_NULLABLE,
            'OtternovaLedgerMaster',
            'Ledger.entryNotFound',
            'v1/staff/ledger/entry/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger entry ' . $row['transaction_date'],
            function (array $row) {
                $types = model(OtternovaLedgerAccountModel::class)
                    ->select('account_type')
                    ->whereIn('id', [(int) $row['from_account_id'], (int) $row['to_account_id']])
                    ->findColumn('account_type') ?? [];
                if ($row['counterparty_id'] === null && in_array(OtternovaLedgerAccountModel::TYPE_EXTERNAL, $types, true)) {
                    return ['counterparty_id' => lang('Ledger.counterpartyRequired')];
                }

                return [];
            },
            fn (array $row) => $row + ['created_by' => $this->actorId()]
        );
    }

    /**
     * POST /staff/ledger/receipt-upload, multipart/form-data { id, receipt }
     * Saves the PDF as the entry's receipt, replacing the one it had
     * @return ResponseInterface
     */
    public function uploadReceipt(): ResponseInterface
    {
        $config = config(LedgerReceiptConfig::class);
        $id     = $this->validId($this->request->getPost('id'));
        if ($id === null) {
            return ApiResponse::error(lang('Ledger.idRequired'), 400);
        }
        // the image upload's check of a genuine upload, which the tests replace for their files
        $file = $this->request->getFile('receipt');
        if ($file === null || ! service('imageProcessor')->isGenuineUpload($file)) {
            return ApiResponse::error(lang('Ledger.receiptRequired'), 422, ['receipt' => lang('Ledger.receiptRequired')]);
        }
        if ($file->getSize() > $config->maxFileSize) {
            return ApiResponse::error(lang('Ledger.receiptTooLarge', [intdiv($config->maxFileSize, 1024 * 1024)]), 413);
        }
        // detected from the content: the client's claimed type and extension are not trusted
        if ($file->getMimeType() !== 'application/pdf') {
            return ApiResponse::error(lang('Ledger.receiptNotPdf'), 415, ['receipt' => lang('Ledger.receiptNotPdf')]);
        }

        $entries = model(OtternovaLedgerMasterModel::class);
        try {
            $stored = $entries->findStored($id);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-upload', $e, 'Ledger.loadFailed');
        }
        if ($stored === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }

        $folder = $this->receiptFolder();
        if (! is_dir($folder) && ! @mkdir($folder, 0750, true) && ! is_dir($folder)) {
            log_message('error', 'v1/staff/ledger/receipt-upload: cannot create {folder}', ['folder' => $folder]);

            return ApiResponse::error(lang('Ledger.receiptSaveFailed'), 500);
        }
        // a new random name each time, so a replaced receipt is never served from a cache
        $name   = $id . '-' . bin2hex(random_bytes(8)) . '.pdf';
        $source = $file->getTempName();
        try {
            $entries->setReceipt($stored, $name, $this->actorId(), 'Uploaded receipt of ledger entry ' . $id, static function () use ($source, $folder, $name) {
                if (! @copy($source, $folder . $name)) {
                    throw new RuntimeException('Cannot save ' . $folder . $name);
                }
            });
        } catch (Throwable $e) {
            @unlink($folder . $name);

            return $this->failed('v1/staff/ledger/receipt-upload', $e, 'Ledger.receiptSaveFailed');
        }

        $old = $this->receiptPath($stored['receipt_file_path']);
        if ($old !== null) {
            @unlink($old);
        }

        return ApiResponse::success(['id' => $id, 'has_receipt' => true], 200, lang('Ledger.receiptUploaded'));
    }

    /**
     * POST /staff/ledger/receipt-delete { id }
     * Removes the entry's receipt
     * @return ResponseInterface
     */
    public function deleteReceipt(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $id = $this->validId($input['id'] ?? null);
        if ($id === null) {
            return ApiResponse::error(lang('Ledger.idRequired'), 400);
        }

        $entries = model(OtternovaLedgerMasterModel::class);
        try {
            $stored = $entries->findStored($id);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-delete', $e, 'Ledger.loadFailed');
        }
        if ($stored === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }
        if ($stored['receipt_file_path'] === null || $stored['receipt_file_path'] === '') {
            return ApiResponse::error(lang('Ledger.noReceipt'), 404);
        }

        $path = $this->receiptPath($stored['receipt_file_path']);
        try {
            // deleted inside the transaction, so a file that will not go leaves the row as it was
            $entries->setReceipt($stored, null, $this->actorId(), 'Deleted receipt of ledger entry ' . $id, static function () use ($path) {
                if ($path !== null && is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed('v1/staff/ledger/receipt-delete', $e, 'Ledger.receiptDeleteFailed');
        }

        return ApiResponse::success(['id' => $id, 'has_receipt' => false], 200, lang('Ledger.receiptDeleted'));
    }

    /**
     * GET /staff/ledger/receipt/{id}
     * The entry's receipt as application/pdf, never cached: the app fetches it with its Bearer token
     * @param string $id
     * @return ResponseInterface
     */
    public function receipt(string $id): ResponseInterface
    {
        $id = $this->validId($id);
        try {
            $stored = $id === null ? null : model(OtternovaLedgerMasterModel::class)->findStored($id);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt', $e, 'Ledger.loadFailed');
        }
        $path = $this->receiptPath($stored['receipt_file_path'] ?? null);
        if ($path === null || ! is_file($path)) {
            return ApiResponse::error(lang('Ledger.noReceipt'), 404);
        }

        // removed first: the response starts with a no-store Cache-Control that setHeader() would add to
        return $this->response
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'inline; filename="receipt-' . $id . '.pdf"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContentType('application/pdf', '')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * POST /staff/ledger/accounts {}
     * Every ledger account, by name
     * @return ResponseInterface
     */
    public function accounts(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerAccountModel::class), 'v1/staff/ledger/accounts');
    }

    /**
     * POST /staff/ledger/account/update { id, account_name, account_type, currency_code, account_class }
     * @return ResponseInterface
     */
    public function saveAccount(): ResponseInterface
    {
        return $this->saveRecord(
            model(OtternovaLedgerAccountModel::class),
            OtternovaLedgerAccountModel::FORM_FIELDS,
            OtternovaLedgerAccountModel::FORM_NULLABLE,
            'OtternovaLedgerAccount',
            'Ledger.accountNotFound',
            'v1/staff/ledger/account/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger account ' . $row['account_name']
        );
    }

    /**
     * POST /staff/ledger/categories {}
     * Every ledger category, by name
     * @return ResponseInterface
     */
    public function categories(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerCategoryModel::class), 'v1/staff/ledger/categories');
    }

    /**
     * POST /staff/ledger/category/update { id, category_code, category_name }
     * @return ResponseInterface
     */
    public function saveCategory(): ResponseInterface
    {
        return $this->saveRecord(
            model(OtternovaLedgerCategoryModel::class),
            OtternovaLedgerCategoryModel::FORM_FIELDS,
            OtternovaLedgerCategoryModel::FORM_NULLABLE,
            'OtternovaLedgerCategory',
            'Ledger.categoryNotFound',
            'v1/staff/ledger/category/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger category ' . $row['category_code']
        );
    }

    /**
     * POST /staff/ledger/counterparties {}
     * Every counterparty, by name
     * @return ResponseInterface
     */
    public function counterparties(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerCounterpartyModel::class), 'v1/staff/ledger/counterparties');
    }

    /**
     * POST /staff/ledger/counterparty/update { id, counterparty_name, counterparty_description }
     * @return ResponseInterface
     */
    public function saveCounterparty(): ResponseInterface
    {
        return $this->saveRecord(
            model(OtternovaLedgerCounterpartyModel::class),
            OtternovaLedgerCounterpartyModel::FORM_FIELDS,
            OtternovaLedgerCounterpartyModel::FORM_NULLABLE,
            'OtternovaLedgerCounterparty',
            'Ledger.counterpartyNotFound',
            'v1/staff/ledger/counterparty/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger counterparty ' . $row['counterparty_name']
        );
    }

    /**
     * @param Model  $model    One with listForLookup()
     * @param string $endpoint For the error log
     * @return ResponseInterface
     */
    private function lookup(Model $model, string $endpoint): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }

        try {
            $rows = $model->listForLookup();
        } catch (DatabaseException $e) {
            return $this->failed($endpoint, $e, 'Ledger.lookupFailed');
        }

        return ApiResponse::success(['rows' => $rows]);
    }

    /**
     * The create-or-update of every .../update endpoint: no id creates, an id updates that record.
     * Every field is required in the body; an empty string saves NULL in a nullable field.
     * @param Model         $model    One with LedgerWriteTrait
     * @param list<string>  $fields   Columns written
     * @param list<string>  $nullable Columns of $fields that may be NULL
     * @param string        $langFile For the field labels in the error messages
     * @param string        $notFound Language key when the id has no record
     * @param string        $endpoint For the error log
     * @param callable      $summary  fn (array $row, ?array $stored): string, for log_activity
     * @param callable|null $check    fn (array $row): array, field => error for what the rules cannot check
     * @param callable|null $onCreate fn (array $row): array, the row with the columns only a create sets
     * @return ResponseInterface
     */
    private function saveRecord(Model $model, array $fields, array $nullable, string $langFile, string $notFound, string $endpoint, callable $summary, ?callable $check = null, ?callable $onCreate = null): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $id = $input['id'] ?? null;
        if ($id !== null && $id !== '' && $this->validId($id) === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $id = $id === null || $id === '' ? null : $this->validId($id);

        $row = [];
        foreach ($fields as $field) {
            $row[$field] = $this->normalize($field, $input[$field] ?? null, in_array($field, $nullable, true));
        }

        $rules = [];
        foreach ($model->getValidationRules(['only' => ['id', ...$fields]]) as $field => $fieldRules) {
            $rules[$field] = ['label' => $field === 'id' ? 'ID' : lang($langFile . '.fields.' . $field), 'rules' => $fieldRules];
        }
        // the record's own id, for is_unique[...,id,{id}]
        $validation = single_service('validation')->setRules($rules);
        if (! $validation->run($row + ['id' => $id === null ? '' : (string) $id])) {
            return ApiResponse::error(lang('Ledger.invalidFields'), 422, $validation->getErrors());
        }

        try {
            $stored = null;
            if ($id !== null) {
                $stored = $model->where('id', $id)->first();
                if ($stored === null) {
                    return ApiResponse::error(lang($notFound), 404);
                }
            }
            $errors = $check === null ? [] : $check($row);
            if ($errors !== []) {
                return ApiResponse::error(lang('Ledger.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                $id = $model->createLogged($onCreate === null ? $row : $onCreate($row), $this->actorId(), $summary($row, null));

                return ApiResponse::success(['id' => $id], 200, lang('Ledger.created'));
            }
            $model->updateLogged($stored, $row, $this->actorId(), $summary($row, $stored));

            return ApiResponse::success(['id' => $id], 200, lang('Ledger.updated'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Ledger.saveFailed');
        }
    }

    /**
     * A value from the body as the validation and the database take it: a trimmed string, or null
     * @param string $field
     * @param mixed  $value
     * @param bool   $nullable
     * @return string|null
     */
    private function normalize(string $field, mixed $value, bool $nullable): ?string
    {
        // a number counts too: amounts and IDs may arrive as JSON numbers; anything else is no value at all
        $value = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (in_array($field, self::UPPERCASE_FIELDS, true)) {
            $value = strtoupper($value);
        }
        if (in_array($field, self::AMOUNT_FIELDS, true) && preg_match(OtternovaLedgerMasterModel::AMOUNT_PATTERN, $value)) {
            [$whole, $fraction] = explode('.', $value . '.');
            $value              = ltrim($whole, '0') . '.' . str_pad($fraction, 4, '0');
            $value              = str_starts_with($value, '.') ? '0' . $value : $value;
        }

        return $value === '' && $nullable ? null : $value;
    }

    /**
     * The valid entries of "filters": a date (Y-m-d) for date_from and date_to, an ID for the rest
     * @param mixed $filters
     * @return array<string, string|int>
     */
    private function listFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $valid = [];
        foreach (OtternovaLedgerMasterModel::LIST_FILTERS as $name) {
            $value = $filters[$name] ?? null;
            if (in_array($name, ['date_from', 'date_to'], true)) {
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($date !== false && $date->format('Y-m-d') === $value) {
                    $valid[$name] = $value;
                }
            } elseif (($id = $this->validId($value)) !== null) {
                $valid[$name] = $id;
            }
        }

        return $valid;
    }

    /**
     * @param string|null $name receipt_file_path
     * @return string|null The file, or null when there is none or the name is not one uploadReceipt() made
     */
    private function receiptPath(?string $name): ?string
    {
        return $name !== null && preg_match(self::RECEIPT_PATTERN, $name) ? $this->receiptFolder() . $name : null;
    }

    /**
     * @return string With a trailing slash
     */
    private function receiptFolder(): string
    {
        return rtrim(config(LedgerReceiptConfig::class)->uploadPath, '/') . '/';
    }

    /**
     * @param mixed $id From the request
     * @return int|null Null unless a positive integer
     */
    private function validId(mixed $id): ?int
    {
        $id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return $id === false ? null : $id;
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc and created_by
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
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
}
