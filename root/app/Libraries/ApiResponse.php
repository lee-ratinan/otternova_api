<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * The JSON envelope every endpoint answers with:
 * { "status": "success", "data": {...} } or { "status": "error", "message": "..." }
 */
class ApiResponse
{
    /**
     * @param array|null $data Left out of the body when null
     * @param int        $status
     * @return ResponseInterface
     */
    public static function success(?array $data = null, int $status = 200): ResponseInterface
    {
        $body = ['status' => 'success'];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return service('response')->setStatusCode($status)->setJSON($body);
    }

    /**
     * @param string $message Shown to the user as is, so keep it friendly and free of internals
     * @param int    $status
     * @return ResponseInterface
     */
    public static function error(string $message, int $status): ResponseInterface
    {
        return service('response')->setStatusCode($status)->setJSON(['status' => 'error', 'message' => $message]);
    }
}
