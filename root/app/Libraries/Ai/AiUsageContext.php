<?php

namespace App\Libraries\Ai;

use InvalidArgumentException;

/**
 * Whose call it is and what it is for, so its tokens and attempts can be logged (ai_usage_log): the business, the
 * artifact or the webpage (at most one; neither for a business-wide feature), and the account that asked (null for
 * a job). The caller takes businessId from the artifact's or page's own row, so the two always match.
 */
final class AiUsageContext
{
    public function __construct(
        public readonly int $businessId,
        public readonly ?int $artifactId = null,
        public readonly ?int $webpageId = null,
        public readonly ?int $requestedBy = null,
    ) {
        if ($artifactId !== null && $webpageId !== null) {
            throw new InvalidArgumentException('An AI call is for an artifact or a webpage, not both');
        }
    }

    public static function forArtifact(int $businessId, int $artifactId, ?int $requestedBy = null): self
    {
        return new self($businessId, $artifactId, null, $requestedBy);
    }

    public static function forWebpage(int $businessId, int $webpageId, ?int $requestedBy = null): self
    {
        return new self($businessId, null, $webpageId, $requestedBy);
    }

    public static function forBusiness(int $businessId, ?int $requestedBy = null): self
    {
        return new self($businessId, null, null, $requestedBy);
    }
}
