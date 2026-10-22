<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Console;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Estate\FacilitiesSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * An estate to test boards 17 to 20 against — the `CollectionsFixture` pattern,
 * with the half `CollectionsFixture` does not need: a real tenant.
 *
 * A DATABASE OF ITS OWN, built once per process by the real migrations, the real
 * chart of accounts and the REAL `FacilitiesSeeder`. Boards 17 and 19 are seeded
 * data as much as they are code — "12 open, 7 in progress, 2 overdue, 3.2 days"
 * is a claim about seventeen specific rows — so a suite that built its own queue
 * would prove nothing about the one a reviewer opens.
 *
 * IT IS NOT PHOENIX PARK, and it does not try to be. `EstateFinanceSeeder` bills
 * 450 units over six months because the figures it proves are estate-wide
 * totals; nothing here is an estate-wide total. What these tests prove is
 * arithmetic on one ticket's clock, one booking's snapshotted terms and one
 * charge's journal lines, and five lots reach all three — so five lots is what
 * gets built, and the amenity fee posted below lands in an otherwise empty
 * receivable where it can be seen on its own.
 *
 * THE FIVE LOTS ARE THE ONES THE BOARDS NAME. `FacilitiesSeeder` skips a booking
 * whose unit does not exist rather than inventing one, so Lot 47, Lot 88, Lot 63
 * and Lot 3 are here because board 19 draws them, and Lot 9 because board 17
 * puts ticket #1039 at it.
 *
 * IT ALSO PROVISIONS THE ESTATE CENTRALLY, which the collections fixture never
 * has to. The permission assertions are HTTP assertions — a role without
 * `estate.facilities.update` has to be REFUSED by the route rather than merely
 * lack an ability — and an estate route is reachable only through a real tenant,
 * resolved from a real subdomain, by a user with a real assignment to it. All
 * three are built here.
 */
final class FacilitiesFixture
{
    /** The subdomain, which is also the tenant key and the database suffix. */
    public const ESTATE = 'facilitiestest';

    /**
     * The fixture's database: the CONFIGURED tenancy prefix, then the tenant key.
     *
     * READ FROM CONFIG, NEVER WRITTEN OUT (M10, baseline). This used to be the
     * literal `gs_estate_facilitiestest`, which is what the prefix is by default
     * — and a literal only agrees with the prefix while nobody changes it. The
     * tenant row below is written without events, so it carries no
     * `tenancy_db_name` and stancl resolves it to `prefix + key` on every
     * request. Give the suite a different prefix and the fixture builds one
     * database while every HTTP request into the estate opens another that does
     * not exist: a wall of "Unknown database" 500s that reads as a broken route.
     *
     * AND A LITERAL IS SHARED BY EVERY COPY OF THE SUITE ON THE SERVER. Two
     * checkouts running at once — two checkouts, two runs — both DROP and
     * rebuild the same database at the top of their run, each out from under
     * the other, and the loser fails on tables and columns that vanished
     * mid-test. A per-checkout prefix is the whole cure, and only works if
     * every fixture takes its name from it.
     */
    public static function database(): string
    {
        return config('tenancy.database.prefix').self::ESTATE;
    }

    /**
     * Its receipt prefix. Not `PPV`: the prefix is unique across the platform,
     * and the seeded Phoenix Park holds that one.
     */
    public const RECEIPT_PREFIX = 'FXT';

    /**
     * The lots boards 17 and 19 name, and nothing else.
     *
     * [reference, phase]
     *
     * @var list<array{0: string, 1: string}>
     */
    private const UNITS = [
        ['Lot 3', 'Phase 1'],
        ['Lot 9', 'Phase 1'],
        ['Lot 47', 'Phase 2'],
        ['Lot 63', 'Phase 3'],
        ['Lot 88', 'Phase 4'],
    ];

    private static bool $built = false;

    private static bool $provisioned = false;

    /**
     * The maintenance tickets `FacilitiesSeeder` made, by the number board 17 prints.
     *
     * TAKEN THE MOMENT THE SEEDER FINISHES, and it has to be (M4 §4, defect 3). This estate is
     * committed and shared: the Resident App's contract walk raises a ticket here, the community
     * history suite raises two more, and board 17's tiles count the whole table. A test that
     * asserts "12 open" is asserting something about the SEEDER, and the only moment the seeder's
     * queue can be told apart from everything added since is before anything is.
     *
     * @var list<int>
     */
    private static array $seededTicketNumbers = [];

