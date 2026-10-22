<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Commands\PhoenixDemoSeed;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Seeded\ReservedContacts;
use App\Support\Seeded\Seeding;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * PHOENIX PARK VILLAGE 1 AT ITS REAL SIZE, FOR MEASURING THE ESTATE CONSOLE — M11 A2.
 *
 * 2,000 lots across five phases, 441 households, 12,015 charges: the figures
 * production holds, and the figures `gs_estate_phoenixscale` on the development
 * server holds too, because both were built the same way this is. A page that
 * is fast on the five-lot `FacilitiesFixture` and slow here is slow in front of
 * the committee, so every query budget in `EstateQueryBudgetsAtScaleTest` is
 * measured against this estate and no other.
 *
 * BUILT BY THE SAME CODE AS PRODUCTION'S. `phoenix:demo-seed` sets
 * `EstateFinanceSeeder::$phases` to its own phase table and then adds the
 * 2,000th lot; this does exactly that, reading the table and calling the method
 * out of `PhoenixDemoSeed` itself (they are private, so by reflection) rather
 * than copying them. A copy would go on describing yesterday's estate the day
 * the client sends the real phase breakdown (PPV-10) and the command changes.
 *
 * ITS OWN DATABASE, NAMED FROM THE CONFIGURED PREFIX, and KEPT BETWEEN RUNS.
 * The build posts twelve dues and receipt runs and a year of governance, and
 * takes the better part of a minute; nothing here writes to it afterwards —
 * every test that uses it is a read, or writes inside a transaction it rolls
 * back, and the one thing a read leaves behind is the dashboard's kept figures
 * (`dashboard_snapshots`), which the tests that care throw away first — so a
 * database that already holds the right estate is reused rather than rebuilt. "The right estate" is checked,
 * not assumed: the migrations are brought up to date, and the three volumes
 * are counted. Anything else is dropped and built again.
 *
 * CENTRALLY, one tenant row with its subdomain and nothing else of Phoenix
 * Park's: the officers, the gate log and the payroll `phoenix:demo-seed` also
 * writes are the Gemini console's and the Guard App's, and the estate screens
 * measured here read none of them except the Security screen, which gets a
 * seeded security record of its own below.
 */
final class EstateScaleFixture
{
    /** The subdomain, which is also the tenant key and the database suffix. */
    public const ESTATE = 'phoenixscale';

    /** Production's own volumes, and the ones the build is checked against. */
    public const LOTS = 2_000;

    public const HOUSEHOLDS = 441;

    public const CHARGES = 12_015;

    /** Security records for the estate's Security screen, so its lists have rows to be slow over. */
    public const GATE_EVENTS = 400;

    public const INCIDENTS = 60;

    public const ALERTS = 40;

    private static bool $ready = false;

    /** How long this process's build took, in seconds, or null when a kept estate was reused. */
    public static ?float $builtInSeconds = null;

    public static function database(): string
    {
        return config('tenancy.database.prefix').self::ESTATE;
    }

