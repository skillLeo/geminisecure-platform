<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console;
use App\Exceptions\MissingTenantContextException;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Jobs\Estate\RecalculateHouseholdStanding;
use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Live\CrossTenantChannelProbe;
use App\Services\Live\LivePayloadAudit;
use App\Services\Rbac\CrossTenantRoleProbe;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Phase 1 acceptance gate: 06_OVERRIDE section 7, steps 3 to 9, step 10
 * from M10 section 4 (a role one estate makes never reaches another), and
 * steps 11 to 14 from M11 Part C (live channels, catch-up and the live log).
 *
 *   php artisan gate:isolation
 *
 * Exits non-zero on any failure so it is usable in CI rather than something a
 * human has to read carefully. Every assertion states what it proves, because
 * a gate nobody understands is a gate nobody maintains.
 *
 * Deliberately pure ASCII. This file is read and rewritten by tooling on
 * Windows, where a UTF-8 round-trip can double-encode the content and place a
 * BOM ahead of the strict_types declaration, which breaks the file outright.
 */
class GateTenantIsolation extends Command
{
    protected $signature = 'gate:isolation {--estate-a=phoenixpark} {--estate-b=oceanview}';

    protected $description = 'Prove tenant isolation and append-only enforcement at the database layer';

    /**
     * The gate on records/residents/{id}, which STEP 3's positive control reads:
     * the Residents register's own (bug hunt B072).
     */
    private const CONTROL_GATE = 'estate.residents.view';

    private int $failures = 0;

    public function handle(): int
    {
        $a = Tenant::findOrFail($this->option('estate-a'));
        $b = Tenant::findOrFail($this->option('estate-b'));

        $this->line('');
        $this->line('=====================================================================');
        $this->line(' PHASE 1 GATE - tenant isolation, RBAC and append-only records');
        $this->line('=====================================================================');

        $this->step3CrossEstateRoutesDenied($a, $b);
        $this->step4CrossEstateQueryFailsOnGrant($a, $b);
        $this->step5NoWildcardGrant();
        $this->step6QueuedJobWithoutTenantThrows();
        $this->step7JournalsAppendOnly($a);
        $this->step7bAuditLogAppendOnly();
        $this->step8NavigationMatchesMatrix();
        $this->step9IdentityIsCentral($a);
        $this->step10RolesCannotCrossTenants($a, $b);
        $this->steps11to13LiveChannelsCannotCrossTenants($a, $b);
        $this->step14LiveLogHoldsNothingForbidden();

        $this->line('');

        if ($this->failures > 0) {
            $this->error(" GATE FAILED - {$this->failures} assertion(s) did not hold");

            return self::FAILURE;
        }

        $this->info(' GATE PASSED - every assertion held');

        return self::SUCCESS;
    }

