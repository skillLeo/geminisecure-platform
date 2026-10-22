<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 anywhere but the platform's own host (M14 §3.1: the client's site is served "on the root hostname only").
 *
 * The routes in routes/web.php answer on every host; an estate's host is only different where routes/tenant.php
 * has a path of its own. The client's `/contact` and `/privacy-policy` must not appear on
 * phoenixparkvillage1.geminisl.com, so they carry this. A root host is one of `tenancy.central_domains`: the estate
 * domain itself (geminisl.com), and localhost and 127.0.0.1 for development.
 */
final class OnlyOnTheRootHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $roots = array_map('strtolower', (array) config('tenancy.central_domains'));

        if (! in_array(strtolower($request->getHost()), $roots, true)) {
            abort(404);
        }

        return $next($request);
    }
}
