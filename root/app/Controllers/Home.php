<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Home extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response->setStatusCode(403)
            ->setJSON(['message' => 'Access denied']);
    }
}
