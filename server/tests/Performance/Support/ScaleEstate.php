<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Support\DemonstrationAccounts;
use Database\Seeders\RbacMatrixSeeder;
use Database\Seeders\SiteEmergencyNumbersSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * PHOENIX PARK VILLAGE 1 AT ITS PRODUCTION SIZE, IN THIS SLOT'S OWN DATABASES (M11 A1).
 *
 * "Measure at that volume, never on a small fixture." The size is not typed here. It comes from
 * the command that filled production: `phoenix:demo-seed`, whose PHASES table is the ONE place
 * Phoenix Park's 2,000 lots and 441 households live (441 being the demonstration's; the real community has 2,000
 * homes, M26), and which bills them through the same
 * `EstateFinanceSeeder` the server ran. The Gemini side comes from `company:demo-seed`, the
 * command that filled the company console on production. Nothing about the estate is invented
 * in this file; it only provides what those two commands expect to find already there.
 *
 * WHAT IT PROVIDES, AND WHY EACH IS NOT THE REAL PIPELINE:
 *
 *   the estate's database   created and migrated here, the way `FacilitiesFixture` does, rather
 *                           than by `estate:provision`. Provisioning creates a MySQL USER per
 *                           estate on the shared server, which is a global side effect a
 *                           measurement has no business leaving behind; the tenant row is
 *                           written without events so that pipeline does not run. The
 *                           connection then authenticates as the slot's owner, which changes
 *                           no query and no plan.
 *   three more estates      production serves four estates (its nginx names demoestate,
 *                           demoestate2, phoenixparkvillage1, skillleoestats). Every Gemini page
 *                           that walks the client list pays per estate, so four are built.
 *                           Their CONTENTS could not be read from production, so the three
 *                           beside Phoenix Park are migrated and empty — said so in BASELINE.md.
 *   the accounts measured   a Director and a Gemini Head of Security on the demonstration
 *                           domain, and the seeded Community Super Admin, each given a password
 *                           generated here, hashed at production's bcrypt cost, and written only
 *                           to PERF_OUT/accounts.json — outside the repository, never printed.
 *
 * IDEMPOTENT. An estate that already has its 2,000 lots is left exactly as it is; the accounts
 * are refreshed on every run.
 *
 * DEV ONLY, AND ONLY IN A SLOT. It refuses to run against a central database that is not a
 * slot's (`gs_estate_…p`), so it can never write into the dev databases.
 */
final class ScaleEstate
{
    public const ESTATE = 'phoenixscale';

    public const NAME = 'Phoenix Park Village 1';

    public const RECEIPT_PREFIX = 'PSX';

    /** [key, name, receipt prefix, demo] — production's other three estates, built empty. */
    public const OTHERS = [
        ['demoestate', 'Seaview Demo Estate (demonstration data)', 'SDE', true],
        ['demoestate2', 'Demo Estate Two', 'DTW', false],
        ['skillleoestats', 'Skillleo Estates', 'SKL', false],
    ];

    public const DIRECTOR_EMAIL = 'perf-director@demo.geminisl.com';

    public const HEAD_OF_SECURITY_EMAIL = 'perf-head-of-security@demo.geminisl.com';

    /** `phoenix:demo-seed`'s own Community Super Admin at this key. */
    public const ESTATE_ADMIN_EMAIL = 'dahlia-community-super-admin@'.self::ESTATE.DemonstrationAccounts::DEMO_DOMAIN_SUFFIX;

    /** Where everything that must not enter the repository is written: passwords, credentials, logs. */
    public static function out(): string
    {
        $out = getenv('PERF_OUT');

        if (! is_string($out) || $out === '') {
            throw new RuntimeException('Set PERF_OUT to a directory outside the repository (the harness writes passwords there).');
        }

        if (! is_dir($out)) {
            mkdir($out, 0700, true);
        }

        return rtrim($out, '\\/');
    }

    public static function assertSlot(): void
    {
        $central = (string) config('database.connections.mysql.database');
        $prefix = (string) config('tenancy.database.prefix');

        if (! preg_match('/^gs_estate_[a-z0-9]+p$/', $central) || ! str_starts_with($prefix, 'gs_estate_') || $prefix === 'gs_estate_') {
            throw new RuntimeException("Refused: [{$central}] with prefix [{$prefix}] is not a slot's database. Run this through t.ps1.");
        }
    }

