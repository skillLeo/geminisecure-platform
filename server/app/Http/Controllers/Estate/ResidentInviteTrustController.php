<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\ResidentInviteTrust;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Trust the main resident's invites" — Settings → Features, "Resident App" (household invites, stage 2;
 * DESIGN §7.4, D-ACCEPT-2).
 *
 * TWO PERMISSIONS, BECAUSE IT IS TWO ACTS. The route asks `estate.settings.configure`, the verb that changes
 * the shape of what the estate runs, as every feature switch does. Turning this on also hands the household's
 * primary resident the office's decision to bind a person to a household, and that decision is
 * `estate.residents.approve` (DESIGN INV-7) — so somebody who could not approve a claim by hand cannot make
 * every future claim approve itself.
 */
class ResidentInviteTrustController extends Controller
{
    public function __invoke(Request $request, ResidentInviteTrust $trust): RedirectResponse
    {
        if (! $request->user()->can('estate.residents.approve')) {
            return back()->withErrors([
                'invite_trust' => 'Trusting the main resident’s invites hands them the approval of a unit claim, so it needs Residents approve access as well as Settings configure. Ask somebody who approves unit claims.',
            ]);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:4', 'max:300'],
        ]);

        try {
            $trust->set($request->boolean('enabled'), (string) $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['invite_trust' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/settings/features'))
            ->with('success', $request->boolean('enabled')
                ? 'The main resident’s invites are now trusted: their invitees are linked when they sign in with the invited address.'
                : 'The main resident’s invites now wait for one tap in Unit claims.');
    }

    /** Where a screen lives, in whichever shape this environment serves (see SettingsController::path). */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