    /**
     * Step 3 - authenticated as a committee member of estate A, request a
     * known record of estate B through every route. Any 200 fails the build.
     */
    private function step3CrossEstateRoutesDenied(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 3 - {$a->getTenantKey()} user requests {$b->getTenantKey()} records by id");

        /*
         * ANY committee member of estate A, not one seeder's naming convention.
         *
         * This asked for `president@<estate>.test`, which is what `DemoDataSeeder` creates. On
         * production the committee comes from `phoenix:demo-seed` and the President is
         * `everton-president@phoenixparkvillage1.demo.geminisl.com`, so the probe found nobody and
         * reported "run DemoDataSeeder first" — and the gate as a whole said FAILED. Every real
         * isolation assertion around it passed; the one that did not hold was the probe's own
         * ability to find a subject.
         *
         * That is the worst way for a safety gate to fail: a red line that means nothing is a red
         * line people learn to skip past, and the next one will be real. So the subject is now
         * found the way the application itself decides who belongs to an estate — an active user
         * with an active assignment to it — and the seeded convention is merely preferred when it
         * happens to exist, so a development run still probes the same account it always did.
         *
         * AN ACCOUNT WITH A PROVEN ALERT ADDRESS FIRST (M10 §1.4). The contact gate sends an
         * unverified committee member to the verification page before any estate screen, so a
         * probe that happened to pick one would see its own-estate CONTROL answer 302 and fail
         * the gate over something that is not isolation. The cross-estate 404s would still hold
         * — `EnsureEstateAccess` answers before the contact gate is reached — but the control is
         * what gives them meaning, so the subject is one the gate lets through.
         *
         * AN ESTATE ACCOUNT THAT MAY READ THE RESIDENTS REGISTER (bug hunt B072). The records
         * probed below carry the register's own gate, `estate.residents.view`. Every SEEDED
         * estate role holds it and no Gemini role does, but a role a community makes for itself
         * (M10 section 4) may hold no Residents access at all, and Gemini's Head of Security
         * holds an active assignment to the estate too. Either, picked here, would see the
         * CONTROL answer a correct 403 and fail the gate over no isolation fault. So the subject
         * is asked the route's own question, through `can()` as the route asks it, and the
         * seeded President is preferred on those terms and no others: a matching address is no
         * reason to probe as an account that is suspended, unverified or in another role.
         */
        $user = $this->probeSubject($a);

        if (! $user) {
            $this->assert(
                false,
                'committee user exists to probe with',
                "no active committee member of {$a->getTenantKey()} may read its Residents register (".self::CONTROL_GATE.") - seed the estate's committee first",
            );

            return;
        }

        $this->line("  authenticated as {$user->email} (".$a->getTenantKey().' committee)');

        /*
         * Positive control, first and deliberately.
         *
         * Without it this whole step passes when the application is simply
         * broken: every route 404s, the assertions all hold, and the gate
         * reports an isolation it never demonstrated. Proving the user CAN
         * reach their own estate is what gives the denials below any meaning.
         */
        $ownId = $this->existingRecordIds($a)['residents'] ?? null;

        if ($ownId !== null) {
            $status = $this->requestAs($user, $a, "records/residents/{$ownId}");

            $this->assert(
                $status === 200,
                "CONTROL - same user CAN read their own estate resident {$ownId}, got 200",
                "got {$status}; the denials below prove nothing if this fails",
            );
        }

        // Ids that genuinely exist in estate B, so a leak returns a plausible
        // record rather than a 404 for a row that was never there.
        foreach ($this->existingRecordIds($b) as $resource => $id) {
            if ($id === null) {
                $this->line("  SKIP {$resource}: no seeded record in {$b->getTenantKey()}");

                continue;
            }

            $status = $this->requestAs($user, $b, "records/{$resource}/{$id}");

            /*
             * 404 specifically, not merely "not 200".
             *
             * A 500 is also non-200 while saying nothing about whether the
             * boundary held; it could be an unrelated crash that happens to
             * precede the check. A 403 would confirm the estate and the record
             * id both exist, which is an enumeration oracle between competing
             * communities. The specified behaviour is 404, so this asserts 404.
             */
            $this->assert(
                $status === 404,
                sprintf('GET records/%s/%s from the wrong estate, got %d', $resource, $id, $status),
                $status === 200
                    ? 'LEAK: the record was returned across the estate boundary'
                    : "expected 404, got {$status}",
            );
        }
    }

    /**
     * The committee member of estate A that STEP 3 signs in as, or null when it
     * has none the control can be run as.
     *
     * Public so the test suite can ask the same question of estates of its own:
     * the gate itself runs against live databases a test must never touch.
     */
    public function probeSubject(Tenant $a): ?User
    {
        return User::query()
            ->where('status', 'active')
            ->where('console', Console::Estate->value)
            ->whereHas('assignments', fn ($q) => $q
                ->where('tenant_id', $a->getTenantKey())
                ->where('is_active', true))
            ->orderByRaw('contact_email_verified_at IS NULL')
            ->orderByRaw('email <> ?', ["president@{$a->getTenantKey()}.test"])
            ->orderBy('id')
            ->get()
            ->first(fn (User $user): bool => $user->canAccessEstate((string) $a->getTenantKey()) && $user->can(self::CONTROL_GATE));
    }

    /**
     * Issues one authenticated request against an estate subdomain.
     *
     * GET by default. A write (STEP 10) carries `Sec-Fetch-Site: same-origin`,
     * which is what a browser sends for a form posted from the estate's own
     * page and what the CSRF middleware accepts in place of a token - so the
     * probe walks the real middleware stack, CSRF check included, rather than
     * a route with the check switched off.
     *
     * @param  array<string, mixed>  $data
     */
    private function requestAs(User $user, Tenant $tenant, string $path, string $method = 'GET', array $data = []): int
    {
        return $this->exchangeAs($user, $tenant, $method, $path, $data)['status'];
    }