    /**
     * Build what is missing and report the size of what is there.
     *
     * @return array<string, mixed>
     */
    public static function ensure(): array
    {
        self::assertSlot();
        $out = self::out();
        $log = [];

        /*
         * THE CENTRAL CATALOGUE THE TWO COMMANDS EXPECT. The role matrix before any account is
         * given a role; the statutory rate card before `phoenix:demo-seed` builds a payroll run
         * on it (it skips the payslips, and says so, without one).
         */
        if (Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->doesntExist()) {
            Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
            $log[] = 'rbac matrix seeded';
        }

        if (DB::connection('mysql')->table('statutory_rate_versions')->doesntExist()) {
            Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
            $log[] = 'statutory rates seeded';
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $estate = self::estate(self::ESTATE, self::NAME, self::RECEIPT_PREFIX, false);

        foreach (self::OTHERS as [$key, $name, $prefix, $demo]) {
            self::estate($key, $name, $prefix, $demo);
        }

        $units = (int) $estate->run(static fn (): int => DB::connection('tenant')->table('units')->count());

        if ($units === 0) {
            $started = microtime(true);
            $code = Artisan::call('phoenix:demo-seed', [
                '--confirm' => true,
                '--estate' => self::ESTATE,
                '--credentials' => $out.DIRECTORY_SEPARATOR.'phoenix-demo-credentials.txt',
            ]);
            file_put_contents($out.DIRECTORY_SEPARATOR.'phoenix-demo-seed.log', Artisan::output());

            if ($code !== 0) {
                throw new RuntimeException('phoenix:demo-seed failed; see '.$out.DIRECTORY_SEPARATOR.'phoenix-demo-seed.log');
            }

            $log[] = sprintf('phoenix:demo-seed %.1f s', microtime(true) - $started);
        }

        $started = microtime(true);
        $code = Artisan::call('company:demo-seed', ['--estate' => self::ESTATE]);
        file_put_contents($out.DIRECTORY_SEPARATOR.'company-demo-seed.log', Artisan::output());

        if ($code !== 0) {
            throw new RuntimeException('company:demo-seed failed; see '.$out.DIRECTORY_SEPARATOR.'company-demo-seed.log');
        }

        $log[] = sprintf('company:demo-seed %.1f s', microtime(true) - $started);

        /*
         * THE FOUR NUMBERS PRODUCTION TEXTS ON A PANIC AT PHOENIX PARK (Q-M09). The seeder keys
         * them to its own estate id, so they are put here through the service it uses.
         */
        if (SiteEmergencyNumber::query()->where('tenant_id', self::ESTATE)->doesntExist()) {
            foreach (SiteEmergencyNumbersSeeder::NUMBERS as $index => $row) {
                app(SiteEmergencyNumbers::class)->put(self::ESTATE, $row['role'], $row['label'], $row['number'], $index + 1);
            }
        }

        self::accounts($estate);

        return ['built' => $log, ...self::size()];
    }

    /** One estate: its database, its schema, its tenant row and its domain. */
    public static function estate(string $key, string $name, string $prefix, bool $demo): Tenant
    {
        $database = config('tenancy.database.prefix').$key;
        $owner = DB::connection('mysql_owner');
        $exists = $owner->select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]) !== [];

        /*
         * A DATABASE WITH NO TENANT ROW IS A LEFTOVER, NEVER AN ESTATE TO ADOPT — the rule
         * `EstateProvisioner` enforces, for the reason it gives. Here it is what `t.ps1 -Fresh`
         * leaves: the central database is rebuilt and the estate's is not, so its 2,000 lots would
         * be found, the seed skipped, and the estate left with no committee, no officers and no
         * rows anywhere central. It is dropped and built again, from empty.
         */
        if ($exists && Tenant::query()->whereKey($key)->doesntExist()) {
            $owner->statement('DROP DATABASE `'.$database.'`');
            $exists = false;
        }

