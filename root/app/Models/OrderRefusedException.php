<?php

namespace App\Models;

use RuntimeException;

/**
 * An order of the mart that MartOrderModel::place() did not place, and why; nothing was written
 * - reason: problems (a line can no longer be had as it is; the quote says which), invalid (the customer's details,
 *   the delivery or the participants), shipping, discount or payment (the choice is not offered)
 * - errors: field => code, as the mart shows them
 * - quote: MartOrderModel::quote()'s answer at the time, for the mart to show again
 */
class OrderRefusedException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $errors, public readonly array $quote)
    {
        parent::__construct('Order refused: ' . $reason);
    }
}
