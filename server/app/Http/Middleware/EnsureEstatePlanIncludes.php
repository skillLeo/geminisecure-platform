<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Estate\PlanGate;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A module this estate's plan leaves out answers with a page that says so (Hassam's closing order, 12 Oct 2026: "a
 * deep link to one shows a clean 'not included in your plan' page, never a stack trace").
 *
 * Asked before the module's own permission, because "not in your plan" is the truer answer for everybody: a role that
 * could not open the module anyway learns nothing it could act on from being told about its permissions. A read is the
 * page, with a 403; a write is refused back to where it came from with the same sentence, in the toast, and nothing is
 * changed. Which modules a plan can leave out, and how that is read, is `PlanGate`'s.
 *
 *   ->middleware(EnsureEstatePlanIncludes::class.':estate_payroll')
 */
final class EnsureEstatePlanIncludes
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenantKey = (string) tenant()?->getTenantKey();

        if (! PlanGate::leavesOut($feature, $tenantKey)) {
            return $next($request);
        }

        $plan = PlanGate::forEstate($tenantKey);
        $module = PlanGate::LABELS[$feature] ?? 'This module';
        $sentence = sprintf('%s is not included in the %s plan.', $module, (string) $plan['plan_name']);

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return back()->with('error', $sentence.' Nothing was changed.');
        }

        return Inertia::render('Estate/NotInPlan', [
            'estate' => ['name' => (string) tenant()?->name],
            'module' => $module,
            'planName' => (string) $plan['plan_name'],
        ])->toResponse($request)->setStatusCode(403);
    }
}
