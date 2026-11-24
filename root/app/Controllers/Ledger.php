<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\Decimal;
use App\Libraries\LedgerPeriods;
use App\Libraries\LedgerPosting;
use App\Libraries\LedgerReports;
use App\Libraries\LedgerTaxReports;
use App\Models\OtternovaLedgerAccountModel;
use App\Models\OtternovaDepartmentModel;
use App\Models\OtternovaLedgerCategoryModel;
use App\Models\OtternovaLedgerCounterpartyModel;
use App\Models\OtternovaLedgerEntityModel;
use App\Models\OtternovaLedgerMasterModel;
use App\Models\OtternovaLedgerPostingRuleModel;
use App\Models\OtternovaLedgerTaxCodeModel;
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
 * (otternova_ledger_master) with their PDF receipts, the accounts, categories and counterparties they point at, the
 * legal entities whose books they are, and each entity's financial reports (trial balance, profit and loss, balance
 * sheet, general ledger; see App\Libraries\LedgerReports) and the closing of its books month by month (see
 * App\Libraries\LedgerPeriods): no entry in a closed month can change. The subscription billing is posted by
 * App\Libraries\LedgerPosting, by the posting rules; those rows cannot be changed here.
 * Every record goes out and comes in by its public_id; the ids stay inside.
 * Each .../update endpoint creates the record when the body has no public_id and updates the record with that
 * public_id otherwise.
 */
class Ledger extends BaseController
{
    private const DEFAULT_LENGTH = 25;
    private const MAX_LENGTH     = 500;

    /** Fields saved in capitals, so "thb" is accepted as THB */
    private const UPPERCASE_FIELDS = ['currency_code', 'transaction_currency_code', 'billed_currency_code', 'category_code', 'country_code', 'base_currency_code', 'tax_code'];

    /** Amounts saved with all 4 decimal places, as DECIMAL(19,4) gives them back, so an unchanged one is not logged as a change */
    private const AMOUNT_FIELDS = ['transaction_amount', 'billed_amount', 'tax_base_amount', 'vat_threshold_amount'];

    /** Rates saved with all 10 decimal places, as DECIMAL(20,10) gives them back, for the same reason */
    private const RATE_FIELDS = ['base_rate'];

    /** The account's column that points at its entity, by the entity's public_id */
    private const ACCOUNT_REFERENCES = [
        'entity_public_id' => ['entity_id', OtternovaLedgerEntityModel::class],
    ];

    /** A receipt_file_path as uploadReceipt() names it; anything else is never served or deleted */
    private const RECEIPT_PATTERN = '/^\d+-[0-9a-f]{16}\.pdf$/';

    /** The entry's columns that point at other ledger records: the body's field => [column, model of the record] */
    private const ENTRY_REFERENCES = [
        'category_public_id'     => ['category_id', OtternovaLedgerCategoryModel::class],
        'from_account_public_id' => ['from_account_id', OtternovaLedgerAccountModel::class],
        'to_account_public_id'   => ['to_account_id', OtternovaLedgerAccountModel::class],
        'counterparty_public_id' => ['counterparty_id', OtternovaLedgerCounterpartyModel::class],
        'tax_code_public_id'     => ['tax_code_id', OtternovaLedgerTaxCodeModel::class],
    ];

    /** The tax code's columns that point at other ledger records */
    private const TAX_CODE_REFERENCES = [
        'entity_public_id'  => ['entity_id', OtternovaLedgerEntityModel::class],
        'account_public_id' => ['account_id', OtternovaLedgerAccountModel::class],
    ];

    /** The posting rule's columns that point at other ledger records */
    private const POSTING_RULE_REFERENCES = [
        'entity_public_id'                      => ['entity_id', OtternovaLedgerEntityModel::class],
        'receivable_account_public_id'          => ['receivable_account_id', OtternovaLedgerAccountModel::class],
        'deferred_revenue_account_public_id'    => ['deferred_revenue_account_id', OtternovaLedgerAccountModel::class],
        'revenue_account_public_id'             => ['revenue_account_id', OtternovaLedgerAccountModel::class],
        'cash_account_public_id'                => ['cash_account_id', OtternovaLedgerAccountModel::class],
        'wht_receivable_account_public_id'      => ['wht_receivable_account_id', OtternovaLedgerAccountModel::class],
        'wht_tax_code_public_id'                => ['wht_tax_code_id', OtternovaLedgerTaxCodeModel::class],
        'category_public_id'                    => ['category_id', OtternovaLedgerCategoryModel::class],
    ];

    /** How far a tax line may be from its taxable amount times the rate, in units of 0.0001: rounding on the bill */
    private const TAX_TOLERANCE = 10_000;

    /** Filters of the entry list that name a record: the body's filter => [OtternovaLedgerMasterModel::LIST_FILTERS name, model] */
    private const FILTER_REFERENCES = [
        'account_public_id'      => ['account_id', OtternovaLedgerAccountModel::class],
        'category_public_id'     => ['category_id', OtternovaLedgerCategoryModel::class],
        'counterparty_public_id' => ['counterparty_id', OtternovaLedgerCounterpartyModel::class],
    ];

