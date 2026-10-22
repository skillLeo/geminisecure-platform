<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A ROUTED ESTATE THAT BELONGS TO ONE TEST FILE AND TO NO OTHER (M11 B5).
 *
 * WHY IT EXISTS. `FacilitiesFixture`'s estate is shared by every file that boots it, and its
 * database is committed rather than rolled back, so each of those files is only as isolated as
 * the least careful file it shares with. Four files ran `EstateFinanceSeeder` over that shared
 * estate in their `beforeEach` — EstateReportsTest, EstateCollectionFlagTest, EstateDocumentsTest
 * and EstateResidentEditTest. The seeder's guard finds no 450-lot estate there, so the FIRST of
 * the four to run in a process TRUNCATEd forty-two of the shared estate's tables (`resetLedger`)
 * and rebuilt it as 450 lots with six months of dues: in the middle of the process, after
 * whichever files the shuffle had already run and before the rest. Every other file standing on
 * the facilities estate therefore met one of two different estates depending on the seed, and
 * a row a file had committed there — a scratch lot, say — could vanish under it. Three of the
 * four also left their own writes there for everybody after them: a household whose primary
 * resident was renamed and unticked, the dunning notices of an automated run, an estate logo.
 *
 * WHAT IT IS. What `EstateSettingsTest` does for `settingstest` and `ReviewerModeTest` for
 * `reviewdemo`, as one class: a database named from the CONFIGURED tenancy prefix (see
 * `FacilitiesFixture::database()` for why it is never a literal), built by the real tenant
 * migrations, with a central tenant row and domain so that the estate is reached the way a
 * committee reaches theirs — through its own subdomain, by a user with an assignment to it.
 *
 * WHAT IT IS NOT. It seeds nothing into the estate. The file that owns it runs the seeder it is
 * about, exactly as it did against the shared estate, so moving a file here changes WHERE its
 * rows live and nothing about WHAT they are.
 *
 * ONCE PER PROCESS, DROPPED AND REBUILT. The first `boot()` in a process tears the database down
 * and builds it again, so a run that stopped half way cannot leave the next one reading a
 * half-migrated estate. The central rows are committed, because tenancy resolves an estate from
 * the central database on every request and a row inside a test's transaction would vanish when
 * the first test ended; they are deleted and written again at the same moment, so a row left by
 * an older run is never trusted.
 *
 * WRITTEN RAW, WITHOUT `EstateProvisioner`, for the reason `EstateSettingsTest` gives: the
 * provisioning pipeline creates a MySQL user per estate, and a suite that rebuilds its estates on
 * every run must not accumulate one per run. With no `db_username` on the row, stancl connects
 * through the template connection. The append-only grants are proven where they can be proven
 * honestly, by `php artisan gate:isolation`.
 *
 * ONE KEY, ONE FILE. Two files handed the same key would share an estate again, which is the
 * defect this class exists to end. Each key is written once, in the file that owns it.
 */
final class OwnEstate
{
    /** @var array<string, true> the keys whose estate has been built in this process */
    private static array $built = [];

    /**
     * @param  string  $key  the subdomain, the tenant id and the database suffix, used by one file only
     * @param  string  $receiptPrefix  unique across the platform, as every estate's is
     * @param  string  $name  the estate's name on every screen that draws it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $receiptPrefix,
        public readonly string $name,
    ) {}

    /** The configured tenancy prefix, then the key: the database tenancy itself resolves this estate to. */
    public function database(): string
    {
        return config('tenancy.database.prefix').$this->key;
    }

    /**
     * Point the tenant connection, and the default, at this estate, building it on first use.
     *
     * Re-applied on every call rather than once, for the reason `FacilitiesFixture::boot()` gives:
     * Laravel builds a fresh application for each test while the PHP process survives, and an HTTP
     * request leaves tenancy's own connection behind it.
     *
     * AND THE MATRIX ON EVERY CALL, as `FacilitiesFixture::platform()` checks it: two cheap
     * queries, and the only thing that seeds it again after a `RefreshDatabase` file has rebuilt
     * the central database earlier in the process.
     */
    public function boot(): void
    {
        FacilitiesFixture::matrix();

        if (! isset(self::$built[$this->key])) {
            $this->build();

            self::$built[$this->key] = true;
        }

        $this->point();
    }

    /** The estate's central record, fetched fresh: a model kept in a static would outlive its application. */
    public function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->key);
    }

    /**
     * A user holding exactly one estate role, assigned to THIS estate.
     *
     * `FacilitiesFixture::viewer()` for this estate. Both halves answer different questions: the
     * role decides what they may do, the assignment whether they may reach this estate at all —
     * `EnsureEstateAccess` 404s an estate user with none, which would make every permission
     * assertion pass for the wrong reason.
     */
    public function viewer(string $roleName): User
    {
        $this->boot();

        $user = User::factory()->create([
            'console' => Console::Estate->value,
            'status' => 'active',
        ]);

        $user->syncRoles([$roleName]);

        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $this->key,
            'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /** Where a screen lives on this estate's own hostname. */
    public function url(string $path): string
    {
        return 'http://'.$this->key.'.'.config('app.estate_domain').'/'.ltrim($path, '/');
    }

    private function point(): void
    {
        config([
            'database.connections.tenant' => array_merge(
                config('database.connections.mysql'),
                ['database' => $this->database()],
            ),
            'database.default' => 'tenant',
        ]);

        DB::purge('tenant');
    }

    private function build(): void
    {
        $owner = DB::connection('mysql_owner');

        $owner->statement('DROP DATABASE IF EXISTS `'.$this->database().'`');
        $owner->statement('CREATE DATABASE `'.$this->database().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::connection('mysql')->table('domains')->where('tenant_id', $this->key)->delete();
        DB::connection('mysql')->table('tenants')->where('id', $this->key)->delete();

        /*
         * NO `provisioned_at`, as `FacilitiesFixture`'s estate has none. Nothing that decides
         * access reads it. The Gemini console's activity feed, its recent estates, the client
         * directory and the revenue reports do, and a committed test estate stamped
         * "provisioned now" pushes every other file's rows down that eight-row feed: this
         * class's own stamps, on top of the five estates three other fixtures already stamp,
         * took GeminiAdminAssistantSeesNoMoneyTest's onboarding off it.
         */
        DB::connection('mysql')->table('tenants')->insert([
            'id' => $this->key,
            'name' => $this->name,
            'receipt_prefix' => $this->receiptPrefix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * The BARE SUBDOMAIN, as `EstateProvisioner` stores it: `InitializeTenancyBySubdomain`
         * strips the central domain off the host and looks up what remains.
         */
        DB::connection('mysql')->table('domains')->insert([
            'domain' => $this->key,
            'tenant_id' => $this->key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->point();

        Artisan::call('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'tenant',
            '--force' => true,
        ]);
    }
}