    /**
     * Point the tenant connection at the fixture, building it on first use.
     *
     * The config is re-applied on every call rather than once, because Laravel
     * refreshes the application between tests and the connection would otherwise
     * fall back to the platform database mid-suite. It is re-applied after an
     * HTTP request too: tenancy leaves the tenant connection pointed at this
     * same database, but it is a connection of tenancy's own making and purging
     * ours keeps one description of where these rows live.
     */
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

        self::buildEstate();

        (new FacilitiesSeeder)->run();

        self::$seededTicketNumbers = MaintenanceTicket::query()->orderBy('number')->pluck('number')->map(intval(...))->all();

        self::$built = true;
    }

    /**
     * The numbers of the tickets the seeder made, and nothing raised since.
     *
     * @return list<int>
     */
    public static function seededTicketNumbers(): array
    {
        self::boot();

        return self::$seededTicketNumbers;
    }

    /**
     * The central half: the permission matrix and the estate itself.
     *
     * Committed rather than rolled back, and once per process. The matrix is the
     * REAL one — transcribed from board 24 and amended by Ruling 1 — because
     * what the permission assertions are about is which cell the Property
     * Manager holds on Dues & ledger, and a hand-built grid here would pass
     * while the seeded one locked the wrong role out of the wrong module.
     */
    public static function platform(): Tenant
    {
        self::boot();

        self::matrix();

        $estate = Tenant::query()->find(self::ESTATE);

        if (! $estate instanceof Tenant) {
            /*
             * WITHOUT EVENTS, deliberately. `TenantCreated` runs CreateDatabase,
             * MigrateDatabase and the append-only grants — the real provisioning
             * pipeline — and this database is already built above. Letting the
             * pipeline run would fail on a database that exists and, if it did
             * not, would leave a second MySQL user behind for a fixture that is
             * dropped and rebuilt on every run.
             */
            $estate = Tenant::withoutEvents(static function (): Tenant {
                $estate = new Tenant;

                // Phoenix Park, because D-038 leaves exactly two estates on this
                // platform and an invented third would reach a screen.
                $estate->forceFill([
                    'id' => self::ESTATE,
                    'name' => 'Phoenix Park',
                    'receipt_prefix' => self::RECEIPT_PREFIX,
                    'status' => 'active',
                ])->save();

                return $estate;
            });
        }

        /*
         * A test database whose estate row predates the prefix was given one by
         * the migration's backfill, from the name. Written through the query
         * builder, past the model's refusal: this database is rebuilt every
         * process, so a receipt an earlier process issued is not a series.
         */
        if ($estate->receipt_prefix !== self::RECEIPT_PREFIX) {
            DB::connection('mysql')->table('tenants')->where('id', self::ESTATE)->update(['receipt_prefix' => self::RECEIPT_PREFIX]);
            $estate->setRawAttributes(array_merge($estate->getAttributes(), ['receipt_prefix' => self::RECEIPT_PREFIX]), true);
        }

        /*
         * The BARE SUBDOMAIN, not the full hostname, exactly as
         * `EstateProvisioner` stores it: `InitializeTenancyBySubdomain` strips
         * the central domain off the host and looks up what remains. Without
         * this row the estate is unidentifiable and every assertion below would
         * be made against a 500.
         */
        if (! $estate->domains()->where('domain', self::ESTATE)->exists()) {
            $estate->domains()->create(['domain' => self::ESTATE]);
        }

        self::$provisioned = true;

        return $estate;
    }

    /**
     * The REAL permission matrix in the central database, seeded when it is a version behind.
     *
     * ITS OWN METHOD SINCE M11 B5, so a file with an estate of its own (`OwnEstate`) stands on
     * exactly the matrix this fixture does, rather than on a second copy of this check that
     * would fall a cell behind the first time somebody adds one here and not there.
     */
    public static function matrix(): void
    {
        /*
         * SEEDED ON THE CELL, NOT ON THE ROLE — the rule `EstateResidentAccessTest`
         * learned from D-053. A test database seeded before D-086 gave the
         * Facilities row its two Approver cells already HAS a Treasurer, so a
         * check for the role would leave the matrix a version behind, and every
         * forfeit assertion would fail as a 403 that looked like a broken route
         * rather than a stale fixture.
         */
        $current = Role::query()
            ->where('name', Role::PROPERTY_MANAGER)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.facilities.approve'))
            ->exists();

        /*
         * AND THE SAME CHECK FOR THE NEWEST CELL, for the same reason (M10 §2).
         *
         * A database seeded before the estate's own Head of Security existed has
         * a Property Manager holding `estate.facilities.approve` already, so the
         * check above is satisfied and the seeder never runs — leaving the
         * matrix a version behind. Every assertion about the new role then fails
         * as "there is no role named estate.head_of_security", which reads as a
         * broken test rather than a stale fixture.
         *
         * Each new cell adds a line here. That is deliberate: the alternative is
         * re-seeding on every call, which costs seventeen seconds a test file.
         */
        $hasSecurity = Role::query()
            ->where('name', Role::ESTATE_HEAD_OF_SECURITY)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.security.view'))
            ->exists();

        /*
         * AND THE NEWEST CELLS (M11 B3): the Community Super Admin's one dues
         * approval, `set_rates`, and not the four it no longer holds; and Billing's
         * `update` as its four acts. The migration narrows the one and moves the
         * other, but a test that plants the old Approver tag, or a database built
         * by an earlier copy of that migration, leaves the matrix a version behind,
         * and a fixture that skipped the re-seed would test it that way.
         */
        // …and since M55 the Community Super Admin holds EVERY approval (the user, 8 Oct 2026): a database seeded
        // before it still has the one dues act and no meeting publication.
        $hasSplit = Role::query()
            ->where('name', Role::COMMUNITY_SUPER_ADMIN)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.dues_ledger.reverse_runs'))
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.governance.publish_meetings'))
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.settings.manage_people'))
            ->exists()
            && Role::query()
                ->where('name', Role::ACCOUNTANT)
                ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'gemini.billing_subscriptions.price_clients'))
                ->exists();

        /*
         * AND M53 (§1 Law 4): the estate Admin Assistant holds no money module — a database seeded before it still
         * grants the role Entry on Dues & ledger, and every "the assistant is refused" assertion would fail on it.
         */
        $assistantHasNoMoney = ! Role::query()
            ->where('name', Role::ESTATE_ADMIN_ASSISTANT)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.dues_ledger.view'))
            ->exists();

        if (! $current || ! $hasSecurity || ! $hasSplit || ! $assistantHasNoMoney) {
            Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
        }

        // Per-process and would otherwise hold whatever a previous test file
        // left in it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Whether the estate has been provisioned centrally in this process. */
    public static function isProvisioned(): bool
    {
        return self::$provisioned;
    }

    /**
     * A user holding exactly one estate role, assigned to this estate.
     *
     * BOTH HALVES ARE NEEDED AND THEY ANSWER DIFFERENT QUESTIONS. The role
     * decides what they may do; the assignment decides whether they may reach
     * this estate at all — `EnsureEstateAccess` 404s an estate user with no
     * assignment, which would make every permission assertion below pass for the
     * wrong reason.
     */
    public static function viewer(string $roleName): User
    {
        $estate = self::platform();

        $user = User::factory()->create([
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

    /** The money modules an entry-level bookkeeper keys into, and what Entry carries on each. */
    public const BOOKKEEPER_MODULES = ['dues_ledger', 'payments', 'accounting_posting', 'vendor_costs', 'maintenance_budget'];

    /**
     * A BOOKKEEPER: an estate user with Entry on the five money modules and nothing else of money — no Reports, no
     * approval. Until M53 the seeded Admin Assistant held exactly these cells; the user then took every money module
     * from that role (M53 §1 Law 4). An estate can still build this role in its roles editor (M10 §4), so the rules
     * that kept such a role away from the estate's position are proven against this user: the Admin Assistant's old
     * cells, given directly.
     */
    public static function bookkeeper(): User
    {
        $user = self::viewer(Role::ESTATE_ADMIN_ASSISTANT);

        $user->givePermissionTo(collect(self::BOOKKEEPER_MODULES)
            ->flatMap(static fn (string $module): array => ["estate.{$module}.view", "estate.{$module}.create", "estate.{$module}.update"])
            ->all());

        return $user->fresh();
    }

    /**
     * A user holding one GEMINI role, with no estate assignment.
     *
     * The platform console is not an estate's, so there is nothing to assign
     * them to: a Director reaches every estate by role, and the cross-tenant
     * exports this fixture is used for are exactly that reach.
     */
    public static function geminiViewer(string $roleName): User
    {
        self::platform();

        /*
         * AN ADDRESS NO OTHER TEST CAN HAVE. The factory's `fake()->unique()` is
         * unique per app instance, and every test boots a fresh one — so a random
         * address can repeat a user an earlier test left in the shared central
         * database, and the insert dies on users_email_unique. That happened once
         * in a shuffled full run (seed 2409). A uuid cannot repeat.
         */
        $user = User::factory()->create([
            'email' => 'gemini-viewer-'.Str::uuid().'@example.com',
            'console' => Console::Gemini->value,
            'status' => 'active',
        ]);

        $user->syncRoles([$roleName]);

        return $user->fresh();
    }

    /** Where a screen lives on this estate's own hostname. */
    public static function url(string $path): string
    {
        return 'http://'.self::ESTATE.'.'.config('app.estate_domain').'/'.ltrim($path, '/');
    }

    /** The unit at this reference. Fails loudly rather than returning null. */
    public static function unit(string $reference): Unit
    {
        self::boot();

        return Unit::query()->where('reference', $reference)->firstOrFail();
    }

    /**
     * A lot of a test's OWN, made the first time it is asked for.
     *
     * THE FIVE LOTS ABOVE ARE SHARED BY EVERY FILE THAT BOOTS THIS FIXTURE, and
     * this estate's database is committed, not rolled back — so whatever one
     * test posts against Lot 3 is still there when the next file reads Lot 3.
     * The ledger is append-only, which makes the worst of it permanent: a test
     * that put a lot 120 days in arrears could never take that back, and the
     * Resident App's contract walk — which happened to be standing on the same
     * lot — was refused a vote it had every right to, but only when the amenity
     * suite had run first (M4 §4, defect 3).
     *
     * So a test that has to leave a mark a later reader would trip over — arrears,
     * a payment plan, a restriction — leaves it on a lot nobody else stands on.
     * Every other count in these suites is taken relative to what was there
     * (`EstateStructureWritesTest` adds thirteen lots of its own), so one more
     * lot moves no assertion.
     */
    /**
     * …AND A LOT BELONGS TO ONE FILE (M4 §4, defect 3 again).
     *
     * The estate database is committed, so the FIRST file to ask for a reference creates it and
     * every later file is handed that same row — with the phase the first one chose. Two files
     * asking for "Lot 982" in different phases is therefore an order dependence that shows up as
     * something else entirely: a phase-scoped notice simply not being served, in the file that ran
     * second. It is refused here instead, where the reason is obvious.
     *
     * AND "PHASE 9" IS THIS FIXTURE'S SCRATCH BLOCK, SO NO TEST MAY ADD A PHASE BY THAT NAME.
     * `EstateStructure::addPhase` refuses to claim a block that already has units in it — rightly
     * — so a test that adds "Phase 9" passes alone and is refused the moment any file above has
     * made a scratch lot. `EstateStructureWritesTest` adds "Hillside" for exactly this reason.
     */
    public static function scratchUnit(string $reference, string $phase = 'Phase 9'): Unit
    {
        self::boot();

        $unit = Unit::query()->firstOrCreate(
            ['reference' => $reference],
            ['block' => $phase, 'street' => $phase.' Drive', 'type' => 'residential', 'status' => 'occupied'],
        );

        if ($unit->block !== $phase) {
            throw new RuntimeException(
                $reference.' already exists in '.$unit->block.', and was asked for in '.$phase.'. A scratch lot belongs to ONE test file: '
                .'give this one a reference no other file uses, or the two will pass alone and fail together.'
            );
        }

        return $unit;
    }

    /** One ticket by the number board 17 prints and board 18's URL uses. */
    public static function ticket(int $number): MaintenanceTicket
    {
        self::boot();

        return MaintenanceTicket::query()->where('number', $number)->firstOrFail();
    }

    /** One amenity by the name board 20 draws on its card. */
    public static function amenity(string $name): Amenity
    {
        self::boot();

        return Amenity::query()->where('name', $name)->firstOrFail();
    }

    /** One vendor from the register board 26 keeps. */
    public static function vendor(string $name): Vendor
    {
        self::boot();

        return Vendor::query()->where('name', $name)->firstOrFail();
    }

    /** The seeded booking for one amenity at one lot — board 19's own rows. */
    public static function booking(string $amenity, string $unitReference): AmenityBooking
    {
        self::boot();

        return AmenityBooking::query()
            ->where('amenity_id', self::amenity($amenity)->id)
            ->where('unit_id', self::unit($unitReference)->id)
            ->orderBy('starts_at')
            ->firstOrFail();
    }

    /** Five lots, at the phases the boards put them in. */
    private static function buildEstate(): void
    {
        foreach (self::UNITS as [$reference, $phase]) {
            Unit::create([
                'reference' => $reference,
                'block' => $phase,
                'street' => $phase.' Drive',
                'type' => 'residential',
                'status' => 'occupied',
            ]);
        }
    }
}
