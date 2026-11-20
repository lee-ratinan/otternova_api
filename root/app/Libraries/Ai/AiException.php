<?php

namespace App\Libraries\Ai;

use RuntimeException;
use Throwable;

/**
 * No usable answer from the model. $kind says why, so a feature can tell "try again later" from "this will never
 * work"; the message is for the log and never holds the API key.
 */
class AiException extends RuntimeException
{
    /** No answer in time */
    public const TIMEOUT = 'timeout';

    /** Too many requests or out of quota at the provider (HTTP 429) */
    public const RATE_LIMITED = 'rate_limited';

    /** The provider failed or could not be reached (HTTP 5xx, network) */
    public const UNAVAILABLE = 'unavailable';

    /** The prompt or the answer was blocked by the provider's safety filters */
    public const BLOCKED = 'blocked';

    /** An answer came back but cannot be used (empty, not the JSON asked for, cut short) */
    public const BAD_RESPONSE = 'bad_response';

    /** The provider refused the request itself (HTTP 400/404: a bad schema, an unknown model) */
    public const INVALID_REQUEST = 'invalid_request';

    /** No API key, or the provider refused it (HTTP 401/403) */
    public const NOT_CONFIGURED = 'not_configured';

    public const KINDS = [self::TIMEOUT, self::RATE_LIMITED, self::UNAVAILABLE, self::BLOCKED, self::BAD_RESPONSE,
        self::INVALID_REQUEST, self::NOT_CONFIGURED];

    public function __construct(public readonly string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Whether the same request may work if sent again later */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::TIMEOUT, self::RATE_LIMITED, self::UNAVAILABLE], true);
    }
}