    /**
     * The same request, answering where a redirect went as well as its status.
     *
     * STEP 10 needs both: a refusal and the contact gate (M10 section 1.4) are
     * each a 302, and only the Location tells a POST the controller refused
     * from one the gate turned away before it got there.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: int, location: string|null}
     */
    private function exchangeAs(User $user, Tenant $tenant, string $method, string $path, array $data = []): array
    {
        Auth::guard('web')->login($user);

        $host = $tenant->getTenantKey().'.'.config('app.estate_domain');

        // A session made at this estate's own door (M59), so the estate's own check is what answers.
        BindSessionToItsDoor::madeAt($host);
        $server = $method === 'GET' ? [] : ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

        $response = app(HttpKernel::class)->handle(
            Request::create("http://{$host}/{$path}", $method, $data, [], [], $server)
        );

        Auth::guard('web')->logout();
        tenancy()->end();

        return ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location')];
    }

    /**
     * The real ids seeded into an estate, so the probe asks for records that
     * exist rather than ones that would 404 regardless.
     *
     * @return array<string, int|null>
     */
    private function existingRecordIds(Tenant $tenant): array
    {
        $ids = [];

        $tenant->run(function () use (&$ids) {
            foreach (['residents', 'households', 'units', 'charges', 'journals'] as $table) {
                $ids[$table] = DB::connection('tenant')->table($table)->min('id');
            }
        });

        return $ids;
    }

