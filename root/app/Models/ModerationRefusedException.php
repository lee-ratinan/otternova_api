<?php

namespace App\Models;

use RuntimeException;

/**
 * A review or moderation action that ModerationEventModel::act() refused, as its subject stands (removed already, not
 * blocked, and so on); nothing was written. langKey is the Moderation language line saying why.
 */
class ModerationRefusedException extends RuntimeException
{
    public function __construct(public readonly string $langKey)
    {
        parent::__construct('Moderation refused: ' . $langKey);
    }
}