        if (! $exists) {
            $owner->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
        DB::purge('tenant');

        Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'tenant', '--force' => true]);

        DB::purge('tenant');

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $estate = Tenant::withoutEvents(static function () use ($key, $name, $prefix, $demo): Tenant {
                $estate = new Tenant;
                $estate->forceFill([
                    'id' => $key,
                    'name' => $name,
                    'receipt_prefix' => $prefix,
                    'status' => 'active',
                    'demo' => $demo,
                    'provisioned_at' => Carbon::now(),
                ])->save();

                return $estate;
            });
        }

        if (! $estate->domains()->where('domain', $key)->exists()) {
            $estate->domains()->create(['domain' => $key]);
        }

        return $estate->refresh();
    }

    /**
     * The accounts the consoles are measured as, each with a password only PERF_OUT knows.
     *
     * Hashed at bcrypt cost 12 — production's — straight into the column, past the model's
     * `hashed` cast, which in the test environment would hash at cost 4.
     */
    private static function accounts(Tenant $estate): void
    {
        $file = self::out().DIRECTORY_SEPARATOR.'accounts.json';
        $saved = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $password = is_string($saved['password'] ?? null) ? $saved['password'] : Str::password(24, symbols: false);

        $director = self::consoleUser(self::DIRECTOR_EMAIL, 'Perf Director', Console::Gemini, Role::DIRECTOR);
        $head = self::consoleUser(self::HEAD_OF_SECURITY_EMAIL, 'Perf Head of Security', Console::Gemini, Role::HEAD_OF_SECURITY);

        // The Gemini Head of Security COVERS this estate, as Devon Clarke covers Phoenix Park, so a
        // panic's alert mail has somebody to go to.
        EstateAssignment::query()->updateOrCreate(
            ['user_id' => $head->id, 'tenant_id' => $estate->getTenantKey()],
            ['role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->value('id'), 'is_active' => true],
        );

        $admin = User::query()->where('email', self::ESTATE_ADMIN_EMAIL)->first();

        if (! $admin instanceof User) {
            throw new RuntimeException('phoenix:demo-seed did not create '.self::ESTATE_ADMIN_EMAIL.'.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        DB::connection('mysql')->table('users')->whereIn('id', [$director->id, $head->id, $admin->id])->update(['password' => $hash]);

        file_put_contents($file, json_encode([
            'password' => $password,
            'director' => self::DIRECTOR_EMAIL,
            'head_of_security' => self::HEAD_OF_SECURITY_EMAIL,
            'estate_admin' => self::ESTATE_ADMIN_EMAIL,
            'estate' => self::ESTATE,
        ], JSON_PRETTY_PRINT));
    }

    private static function consoleUser(string $email, string $name, Console $console, string $role): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'console' => $console->value,
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
        ]);

        if (! $user->exists) {
            // A placeholder the column requires; replaced below by the real hash.
            $user->forceFill(['password' => Str::random(40)]);
        }

        $user->save();
        $user->syncRoles([$role]);
        DemonstrationAccounts::verify($user);

        return $user->fresh();
    }

    /**
     * How big the estate and the platform actually are, counted.
     *
     * @return array<string, mixed>
     */
    public static function size(): array
    {
        $estate = Tenant::query()->findOrFail(self::ESTATE);

        $tenant = $estate->run(static function (): array {
            $count = static fn (string $table): int => (int) DB::connection('tenant')->table($table)->count();

            return [
                'units' => $count('units'),
                'households' => $count('households'),
                'residents' => $count('residents'),
                'charges' => $count('charges'),
                'payments' => $count('payments'),
                'journals' => $count('journals'),
                'journal_lines' => $count('journal_lines'),
                'maintenance_tickets' => $count('maintenance_tickets'),
                'notices' => $count('notices'),
                'amenity_bookings' => $count('amenity_bookings'),
            ];
        });

        $central = static fn (string $table): int => (int) DB::connection('mysql')->table($table)->count();

        return [
            ...$tenant,
            'central' => [
                'tenants' => $central('tenants'),
                'users' => $central('users'),
                'guards' => $central('guards'),
                'posts' => $central('posts'),
                'shifts' => $central('shifts'),
                'duress_alerts' => $central('duress_alerts'),
                'gate_events' => $central('gate_events'),
                'invoices' => $central('invoices'),
                'audit_log' => $central('audit_log'),
            ],
        ];
    }
}