    /**
     * POST /staff/ledger/entries { start, length, search, filters: {date_from, date_to, account_public_id,
     * category_public_id, counterparty_public_id, journal_id} }
     * One page of entries, newest transaction first. Every part of the body is optional, and a date filter that is
     * not a date (Y-m-d) is ignored rather than rejected; a public_id filter naming no record matches no entry.
     * account_public_id matches either side.
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
            $filters = $this->listFilters($input['filters'] ?? null);
            $data    = model(OtternovaLedgerMasterModel::class)->listEntries($start, $length, $search, $filters);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/entries', $e, 'Ledger.listFailed');
        }

        return ApiResponse::success($data);
    }

    /**
     * POST /staff/ledger/entry { public_id }
     * One entry with the names of its category, accounts, counterparty and creator. has_receipt says whether
     * GET /staff/ledger/receipt/{public_id} has a PDF.
     * @return ResponseInterface
     */
    public function entry(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('Ledger.idRequired'), 400);
        }

        try {
            $entries = model(OtternovaLedgerMasterModel::class);
            $id      = $entries->idOfPublicId($publicId);
            $entry   = $id === null ? null : $entries->findEntry($id);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/entry', $e, 'Ledger.loadFailed');
        }
        if ($entry === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }

        return ApiResponse::success(['entry' => $entry]);
    }

    /**
     * POST /staff/ledger/entry/update { public_id, transaction_date, transaction_detail, reference_number,
     * category_public_id, transaction_currency_code, transaction_amount, billed_currency_code, billed_amount,
     * base_rate, from_account_public_id, to_account_public_id, counterparty_public_id }
     * Amounts are best sent as strings ("1234.5"), up to 4 decimal places. counterparty_public_id is required when
     * either account is external. Both accounts must be of the same entity. The receipt has its own endpoints.
     * The entry is also kept in its entity's base currency (base_amount): the paid amount when that is in the base
     * currency, else the billed amount when that is, else the paid amount times base_rate, base currency per unit of
     * the paid currency. With no base_rate, the day's rate is fetched (see App\Libraries\ExchangeRates) and kept
     * as base_rate; when none can be had, base_rate is required. base_rate is cleared when no rate is needed.
     * tax_code_public_id makes the row that tax: one of its accounts must be the code's tax account, and its base
     * amount must be tax_base_amount (in the base currency) times the rate, give or take 1 for rounding. A 0% code
     * goes on the sale or purchase itself, which is its own taxable amount. with_entry_public_id, on a create only,
     * puts the new row in the same transaction (journal_id) as that entry, such as the VAT of a bill.
     * @return ResponseInterface
     */
    public function saveEntry(): ResponseInterface
    {
        $with = null;
        try {
            $stored = $this->storedEntry();
            if ($stored !== null && $this->periodsLibrary()->isClosed($stored['entity_id'], $stored['transaction_date'])) {
                return ApiResponse::error(lang('Ledger.entryInClosedPeriod'), 409);
            }
            if ($stored !== null && $stored['source_key'] !== null) {
                return ApiResponse::error(lang('Ledger.entryPosted'), 409);
            }
            $withId = $this->jsonInput()['with_entry_public_id'] ?? null;
            if ($stored === null && is_string($withId) && $withId !== '') {
                $with = model(OtternovaLedgerMasterModel::class)->findByPublicId($withId);
                if ($with === null) {
                    return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
                }
                if ($with['source_key'] !== null) {
                    return ApiResponse::error(lang('Ledger.entryPosted'), 409);
                }
                $with['entity_id'] = $this->entityOfEntry((int) $with['id']);
                if ($this->periodsLibrary()->isClosed($with['entity_id'], $with['transaction_date'])) {
                    return ApiResponse::error(lang('Ledger.entryInClosedPeriod'), 409);
                }
            }
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/entry/update', $e, 'Ledger.loadFailed');
        }

        return $this->saveRecord(
            model(OtternovaLedgerMasterModel::class),
            OtternovaLedgerMasterModel::FORM_FIELDS,
            OtternovaLedgerMasterModel::FORM_NULLABLE,
            'OtternovaLedgerMaster',
            'Ledger.entryNotFound',
            'v1/staff/ledger/entry/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger entry ' . $row['transaction_date'],
            function (array &$row) use ($with) {
                $accounts = model(OtternovaLedgerAccountModel::class)->db->table('otternova_ledger_account a')
                    ->select('a.id, a.account_type, a.entity_id, n.base_currency_code')
                    ->join('otternova_ledger_entity n', 'n.id = a.entity_id', 'left')
                    ->whereIn('a.id', [(int) $row['from_account_id'], (int) $row['to_account_id']])
                    ->get()->getResultArray();
                $accounts = array_column($accounts, null, 'id');
                $from     = $accounts[(int) $row['from_account_id']] ?? null;
                $to       = $accounts[(int) $row['to_account_id']] ?? null;
                if ($row['counterparty_id'] === null && in_array(OtternovaLedgerAccountModel::TYPE_EXTERNAL, array_column($accounts, 'account_type'), true)) {
                    return ['counterparty_id' => lang('Ledger.counterpartyRequired')];
                }
                if ($from === null || $to === null || $from['entity_id'] === null || $from['entity_id'] !== $to['entity_id']) {
                    return ['to_account_id' => lang('Ledger.entityMismatch')];
                }
                if ($this->periodsLibrary()->isClosed((int) $from['entity_id'], $row['transaction_date'])) {
                    return ['transaction_date' => lang('Ledger.dateInClosedPeriod')];
                }
                if ($with !== null && (string) $with['entity_id'] !== (string) $from['entity_id']) {
                    return ['to_account_id' => lang('Ledger.entityMismatch')];
                }

                return $this->baseAmount($row, (string) $from['base_currency_code'])
                    ?: $this->taxLine($row, (int) $from['entity_id']);
            },
            function (array $row) use ($with) {
                $row['created_by'] = $this->actorId();
                if ($with !== null) {
                    $row['journal_id'] = $with['journal_id'] ?? $with['public_id'];
                    if ($with['journal_id'] === null) {
                        // the entry starts the transaction, named after itself
                        model(OtternovaLedgerMasterModel::class)->updateLogged($with, ['journal_id' => $with['public_id']], $this->actorId(), 'Grouped ledger entry ' . $with['public_id']);
                    }
                }

                return $row;
            },
            self::ENTRY_REFERENCES
        );
    }

    /**
     * POST /staff/ledger/receipt-upload, multipart/form-data { public_id, receipt }
     * Saves the PDF as the entry's receipt, replacing the one it had
     * @return ResponseInterface
     */
    public function uploadReceipt(): ResponseInterface
    {
        $config   = config(LedgerReceiptConfig::class);
        $publicId = $this->request->getPost('public_id');
        if (! is_string($publicId) || $publicId === '') {
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
            $stored = $entries->findByPublicId($publicId);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-upload', $e, 'Ledger.loadFailed');
        }
        if ($stored === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }
        $id = (int) $stored['id'];
        // a missing receipt can still be added to an entry in a closed month, but the one it has stays
        $hasReceipt = $stored['receipt_file_path'] !== null && $stored['receipt_file_path'] !== '';
        try {
            if ($hasReceipt && $this->periodsLibrary()->isClosed($this->entityOfEntry($id), $stored['transaction_date'])) {
                return ApiResponse::error(lang('Ledger.entryInClosedPeriod'), 409);
            }
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-upload', $e, 'Ledger.loadFailed');
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

        return ApiResponse::success(['public_id' => $publicId, 'has_receipt' => true], 200, lang('Ledger.receiptUploaded'));
    }

    /**
     * POST /staff/ledger/receipt-delete { public_id }
     * Removes the entry's receipt
     * @return ResponseInterface
     */
    public function deleteReceipt(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return ApiResponse::error(lang('Ledger.idRequired'), 400);
        }

        $entries = model(OtternovaLedgerMasterModel::class);
        try {
            $stored = $entries->findByPublicId($publicId);
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-delete', $e, 'Ledger.loadFailed');
        }
        if ($stored === null) {
            return ApiResponse::error(lang('Ledger.entryNotFound'), 404);
        }
        if ($stored['receipt_file_path'] === null || $stored['receipt_file_path'] === '') {
            return ApiResponse::error(lang('Ledger.noReceipt'), 404);
        }
        try {
            if ($this->periodsLibrary()->isClosed($this->entityOfEntry((int) $stored['id']), $stored['transaction_date'])) {
                return ApiResponse::error(lang('Ledger.entryInClosedPeriod'), 409);
            }
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/receipt-delete', $e, 'Ledger.loadFailed');
        }

        $path = $this->receiptPath($stored['receipt_file_path']);
        try {
            // deleted inside the transaction, so a file that will not go leaves the row as it was
            $entries->setReceipt($stored, null, $this->actorId(), 'Deleted receipt of ledger entry ' . $stored['id'], static function () use ($path) {
                if ($path !== null && is_file($path) && ! @unlink($path)) {
                    throw new RuntimeException('Cannot delete ' . $path);
                }
            });
        } catch (Throwable $e) {
            return $this->failed('v1/staff/ledger/receipt-delete', $e, 'Ledger.receiptDeleteFailed');
        }

        return ApiResponse::success(['public_id' => $publicId, 'has_receipt' => false], 200, lang('Ledger.receiptDeleted'));
    }

    /**
     * GET /staff/ledger/receipt/{public_id}
     * The entry's receipt as application/pdf, never cached: the app fetches it with its Bearer token
     * @param string $publicId
     * @return ResponseInterface
     */
    public function receipt(string $publicId): ResponseInterface
    {
        try {
            $stored = model(OtternovaLedgerMasterModel::class)->findByPublicId($publicId);
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
            ->setHeader('Content-Disposition', 'inline; filename="receipt-' . $publicId . '.pdf"')
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
     * POST /staff/ledger/account/update { public_id, entity_public_id, account_name, account_type, currency_code,
     * account_class }
     * An account with entries stays in its entity: moving it would move them into another entity's books.
     * @return ResponseInterface
     */
    public function saveAccount(): ResponseInterface
    {
        $accounts = model(OtternovaLedgerAccountModel::class);
        $input    = $this->jsonInput();
        $stored   = null;
        if (is_string($input['public_id'] ?? null) && $input['public_id'] !== '') {
            try {
                $stored = $accounts->findByPublicId($input['public_id']);
            } catch (DatabaseException $e) {
                return $this->failed('v1/staff/ledger/account/update', $e, 'Ledger.loadFailed');
            }
        }

        return $this->saveRecord(
            $accounts,
            OtternovaLedgerAccountModel::FORM_FIELDS,
            OtternovaLedgerAccountModel::FORM_NULLABLE,
            'OtternovaLedgerAccount',
            'Ledger.accountNotFound',
            'v1/staff/ledger/account/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger account ' . $row['account_name'],
            function (array $row) use ($stored) {
                if ($stored === null) {
                    return [];
                }
                $entries = fn () => $this->db()->table('otternova_ledger_master')
                    ->groupStart()->where('from_account_id', $stored['id'])->orWhere('to_account_id', $stored['id'])->groupEnd();
                if ($stored['entity_id'] !== null && (string) $stored['entity_id'] !== (string) $row['entity_id'] && $entries()->countAllResults() > 0) {
                    return ['entity_id' => lang('Ledger.accountHasEntries')];
                }
                // a class the closed months' reports were made with stays
                $through = $stored['entity_id'] === null ? null
                    : ($this->db()->table('otternova_ledger_entity')->select('closed_through')->where('id', $stored['entity_id'])->get()->getRow()->closed_through ?? null);
                if ($stored['account_class'] !== null && $stored['account_class'] !== $row['account_class'] && $through !== null
                    && $entries()->where('transaction_date <=', $through)->countAllResults() > 0) {
                    return ['account_class' => lang('Ledger.accountClassClosed')];
                }

                return [];
            },
            null,
            self::ACCOUNT_REFERENCES
        );
    }

    /**
     * POST /staff/ledger/posting-rules {}
     * Every posting rule, by country and date
     * @return ResponseInterface
     */
    public function postingRules(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerPostingRuleModel::class), 'v1/staff/ledger/posting-rules');
    }

    /**
     * POST /staff/ledger/posting-rule/update { public_id, entity_public_id, country_code, effective_from, effective_to,
     * receivable_account_public_id, deferred_revenue_account_public_id, revenue_account_public_id,
     * cash_account_public_id, wht_receivable_account_public_id, wht_tax_code_public_id, category_public_id }
     * The accounts and the tax code are of the rule's entity, the tax code is withholding tax suffered (WS), and the
     * rules of a country never overlap. Once invoices are posted by it, the rule keeps its entity, country and start.
     * @return ResponseInterface
     */
    public function savePostingRule(): ResponseInterface
    {
        $rules  = model(OtternovaLedgerPostingRuleModel::class);
        $input  = $this->jsonInput();
        $stored = null;
        if (is_string($input['public_id'] ?? null) && $input['public_id'] !== '') {
            try {
                $stored = $rules->findByPublicId($input['public_id']);
            } catch (DatabaseException $e) {
                return $this->failed('v1/staff/ledger/posting-rule/update', $e, 'Ledger.loadFailed');
            }
        }

        return $this->saveRecord(
            $rules,
            OtternovaLedgerPostingRuleModel::FORM_FIELDS,
            OtternovaLedgerPostingRuleModel::FORM_NULLABLE,
            'OtternovaLedgerPostingRule',
            'Ledger.postingRuleNotFound',
            'v1/staff/ledger/posting-rule/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger posting rule for ' . $row['country_code'] . ' from ' . $row['effective_from'],
            function (array $row) use ($stored) {
                if ($row['effective_to'] !== null && $row['effective_to'] < $row['effective_from']) {
                    return ['effective_to' => lang('Ledger.effectiveToBeforeFrom')];
                }
                foreach (OtternovaLedgerPostingRuleModel::ACCOUNT_FIELDS as $column) {
                    if ($row[$column] === null) {
                        continue;
                    }
                    $entity = $this->db()->table('otternova_ledger_account')->select('entity_id')->where('id', $row[$column])->get()->getRow()->entity_id ?? null;
                    if ((string) $entity !== (string) $row['entity_id']) {
                        return [$column => lang('Ledger.entityMismatch')];
                    }
                }
                if ($row['wht_tax_code_id'] !== null) {
                    $code = $this->db()->table('otternova_ledger_tax_code')->where('id', $row['wht_tax_code_id'])->get()->getRowArray();
                    if ((string) $code['entity_id'] !== (string) $row['entity_id'] || $code['tax_kind'] !== OtternovaLedgerTaxCodeModel::KIND_WHT_SUFFERED) {
                        return ['wht_tax_code_id' => lang('Ledger.postingTaxCodeWrong')];
                    }
                    if ($row['wht_receivable_account_id'] === null || (string) $code['account_id'] !== (string) $row['wht_receivable_account_id']) {
                        return ['wht_receivable_account_id' => lang('Ledger.postingTaxAccountWrong')];
                    }
                }
                $overlap = $this->db()->table('otternova_ledger_posting_rule')->where('country_code', $row['country_code'])
                    ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $row['effective_from'])->groupEnd();
                if ($row['effective_to'] !== null) {
                    $overlap->where('effective_from <=', $row['effective_to']);
                }
                if ($stored !== null) {
                    $overlap->where('id !=', $stored['id']);
                }
                if ($overlap->countAllResults() > 0) {
                    return ['effective_from' => lang('Ledger.postingRuleOverlaps')];
                }
                $posted = $stored === null ? 0 : $this->db()->table('otternova_ledger_master e')
                    ->join('otternova_ledger_account a', 'a.id = e.from_account_id', 'inner')
                    ->where('a.entity_id', $stored['entity_id'])
                    ->like('e.source_key', 'inv:', 'after')
                    ->countAllResults();
                if ($posted > 0) {
                    foreach (['entity_id', 'country_code', 'effective_from'] as $column) {
                        if ((string) $stored[$column] !== (string) $row[$column]) {
                            return [$column => lang('Ledger.postingRuleInUse')];
                        }
                    }
                }

                return [];
            },
            null,
            self::POSTING_RULE_REFERENCES
        );
    }

    /**
     * POST /staff/ledger/posting/status {}
     * The billing events waiting to be posted, with why, and when the posting last ran
     * @return ResponseInterface
     */
    public function postingStatus(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        try {
            $pending  = (new LedgerPosting($this->db()))->pending();
            $entities = array_column($this->db()->table('otternova_ledger_entity')->select('id, public_id, entity_name')->get()->getResultArray(), null, 'id');
            $posted   = $this->db()->table('otternova_ledger_master')->selectMax('created_at', 'last_posted_at')->where('source_key IS NOT NULL', null, false)->get()->getRow()->last_posted_at ?? null;
        } catch (Throwable $e) {
            return $this->failed('v1/staff/ledger/posting/status', $e, 'Ledger.loadFailed');
        }
        foreach ($pending as &$event) {
            $entity                    = $entities[$event['entity_id']] ?? null;
            $event['entity_public_id'] = $entity['public_id'] ?? null;
            $event['entity_name']      = $entity['entity_name'] ?? null;
            $event['message']          = lang('Ledger.posting_' . $event['problem'], [$event['country_code']]);
            unset($event['entity_id']);
        }
        unset($event);

        return ApiResponse::success(['pending' => $pending, 'last_posted_at' => $posted]);
    }

    /**
     * POST /staff/ledger/posting/sync {}
     * Posts the billing events not posted yet; billing:run does it every hour
     * @return ResponseInterface
     */
    public function syncPosting(): ResponseInterface
    {
        if ($this->jsonInput() === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        try {
            $counts = (new LedgerPosting($this->db()))->sync();
        } catch (Throwable $e) {
            return $this->failed('v1/staff/ledger/posting/sync', $e, 'Ledger.saveFailed');
        }

        return ApiResponse::success($counts, 200, lang('Ledger.postingDone', [$counts['posted'], $counts['waiting']]));
    }

    /**
     * POST /staff/ledger/tax-codes {}
     * Every tax code, by entity and code
     * @return ResponseInterface
     */
    public function taxCodes(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerTaxCodeModel::class), 'v1/staff/ledger/tax-codes');
    }

    /**
     * POST /staff/ledger/tax-code/update { public_id, entity_public_id, tax_code, tax_name, tax_kind, rate,
     * account_public_id, effective_from, effective_to }
     * The tax account is of the same entity, and required unless the rate is 0. Once the code is on an entry, its
     * entity, kind, rate and account stay: a new rate is a new code, the old one ending the day before.
     * @return ResponseInterface
     */
    public function saveTaxCode(): ResponseInterface
    {
        $codes  = model(OtternovaLedgerTaxCodeModel::class);
        $input  = $this->jsonInput();
        $stored = null;
        if (is_string($input['public_id'] ?? null) && $input['public_id'] !== '') {
            try {
                $stored = $codes->findByPublicId($input['public_id']);
            } catch (DatabaseException $e) {
                return $this->failed('v1/staff/ledger/tax-code/update', $e, 'Ledger.loadFailed');
            }
        }

        return $this->saveRecord(
            $codes,
            OtternovaLedgerTaxCodeModel::FORM_FIELDS,
            OtternovaLedgerTaxCodeModel::FORM_NULLABLE,
            'OtternovaLedgerTaxCode',
            'Ledger.taxCodeNotFound',
            'v1/staff/ledger/tax-code/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger tax code ' . $row['tax_code'],
            function (array $row) use ($stored) {
                if ($row['effective_to'] !== null && $row['effective_to'] < $row['effective_from']) {
                    return ['effective_to' => lang('Ledger.effectiveToBeforeFrom')];
                }
                if ((float) $row['rate'] > 0 && $row['account_id'] === null) {
                    return ['account_id' => lang('Ledger.taxAccountRequired')];
                }
                if ($row['account_id'] !== null) {
                    $entity = $this->db()->table('otternova_ledger_account')->select('entity_id')->where('id', $row['account_id'])->get()->getRow()->entity_id ?? null;
                    if ((string) $entity !== (string) $row['entity_id']) {
                        return ['account_id' => lang('Ledger.entityMismatch')];
                    }
                }
                $taken = $this->db()->table('otternova_ledger_tax_code')->where(['entity_id' => $row['entity_id'], 'tax_code' => $row['tax_code']]);
                if ($stored !== null) {
                    $taken->where('id !=', $stored['id']);
                }
                if ($taken->countAllResults() > 0) {
                    return ['tax_code' => lang('Ledger.taxCodeTaken')];
                }
                if ($stored === null || $this->db()->table('otternova_ledger_master')->where('tax_code_id', $stored['id'])->countAllResults() === 0) {
                    return [];
                }
                foreach (OtternovaLedgerTaxCodeModel::FIXED_WHEN_USED as $column) {
                    $before = $stored[$column] === null ? null : (string) $stored[$column];
                    $after  = $row[$column] === null ? null : (string) $row[$column];
                    if ($column === 'rate' ? (float) $before !== (float) $after : $before !== $after) {
                        return [$column => lang('Ledger.taxCodeInUse')];
                    }
                }

                return [];
            },
            null,
            self::TAX_CODE_REFERENCES
        );
    }

    /**
     * POST /staff/ledger/report/vat { entity_public_id, date_from, date_to }
     * Output and input VAT lines of the period, with their totals and what is payable
     * @return ResponseInterface
     */
    public function vatReport(): ResponseInterface
    {
        return $this->taxReport('v1/staff/ledger/report/vat', static fn (LedgerTaxReports $tax, int $entityId, array $dates) => $tax->vat($entityId, $dates['date_from'], $dates['date_to']));
    }

    /**
     * POST /staff/ledger/report/wht { entity_public_id, date_from, date_to }
     * Withholding tax deducted from payees and by customers in the period
     * @return ResponseInterface
     */
    public function whtReport(): ResponseInterface
    {
        return $this->taxReport('v1/staff/ledger/report/wht', static fn (LedgerTaxReports $tax, int $entityId, array $dates) => $tax->wht($entityId, $dates['date_from'], $dates['date_to']));
    }

    /**
     * POST /staff/ledger/report/revenue-register { entity_public_id, date_from, date_to }
     * @return ResponseInterface
     */
    public function revenueRegister(): ResponseInterface
    {
        return $this->taxReport('v1/staff/ledger/report/revenue-register', static fn (LedgerTaxReports $tax, int $entityId, array $dates) => $tax->revenueRegister($entityId, $dates['date_from'], $dates['date_to']));
    }

    /**
     * POST /staff/ledger/report/expense-register { entity_public_id, date_from, date_to }
     * @return ResponseInterface
     */
    public function expenseRegister(): ResponseInterface
    {
        return $this->taxReport('v1/staff/ledger/report/expense-register', static fn (LedgerTaxReports $tax, int $entityId, array $dates) => $tax->expenseRegister($entityId, $dates['date_from'], $dates['date_to']));
    }

    /**
     * POST /staff/ledger/report/vat-threshold { entity_public_id, date_to }
     * The entity's revenue in the fiscal year and the last 12 months to date_to against its VAT registration threshold
     * @return ResponseInterface
     */
    public function vatThreshold(): ResponseInterface
    {
        return $this->report('v1/staff/ledger/report/vat-threshold', ['date_to'], fn (LedgerReports $reports, int $entityId, array $dates) => (new LedgerTaxReports($this->db()))->vatThreshold($entityId, $dates['date_to']));
    }

    /**
     * POST /staff/ledger/entities {}
     * Every legal entity, by name
     * @return ResponseInterface
     */
    public function entities(): ResponseInterface
    {
        return $this->lookup(model(OtternovaLedgerEntityModel::class), 'v1/staff/ledger/entities');
    }

    /**
     * POST /staff/ledger/entity/update { public_id, entity_name, legal_form, country_code, tax_id, base_currency_code,
     * timezone, fiscal_year_start_month, registered_on, closed_on }
     * The base currency stays once the entity has entries, as their base amounts are in it. The fiscal year's first
     * month can change at any time: the reports work it out when they are run.
     * @return ResponseInterface
     */
    public function saveEntity(): ResponseInterface
    {
        $entities = model(OtternovaLedgerEntityModel::class);
        $input    = $this->jsonInput();
        $stored   = null;
        if (is_string($input['public_id'] ?? null) && $input['public_id'] !== '') {
            try {
                $stored = $entities->findByPublicId($input['public_id']);
            } catch (DatabaseException $e) {
                return $this->failed('v1/staff/ledger/entity/update', $e, 'Ledger.loadFailed');
            }
        }

        return $this->saveRecord(
            $entities,
            OtternovaLedgerEntityModel::FORM_FIELDS,
            OtternovaLedgerEntityModel::FORM_NULLABLE,
            'OtternovaLedgerEntity',
            'Ledger.entityNotFound',
            'v1/staff/ledger/entity/update',
            static fn (array $row, ?array $stored) => ($stored === null ? 'Created' : 'Updated') . ' ledger entity ' . $row['entity_name'],
            function (array $row) use ($stored) {
                if ($row['registered_on'] !== null && $row['closed_on'] !== null && $row['closed_on'] < $row['registered_on']) {
                    return ['closed_on' => lang('Ledger.closedBeforeRegistered')];
                }
                if ($stored === null || $stored['base_currency_code'] === $row['base_currency_code']) {
                    return [];
                }
                $used = $this->db()->table('otternova_ledger_master e')
                    ->join('otternova_ledger_account a', 'a.id = e.from_account_id', 'inner')
                    ->where('a.entity_id', $stored['id'])
                    ->countAllResults();

                return $used > 0 ? ['base_currency_code' => lang('Ledger.entityHasEntries')] : [];
            }
        );
    }

    /**
     * POST /staff/ledger/report/trial-balance { entity_public_id, date_to }
     * Every account of the entity with its debits, credits and balance up to date_to, in the base currency
     * @return ResponseInterface
     */
    public function trialBalance(): ResponseInterface
    {
        return $this->report('v1/staff/ledger/report/trial-balance', ['date_to'], static fn (LedgerReports $reports, int $entityId, array $dates) => $reports->trialBalance($entityId, $dates['date_to']));
    }

    /**
     * POST /staff/ledger/report/profit-loss { entity_public_id, date_from, date_to }
     * Revenue and expenses of the entity from date_from to date_to, by account and category, in the base currency
     * @return ResponseInterface
     */
    public function profitLoss(): ResponseInterface
    {
        return $this->report('v1/staff/ledger/report/profit-loss', ['date_from', 'date_to'], static function (LedgerReports $reports, int $entityId, array $dates) {
            return $dates['date_from'] > $dates['date_to'] ? null : $reports->profitLoss($entityId, $dates['date_from'], $dates['date_to']);
        });
    }

    /**
     * POST /staff/ledger/report/balance-sheet { entity_public_id, date_to }
     * Assets, liabilities and equity of the entity at the end of date_to, with the retained earnings and the profit
     * of the fiscal year so far worked out from revenue and expenses, in the base currency
     * @return ResponseInterface
     */
    public function balanceSheet(): ResponseInterface
    {
        return $this->report('v1/staff/ledger/report/balance-sheet', ['date_to'], static fn (LedgerReports $reports, int $entityId, array $dates) => $reports->balanceSheet($entityId, $dates['date_to']));
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
     * POST /staff/ledger/category/update { public_id, category_code, category_name }
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
     * POST /staff/ledger/counterparty/update { public_id, counterparty_name, counterparty_description }
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
     * POST /staff/ledger/report/general-ledger { entity_public_id, date_from, date_to }
     * Every entry of the entity from date_from to date_to, oldest first, with its base amount
     * @return ResponseInterface
     */
    public function generalLedger(): ResponseInterface
    {
        return $this->report('v1/staff/ledger/report/general-ledger', ['date_from', 'date_to'], static function (LedgerReports $reports, int $entityId, array $dates) {
            return $dates['date_from'] > $dates['date_to'] ? null : $reports->generalLedger($entityId, $dates['date_from'], $dates['date_to']);
        });
    }

    /**
     * POST /staff/ledger/periods { entity_public_id }
     * The entity's months from its first entry, newest first, closed or open with their history, and the next one
     * to close with what stands in its way
     * @return ResponseInterface
     */
    public function periods(): ResponseInterface
    {
        return $this->withEntity('v1/staff/ledger/periods', fn (int $entityId) => ApiResponse::success($this->periodsLibrary()->overview($entityId)));
    }

    /**
     * POST /staff/ledger/period/close { entity_public_id, period_start }
     * Closes the month starting on period_start, which must be the next one, and keeps its reports
     * @return ResponseInterface
     */
    public function closePeriod(): ResponseInterface
    {
        return $this->withEntity('v1/staff/ledger/period/close', function (int $entityId, array $input) {
            $start = $input['period_start'] ?? null;
            if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-01$/', $start)) {
                return ApiResponse::error(lang('Ledger.invalidFields'), 422, ['period_start' => lang('Ledger.periodNotNext')]);
            }
            // the billing up to now first, so the month's invoices are in its reports
            try {
                (new LedgerPosting($this->db()))->sync();
            } catch (Throwable $e) {
                log_message('error', 'Ledger posting before closing a month failed: {message}', ['message' => $e->getMessage()]);
            }
            $result = $this->periodsLibrary()->close($entityId, $start, $this->actorId());
            if ($result['problems'] !== []) {
                return ApiResponse::error(lang('Ledger.periodCannotClose'), 409, ['problems' => $result['problems']]);
            }

            return ApiResponse::success(['public_id' => $result['public_id']], 200, lang('Ledger.periodClosed', [substr($start, 0, 7)]));
        });
    }

    /**
     * POST /staff/ledger/period/reopen { entity_public_id, reason }
     * Reopens the entity's last closed month; the Executive Office only, with the reason
     * @return ResponseInterface
     */
    public function reopenPeriod(): ResponseInterface
    {
        return $this->withEntity('v1/staff/ledger/period/reopen', function (int $entityId, array $input) {
            if (model(OtternovaDepartmentModel::class)->codeOfStaff($this->actorId()) !== 'EXE') {
                return ApiResponse::error(lang('Ledger.reopenExecutiveOnly'), 403);
            }
            $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
            if ($reason === '' || mb_strlen($reason) > 255) {
                return ApiResponse::error(lang('Ledger.invalidFields'), 422, ['reason' => lang('Ledger.reopenReasonRequired')]);
            }
            $start = $this->periodsLibrary()->reopen($entityId, $this->actorId(), $reason);
            if ($start === null) {
                return ApiResponse::error(lang('Ledger.nothingToReopen'), 409);
            }

            return ApiResponse::success(['period_start' => $start], 200, lang('Ledger.periodReopened', [substr($start, 0, 7)]));
        });
    }

    /**
     * POST /staff/ledger/period/snapshot { period_public_id, report_type }
     * A report as it was when its month closed (report_type TB, BS, PL, PY or GL), with the sha256 of what was kept
     * @return ResponseInterface
     */
    public function periodSnapshot(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        try {
            $snapshot = $this->periodsLibrary()->snapshot((string) ($input['period_public_id'] ?? ''), (string) ($input['report_type'] ?? ''));
        } catch (DatabaseException $e) {
            return $this->failed('v1/staff/ledger/period/snapshot', $e, 'Ledger.reportFailed');
        }
        if ($snapshot === null) {
            return ApiResponse::error(lang('Ledger.snapshotNotFound'), 404);
        }

        return ApiResponse::success(['snapshot' => $snapshot]);
    }

    /**
     * A tax report over date_from to date_to
     * @param string   $endpoint
     * @param callable $build fn (LedgerTaxReports, int $entityId, array $dates): array
     * @return ResponseInterface
     */
    private function taxReport(string $endpoint, callable $build): ResponseInterface
    {
        return $this->report($endpoint, ['date_from', 'date_to'], function (LedgerReports $reports, int $entityId, array $dates) use ($build) {
            return $dates['date_from'] > $dates['date_to'] ? null : $build(new LedgerTaxReports($this->db()), $entityId, $dates);
        });
    }

    /**
     * Checks the tax of an entry's checked row, as saveEntry() describes, and sets tax_base_amount of a 0% code
     * @param array $row      By reference; base_amount is set
     * @param int   $entityId
     * @return array<string, string> column => error
     */
    private function taxLine(array &$row, int $entityId): array
    {
        if ($row['tax_code_id'] === null) {
            $row['tax_base_amount'] = null;

            return [];
        }
        $code = $this->db()->table('otternova_ledger_tax_code')->where('id', $row['tax_code_id'])->get()->getRowArray();
        if ((int) $code['entity_id'] !== $entityId) {
            return ['tax_code_id' => lang('Ledger.entityMismatch')];
        }
        if ($row['transaction_date'] < $code['effective_from'] || ($code['effective_to'] !== null && $row['transaction_date'] > $code['effective_to'])) {
            return ['tax_code_id' => lang('Ledger.taxCodeNotEffective')];
        }
        if ((float) $code['rate'] === 0.0) {
            $row['tax_base_amount'] = $row['base_amount'];

            return [];
        }
        if (! in_array((string) $code['account_id'], [(string) $row['from_account_id'], (string) $row['to_account_id']], true)) {
            $account = $this->db()->table('otternova_ledger_account')->select('account_name')->where('id', $code['account_id'])->get()->getRow()->account_name ?? '';

            return ['tax_code_id' => lang('Ledger.taxAccountNotUsed', [$account])];
        }
        if ($row['tax_base_amount'] === null || Decimal::toUnits($row['tax_base_amount']) === 0) {
            return ['tax_base_amount' => lang('Ledger.taxBaseRequired')];
        }
        // the base times the percentage, rounded to 0.0001
        $expected = intdiv(Decimal::toUnits(Decimal::multiply($row['tax_base_amount'], (string) $code['rate'])) + 50, 100);
        if (abs(Decimal::toUnits($row['base_amount']) - $expected) > self::TAX_TOLERANCE) {
            return ['tax_base_amount' => lang('Ledger.taxAmountMismatch', [number_format($expected / 10_000, 2, '.', '')])];
        }

        return [];
    }

    /**
     * An endpoint about one entity, named by entity_public_id
     * @param string   $endpoint For the error log
     * @param callable $work     fn (int $entityId, array $input): ResponseInterface
     * @return ResponseInterface
     */
    private function withEntity(string $endpoint, callable $work): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        try {
            $entityId = model(OtternovaLedgerEntityModel::class)->idOfPublicId($input['entity_public_id'] ?? null);
            if ($entityId === null) {
                return ApiResponse::error(lang('Ledger.entityNotFound'), 404);
            }

            return $work($entityId, $input);
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Ledger.saveFailed');
        }
    }

    /**
     * The stored entry the body's public_id names, with the entity of its accounts; null for a new one
     * @return array|null
     */
    private function storedEntry(): ?array
    {
        $publicId = $this->jsonInput()['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $stored = model(OtternovaLedgerMasterModel::class)->findByPublicId($publicId);
        if ($stored === null) {
            return null; // saveRecord() answers 404
        }

        return $stored + ['entity_id' => $this->entityOfEntry((int) $stored['id'])];
    }

    /**
     * @param int $entryId
     * @return int|null The entity of the entry's accounts
     */
    private function entityOfEntry(int $entryId): ?int
    {
        $row = $this->db()->table('otternova_ledger_master e')
            ->select('a.entity_id')
            ->join('otternova_ledger_account a', 'a.id = e.from_account_id', 'inner')
            ->where('e.id', $entryId)
            ->get()->getRow();

        return $row === null || $row->entity_id === null ? null : (int) $row->entity_id;
    }

    /**
     * @return LedgerPeriods
     */
    private function periodsLibrary(): LedgerPeriods
    {
        return new LedgerPeriods($this->db());
    }

    /**
     * A report endpoint: entity_public_id and the dates (Y-m-d) are required
     * @param string       $endpoint For the error log
     * @param list<string> $dates    The body's date fields
     * @param callable     $build    fn (LedgerReports, int $entityId, array $dates): ?array, null for dates out of order
     * @return ResponseInterface
     */
    private function report(string $endpoint, array $dates, callable $build): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $errors = [];
        $valid  = [];
        foreach ($dates as $name) {
            $value = $input[$name] ?? null;
            $date  = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                $errors[$name] = lang('Ledger.dateRequired');
            } else {
                $valid[$name] = $value;
            }
        }

        try {
            $entityId = model(OtternovaLedgerEntityModel::class)->idOfPublicId($input['entity_public_id'] ?? null);
            if ($entityId === null) {
                return ApiResponse::error(lang('Ledger.entityNotFound'), 404);
            }
            if ($errors !== []) {
                return ApiResponse::error(lang('Ledger.invalidFields'), 422, $errors);
            }
            $report = $build(new LedgerReports($this->db()), $entityId, $valid);
        } catch (DatabaseException $e) {
            return $this->failed($endpoint, $e, 'Ledger.reportFailed');
        }
        if ($report === null) {
            return ApiResponse::error(lang('Ledger.invalidFields'), 422, ['date_from' => lang('Ledger.datesOutOfOrder')]);
        }

        return ApiResponse::success(['report' => $report]);
    }

    /**
     * Sets base_amount (and base_rate) of an entry's checked row, as saveEntry() describes
     * @param array  $row  By reference
     * @param string $base The entity's base currency
     * @return array<string, string> column => error
     */
    private function baseAmount(array &$row, string $base): array
    {
        if ($row['transaction_currency_code'] === $base || $row['billed_currency_code'] === $base) {
            $row['base_rate']   = null;
            $row['base_amount'] = $row['transaction_currency_code'] === $base ? $row['transaction_amount'] : $row['billed_amount'];

            return [];
        }
        if ($row['base_rate'] === null) {
            $rate = service('exchangeRates')->rate($row['transaction_date'], $row['transaction_currency_code'], $base);
            if ($rate === null) {
                return ['base_rate' => lang('Ledger.rateRequired', [$row['transaction_currency_code'], $base])];
            }
            $row['base_rate'] = $this->normalize('base_rate', $rate, true);
        }
        $row['base_amount'] = Decimal::multiply($row['transaction_amount'], $row['base_rate']);

        return [];
    }

    /**
     * @return \CodeIgniter\Database\BaseConnection
     */
    private function db()
    {
        return db_connect();
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
     * The create-or-update of every .../update endpoint: no public_id creates, a public_id updates that record.
     * Every field is required in the body; an empty string saves NULL in a nullable field.
     * @param Model         $model      One with LedgerWriteTrait
     * @param list<string>  $fields     Columns written
     * @param list<string>  $nullable   Columns of $fields that may be NULL
     * @param string        $langFile   For the field labels in the error messages
     * @param string        $notFound   Language key when the public_id has no record
     * @param string        $endpoint   For the error log
     * @param callable      $summary    fn (array $row, ?array $stored): string, for log_activity
     * @param callable|null $check      fn (array $row): array, column => error for what the rules cannot check;
     *                                  it may take the row by reference to set the columns worked out from it
     * @param callable|null $onCreate   fn (array $row): array, the row with the columns only a create sets
     * @param array         $references Columns of $fields the body names by the public_id of another record:
     *                                  the body's field => [column, model of the record]; their errors go back
     *                                  under the body's field
     * @return ResponseInterface
     */
    private function saveRecord(Model $model, array $fields, array $nullable, string $langFile, string $notFound, string $endpoint, callable $summary, ?callable $check = null, ?callable $onCreate = null, array $references = []): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        $publicId = $input['public_id'] ?? null;
        if ($publicId !== null && ! is_string($publicId)) {
            return ApiResponse::error(lang('Ledger.invalidRequest'), 422);
        }
        // the columns are only ever set from the public_ids
        $input   = array_diff_key($input, array_flip(array_column($references, 0)));
        $toField = array_combine(array_column($references, 0), array_keys($references));
        $errors  = [];

        try {
            $stored = null;
            if ($publicId !== null && $publicId !== '') {
                $stored = $model->findByPublicId($publicId);
                if ($stored === null) {
                    return ApiResponse::error(lang($notFound), 404);
                }
            }
            foreach ($references as $field => [$column, $class]) {
                $value = $input[$field] ?? null;
                if ($value === null || $value === '') {
                    continue; // the rules of the column say whether it is required
                }
                $id = model($class)->idOfPublicId($value);
                if ($id === null) {
                    $errors[$field] = lang('Ledger.referenceNotFound');
                } else {
                    $input[$column] = $id;
                }
            }
        } catch (DatabaseException $e) {
            return $this->failed($endpoint, $e, 'Ledger.loadFailed');
        }

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
        if (! $validation->run($row + ['id' => $stored === null ? '' : (string) $stored['id']])) {
            $errors += $this->renameKeys($validation->getErrors(), $toField);
        }
        if ($errors !== []) {
            return ApiResponse::error(lang('Ledger.invalidFields'), 422, $errors);
        }

        try {
            $errors = $check === null ? [] : $this->renameKeys($check($row), $toField);
            if ($errors !== []) {
                return ApiResponse::error(lang('Ledger.invalidFields'), 422, $errors);
            }

            if ($stored === null) {
                $id = $model->createLogged($onCreate === null ? $row : $onCreate($row), $this->actorId(), $summary($row, null));

                return ApiResponse::success(['public_id' => $model->publicIdOf($id)], 200, lang('Ledger.created'));
            }
            $model->updateLogged($stored, $row, $this->actorId(), $summary($row, $stored));

            return ApiResponse::success(['public_id' => $stored['public_id']], 200, lang('Ledger.updated'));
        } catch (Throwable $e) {
            return $this->failed($endpoint, $e, 'Ledger.saveFailed');
        }
    }

    /**
     * $errors with the keys of $names renamed: column => the body's field
     * @param array<string, string> $errors
     * @param array<string, string> $names
     * @return array<string, string>
     */
    private function renameKeys(array $errors, array $names): array
    {
        $renamed = [];
        foreach ($errors as $key => $error) {
            $renamed[$names[$key] ?? $key] = $error;
        }

        return $renamed;
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
        $places = match (true) {
            in_array($field, self::AMOUNT_FIELDS, true) && preg_match(OtternovaLedgerMasterModel::AMOUNT_PATTERN, $value) === 1 => 4,
            in_array($field, self::RATE_FIELDS, true) && preg_match(OtternovaLedgerMasterModel::RATE_PATTERN, $value) === 1    => 10,
            default                                                                                                          => null,
        };
        if ($places !== null) {
            [$whole, $fraction] = explode('.', $value . '.');
            $value              = ltrim($whole, '0') . '.' . str_pad($fraction, $places, '0');
            $value              = str_starts_with($value, '.') ? '0' . $value : $value;
        }

        return $value === '' && $nullable ? null : $value;
    }

    /**
     * The valid entries of "filters" as OtternovaLedgerMasterModel::LIST_FILTERS takes them: a date (Y-m-d) for
     * date_from and date_to, the id of the record a public_id filter names; one that names none gives 0, which no
     * entry points at
     * @param mixed $filters
     * @return array<string, string|int>
     */
    private function listFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $valid = [];
        foreach (['date_from', 'date_to'] as $name) {
            $value = $filters[$name] ?? null;
            $date  = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date !== false && $date->format('Y-m-d') === $value) {
                $valid[$name] = $value;
            }
        }
        $journal = $filters['journal_id'] ?? null;
        if (is_string($journal) && preg_match('/^[0-9A-Za-z_-]{12}$/', $journal)) {
            $valid['journal_id'] = $journal; // the rows of one transaction
        }
        foreach (self::FILTER_REFERENCES as $field => [$name, $class]) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $valid[$name] = model($class)->idOfPublicId($value) ?? 0;
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
