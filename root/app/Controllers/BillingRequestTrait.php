<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BillingException;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The request handling the billing controllers (Plans, Subscriptions, SubscriptionAdmin) share
 */
trait BillingRequestTrait
{
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
     * start and length of a list request: 0 and 25 when left out, length at most 500
     * @param array $input
     * @return array{int, int}
     */
    private function page(array $input): array
    {
        $start  = filter_var($input['start'] ?? null, FILTER_VALIDATE_INT);
        $length = filter_var($input['length'] ?? null, FILTER_VALIDATE_INT);

        return [$start === false ? 0 : max(0, $start), $length === false ? 25 : min(500, max(1, $length))];
    }

    /**
     * A string value from the body, trimmed; '' when it is anything else
     * @param array  $input
     * @param string $field
     * @return string
     */
    private function text(array $input, string $field): string
    {
        $value = $input[$field] ?? null;

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * auth_master.id of the signed-in account, for log_activity.changed_by_acc
     * @return int
     */
    private function actorId(): int
    {
        return (int) service('authContext')->account()['id'];
    }

    /**
     * The response to a request the billing rules refused
     * @param BillingException $e
     * @return ResponseInterface
     */
    private function refused(BillingException $e): ResponseInterface
    {
        return ApiResponse::error(lang($e->langKey), $e->status, $e->errors === [] ? null : $e->errors);
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
