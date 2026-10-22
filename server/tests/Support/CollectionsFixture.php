<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Estate\CollectionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * An estate to test collections against — the same fixture pattern as
 * `EstateArrearsTest`, deliberately smaller.
 *
 * A DATABASE OF ITS OWN, built once per process by the real migrations and the
 * real chart of accounts, so a payment plan is scheduled against a genuine
 * posted balance rather than a number a test wrote into a column. Every balance
 * below is raised as a CHARGE through `Dues`, which posts the entry, because
 * `Collections::schedule` reads the ledger and would find nothing otherwise.
 *
 * IT IS NOT PHOENIX PARK, and it does not try to be. The arrears suite needs
 * 450 units because the figures it proves are estate-wide totals; nothing here
 * is a total. What these tests prove is arithmetic on one unit's balance and a
 * gate decision about one household, and both are reachable with six lots — so
 * six lots is what gets built, and the suite stays fast enough to run on every
 * change to the restriction policy.
 *
 * SHARED BY TWO SUITES. `EstateCollectionsTest` and the payment-plan cases in
 * `RestrictionPolicyTest` both build on it, which is why it is a class rather
 * than a Pest helper: a global function defined in one test file and called
 * from another is a dependency on load order that nothing declares.
 */
final class CollectionsFixture
{
    /**
     * The configured tenancy prefix, then this fixture's own name.
     *
     * NOT A LITERAL, for the reason `FacilitiesFixture::database()` gives: a
     * literal is shared by every checkout of the suite on the server, and two
     * copies running at once each drop the other's database at the top of
     * their run. The prefix is what tells two checkouts apart.
     */
    public static function database(): string
    {
        return config('tenancy.database.prefix').'collectionstest';
    }

    /**
     * The lots board 8's log names, at the balances boards 5 and 6 give them,
     * plus one owing an amount that will not divide.
     *
     * [reference, phase, resident, household, balance in minor units]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const UNITS = [
        ['Lot 47', 'Phase 2', 'Andrea Fletcher', 'Fletcher household', 12_400_00],
        ['Lot 31', 'Phase 2', 'Omar Brown', 'Brown household', 31_600_00],
        ['Lot 9', 'Phase 1', 'Ricardo Hall', 'Hall household', 18_600_00],
        ['Lot 21', 'Phase 4', 'Tanya Simms', 'Simms household', 44_900_00],
        ['Lot 63', 'Phase 3', 'Devon Clarke', 'Clarke household', 6_200_00],

        /*
         * J$7,777.77 over four instalments is 1,944.4425 each, which no estate
         * can bill. It is here so the rounding rule is proven against a balance
         * that actually exercises it — every board figure divides cleanly by
         * four, and a test that only used those would pass whatever the
         * remainder did.
         */
        ['Lot 100', 'Phase 5', 'Marcia Grant', 'Grant household', 7_777_77],
    ];

    private static bool $built = false;

    /**
     * Point the tenant connection at the fixture, building it on first use.
     *
     * The config is re-applied on every call rather than once, because Laravel
     * refreshes the application between tests and the connection would
     * otherwise fall back to the platform database mid-suite.
     */
    public static function boot(): void
    {
        /*
         * ALREADY POINTED AT IT? RETURN, AND ABOVE ALL DO NOT PURGE (M4 §4, defect 3).
         *
         * `DB::purge` disconnects, and a disconnect throws away any transaction open on this
         * connection. The two files that use this fixture now open one per test and roll it back,
         * so the seeded ladder, log and Lot 47 plan are the seeder's whatever order they run in —
         * and `boot()` is called from half a dozen helpers inside a single test, so purging on
         * every call would have made that impossible.
         */
        if (config('database.default') === 'tenant' && config('database.connections.tenant.database') === self::database()) {
            return;
        }

        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.mysql'),
                ['database' => self::database()],
            ),
            'database.default' => 'tenant',
        ]);

        DB::purge('tenant');

        if (self::$built) {
            return;
        }

        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.self::database().'`');
        $owner->statement('CREATE DATABASE `'.self::database().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('tenant');

        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        (new ChartOfAccountsSeeder)->run();

        self::build();

        /*
         * The real seeder, against a real ledger. Boards 7 and 8 are seeded
         * data as much as they are code, and a suite that built its own
         * templates and its own log would prove nothing about the ones a
         * reviewer will actually open.
         */
        (new CollectionsSeeder)->run();

        self::$built = true;
    }

    /** The unit at this reference. Fails loudly rather than returning null. */
    public static function unit(string $reference): Unit
    {
        self::boot();

        return Unit::where('reference', $reference)->firstOrFail();
    }

    /**
     * A household of its own, already restricted, with a real balance behind it.
     *
     * EVERY RESTRICTION TEST GETS ITS OWN, because these tests activate plans
     * and default them — they mutate the very state the next assertion reads.
     * Sharing one household between them would make the suite order-dependent,
     * and an order-dependent test of a gate decision is worse than no test.
     */
    public static function restrictedUnit(string $reference, int $balanceMinor = 12_400_00): Unit
    {
        self::boot();

        $unit = Unit::create([
            'reference' => $reference,
            'block' => 'Phase 1',
            'street' => 'Phase 1 Drive',
            'type' => 'residential',
            'status' => 'occupied',
        ]);

        $household = Household::create([
            'unit_id' => $unit->id,
            'name' => $reference.' household',
            'access_restricted' => true,
        ]);

        Resident::create([
            'household_id' => $household->id,
            'full_name' => 'Test Householder',
            'email' => 'test@collections.test',
            'relationship' => 'owner',
            'is_primary' => true,
        ]);

        self::charge($unit, $balanceMinor, 3);

        return $unit->refresh();
    }

    /** Six lots, their households, and a posted charge behind every balance. */
    private static function build(): void
    {
        foreach (self::UNITS as [$reference, $phase, $resident, $householdName, $balance]) {
            $unit = Unit::create([
                'reference' => $reference,
                'block' => $phase,
                'street' => $phase.' Drive',
                'type' => 'residential',
                'status' => 'occupied',
            ]);

            $household = Household::create([
                'unit_id' => $unit->id,
                'name' => $householdName,
                'access_restricted' => false,
            ]);

            Resident::create([
                'household_id' => $household->id,
                'full_name' => $resident,
                'email' => str_replace(' ', '', strtolower($reference)).'@collections.test',
                'relationship' => 'owner',
                'is_primary' => true,
            ]);

            self::charge($unit, $balance, 2);
        }
    }

    /** One charge, posted through the ledger like every other charge. */
    private static function charge(Unit $unit, int $minor, int $monthsBack): void
    {
        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::ofMinor($minor, 'JMD'),
            description: 'Maintenance fee — arrears brought forward',
            dueOn: Carbon::today()->startOfMonth()->subMonths($monthsBack),
        );
    }
}
