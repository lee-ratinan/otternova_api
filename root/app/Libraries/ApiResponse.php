<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * The JSON envelope every endpoint answers with:
 * { "status": "success", "message": "...", "data": {...} } or { "status": "error", "message": "...", "errors": {...} },
 * where message, data and errors are left out when there are none
 */
class ApiResponse
{
    /**
     * @param array|null  $data    Left out of the body when null
     * @param int         $status
     * @param string|null $message Shown to the user as is; left out of the body when null
     * @return ResponseInterface
     */
    public static function success(?array $data = null, int $status = 200, ?string $message = null): ResponseInterface
    {
        $body = ['status' => 'success'];
        if ($message !== null) {
            $body['message'] = $message;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }

        return service('response')->setStatusCode($status)->setJSON($body);
    }

    /**
     * @param string                     $message Shown to the user as is, so keep it friendly and free of internals
     * @param int                        $status
     * @param array<string, string>|null $errors  Field => message, for a form to highlight; left out when null
     * @return ResponseInterface
     */
    public static function error(string $message, int $status, ?array $errors = null): ResponseInterface
    {
        $body = ['status' => 'error', 'message' => $message];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return service('response')->setStatusCode($status)->setJSON($body);
    }
}
