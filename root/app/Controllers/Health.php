<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * GET /health, for the container's health check (/ is 403 on purpose, see Home): 200 while the API can reach its
 * database, 503 when it cannot. Public, never cached, and says nothing about the setup.
 */
class Health extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $up = db_connect()->query('SELECT 1') !== false;
        } catch (Throwable $e) {
            log_message('error', 'health: {message}', ['message' => $e->getMessage()]);
            $up = false;
        }

        return $this->response->setStatusCode($up ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON(['status' => $up ? 'ok' : 'unavailable']);
    }
}
