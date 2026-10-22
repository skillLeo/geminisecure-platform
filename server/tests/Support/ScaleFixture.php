<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Commands\PhoenixDemoSeed;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\Tenant;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * AN ESTATE THE SIZE PHOENIX PARK VILLAGE 1 REALLY IS (M11 A2).
 *
 * 2,000 lots across five phases, 441 households on them, and six months of dues:
 * 12,015 charges. Those are production's figures, and a query budget proven on
 * FacilitiesFixture's five lots proves nothing about them — a list that fires one
 * query per row is invisible at five rows and is the whole request at two thousand.
 *
 * BUILT BY THE REPO'S OWN SCALE TOOLING, NOT BY HAND. `phoenix:demo-seed` fills a
 * real estate by setting `EstateFinanceSeeder::$phases` to Phoenix Park's table and
 * adding the 2,000th lot; this does exactly that, reading the table and the extra
 * lot from `PhoenixDemoSeed` itself so there is still only one place they live. The
 * development database's `phoenixscale` estate was built the same way and holds the
 * same 2,000 / 441 / 12,015.
 *
 * A DATABASE OF ITS OWN, named from the configured tenancy prefix like every other
 * fixture (see `FacilitiesFixture::database()`), built once per process by the real
 * tenant migrations. It is committed, and the budget tests only READ it: every
 * central row a test adds goes inside that test's own rolled-back transaction.
 *
 * `verify()` REFUSES A SMALL ESTATE. A budget test that quietly ran against a
 * half-built fixture would pass for the wrong reason, so the counts are asserted
 * before any figure is taken.
 */
final class ScaleFixture
{
    /**
     * The tenant key, subdomain and database suffix.
     *
     * NOT `phoenixscale` ANY MORE, which `EstateScaleFixture` also builds, differently: its
     * receipts carry `PHOENI`, these `PSC`. The two units were written apart, and each passed
     * alone; in one suite, whichever built the database first was the one the other reused, and
     * the second's receipt tests read a sequence that did not exist. One estate each.
     */
    public const ESTATE = 'phoenixscaleg';

    public const LOTS = 2000;

    public const HOUSEHOLDS = 441;

    public const CHARGES = 12015;

    /** Unique across the platform, and not PPV, which the seeded Phoenix Park holds. */
    public const RECEIPT_PREFIX = 'PSC';

    private static bool $built = false;

    /** Seconds the build took in this process, for the report. Null when nothing was built. */
    public static ?float $buildSeconds = null;

    public static function database(): string
    {
        return config('tenancy.database.prefix').self::ESTATE;
    }

    /**
     * Point the tenant connection at the scale estate, building it on first use.
     *
     * Re-applied on every call for FacilitiesFixture's reason: Laravel refreshes the
     * application between tests, and an HTTP request leaves the connection pointed at
     * tenancy's own making.
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

        if (self::reusable()) {
            self::$built = true;

            return;
        }

        $started = microtime(true);

        $owner = DB::connection('mysql_owner');
        $owner->statement('DROP DATABASE IF EXISTS `'.self::database().'`');
        $owner->statement('CREATE DATABASE `'.self::database().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('tenant');

        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        /*
         * PHOENIX PARK'S TABLE, READ FROM THE COMMAND THAT OWNS IT, and put back in a
         * `finally` exactly as the command does — every other caller of the seeder is
         * the board's 450-unit estate and must stay so.
         */
        EstateFinanceSeeder::$phases = (new ReflectionClassConstant(PhoenixDemoSeed::class, 'PHASES'))->getValue();

