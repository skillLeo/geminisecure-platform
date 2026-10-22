<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console as ConsoleEnum;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Navigation\ConsoleNavigation;
use App\Support\DemonstrationAccounts;
use App\Support\NamedActs;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Phase 2 acceptance gate: console access follows the role matrix.
 *
 *   php artisan gate:console
 *
 * Proves cross-cutting rule 9 end to end - that navigation is GENERATED from
 * the matrix rather than hand-written, and that a module absent from a role's
 * navigation is also unreachable by URL rather than merely hidden.
 *
 * Pure ASCII, for the same reason as GateTenantIsolation.
 */
class GateConsoleAccess extends Command
{
    protected $signature = 'gate:console';

    protected $description = 'Prove console navigation and route gating follow the role access matrix';

    private int $failures = 0;

    /**
     * [role, expected module count, a module they HOLD, a module they do NOT]
     *
     * Read straight off the approved matrix screen. If a wireframe cell
     * changes, this list must change with it - that is the point.
     *
     * A role may have more than one row: each row is one held module and one
     * refused one, and every row re-asserts the count. The Admin Assistant has
     * three because M10 section 5 turns on it - the two modules that own money,
     * Billing & subscriptions and Payroll & Accounting, must stay out of its
     * navigation, and each is paired with an operational module it does hold
     * (Dispatch, Guard workforce) so a refusal can never pass on a sidebar that
     * simply came back empty.
     */
    private const EXPECTATIONS = [
        [Role::DIRECTOR, 9, 'platform_settings', null],
        [Role::DISPATCHER, 4, 'guard_workforce', 'payroll_accounting'],
        [Role::ACCOUNTANT, 6, 'payroll_accounting', 'platform_settings'],
        [Role::ADMIN_ASSISTANT, 4, 'clients', 'access_audit_log'],
        [Role::ADMIN_ASSISTANT, 4, 'dispatch', 'billing_subscriptions'],
        [Role::ADMIN_ASSISTANT, 4, 'guard_workforce', 'payroll_accounting'],
        [Role::HEAD_OF_SECURITY, 5, 'clients', 'billing_subscriptions'],
        [Role::OPERATIONS_MANAGER, 8, 'access_audit_log', 'platform_settings'],
    ];

