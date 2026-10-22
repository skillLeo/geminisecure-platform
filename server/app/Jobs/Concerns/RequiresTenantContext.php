<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Exceptions\MissingTenantContextException;

/**
 * A job that touches estate data and must never run without knowing which
 * estate it belongs to.
 *
 * stancl's QueueTenancyBootstrapper serialises the tenant id with the job and
 * restores it on the worker. When that fails — a job queued before tenancy was
 * initialised, a payload replayed from a dead letter queue, a developer
 * dispatching from a console command — the context is simply absent.
 *
 * The dangerous outcome is not an error. It is a job that quietly falls back to
 * the central connection or to whichever tenant the worker last handled, and
 * writes one estate's charge into another estate's ledger. So this throws, and
 * never defaults.
 *
 * Use by calling assertTenantContext() at the top of handle().
 */
trait RequiresTenantContext
{
    protected function assertTenantContext(): void
    {
        if (tenant() === null) {
            throw MissingTenantContextException::forJob(static::class);
        }
    }
}
