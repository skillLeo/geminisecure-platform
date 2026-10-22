<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ConsoleHome;
use App\Support\DemonstrationAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-click sign-in as any role. LOCAL DEVELOPMENT ONLY.
 *
 * This is an authentication bypass. It exists so the 14 system roles can be
 * compared without maintaining 14 sets of credentials, and it must never be reachable
 * anywhere but a developer's machine.
 *
 * THREE INDEPENDENT GUARDS, deliberately redundant:
 *
 *   1. routes/web.php registers these routes only when app()->isLocal()
 *   2. every action calls assertLocal() again, so the controller is inert even
 *      if a route were registered by mistake
 *   3. the accounts it creates are marked with a recognisable email domain and
 *      an unusable random password, so they cannot be signed into by hand and
 *      are trivial to find and delete
 *
 * Guard 2 matters because guard 1 is a single line in a route file, and a
 * single line is exactly the kind of thing that gets moved during a refactor.
 */
class QuickLoginController extends Controller
{
    /** Recognisable, and never a real address. `DemonstrationAccounts` reads the same constant. */
    private const DEMO_DOMAIN = DemonstrationAccounts::QUICK_LOGIN_DOMAIN;

    public function __invoke(string $roleName): RedirectResponse
    {
        $this->assertLocal();

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            abort(404);
        }

        $user = $this->seededHolderOf($role) ?? $this->demoUserFor($role);

        /*
         * PRE-VERIFIED FOR ALERTS (M10 §1.4), both kinds. This bypass exists to compare
         * what each role SEES, and the fidelity harness signs in through it; landing
         * every role on the contact gate's verification page would make that comparison
         * a comparison of one page. The demo account is on `quicklogin.local` and the
         * seeded holder on a seeder's own domain, so `DemonstrationAccounts` accepts
         * both — and would refuse a real person's account if one were ever picked.
         */
        DemonstrationAccounts::verify($user);

        Auth::guard('web')->login($user);
        session()->regenerate();

        // The same answer the real sign-in form uses. This logic lived here
        // first and was not copied into LoginController, which is how the real
        // form kept sending estate users to the Gemini dashboard long after
        // the bypass stopped doing it.
        return redirect(ConsoleHome::for($user));
    }

    /**
     * The real committee member who holds this role at the board's estate.
     *
     * WHY A REAL PERSON RATHER THAN A DEMO ACCOUNT. Every Estate Console board
     * names the person in its sidebar footer and its topbar chip — "Tracey
     * Reid, Treasurer" — and the fidelity harness signs in through this route.
     * Signed in as "Treasurer (demo)" every estate screen carries a name the
     * board does not draw, in two places, and reports it as a styling
     * difference.
     *
     * Phoenix Park only, and only for an estate role: it is the estate every
     * board is drawn from, and its committee is seeded with the boards' own
     * names for exactly this reason. Any role with nobody seeded falls back to
     * the demo account, so the thirteen-role comparison still works.
     */
    private function seededHolderOf(Role $role): ?User
    {
        if ($role->console !== Console::Estate) {
            return null;
        }

        $estate = Tenant::find('phoenixpark');

        if ($estate === null) {
            return null;
        }

        return User::query()
            ->where('console', Console::Estate->value)
            ->where('status', 'active')
            ->whereHas('assignments', fn ($assignment) => $assignment
                ->where('tenant_id', $estate->getTenantKey())
                ->where('is_active', true))
            ->whereHas('roles', fn ($roles) => $roles->where('name', $role->name))
            ->first();
    }

    /**
     * A demo account holding exactly this role.
     *
     * Re-roled rather than duplicated, so repeated use does not fill the users
     * table with near-identical rows.
     */
    private function demoUserFor(Role $role): User
    {
        $email = Str::of($role->name)->replace('.', '-')->append('@'.self::DEMO_DOMAIN)->value();

        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            // Random and never disclosed: this account is reachable only
            // through this local-only route, never through the sign-in form.
            $user->password = Str::password(32);
        }

        $user->forceFill([
            'name' => $role->label.' (demo)',
            'console' => $role->console->value,
            'status' => 'active',
        ])->save();

        $user->syncRoles([$role->name]);

        /*
         * Estate roles need somewhere to be. Without an assignment,
         * canAccessEstate() correctly refuses them everything, and the button
         * would appear broken rather than demonstrating the role.
         */
        if ($role->console === Console::Estate) {
            /*
             * Phoenix Park by preference, and it matters. It is the estate every
             * board is drawn from — 450 units, six months of dues, Lot 47 owing
             * J$12,400 — and the fidelity harness signs in through this route.
             * Landed in Ocean View, which is mid-onboarding and has no dues
             * history by design, every money screen would measure an empty
             * state against a populated board.
             */
            $estate = Tenant::find('phoenixpark') ?? Tenant::estates()->first();

            if ($estate !== null) {
                EstateAssignment::updateOrCreate(
                    ['user_id' => $user->id, 'tenant_id' => $estate->getTenantKey()],
                    ['role_id' => $role->id, 'is_active' => true],
                );
            }
        }

        return $user->fresh();
    }

    /**
     * Refuse to run anywhere but local.
     *
     * A RuntimeException rather than abort(404): if this is ever reached off a
     * developer machine, that is a deployment fault worth a stack trace and an
     * alert, not a quiet not-found.
     */
    private function assertLocal(): void
    {
        if (! app()->isLocal() || app()->environment('production')) {
            throw new RuntimeException(
                'Quick login is a local development bypass and must never run outside APP_ENV=local. '
                .'Reaching this line in any other environment is a deployment fault.'
            );
        }
    }
}
