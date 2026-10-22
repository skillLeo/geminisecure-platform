<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second gate on every estate request.
 *
 * The database boundary already makes a cross-estate READ impossible: the
 * estate connection authenticates as that estate's own MySQL user, which holds
 * no grant on any other estate. This middleware exists for the case the grant
 * cannot see — an authenticated user of estate A arriving at estate B's
 * subdomain, where the connection is legitimately B's and the query would
 * succeed.
 *
 * 404 rather than 403, deliberately: a 403 confirms the estate exists and that
 * the record has a real id, which is a small enumeration oracle across
 * competing communities. From outside, an estate you cannot reach is simply
 * not there.
 */
class EnsureEstateAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant === null) {
            // Reachable only through a misconfigured route group: this
            // middleware must always sit after tenancy initialisation.
            abort(500, 'EnsureEstateAccess ran with no tenant context.');
        }

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        // Tenant identity comes from the resolved subdomain and from nowhere
        // else. Nothing here reads the request body, a query string or a
        // hidden field.
        if (! $user->canAccessEstate((string) $tenant->getTenantKey())) {
            abort(404);
        }

        return $next($request);
    }
}
