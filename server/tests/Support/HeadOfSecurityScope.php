<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rows the Gemini Head of Security scope tests stand on — bug hunt B003, B004 and B007.
 *
 * The Head of Security is the one Gemini role confined to its assigned sites (D-015: on every
 * cell it holds), and it holds no Billing & subscriptions cell at all (D-139: so it loses the
 * figures the Admin Assistant loses). Every test built on this asks one of two questions of a
 * screen — does it reach past the viewer's sites, does it hand over Billing's figures — so it
 * needs the same three clients to ask them about:
 *
 *   mine        active, the Head of Security's one assignment, 120 units on the test's tier
 *   theirs      active, elsewhere, 300 units and two contracted guards
 *   onboarding  elsewhere, mid-onboarding — the new-client form's "same as … is now"
 *
 * and officers at them: one hired today at each live client, and one at "theirs" whose licence
 * lapsed yesterday.
 *
 * AND ONE ROLE NO SEEDED ROLE IS: a copy of the Head of Security that has been given Billing,
 * which M10 §4 lets the Director build. It may open what Billing opens, and must still read
 * only its own sites — the scope half of each fix, tested on its own.
 *
 * Everything is written to the central connection by the caller's open transaction and rolled
 * back with it. One token is in every name, so a scan of a payload is a scan for THESE rows
 * whatever else a shared test database holds.
 */
final class HeadOfSecurityScope
{
    /**
     * Build the clients, officers and viewers, as properties of the test.
     *
     * token, suffix, planName, planId, cheapPlanId, mine, theirs, onboarding, names,
     * mineGuard, theirsGuard, lapsed, head, director, operations.
     */
    public static function seed(object $test): void
    {
        $suffix = random_int(100000, 999999);

        $test->suffix = (string) $suffix;
        $test->token = 'Scopetest'.$suffix;
        $test->planName = 'Scopeview'.$suffix;

        $test->planId = self::plan('scopeview'.$suffix, $test->planName, 340_00, 10, 98);
        $test->cheapPlanId = self::plan('scopecheap'.$suffix, 'Scopecheap'.$suffix, 100_00, 1, 99);

        $test->mine = 'scopemine'.$suffix;
        $test->theirs = 'scopetheirs'.$suffix;
        $test->onboarding = 'scopeboard'.$suffix;

        $test->names = [
            $test->mine => $test->token.' Mine',
            $test->theirs => $test->token.' Theirs',
            $test->onboarding => $test->token.' Bayside',
        ];

        foreach ([
            $test->mine => ['active', 120, 0],
            $test->theirs => ['active', 300, 2],
            $test->onboarding => ['onboarding', 50, 0],
        ] as $id => [$status, $units, $guards]) {
            DB::connection('mysql')->table('tenants')->insert([
                'id' => $id,
                'name' => $test->names[$id],
                'status' => $status,
                'demo' => false,
                'parish' => 'St. Ann',
                'address_line' => 'Ocho Rios',
                'provisioned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('mysql')->table('subscriptions')->insert([
                'tenant_id' => $id,
                'plan_id' => $test->planId,
                'unit_count' => $units,
                'contracted_guards' => $guards,
                'status' => $status,
                'started_on' => $status === 'onboarding' ? null : now()->subYear()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $test->mineGuard = self::officer($test->mine, 'Andre '.$test->token);
        $test->theirsGuard = self::officer($test->theirs, 'Marcus '.$test->token);
        $test->lapsed = self::officer($test->theirs, 'Lapsed '.$test->token, [
            'status' => 'licence_expired',
            'psra_expires_on' => now()->subDay()->toDateString(),
        ]);

        $test->head = self::assigned(FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY), Role::HEAD_OF_SECURITY, $test->mine);
        $test->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
        $test->operations = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);
    }

    /**
     * An officer posted at this estate (or none), hired today.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function officer(?string $tenantId, string $name, array $overrides = []): Guard
    {
        $suffix = random_int(100000, 999999);

        return Guard::create([
            'full_name' => $name,
            'employee_number' => 'GS-SC'.$suffix,
            'psra_number' => 'PSRA-SC'.$suffix,
            'psra_expires_on' => now()->addYear()->toDateString(),
            'employment_type' => 'full_time',
            'status' => 'active',
            'tenant_id' => $tenantId,
            'hired_on' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    /** Give a user an active assignment to one estate under the named role. */
    public static function assigned(User $user, string $roleName, string $tenantId): User
    {
        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
            'is_active' => true,
        ]);

        $fresh = $user->fresh();

        return $fresh instanceof User ? $fresh : $user;
    }

    /**
     * A copy of the Head of Security that has been given Billing: confined to its sites, holding
     * every permission the revenue and pricing screens ask for, and assigned to one estate.
     */
    public static function billingCopy(string $suffix, string $tenantId): User
    {
        $name = 'gemini.scoped_billing_'.$suffix;

        Role::findOrCreate($name, 'web');

        $role = Role::named($name);
        $role->forceFill(['console' => 'gemini', 'label' => 'Scoped billing', 'sort' => 96, 'scope_default' => 'assigned_sites'])->save();
        $role->givePermissionTo([
            'gemini.dashboard.view',
            'gemini.clients.view',
            'gemini.clients.create',
            'gemini.clients.update',
            'gemini.billing_subscriptions.view',
            // Pricing a client is one of Billing's writes, not its read verb (B069),
            // and its own act since M11 B3.
            'gemini.billing_subscriptions.price_clients',
            'gemini.cross_tenant_reports.view',
            'gemini.cross_tenant_reports.export',
        ]);

        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $user->syncRoles([$name]);

        return self::assigned($user, $name, $tenantId);
    }

    /**
     * The Inertia props a response carried, as the browser receives them.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    public static function props(TestResponse $response): array
    {
        $props = [];

        $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return json_decode((string) json_encode($props), true);
    }

    /**
     * A whole payload as one lower-cased string, for a scan by name.
     *
     * @param  array<string, mixed>  $props
     */
    public static function scan(array $props): string
    {
        return strtolower((string) json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function plan(string $key, string $name, int $pricePerUnitMinor, int $minUnits, int $sort): int
    {
        return (int) DB::connection('mysql')->table('plans')->insertGetId([
            'key' => $key,
            'name' => $name,
            'price_per_unit_minor' => $pricePerUnitMinor,
            'currency' => 'JMD',
            'min_units' => $minUnits,
            'is_active' => true,
            'sort' => $sort,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