        try {
            Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);
        } finally {
            EstateFinanceSeeder::$phases = null;
        }

        // The 2,000th lot, through the command's own method.
        (new ReflectionMethod(PhoenixDemoSeed::class, 'extraLot'))->invoke(app(PhoenixDemoSeed::class));

        self::$built = true;
        self::$buildSeconds = microtime(true) - $started;

        self::verify();
    }

    /**
     * A scale estate an EARLIER process built, kept only when asked for and only when whole.
     *
     * The build is ninety seconds of real seeding, which is right for a suite run and
     * punishing for somebody iterating on one budget. `GS_SCALE_FIXTURE_REUSE=1` keeps the
     * database an earlier run left — but only if every tenant migration has run on it and
     * it still holds exactly 2,000 lots, 441 households and 12,015 charges. Anything else
     * is rebuilt, and without the variable it is always rebuilt, as every fixture here is.
     */
    private static function reusable(): bool
    {
        if (getenv('GS_SCALE_FIXTURE_REUSE') !== '1') {
            return false;
        }

        $exists = DB::connection('mysql_owner')->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?',
            [self::database()],
        );

        if ((int) $exists->n === 0) {
            return false;
        }

        $files = count(glob(database_path('migrations/tenant/*.php')) ?: []);

        try {
            $ran = DB::connection('tenant')->table('migrations')->count();

            return $ran === $files
                && Unit::query()->count() === self::LOTS
                && Household::query()->count() === self::HOUSEHOLDS
                && Charge::query()->count() === self::CHARGES;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The estate's size, asserted. Throws rather than returns false: a budget measured
     * on anything smaller is not a measurement at production volume.
     *
     * @return array{lots: int, households: int, charges: int}
     */
    public static function verify(): array
    {
        self::boot();

        $counts = [
            'lots' => Unit::query()->count(),
            'households' => Household::query()->count(),
            'charges' => Charge::query()->count(),
        ];

        if ($counts !== ['lots' => self::LOTS, 'households' => self::HOUSEHOLDS, 'charges' => self::CHARGES]) {
            throw new RuntimeException('The scale estate is not production-sized: '.json_encode($counts).'. Expected '.self::LOTS.' lots, '.self::HOUSEHOLDS.' households and '.self::CHARGES.' charges.');
        }

        return $counts;
    }

    /**
     * The central half: the role matrix and the estate's own tenant row and domain.
     *
     * Committed and once per process, like `FacilitiesFixture::platform()`, and
     * WITHOUT EVENTS for the same reason — the database above is already built, and
     * the provisioning pipeline would fail on it or leave a MySQL user behind.
     */
    public static function platform(): Tenant
    {
        self::boot();

        /*
         * SEEDED ON THE NEWEST CELLS, NOT ON THE ROLE — `FacilitiesFixture::platform()`'s
         * rule, and its two checks: a matrix a version behind has every role and still
         * refuses the newest screens, which would read as a broken route.
         */
        $current = Role::query()
            ->where('name', Role::PROPERTY_MANAGER)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.facilities.approve'))
            ->exists();

        $hasSecurity = Role::query()
            ->where('name', Role::ESTATE_HEAD_OF_SECURITY)
            ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.security.view'))
            ->exists();

        if (! $current || ! $hasSecurity || ! Role::query()->where('name', Role::DIRECTOR)->exists()) {
            Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $estate = Tenant::query()->find(self::ESTATE);

        if (! $estate instanceof Tenant) {
            $estate = Tenant::withoutEvents(static function (): Tenant {
                $estate = new Tenant;

                $estate->forceFill([
                    'id' => self::ESTATE,
                    'name' => 'Phoenix Park Village 1 (scale proof)',
                    'receipt_prefix' => self::RECEIPT_PREFIX,
                    'status' => 'active',
                    'parish' => 'St. Andrew',
                    'address_line' => 'Waterloo Road',
                    'provisioned_at' => now()->subMonths(7),
                ])->save();

                return $estate;
            });
        }

        if (! $estate->domains()->where('domain', self::ESTATE)->exists()) {
            $estate->domains()->create(['domain' => self::ESTATE]);
        }

        return $estate;
    }

    /** Where a screen lives on this estate's own hostname. */
    public static function url(string $path): string
    {
        return 'http://'.self::ESTATE.'.'.config('app.estate_domain').'/'.ltrim($path, '/');
    }
}
