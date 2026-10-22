<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops the `{tenant}` domain parameter after tenancy has been initialised.
 *
 * Estate routes are declared as Route::domain('{tenant}.'.$estateDomain), and
 * Laravel passes domain parameters to the controller BEFORE route parameters.
 * stancl's subdomain middleware resolves the tenant from it but does not
 * remove it, so without this every action would receive the subdomain as its
 * first argument:
 *
 *     resident(string $id)   // $id === 'phoenixpark', not '1'
 *
 * That failure is quiet and dangerous in exactly this context. Casting the
 * subdomain to int yields 0, findOrFail(0) raises ModelNotFound, and the route
 * answers 404 — which in an isolation test is indistinguishable from a
 * correctly denied cross-estate request. The gate would pass while proving
 * nothing.
 *
 * Removing the parameter here keeps controllers ignorant of the transport:
 * the tenant is already available through tenant(), which is the one place it
 * should ever be read from.
 */
class ForgetTenantRouteParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->route()?->forgetParameter('tenant');

        return $next($request);
    }
}