    /**
     * Step 4 - a query against another estate must fail on a GRANT error, not
     * return an empty set. An empty set would mean the grant is too wide and
     * only application logic is keeping the estates apart.
     */
    private function step4CrossEstateQueryFailsOnGrant(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 4 - from {$a->getTenantKey()} context, read {$b->getTenantKey()} directly");

        tenancy()->initialize($a);

        $foreignDb = $b->database()->getName();
        $result = null;
        $error = null;

        try {
            $result = DB::connection('tenant')->select("SELECT * FROM `{$foreignDb}`.`residents`");
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        tenancy()->end();

        if ($error !== null) {
            $this->line('  '.str($error)->limit(200));
        }

        $this->assert(
            $error !== null && str_contains($error, 'denied'),
            'cross-estate read denied at the GRANT layer',
            $result !== null
                ? 'LEAK: query SUCCEEDED and returned '.count($result).' row(s)'
                : "query failed, but not on a grant error: {$error}",
        );

        $this->assert(
            $result === null,
            'no rows returned across the estate boundary',
            'a result set was returned',
        );
    }

    /** Step 5 - gs_app must hold no grant on any estate database. */
    private function step5NoWildcardGrant(): void
    {
        $this->section('STEP 5 - gs_app holds no grant on any estate database');

        foreach (['localhost', '127.0.0.1'] as $host) {
            $grants = DB::connection('mysql_owner')->select("SHOW GRANTS FOR 'gs_app'@'{$host}'");

            foreach ($grants as $row) {
                $this->line('  '.reset($row));
            }

            $offending = array_filter(
                array_map(fn ($r) => (string) reset($r), $grants),
                fn (string $g) => str_contains($g, 'gs_estate'),
            );

            $this->assert(
                $offending === [],
                "gs_app@{$host} holds no gs_estate grant",
                'FOUND: '.implode(' | ', $offending),
            );
        }
    }

    /**
     * Step 6 - a queued job that writes estate data must throw when it runs
     * with no tenant context, never silently default to one.
     */
    private function step6QueuedJobWithoutTenantThrows(): void
    {
        $this->section('STEP 6 - queued job with no tenant context must throw');

        tenancy()->end();

        $threw = null;

        try {
            (new RecalculateHouseholdStanding(1))->handle();
        } catch (Throwable $e) {
            $threw = $e;
        }

        if ($threw !== null) {
            $this->line('  '.class_basename($threw).': '.str($threw->getMessage())->limit(130));
        }

        $this->assert(
            $threw instanceof MissingTenantContextException,
            'job refuses to run without tenant context',
            $threw === null
                ? 'JOB RAN - it would have written to whichever database was default'
                : 'threw the wrong exception: '.get_class($threw),
        );
    }

    /**
     * Step 7 - journals must reject UPDATE twice over: once on the withheld
     * grant, and again on the trigger were the grant ever restored.
     */
    private function step7JournalsAppendOnly(Tenant $a): void
    {
        $this->section("STEP 7 - journals are append-only in {$a->getTenantKey()}");

        $db = $a->database()->getName();
        $user = $a->database()->getUsername();

        $grants = DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$user}`@`%`");
        $journalGrant = array_filter(
            array_map(fn ($r) => (string) reset($r), $grants),
            fn (string $g) => str_contains($g, 'journals'),
        );

        $this->assert(
            $journalGrant === [],
            'LAYER 1 - estate user holds no table grant on journals',
            'FOUND: '.implode(' | ', $journalGrant),
        );

        /*
         * Seed one row to attempt the update against - and it has to be a REAL
         * entry now.
         *
         * This probe used to insert a bare header with an amount and no lines,
         * which was all a journal was before the ledger existed. The double
         * entry migration made that impossible on purpose: journals_must_balance
         * refuses a header whose lines do not sum, and a header with no lines
         * sums to nothing. So the probe posts two balanced lines against the
         * first two accounts in the estate's chart, then the header - which is
         * the order every write to this ledger takes.
         *
         * An estate with no chart of accounts yet cannot be probed this way, and
         * the step says so rather than passing on an entry it never created.
         */
        tenancy()->initialize($a);

        $accounts = DB::connection('tenant')->table('accounts')->orderBy('code')->limit(2)->pluck('id')->all();

        if (count($accounts) < 2) {
            tenancy()->end();

            $this->assert(
                false,
                'a balanced entry exists to attempt an edit against',
                "estate {$a->getTenantKey()} has fewer than two accounts; run the chart of accounts seeder",
            );

            return;
        }

        if (! DB::connection('tenant')->table('journals')->where('id', 999)->exists()) {
            DB::connection('tenant')->table('journal_lines')->insert([
                ['entry_ref' => 'GATE-PROBE', 'account_id' => $accounts[0], 'line_no' => 1, 'debit_minor' => 10000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
                ['entry_ref' => 'GATE-PROBE', 'account_id' => $accounts[1], 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 10000, 'currency' => 'JMD', 'created_at' => now()],
            ]);

            DB::connection('tenant')->table('journals')->insert([
                'id' => 999,
                'reference' => 'GATE-PROBE',
                'memo' => 'gate probe',
                'source' => 'manual',
                'amount_minor' => 10000,
                'currency' => 'JMD',
                'posted_on' => now()->toDateString(),
                'created_at' => now(),
            ]);
        }

        $error = null;

        try {
            DB::connection('tenant')->statement("UPDATE journals SET memo = 'edited' WHERE id = 999");
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        tenancy()->end();

        if ($error !== null) {
            $this->line('  '.str($error)->limit(160));
        }

        $this->assert($error !== null, 'UPDATE on journals rejected for the estate user', 'the ledger is mutable');

        // Layer 2, proven independently: attempt the same write as the OWNER,
        // which does hold UPDATE on the table. Only the trigger can stop this.
        $triggerError = null;

        try {
            DB::connection('mysql_owner')->statement("UPDATE `{$db}`.`journals` SET memo = 'edited' WHERE id = 999");
        } catch (Throwable $e) {
            $triggerError = $e->getMessage();
        }

        if ($triggerError !== null) {
            $this->line('  '.str($triggerError)->limit(160));
        }

        $this->assert(
            $triggerError !== null && str_contains($triggerError, 'append-only'),
            'LAYER 2 - trigger rejects UPDATE even for a privileged user',
            'the schema owner edited a posted journal',
        );

        $deleteError = null;

        try {
            DB::connection('mysql_owner')->statement("DELETE FROM `{$db}`.`journals` WHERE id = 999");
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
        }

        $this->assert(
            $deleteError !== null && str_contains($deleteError, 'append-only'),
            'LAYER 2 - trigger rejects DELETE even for a privileged user',
            'a posted journal was deleted',
        );

        $this->line('  append-only tables: '.implode(', ', ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));

        $this->step7cEveryMutableTableIsActuallyMutable();
    }

    /**
     * Which tables in one estate database the estate's own user may UPDATE.
     *
     * ASKED WITH `SHOW GRANTS`, AND THE TWO OBVIOUS SOURCES BOTH FAILED.
     * `information_schema.TABLE_PRIVILEGES` shows only what the CONNECTED user
     * can see, and the schema owner is not the grantee, so it returned nothing —
     * which the first version of this check read as "forty-two tables are
     * ungranted" while every write on the platform was plainly working. Reading
     * `mysql.tables_priv` directly is refused too: `gs_owner` holds no SELECT on
     * the `mysql` schema, and it should not.
     *
     * `SHOW GRANTS FOR user@%` works, because the owner holds GRANT OPTION on
     * these databases — the same privilege that let it issue the grants in the
     * first place. Its output is text, so this parses it, which is the price of
     * asking the only source that will answer.
     *
     * @return list<string>
     */
    private function tablesGrantedUpdate(string $username, string $database): array
    {
        $rows = DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$username}`@`%`");

        $tables = [];

        foreach ($rows as $row) {
            // One column, named after the user, so it cannot be addressed by a
            // fixed key — take whatever the row holds.
            $line = (string) (array_values((array) $row)[0] ?? '');

            if (! preg_match('/^GRANT (.+?) ON `'.preg_quote($database, '/').'`\.`(.+?)` TO /', $line, $m)) {
                continue;
            }

            if (str_contains(strtoupper($m[1]), 'UPDATE')) {
                $tables[] = $m[2];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Step 7c — the OTHER half of the grant model, and the half that broke.
     *
     * Everything above proves the append-only tables are locked. Nothing proved
     * that the rest are USABLE, and that is the failure this platform actually
     * hit: UPDATE and DELETE are withheld at database level and granted back per
     * table (D-017), so a migration that adds a table leaves it with no grant at
     * all. Reads work. Inserts work. Only an update fails, and only when
     * somebody happens to run one — twice in one day here, on the payroll tables
     * and then on the notices.
     *
     * `DatabaseMigrated` now re-grants automatically, and this is what proves it
     * worked. A gate that only ever checked the locks would keep passing while
     * every new table in the estate was quietly read-only.
     */
    private function step7cEveryMutableTableIsActuallyMutable(): void
    {
        $this->newLine();
        $this->line('--- STEP 7c - every mutable estate table can actually be written -------');

        $estate = Tenant::estates()->first();

        if ($estate === null) {
            $this->assert(false, 'an estate exists to check grants on', 'no estates are provisioned');

            return;
        }

        $database = $estate->database()->getName();
        $username = $estate->database()->getUsername();

        /*
         * Read from the server's own grant tables rather than by attempting a
         * write per table. An UPDATE probe against forty tables would have to
         * invent a row to update in each, and a table with no rows would pass
         * for the wrong reason.
         */
        $granted = $this->tablesGrantedUpdate($username, $database);

        $tables = collect(DB::connection('mysql_owner')->select('
            SELECT TABLE_NAME AS name
              FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?
        ', [$database, 'BASE TABLE']))
            ->pluck('name')
            ->reject(static fn (string $table): bool => $table === 'migrations')
            ->all();

        $shouldBeMutable = array_values(array_diff($tables, ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));
        $missing = array_values(array_diff($shouldBeMutable, $granted));

        if ($missing !== []) {
            $this->line('  ungranted: '.implode(', ', $missing));
        }

        $this->assert(
            $missing === [],
            count($shouldBeMutable).' mutable tables all carry UPDATE for the estate user',
            count($missing).' table(s) were added by a migration and never granted — run grants:estates',
        );

        /*
         * And the converse, which is the invariant itself: nothing on the
         * append-only list may have been granted UPDATE by accident. A table
         * added to that list AFTER an estate was provisioned would still be
         * carrying the grant it was given when it was ordinary.
         */
        $wronglyGranted = array_values(array_intersect(ApplyAppendOnlyGrants::APPEND_ONLY_TABLES, $granted));

        if ($wronglyGranted !== []) {
            $this->line('  wrongly granted: '.implode(', ', $wronglyGranted));
        }

        $this->assert(
            $wronglyGranted === [],
            'no append-only table carries UPDATE for the estate user',
            implode(', ', $wronglyGranted).' is append-only and still holds a grant',
        );
    }

    /**
     * Step 7b - the CENTRAL audit log is append-only too.
     *
     * "Not editable by anyone including platform staff" has to survive a
     * Director, who holds Full on this module. Both layers are proven the same
     * way as journals: the grant for the application user, and the trigger
     * against a privileged connection that does hold UPDATE.
     */
    private function step7bAuditLogAppendOnly(): void
    {
        $this->section('STEP 7b - audit_log is append-only in gs_platform');

        $database = config('database.connections.mysql.database');
        $appUser = config('database.connections.mysql.username');

        foreach (['localhost', '127.0.0.1'] as $host) {
            $grants = array_map(
                fn ($r) => (string) reset($r),
                DB::connection('mysql_owner')->select("SHOW GRANTS FOR `{$appUser}`@`{$host}`"),
            );

            // A database-wide UPDATE/DELETE grant would silently cover
            // audit_log and make the withheld-grant layer meaningless.
            $wideGrant = array_filter(
                $grants,
                fn (string $g) => str_contains($g, "`{$database}`.*")
                    && (str_contains($g, 'UPDATE') || str_contains($g, 'DELETE')),
            );

            $this->assert(
                $wideGrant === [],
                "LAYER 1 - {$appUser}@{$host} holds no database-wide UPDATE or DELETE",
                'FOUND: '.implode(' | ', $wideGrant),
            );

            $auditGrant = array_filter($grants, fn (string $g) => str_contains($g, 'audit_log'));

            $this->assert(
                $auditGrant === [],
                "LAYER 1 - {$appUser}@{$host} holds no table grant on audit_log",
                'FOUND: '.implode(' | ', $auditGrant),
            );
        }

        // Seed one row to attempt the update against.
        DB::connection('mysql_owner')->table('audit_log')->insertOrIgnore([
            'id' => 999999,
            'action' => 'gate.probe',
            'actor_name' => 'Gate',
            'created_at' => now(),
        ]);

        $updateError = null;

        try {
            DB::connection('mysql_owner')
                ->statement("UPDATE `{$database}`.`audit_log` SET action = 'tampered' WHERE id = 999999");
        } catch (Throwable $e) {
            $updateError = $e->getMessage();
        }

        if ($updateError !== null) {
            $this->line('  '.str($updateError)->limit(160));
        }

        $this->assert(
            $updateError !== null && str_contains($updateError, 'append-only'),
            'LAYER 2 - trigger rejects UPDATE even for the schema owner',
            'an audit entry was edited',
        );

        $deleteError = null;

        try {
            DB::connection('mysql_owner')
                ->statement("DELETE FROM `{$database}`.`audit_log` WHERE id = 999999");
        } catch (Throwable $e) {
            $deleteError = $e->getMessage();
        }

        $this->assert(
            $deleteError !== null && str_contains($deleteError, 'append-only'),
            'LAYER 2 - trigger rejects DELETE even for the schema owner',
            'an audit entry was deleted',
        );
    }

    /** Step 8 - each role's navigation contains only its permitted modules. */
    private function step8NavigationMatchesMatrix(): void
    {
        $this->section('STEP 8 - navigation contains only permitted modules, per role');

        $roles = Role::with('moduleAccess.module')->orderBy('console')->orderBy('sort')->get();

        /*
         * THE SEEDED FOURTEEN, NOT A COUNT OF EVERY ROLE (M10 section 4).
         *
         * This asserted `count() === 13`, which went stale the day the estate's
         * own Head of Security made fourteen and is wrong for ever once a
         * console can make roles at runtime. What must hold is that every role
         * the code relies on by name exists and is marked as the platform's
         * own - the flag that stops it being renamed or deleted - and that
         * nothing else is. The loop below still walks EVERY role, custom ones
         * included, which is exactly right.
         */
        $seeded = [
            Role::DIRECTOR, Role::OPERATIONS_MANAGER, Role::HEAD_OF_SECURITY,
            Role::DISPATCHER, Role::ADMIN_ASSISTANT, Role::ACCOUNTANT,
            Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY,
            Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_HEAD_OF_SECURITY,
        ];

        $unprotected = array_values(array_filter(
            $seeded,
            fn (string $name): bool => ! $roles->contains(fn (Role $role): bool => $role->name === $name && $role->is_system && $role->tenant_id === null),
        ));

        $this->assert(
            $unprotected === [],
            '14 seeded roles exist, each marked is_system with no tenant (6 Gemini + 8 estate)',
            'missing or unprotected: '.implode(', ', $unprotected),
        );

        $systemCount = $roles->where('is_system', true)->count();

        $this->assert(
            $systemCount === count($seeded),
            'no role beyond the seeded fourteen is marked is_system',
            "{$systemCount} roles carry is_system",
        );

        foreach ($roles as $role) {
            $nav = $role->navigableModules();

            /*
             * "Grants view", read off the cell's own verbs. A custom cell can
             * hold export without view, and one of those reaching the sidebar
             * is the same leak as a `none` cell reaching it - which is all this
             * used to look for.
             */
            $leaked = $nav->filter(function ($module) use ($role) {
                $cell = $role->moduleAccess->firstWhere('module_id', $module->id);

                return $cell === null || ! $cell->grantsView();
            });

            // Ruling 1: a locked financial module must never reach the
            // Property Manager, by any route including a hand-edited matrix -
            // nor any role copied from it (M10 section 4).
            $lockedLeak = $role->derivesFrom(Role::PROPERTY_MANAGER)
                ? $nav->filter(fn ($m) => $m->is_locked_financial)
                : collect();

            $this->assert(
                $leaked->isEmpty() && $lockedLeak->isEmpty(),
                sprintf('%-24s %2d modules', $role->label, $nav->count()),
                $lockedLeak->isNotEmpty()
                    ? 'LOCKED FINANCIAL LEAK: '.$lockedLeak->pluck('key')->implode(', ')
                    : 'level none appeared in navigation: '.$leaked->pluck('key')->implode(', '),
            );
        }
    }

    /**
     * Step 9 - roles, permissions and users are central and never duplicated
     * into an estate database, or two estates could drift apart.
     */
    private function step9IdentityIsCentral(Tenant $a): void
    {
        $this->section('STEP 9 - identity lives in gs_platform, not per estate');

        $identityTables = ['users', 'roles', 'permissions', 'role_module_access', 'modules'];

        $central = DB::connection('mysql_owner')->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [config('database.connections.mysql.database')],
        );
        $centralNames = array_map(fn ($r) => $r->name, $central);

        $missing = array_diff($identityTables, $centralNames);

        $this->assert(
            $missing === [],
            'every identity table present in gs_platform',
            'MISSING: '.implode(', ', $missing),
        );

        $estateTables = [];
        $a->run(function () use (&$estateTables, $a) {
            $rows = DB::connection('tenant')->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [$a->database()->getName()],
            );
            $estateTables = array_map(fn ($r) => $r->name, $rows);
        });

        $this->line('  estate tables: '.implode(', ', $estateTables));

        $duplicated = array_intersect($identityTables, $estateTables);

        $this->assert(
            $duplicated === [],
            'no identity table duplicated into the estate database',
            'DUPLICATED: '.implode(', ', $duplicated),
        );
    }

    /**
     * Step 10 - a role one estate makes is that estate's and nobody else's
     * (M10 section 4: "enforce this server-side, and add it to the isolation
     * probe").
     *
     * The proof itself is `CrossTenantRoleProbe`, so that the test suite runs
     * the same code against estates of its own; see that class for the seven
     * things it establishes. This finds the two administrators it acts as and
     * gives it a real POST into the estate, through the whole middleware stack.
     */
    private function step10RolesCannotCrossTenants(Tenant $a, Tenant $b): void
    {
        $this->section("STEP 10 - a role made in {$a->getTenantKey()} is invisible and unassignable in {$b->getTenantKey()}");

        $adminA = $this->superAdminOf($a);
        $adminB = $this->superAdminOf($b);

        if ($adminA === null) {
            $this->assert(
                false,
                "a Community Super Admin of {$a->getTenantKey()} exists to make the probe role",
                "no active Community Super Admin is assigned to {$a->getTenantKey()} - seed the estate's committee first",
            );

            return;
        }

        $this->line("  estate A administrator: {$adminA->email}");
        $this->line('  estate B administrator: '.($adminB === null ? 'none - the B-side checks are skipped' : $adminB->email));

        $results = app(CrossTenantRoleProbe::class)->run(
            $a,
            $b,
            $adminA,
            $adminB,
            fn (User $user, Tenant $tenant, string $method, string $path, array $data): array => $this->exchangeAs($user, $tenant, $method, $path, $data),
        );

        foreach ($results as $result) {
            if ($result['status'] === 'skip') {
                $this->line("  SKIP {$result['proves']}: {$result['detail']}");

                continue;
            }

            $this->assert($result['status'] === 'pass', $result['proves'], $result['detail']);
        }
    }

    /**
     * Steps 11 to 13 - live updates: every channel a principal of estate A may not hold is
     * refused through the real doors, the console's and the handsets', and catch-up refuses
     * the same (M11 Part C, DESIGN.md section 10.1).
     *
     * The proof is `CrossTenantChannelProbe`, which the test suite runs against estates of its
     * own. This finds the three principals it acts as, in the data: the same committee member
     * STEP 3 probes with, an active guard, an active resident account with a household. Their
     * status variants are made inside the probe's own transaction, which it rolls back.
     */
    private function steps11to13LiveChannelsCannotCrossTenants(Tenant $a, Tenant $b): void
    {
        $this->section("STEPS 11-13 - live channels of {$a->getTenantKey()} are not {$b->getTenantKey()}'s, and no refusal leaks");

        $results = app(CrossTenantChannelProbe::class)->run($a, $b, [
            'member' => $this->probeSubject($a),
            'guard' => Guard::query()->where('tenant_id', $a->getTenantKey())->where('status', 'active')->orderBy('id')->first(),
            'resident' => ResidentAccount::query()
                ->where('tenant_id', $a->getTenantKey())
                ->where('status', ResidentAccount::ACTIVE)
                ->whereNotNull('resident_id')
                ->orderBy('id')
                ->first(),
        ]);

        $this->report($results);
    }

    /**
     * Step 14 - no live event anywhere carries a name, money, free text, a location, a
     * credential or a vote in its stored summary (DESIGN.md section 4.2).
     */
    private function step14LiveLogHoldsNothingForbidden(): void
    {
        $this->section('STEP 14 - the live log holds no forbidden summary key, in any database');

        $this->report(app(LivePayloadAudit::class)->run());
    }

    /** @param  list<array{status: string, proves: string, detail: string}>  $results */
    private function report(array $results): void
    {
        foreach ($results as $result) {
            if ($result['status'] === 'skip') {
                $this->line("  SKIP {$result['proves']}: {$result['detail']}");

                continue;
            }

            $this->assert($result['status'] === 'pass', $result['proves'], $result['detail']);
        }
    }

    /**
     * An active Community Super Admin with an active assignment to this estate,
     * one with a proven alert address first (M10 section 1.4). Estate B's is the
     * one STEP 10 signs in as over HTTP, and an unverified one would be held at
     * the contact gate, which the probe then reports as a SKIP rather than a pass.
     * Nobody is verified here: the gate is never waved through for the probe.
     *
     * An ESTATE account (bug hunt B067): a Gemini account an estate re-roled
     * into this role before Users & roles was fenced holds it and reaches no
     * estate by it, and the probe names that account rather than acting as it.
     */
    private function superAdminOf(Tenant $tenant): ?User
    {
        return User::query()
            ->where('status', 'active')
            ->where('console', Console::Estate->value)
            // The Community Super Admin, or this estate's own version of it (M11 B4).
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', Role::idsStandingFor(Role::COMMUNITY_SUPER_ADMIN, (string) $tenant->getTenantKey())))
            ->whereHas('assignments', fn ($q) => $q
                ->where('tenant_id', $tenant->getTenantKey())
                ->where('is_active', true))
            ->orderByRaw('contact_email_verified_at IS NULL')
            ->orderBy('id')
            ->first();
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
