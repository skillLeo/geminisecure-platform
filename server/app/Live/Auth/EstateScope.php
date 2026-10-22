<?php

declare(strict_types=1);

namespace App\Live\Auth;

use App\Models\Tenant;
use Closure;
use RuntimeException;

/**
 * RUN SOMETHING INSIDE ONE ESTATE, AND ALWAYS LEAVE AGAIN (M11 Part C, DESIGN §3.5).
 *
 * `Tenant::run()` initialises tenancy, calls back and restores the previous state — but
 * with no `finally` (vendor/stancl/tenancy/src/Database/Concerns/TenantRun.php): a
 * callback that throws leaves the estate OPEN, and whatever the process does next runs
 * against that estate's database. For a web request that is the rest of the request;
 * for the relay or a probe it is every estate after it. So this is the same act with
 * the restore in a `finally`, whatever the callback does.
 *
 *   - Already inside this estate: the callback runs as it is, and the estate stays open
 *     for whoever opened it (the API's `ResolveDevice` has opened the handset's own
 *     estate before any live rule runs).
 *   - Inside another estate: that one is put back afterwards.
 *   - Inside none: tenancy is ended afterwards.
 *
 * WHY A CHANNEL'S `can:` IS ASKED IN HERE AT ALL. The estate page it mirrors asks its
 * `can:` with the estate open — `routes/tenant.php` initialises tenancy before `auth` —
 * so the channel asks it the same way and inherits whatever `can()` means inside an
 * estate, per-estate roles and the permission cache included. Callers open an estate
 * only after the central checks (`canAccessEstate()` above all) have passed, so a
 * foreign estate is never opened for somebody who does not belong there.
 */
final class EstateScope
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $tenantId, Closure $callback): mixed
    {
        $previous = tenant();

        if ($previous !== null && (string) $previous->getTenantKey() === $tenantId) {
            return $callback();
        }

        $estate = Tenant::query()->find($tenantId);

        if (! $estate instanceof Tenant) {
            throw new RuntimeException("There is no estate [{$tenantId}] to open.");
        }

        try {
            tenancy()->initialize($estate);

            return $callback();
        } finally {
            if ($previous !== null) {
                tenancy()->initialize($previous);
            } else {
                tenancy()->end();
            }
        }
    }
}
