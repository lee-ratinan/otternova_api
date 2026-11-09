<?php

namespace App\Models;

use RuntimeException;

/**
 * Thrown inside ServiceSessionModel's transaction when a person or a resource a slot would hold is held at the same
 * time by another session; the transaction rolls back, and the caller answers with $errors
 */
class SlotConflictException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field (slots.<index>.staff, slots.<index>.resources) => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('A person or a resource is held by another session at that time.');
    }
}
