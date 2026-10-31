<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Models\AuthAccountModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * auth_master accounts for the Admin app, behind the jwt:S filter (staff only)
 */
class Accounts extends BaseController
{
    private const DEFAULT_LENGTH = 25;
    private const MAX_LENGTH     = 500;

    /**
     * POST /accounts { start, length, search, order: [{field, dir}], filters: {column: value} }
     * One page of the server-side DataTables list. Every part of the body is optional, and anything
     * outside the whitelists in AuthAccountModel is ignored rather than rejected.
     * @return ResponseInterface
     */
    public function list(): ResponseInterface
    {
        $input = $this->jsonInput();
        if ($input === null) {
            return ApiResponse::error(lang('Accounts.invalidRequest'), 422);
        }

        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $start  = $start === false ? 0 : max(0, $start);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);
        $length = $length === false ? self::DEFAULT_LENGTH : min(self::MAX_LENGTH, max(1, $length));
        // a number counts too: a telephone search may arrive as one
        $search = $input['search'] ?? null;
        $search = is_string($search) || is_int($search) ? trim((string) $search) : '';

        try {
            $data = model(AuthAccountModel::class)->listAccounts(
                $start,
                $length,
                $search,
                $this->listFilters($input['filters'] ?? null),
                $this->listOrder($input['order'] ?? null)
            );
        } catch (DatabaseException $e) {
            log_message('error', 'v1/accounts: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Accounts.listFailed'), 500);
        }

        return ApiResponse::success($data);
    }

    /**
     * The valid entries of "filters": a known column with one of its known values
     * @param mixed $filters
     * @return array<string, string>
     */
    private function listFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $valid = [];
        foreach (AuthAccountModel::LIST_FILTERS as $column => $values) {
            if (in_array($filters[$column] ?? null, $values, true)) {
                $valid[$column] = $filters[$column];
            }
        }

        return $valid;
    }

    /**
     * The valid entries of "order", each column once; email_address ASC when none is left
     * @param mixed $order
     * @return list<array{string, string}>
     */
    private function listOrder(mixed $order): array
    {
        $valid = [];
        foreach (is_array($order) ? $order : [] as $item) {
            $field = is_array($item) ? ($item['field'] ?? null) : null;
            if (! in_array($field, AuthAccountModel::LIST_ORDER_FIELDS, true) || isset($valid[$field])) {
                continue;
            }
            $dir           = is_string($item['dir'] ?? null) ? strtolower($item['dir']) : '';
            $valid[$field] = [$field, $dir === 'desc' ? 'DESC' : 'ASC'];
        }

        return $valid === [] ? [['email_address', 'ASC']] : array_values($valid);
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