    public function handle(ConsoleNavigation $navigation): int
    {
        /*
         * From a clean cache, always (M10 section 4). Roles can be re-granted at
         * runtime now, and the permission cache lives for 24 hours in the
         * central store; a gate that read a day-old copy would pass or fail on
         * what the matrix USED to say.
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->line('');
        $this->line('=====================================================================');
        $this->line(' PHASE 2 GATE - console navigation is generated from the role matrix');
        $this->line('=====================================================================');

        $this->assertUnauthenticatedIsRedirected();
        $this->assertNavigationMatchesMatrix($navigation);
        $this->assertForbiddenRoutesAreUnreachable();
        $this->assertBuiltScreensRender();

        $this->line('');

        if ($this->failures > 0) {
            $this->error(" GATE FAILED - {$this->failures} assertion(s) did not hold");

            return self::FAILURE;
        }

        $this->info(' GATE PASSED - every assertion held');

        return self::SUCCESS;
    }

    private function assertUnauthenticatedIsRedirected(): void
    {
        $this->section('Unauthenticated access');

        $response = app(HttpKernel::class)->handle(Request::create('http://localhost/dashboard', 'GET'));

        $this->assert(
            $response->getStatusCode() === 302,
            'anonymous request to /dashboard is redirected, got '.$response->getStatusCode(),
            'expected a 302 to the sign-in screen',
        );

        // There is no registration route anywhere in this application.
        $register = app(HttpKernel::class)->handle(Request::create('http://localhost/register', 'GET'));

        $this->assert(
            $register->getStatusCode() === 404,
            'no public registration endpoint exists, /register gives '.$register->getStatusCode(),
            'a registration route is reachable; accounts must be issued, never self-created',
        );
    }

    private function assertNavigationMatchesMatrix(ConsoleNavigation $navigation): void
    {
        $this->section('Navigation generated per role');

        foreach (self::EXPECTATIONS as [$roleName, $expectedCount, $shouldHold, $shouldNotHold]) {
            $role = Role::named($roleName);
            $user = $this->probeUserFor($role);

            $nav = $navigation->for($user, ConsoleEnum::Gemini);
            $keys = array_column($nav, 'key');

            $this->assert(
                count($keys) === $expectedCount,
                sprintf('%-22s %d modules', $role->label, count($keys)),
                "expected {$expectedCount}, got ".count($keys).': '.implode(', ', $keys),
            );

            $this->assert(
                in_array($shouldHold, $keys, true),
                sprintf('%-22s can see %s', $role->label, $shouldHold),
                "{$shouldHold} missing from navigation",
            );

            if ($shouldNotHold !== null) {
                $this->assert(
                    ! in_array($shouldNotHold, $keys, true),
                    sprintf('%-22s cannot see %s', $role->label, $shouldNotHold),
                    "LEAK: {$shouldNotHold} appeared in navigation",
                );
            }
        }
    }

    /**
     * A module absent from navigation must also be unreachable by URL.
     *
     * Hiding a link is not access control. This walks up to the route the
     * sidebar refuses to render and confirms the server refuses it too.
     */
    private function assertForbiddenRoutesAreUnreachable(): void
    {
        $this->section('Hidden modules are unreachable by URL, not merely unlinked');

        $dispatcher = $this->probeUserFor(Role::named(Role::DISPATCHER));

        Auth::guard('web')->login($dispatcher);
        BindSessionToItsDoor::madeAt('localhost');
        $response = app(HttpKernel::class)->handle(Request::create('http://localhost/dashboard', 'GET'));
        Auth::guard('web')->logout();

        $this->assert(
            $response->getStatusCode() === 200,
            'CONTROL - Dispatcher CAN reach a module they hold (/dashboard), got 200',
            'got '.$response->getStatusCode().'; the denial below proves nothing if this fails',
        );

        // The Dispatcher holds no level on Platform settings. There is no such
        // route yet, so this asserts the permission itself rather than a 403 -
        // the route will inherit the same gate when it is built.
        $this->assert(
            ! $dispatcher->can('gemini.platform_settings.view'),
            'Dispatcher is denied gemini.platform_settings.view at the gate',
            'LEAK: the permission was granted',
        );

        $this->assert(
            ! $dispatcher->can('gemini.payroll_accounting.view'),
            'Dispatcher is denied gemini.payroll_accounting.view at the gate',
            'LEAK: the permission was granted',
        );

        /*
         * The power to change what a role may do (M10 section 4). Held by the
         * Director and nobody else among the seeded six: every route that
         * creates, re-grants or deletes a role is gated on it.
         */
        $this->assert(
            ! $dispatcher->can('gemini.platform_settings.approve'),
            'Dispatcher is denied gemini.platform_settings.approve, the power to change roles',
            'LEAK: the permission was granted',
        );

        $this->assert(
            $this->probeUserFor(Role::named(Role::DIRECTOR))->can('gemini.platform_settings.approve'),
            'CONTROL - the Director holds gemini.platform_settings.approve',
            'nobody on the Gemini Console could change a role; re-run RbacMatrixSeeder',
        );

        $this->assertSplitVerbsAreOnePermissionPerPower();
    }

    /**
     * ONE PERMISSION PER POWER (M11 B3, D-150). Platform settings' `configure`
     * was the prices, the platform's mail and SMS relay and the simulator at
     * once; Billing & subscriptions' `update` was pricing a client, raising an
     * invoice, resending one and writing some of it off. Each is one permission
     * per power now, and the verb's own permission is gone.
     *
     * A retired permission that came back would be a gate nobody is granted
     * (every route names an act), and an act the Director lost would leave
     * nobody able to price the platform, reach its relay or bill a client. The
     * seeded Dispatcher holds nothing on either module, so it holds none of the
     * acts.
     */
    private function assertSplitVerbsAreOnePermissionPerPower(): void
    {
        $director = $this->probeUserFor(Role::named(Role::DIRECTOR));

        foreach (NamedActs::replacements() as $retired => $acts) {
            if (! str_starts_with($retired, ConsoleEnum::Gemini->value.'.')) {
                continue;
            }

            $this->assert(
                ! Permission::query()->where('name', $retired)->where('guard_name', 'web')->exists(),
                "{$retired} is not a permission any more - it is ".count($acts).' acts',
                'the bundled permission exists again; run the M11 B3 migration and re-run RbacMatrixSeeder',
            );

            foreach ($acts as $act) {
                $this->assert(
                    $director->can($act),
                    "CONTROL - the Director holds {$act}",
                    'the Director lost one of its acts; re-run RbacMatrixSeeder',
                );
            }
        }

        $dispatcher = $this->probeUserFor(Role::named(Role::DISPATCHER));

        foreach (['billing_subscriptions', 'platform_settings'] as $module) {
            foreach (NamedActs::of(ConsoleEnum::Gemini, $module) as $act) {
                $this->assert(
                    ! $dispatcher->can($act['permission']),
                    "Dispatcher is denied {$act['permission']}",
                    'LEAK: the permission was granted',
                );
            }
        }
    }

    /**
     * Every screen built so far returns 200 and names its Inertia component.
     *
     * Asserting the component name, not just the status, is what makes this
     * meaningful: a 200 alone would also be returned by a page that resolved
     * to the wrong component or silently rendered an error partial.
     */
    private function assertBuiltScreensRender(): void
    {
        $this->section('Built screens render for a Director');

        $director = $this->probeUserFor(Role::named(Role::DIRECTOR));

        $screens = [
            '/dashboard' => 'Gemini/Dashboard',
            '/clients' => 'Gemini/Clients/Index',
            '/guards' => 'Gemini/Guards/Index',
            '/guards/compliance' => 'Gemini/Guards/Compliance',
            '/billing' => 'Gemini/Billing/Index',
            '/dispatch/alerts' => 'Gemini/Dispatch/Alerts',
            '/payroll' => 'Gemini/Payroll/Index',
            '/reports' => 'Gemini/Reports/Index',
            '/audit' => 'Gemini/Audit/Index',
            '/settings/roles' => 'Gemini/Settings/RoleMatrix',
        ];

        foreach ($screens as $path => $component) {
            Auth::guard('web')->login($director);
            BindSessionToItsDoor::madeAt('localhost');
            $response = app(HttpKernel::class)->handle(Request::create("http://localhost{$path}", 'GET'));
            Auth::guard('web')->logout();

            $status = $response->getStatusCode();
            $resolved = $this->inertiaComponent((string) $response->getContent());

            $this->assert(
                $status === 200 && $resolved === $component,
                sprintf('%-22s renders %s', $path, $component),
                $status !== 200
                    ? "got {$status}"
                    : '200 but resolved to '.($resolved ?? 'no Inertia component at all'),
            );
        }
    }

    /**
     * The component name out of Inertia's data-page attribute.
     *
     * The payload is JSON, HTML-escaped into an attribute, so the component
     * arrives as `Gemini&#x5C;/Dashboard` — both entity-encoded and
     * JSON-slash-escaped. A naive str_contains on the raw body misses it and
     * reports every screen broken.
     */
    private function inertiaComponent(string $body): ?string
    {
        // Inertia 2 emits <script data-page type="application/json">{...}</script>
        // with the payload as element CONTENT. Inertia 1 used a div with
        // data-page as an HTML-escaped ATTRIBUTE. Both are handled, because a
        // regex written for only one silently reports every screen broken.
        if (preg_match('/<script[^>]*\bdata-page\b[^>]*>(.*?)<\/script>/s', $body, $match)) {
            $raw = $match[1];
        } elseif (preg_match('/data-page="([^"]*)"/', $body, $match)) {
            $raw = html_entity_decode($match[1], ENT_QUOTES);
        } else {
            return null;
        }

        return json_decode(trim($raw), true)['component'] ?? null;
    }

    /**
     * A single reusable probe account, re-roled for each expectation.
     *
     * It IS persisted, because syncRoles needs a saved model. One account is
     * reused rather than one created per role so the gate leaves exactly one
     * recognisable row behind instead of six, and its password is not a usable
     * credential - it is never hashed for login and the account holds no
     * estate assignment, so it can reach no estate data.
     */
    private function probeUserFor(Role $role): User
    {
        $user = User::where('email', 'gate-probe@geminisecurity.test')->first()
            ?? new User([
                'name' => 'Gate Probe',
                'email' => 'gate-probe@geminisecurity.test',
                'console' => ConsoleEnum::Gemini->value,
                'status' => 'active',
            ]);

        if (! $user->exists) {
            $user->password = 'not-a-login-account';
            $user->save();
        }

        $user->syncRoles([$role->name]);

        /*
         * PRE-VERIFIED FOR ALERTS (M10 §1.4). This gate measures the role matrix, and
         * a Director probe with no proven alert address would be redirected to the
         * verification page by `EnsureContactVerified` on every screen it asks for —
         * reporting ten "broken" screens that are the contact gate working. The probe's
         * `.test` address can receive no mail, so it is a demonstration account by
         * `DemonstrationAccounts`' own rule.
         */
        DemonstrationAccounts::verify($user);

        return $user->fresh();
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("--- {$title} ".str_repeat('-', max(0, 66 - strlen($title))));
    }

    private function assert(bool $held, string $proves, string $failureDetail): void
    {
        if ($held) {
            $this->line("  <fg=green>PASS</> {$proves}");

            return;
        }

        $this->failures++;
        $this->line("  <fg=red>FAIL</> {$proves} - {$failureDetail}");
    }
}
