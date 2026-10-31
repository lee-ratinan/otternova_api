<?php

namespace App\Libraries;

use stdClass;

/**
 * The signed-in account of the current request, set by the jwt filter for the controllers behind it
 */
class AuthContext
{
    private ?stdClass $claims = null;
    private ?array $account   = null;

    /**
     * @param stdClass $claims  The decoded access token
     * @param array    $account AuthAccountModel row, without password_hash
     * @return void
     */
    public function set(stdClass $claims, array $account): void
    {
        $this->claims  = $claims;
        $this->account = $account;
    }

    /**
     * @return stdClass|null
     */
    public function claims(): ?stdClass
    {
        return $this->claims;
    }

    /**
     * @return array|null
     */
    public function account(): ?array
    {
        return $this->account;
    }
}
