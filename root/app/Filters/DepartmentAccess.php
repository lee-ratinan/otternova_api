<?php

namespace App\Filters;

use App\Libraries\ApiResponse;
use App\Models\OtternovaDepartmentModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Lets a staff member through only when their staff_master department is one of the arguments:
 * 'department:EXE,FIN' lets the Executive Office and Finance through. Goes after 'jwt:S', which sets the account.
 */
class DepartmentAccess implements FilterInterface
{
    /**
     * @param RequestInterface $request
     * @param array|null       $arguments otternova_department.department_code values allowed; nobody when empty
     * @return ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $account = service('authContext')->account();
        if ($account === null) {
            return ApiResponse::error(lang('Auth.signInRequired'), 401);
        }

        try {
            $code = model(OtternovaDepartmentModel::class)->codeOfStaff((int) $account['id']);
        } catch (DatabaseException $e) {
            log_message('error', 'department filter: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Auth.departmentCheckFailed'), 500);
        }
        if ($code === null || ! in_array($code, $arguments ?? [], true)) {
            return ApiResponse::error(lang('Auth.wrongDepartment'), 403);
        }

        return null;
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     * @return void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
