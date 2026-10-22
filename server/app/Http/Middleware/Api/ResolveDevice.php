<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\ResidentApp\ResidentAccounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the handset speaking, and open its estate (13 D1).
 *
 *   device:guard            a Guard App token only
 *   device:resident         a Resident App token only, from an ACTIVE account
 *   device:resident,pending a Resident App token, pending accounts allowed
 *   device:guard,resident   either
 *
 * THE ESTATE COMES FROM THE PRINCIPAL. A guard's `tenant_id`, a resident
 * account's `tenant_id` — never the request body — and tenancy is initialised
 * here, so every estate read that follows is that estate's database and no
 * other's. The resolved `DeviceContext` is what endpoints read.
 */
final class ResolveDevice
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $principal = Auth::guard('sanctum')->user();
        $allowPending = in_array('pending', $allowed, true);

        if ($principal instanceof Guard && in_array(AppMatrix::GUARD, $allowed, true)) {
            if ($principal->status !== 'active') {
                throw ApiError::forbidden('guard_not_active', $principal->full_name.' is not on active duty, so this handset cannot act for them.');
            }

            $context = $this->open(AppMatrix::GUARD, (string) $principal->tenant_id, $principal, null);
        } elseif ($principal instanceof ResidentAccount && in_array(AppMatrix::RESIDENT, $allowed, true)) {
            /*
             * THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS (Q-M44). Checked before
             * anything else a resident token can reach, and before `reconcile()` — an
             * approved claim must never hand a revoked account its estate back. The
             * sentence says the same thing the guard at the barrier is told, because a
             * person told one thing by the app and another at the gate is the failure
             * this whole invariant is written against.
             */
            if ($principal->isRevoked()) {
                throw ApiError::forbidden(
                    'account_revoked',
                    'The estate office has ended this account\'s access. You are no longer a resident of this unit in the app. '.
                    'If you need to come to the estate, use the Visitor Entrance and the guard will announce you. '.
                    'If this is wrong, contact the estate office.'
                );
            }

            if ($principal->status === ResidentAccount::SUSPENDED) {
                throw ApiError::forbidden('account_suspended', 'The estate has withdrawn this account\'s access. Contact the estate office.');
            }

            $context = $this->open(AppMatrix::RESIDENT, $principal->tenant_id, null, $principal);

            /*
             * A pending account whose claim the estate has since approved becomes
             * active on its next request, from the estate's own record — however
             * the approval was made (console, import, seeder). Nothing has to
             * remember to tell the platform.
             */
            if (! $principal->isActive()) {
                app(ResidentAccounts::class)->reconcile($principal);
            }

            if (! $principal->isActive() && ! $allowPending) {
                if ($this->opened) {
                    tenancy()->end();
                    $this->opened = false;
                }

                throw ApiError::forbidden('account_pending', 'This account has not been linked to a unit yet. Claim your unit, and the estate will approve it.');
            }
        } else {
            throw ApiError::forbidden('wrong_app', 'This endpoint is not for this app.');
        }

        app()->instance(DeviceContext::class, $context);

        try {
            return $next($request);
        } finally {
            /*
             * Closed again once the response exists. A long-lived worker or a test
             * process handles the next request with whatever estate was left open,
             * and a query meant for the platform would land in an estate database.
             */
            if ($this->opened) {
                tenancy()->end();
                $this->opened = false;
            }

            app()->forgetInstance(DeviceContext::class);
        }
    }

    private bool $opened = false;

    private function open(string $app, string $tenantId, ?Guard $guard, ?ResidentAccount $resident): DeviceContext
    {
        $estate = Tenant::query()->find($tenantId);

        if (! $estate instanceof Tenant) {
            throw ApiError::forbidden('no_site', 'This handset is not attached to an estate on this platform.');
        }

        if (tenant()?->getTenantKey() !== $estate->getTenantKey()) {
            tenancy()->initialize($estate);
            $this->opened = true;
        }

        return new DeviceContext($app, $tenantId, $guard, $resident, Carbon::now());
    }
}
