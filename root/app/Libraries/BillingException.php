<?php

namespace App\Libraries;

use RuntimeException;

/**
 * A billing request refused by its rules (SubscriptionBilling), carrying what the API answers with
 */
class BillingException extends RuntimeException
{
    /**
     * @param string                $langKey Shown to the user
     * @param int                   $status  HTTP status
     * @param array<string, string> $errors  Field => message, for a form to highlight
     */
    public function __construct(public readonly string $langKey, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($langKey);
    }
}
