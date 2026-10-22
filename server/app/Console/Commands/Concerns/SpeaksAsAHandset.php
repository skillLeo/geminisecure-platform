<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Guard;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a simulator command speak to /api/v1 the way a real handset does.
 *
 * WHY THIS EXISTS AT ALL. Every /api/v1 endpoint is behind `auth:sanctum` and an
 * ability. `simulate:alerts` was written before that was true and never updated,
 * so it had been answering 401 on every request and reporting "Raised 0 of 5"
 * as though the simulation had merely gone badly. A simulator that cannot
 * authenticate is not simulating anything.
 *
 * IT ENROLS A REAL HANDSET AND REVOKES IT AFTERWARDS. The alternative — letting
 * the simulator bypass the middleware with `Sanctum::actingAs` — would exercise
 * the controllers and skip the two things most likely to be wrong: whether the
 * route is behind the right ability, and whether a token that lacks it is
 * refused. So the simulator enrols, speaks over the wire with a bearer token,
 * and hands the handset back.
 *
 * The guard it borrows is a real one at the estate being simulated, which also
 * means the events it writes carry a plausible guard and post rather than nulls.
 */
trait SpeaksAsAHandset
{
    private ?string $handsetToken = null;

    private ?Guard $handsetGuard = null;

    /**
     * Borrow a handset belonging to a guard at this estate.
     *
     * Returns false when the estate has no active guard to borrow from, which
     * is a real state — an estate mid-onboarding has none — and not an error.
     */
    private function borrowHandset(string $tenantId): bool
    {
        $guard = Guard::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($guard === null) {
            return false;
        }

        return $this->borrowHandsetFrom($guard);
    }

    /** Borrow THIS guard's handset — a shift is clocked from its own guard's (13 D1). */
    private function borrowHandsetFrom(Guard $guard): bool
    {
        if ($guard->status !== 'active') {
            return false;
        }

        $this->handsetGuard = $guard;
        $this->handsetToken = app(DeviceEnrolment::class)->enrol($guard, 'Simulator handset')['token'];

        return true;
    }

    /**
     * Hand the handset back.
     *
     * ALWAYS CALLED, including when a run fails part way. A simulator that left
     * a working token behind would be leaving a credential on the machine every
     * time somebody ran it, and the guard it borrowed would show as carrying a
     * bound handset they do not have — which `AdoptionRollup` would then count
     * as Guard App coverage.
     */
    private function returnHandset(): void
    {
        if ($this->handsetGuard !== null) {
            app(DeviceEnrolment::class)->revoke($this->handsetGuard);
        }

        $this->handsetGuard = null;
        $this->handsetToken = null;
    }

    /** The guard whose handset is currently borrowed, if any. */
    private function handsetGuard(): ?Guard
    {
        return $this->handsetGuard;
    }

    /**
     * Fire one request through the real HTTP kernel, with the bearer token.
     *
     * Through the kernel rather than by calling the controller, so the route's
     * middleware, its ability check, its validation and its route binding are
     * all exercised rather than bypassed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}
     */
    private function callApi(string $path, array $payload = []): array
    {
        $request = Request::create('http://localhost'.$path, 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($payload));

        $request->headers->set('Content-Type', 'application/json');

        if ($this->handsetToken !== null) {
            $request->headers->set('Authorization', 'Bearer '.$this->handsetToken);
        }

        // The Sanctum guard caches its principal; each handset speaks for itself.
        $sanctum = Auth::guard('sanctum');

        if ($sanctum instanceof RequestGuard) {
            $sanctum->forgetUser();
            $sanctum->setRequest($request);
        }

        try {
            $response = app(HttpKernel::class)->handle($request);
        } finally {
            if ($sanctum instanceof RequestGuard) {
                $sanctum->forgetUser();
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
        ];
    }
}
