<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-scoped work is attempted with no tenant context.
 *
 * Always a bug, never a recoverable condition — which is why there is no
 * "default tenant" branch anywhere in this codebase.
 */
class MissingTenantContextException extends RuntimeException
{
    public static function forJob(string $job): self
    {
        return new self(
            "Job [{$job}] ran with no tenant context. It writes estate data and has no safe default: ".
            'dispatch it from within tenancy, or from an explicit tenant()->run() block. '.
            'Falling back to the central connection or to the last tenant the worker handled '.
            "would write one estate's records into another's."
        );
    }

    public static function forService(string $service): self
    {
        return new self(
            "[{$service}] requires tenant context and was called without one."
        );
    }
}