    /** Point the `tenant` connection at the estate, building it the first time a process asks. */
    public static function boot(): void
    {
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.mysql'),
                ['database' => self::database()],
            ),
            'database.default' => 'tenant',
        ]);

        DB::purge('tenant');

        if (self::$ready) {
            // Checked again on every boot, not only the first (found by the suite, seed 1790537889): a
            // `RefreshDatabase` suite that runs AFTER this process built or reused the estate migrates the platform
            // database fresh, the rate card comes back under a new id, and every pay run opened as a 404.
            self::keepTheRateCardReference();

            return;
        }

        if (! self::reusable()) {
            self::build();
        }

        self::$ready = true;
    }

    /**
     * The pay runs name the TAJ rate card by its central id. When that id is gone, the card is seeded again (the
     * seeder is idempotent, and seeds the same card) and the runs are pointed at it: the figures they were calculated
     * on are the same card's, so nothing a page shows changes. Two small queries when nothing is wrong.
     */
    private static function keepTheRateCardReference(): void
    {
        $referenced = DB::connection('tenant')->table('payroll_runs')->distinct()->pluck('statutory_rate_version_id')->filter()->all();
        $held = DB::connection('mysql')->table('statutory_rate_versions')->whereIn('id', $referenced)->pluck('id')->all();

        if (count($held) === count($referenced)) {
            return;
        }

        Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
        $card = DB::connection('mysql')->table('statutory_rate_versions')->max('id');

        DB::connection('tenant')->table('payroll_runs')->whereNotIn('statutory_rate_version_id', $held)->update(['statutory_rate_version_id' => $card]);
    }

    /**
     * The permission matrix, COMMITTED, because it is the real one every test shares.
     *
     * Re-seeded only when it is behind — the same two cells `FacilitiesFixture::platform()`
     * checks, for the same reason: re-seeding costs seconds a file.
     */
    public static function matrix(): void
    {
        $current = Role::query()
            ->where('name', Role::PROPERTY_MANAGER)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.facilities.approve'))
            ->exists()
            && Role::query()
                ->where('name', Role::ESTATE_HEAD_OF_SECURITY)
                ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.security.view'))
                ->exists();

        if (! $current) {
            Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The central half of the estate: its tenant row, its subdomain and its security record.
     *
     * CALL IT INSIDE A TRANSACTION ON `mysql` THAT THE TEST ROLLS BACK. Nothing central
     * about this estate may outlive the test: a committed `phoenixscale` tenant would be
     * a client on every Gemini console list the rest of the suite asserts against, and
     * four hundred committed gate events would be on every gate feed. The requests a
     * test makes run on the same connection, so they see these rows; nothing else does.
     */
    public static function enter(): Tenant
    {
        self::boot();

        if (DB::connection('mysql')->transactionLevel() === 0) {
            throw new RuntimeException('EstateScaleFixture::enter() writes central rows that must be rolled back: begin a transaction on `mysql` first.');
        }

        $estate = Tenant::query()->find(self::ESTATE);

        if (! $estate instanceof Tenant) {
            // Without events: the database is built above, and the provisioning pipeline would
            // try to create it again and leave a MySQL user behind for a test fixture.
            $estate = Tenant::withoutEvents(static function (): Tenant {
                $estate = new Tenant;

                $estate->forceFill([
                    'id' => self::ESTATE,
                    'name' => 'Phoenix Park Village 1 (scale)',

                    /*
                     * THE PREFIX THE ESTATE'S 11,726 RECEIPTS ALREADY CARRY. It is
                     * built with no central row, so `Receipts::prefix()` numbers it
                     * under its testing fallback — the key's first six letters,
                     * `PHOENI`. Giving the row any other prefix would open a second,
                     * empty sequence, and the register at scale would be measured
                     * over none of them.
                     */
                    'receipt_prefix' => 'PHOENI',
                    'status' => 'active',
                ])->save();

                return $estate;
            });
        }

        if (! $estate->domains()->where('domain', self::ESTATE)->exists()) {
            $estate->domains()->create(['domain' => self::ESTATE]);
        }

        self::seedSecurityRecords();

        return $estate;
    }

    /**
     * Drop the estate, so the next `boot()` in this process or any other builds it again.
     *
     * FOR A TEST THAT HAD TO WRITE TO IT FOR REAL. A request opens the estate on a
     * connection of its own, so a write made through HTTP cannot be held inside the
     * test's transaction and rolled back — and the ledger is append-only, so it cannot
     * be deleted afterwards either. Such a test measures its writes and then calls this:
     * every other test goes on measuring the estate as it was built, never one a month
     * of dues heavier.
     */
    public static function discard(): void
    {
        DB::purge('tenant');
        DB::connection('mysql_owner')->statement('DROP DATABASE IF EXISTS `'.self::database().'`');

        self::$ready = false;
    }

    /** A committee member holding exactly one estate role, assigned to this estate. Inside the test's transaction. */
    public static function viewer(string $roleName): User
    {
        $estate = Tenant::query()->findOrFail(self::ESTATE);

        $user = User::factory()->create([
            /*
             * An address nobody else can hold. Faker's `unique()` is unique within this
             * process only, and the platform database keeps other suites' committed
             * users — one of whom shared a generated address with a viewer here once.
             */
            'email' => 'm11-scale-'.Str::lower((string) Str::ulid()).'@example.test',
            'console' => Console::Estate->value,
            'status' => 'active',
        ]);

        $user->syncRoles([$roleName]);

        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $estate->getTenantKey(),
            'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /**
     * A committee of one person in each of the eight estate roles, as Phoenix Park's
     * production estate has — so the users screen and anything else that lists the
     * committee is measured over a committee, not over the one viewer. Inside the
     * test's transaction, like `viewer()`.
     *
     * @return list<User>
     */
    public static function committee(): array
    {
        return array_map(static fn (string $role): User => self::viewer($role), [
            Role::COMMUNITY_SUPER_ADMIN,
            Role::PRESIDENT,
            Role::VICE_PRESIDENT,
            Role::SECRETARY,
            Role::PROPERTY_MANAGER,
            Role::TREASURER,
            Role::ESTATE_ADMIN_ASSISTANT,
            Role::ESTATE_HEAD_OF_SECURITY,
        ]);
    }

    /** Where a screen lives on this estate's own hostname. */
    public static function url(string $path): string
    {
        return 'http://'.self::ESTATE.'.'.config('app.estate_domain').'/'.ltrim($path, '/');
    }

    /**
     * The three volumes, counted.
     *
     * @return array{lots: int, households: int, charges: int}
     */
    public static function volumes(): array
    {
        $tenant = DB::connection('tenant');

        return [
            'lots' => (int) $tenant->table('units')->count(),
            'households' => (int) $tenant->table('households')->count(),
            'charges' => (int) $tenant->table('charges')->count(),
        ];
    }

    /** An estate already built to the right size, with its migrations current, is kept. */
    private static function reusable(): bool
    {
        $exists = DB::connection('mysql_owner')->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?',
            [self::database()],
        );

        if ((int) $exists->n === 0) {
            return false;
        }

        try {
            Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

            if (self::volumes() !== ['lots' => self::LOTS, 'households' => self::HOUSEHOLDS, 'charges' => self::CHARGES]) {
                return false;
            }

            // AND BUILT SINCE A SEEDER'S ROWS CARRY THE MARK (M33 §5): an estate seeded before it says nothing of itself.
            if (DB::connection('tenant')->table('units')->whereNull('seeded_at')->exists()) {
                return false;
            }

            // AND SINCE A SEEDED PERSON'S NUMBER REACHES NOBODY (M35 §5): the seed used to give lot 47 "876-555-0047".
            $phone = DB::connection('tenant')->table('residents')->whereNotNull('seeded_at')->orderBy('id')->value('phone');

            if ($phone !== null && ! ReservedContacts::phoneIsFictional((string) $phone)) {
                return false;
            }

            /*
             * AND WHAT IT POINTS AT CENTRALLY STILL EXISTS. A payroll run names the
             * TAJ rate card it was calculated on by its id in the platform database,
             * and a suite that uses `RefreshDatabase` (SimulatorTest, AuthenticationTest)
             * migrates that database fresh — so an estate kept from before it would
             * open every pay run as a 404 for a rate card with an id nobody holds.
             * Such an estate is rebuilt against the card as it is now.
             */
            $referenced = DB::connection('tenant')->table('payroll_runs')->distinct()->pluck('statutory_rate_version_id')->filter()->all();
            $held = DB::connection('mysql')->table('statutory_rate_versions')->whereIn('id', $referenced)->count();

            return $held === count($referenced);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function build(): void
    {
        $started = hrtime(true);
        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.self::database().'`');
        $owner->statement('CREATE DATABASE `'.self::database().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('tenant');

        /*
         * The TAJ rate card first, because it is central and the estate's payroll
         * seeder quietly seeds no pay run at all without one (it will not invent a
         * card). Idempotent — `EstatePayrollTest` seeds it the same way, for the
         * same reason: the central test database may have been migrated fresh.
         */
        Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

        Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

        EstateFinanceSeeder::$phases = (new ReflectionClassConstant(PhoenixDemoSeed::class, 'PHASES'))->getValue();

        try {
            Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);
        } finally {
            EstateFinanceSeeder::$phases = null;
        }

        // The 2,000th lot, exactly as `phoenix:demo-seed` adds it (PPV-09) — marked as that command marks it (M33 §5).
        Seeding::run('phoenix:demo-seed', static fn () => (new ReflectionMethod(PhoenixDemoSeed::class, 'extraLot'))->invoke(app(PhoenixDemoSeed::class)));

        $volumes = self::volumes();

        if ($volumes !== ['lots' => self::LOTS, 'households' => self::HOUSEHOLDS, 'charges' => self::CHARGES]) {
            throw new RuntimeException(
                'The scale estate was built with '.json_encode($volumes).' and production holds '
                .json_encode(['lots' => self::LOTS, 'households' => self::HOUSEHOLDS, 'charges' => self::CHARGES])
                .'. The seeder or PhoenixDemoSeed has changed; measure against what production really holds.'
            );
        }

        self::$builtInSeconds = (hrtime(true) - $started) / 1e9;
    }

    /**
     * Gate events, incidents and panic alerts for the Security screen — central tables.
     *
     * Keyed on this estate and marked simulated, inside the test's transaction, and
     * counted first so a test that enters twice does not double them.
     */
    private static function seedSecurityRecords(): void
    {
        $central = DB::connection('mysql');
        $site = self::ESTATE;

        if ((int) $central->table('gate_events')->where('tenant_id', $site)->count() > 0) {
            return;
        }

        $now = Carbon::now();

        $rows = [];
        for ($i = 0; $i < self::GATE_EVENTS; $i++) {
            $at = $now->copy()->subMinutes(17 * $i);
            $rows[] = [
                'tenant_id' => $site,
                'guard_name' => 'Scale Officer '.($i % 6 + 1),
                'post_name' => 'Main Gate',
                'verdict' => $i % 9 === 0 ? 'denied' : 'admitted',
                'category' => ['single', 'delivery', 'contractor', 'walk_up'][$i % 4],
                'subject' => 'Scale visitor '.$i,
                'basis' => 'Pass verified · Lot '.(($i * 7) % 1999 + 1),
                'occurred_at' => $at,
                'device_time' => $at,
                'idempotency_key' => 'm11-scale-gate-'.$i,
                'is_simulated' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $central->table('gate_events')->insert($chunk);
        }

        $rows = [];
        for ($i = 0; $i < self::INCIDENTS; $i++) {
            $at = $now->copy()->subHours(5 * $i + 1);
            $closed = $i % 3 !== 0;
            $rows[] = [
                'tenant_id' => $site,
                'guard_name' => 'Scale Officer '.($i % 6 + 1),
                'kind' => ['suspicious', 'property', 'noise', 'access'][$i % 4],
                'severity' => ['low', 'med', 'high'][$i % 3],
                'status' => $closed ? 'closed' : 'open',
                'occurred_at' => $at,
                'detail' => 'Scale incident '.$i.'. Sample data.',
                'resolution' => $closed ? 'Resolved on patrol.' : null,
                'closed_at' => $closed ? $at->copy()->addHour() : null,
                'logged_by_name' => 'Scale Officer '.($i % 6 + 1),
                'is_simulated' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $central->table('security_incidents')->insert($rows);

        $rows = [];
        for ($i = 0; $i < self::ALERTS; $i++) {
            $at = $now->copy()->subHours(11 * $i + 2);
            $rows[] = [
                'tenant_id' => $site,
                'kind' => 'panic',
                'mode' => 'audible',
                'raised_by_name' => 'Lot '.(($i * 13) % 1999 + 1).' household',
                'unit_reference' => 'Lot '.(($i * 13) % 1999 + 1),
                'status' => 'resolved',
                'device_time' => $at,
                'server_time' => $at,
                'clock_skewed' => false,
                'captured_offline' => false,
                'idempotency_key' => 'm11-scale-panic-'.$i,
                'acknowledged_at' => $at->copy()->addSeconds(40),
                'resolved_at' => $at->copy()->addMinutes(12),
                'resolution_note' => 'Attended; nothing found. Sample data.',
                'is_simulated' => true,
                'alerted_handsets' => 2,
                'alerted_via' => 'on_duty',
                'escalated_contacts' => 0,
                'sms_recipients' => 0,
                'sms_delivered' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $central->table('duress_alerts')->insert($rows);
    }
}
