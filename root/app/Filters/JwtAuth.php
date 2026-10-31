<?php

namespace App\Filters;

use App\Libraries\ApiResponse;
use App\Models\AuthAccountModel;
use App\Models\AuthTokenDenylistModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use UnexpectedValueException;

/**
 * Lets a request through only with a valid "Authorization: Bearer <jwt>": signature, exp and nbf check out,
 * the jti has not been signed out, and the account still exists and is active.
 * The controller reads the account from service('authContext').
 */
class JwtAuth implements FilterInterface
{
    /**
     * @param RequestInterface $request
     * @param array|null       $arguments
     * @return ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches)) {
            return ApiResponse::error(lang('Auth.signInRequired'), 401);
        }

        try {
            $claims = service('jwt')->decode($matches[1]);
        } catch (UnexpectedValueException|DomainException) {
            return ApiResponse::error(lang('Auth.sessionExpired'), 401);
        }

        if (model(AuthTokenDenylistModel::class)->isRevoked((string) $claims->jti)) {
            return ApiResponse::error(lang('Auth.sessionExpired'), 401);
        }

        $account = model(AuthAccountModel::class)->findForSessionByUid((string) $claims->sub, (string) $claims->typ);
        if ($account === null) {
            return ApiResponse::error(lang('Auth.sessionExpired'), 401);
        }
        if ($account['account_status'] !== AuthAccountModel::STATUS_ACTIVE) {
            return ApiResponse::error(lang('Auth.accountNotActive'), 403);
        }

        service('authContext')->set($claims, $account);

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
