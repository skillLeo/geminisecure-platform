<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * M10 section 4's isolation proof: a role one estate makes stays that estate's.
 *
 * `gate:isolation` STEP 10 runs this against the real estates, and
 * `RolesAreDynamicTest` runs the same code against two estates of its own. That
 * is why it is a class and not a method on the command: the gate is run against
 * live databases a test suite must never touch, and a proof that only ever ran
 * there would be a proof nobody had ever seen fail.
 *
 * WHAT IT PROVES, WITH REAL ROWS, THROUGH THE REAL PATHS:
 *
 *   1. a role created in estate A through `RoleEditor` compiles, and holds only
 *      the estate permission it was given (the positive control)
 *   2. inside estate B it appears in none of the four lists an estate reads
 *      roles from: the invite list, the matrix, the editor's board, the
 *      assignable set
 *   3. inside estate B, B's own Community Super Admin cannot find, rename,
 *      re-grant or delete it by id - refused as a role that does not exist
 *   4. over HTTP inside estate B, B's administrator can neither assign it to one
 *      of B's people nor invite anybody into it - checked on the rows afterwards,
 *      not on the status code, because a refusal and a success both redirect.
 *      A row check alone would also pass if the request never reached the
 *      controller, so it is only trusted behind a positive control: the same
 *      administrator must first OPEN settings/roles in B (HTTP 200), and no POST
 *      may come back as the contact gate's redirect (M10 section 1.4). An
 *      administrator still held at that gate cannot prove anything over HTTP,
 *      and the HTTP checks are SKIPPED with that reason, never passed
 *   5. A's own administrator cannot give it a Gemini module, by bare key or by
 *      full permission prefix - the security company's payroll is unreachable
 *   6. across the COMPILED table `can:` reads, no estate role holds a gemini.*
 *      permission and no Gemini role an estate.* one
 *   7. no matrix row joins a role of one console to a module of the other
 *   8. no account holds a role of the other console, by the role it holds or by
 *      an active site assignment carrying one (bug hunt B067)
 *
 * The probe role is created and deleted through `RoleEditor` itself, so the gate
 * exercises the path a Community Super Admin uses and leaves nothing behind but
 * the audit entries that path writes.
 *
 * Pure ASCII, like the command that prints it.
 */
final class CrossTenantRoleProbe
{
    public function __construct(
        private readonly RoleEditor $roles,
    ) {}

    /** Where the contact gate sends a console user who has not proven an alert address. */
    public const CONTACT_GATE_PATH = '/verify-contact';

    /**
     * @param  callable(User, Tenant, string, string, array<string, mixed>): array{status: int, location: string|null}  $request
     *         one authenticated request into an estate: user, estate, method, path, data
     * @return list<array{status: string, proves: string, detail: string}>
     */
    public function run(Tenant $a, Tenant $b, User $adminA, ?User $adminB, callable $request): array
    {
        $results = [];
        $label = 'Isolation probe '.Str::lower(Str::random(6));

        try {
            $probe = $a->run(fn (): Role => $this->roles->create($adminA, $label, ['reports' => ['level' => 'view']]));
        } catch (Throwable $e) {
            tenancy()->end();

            return [$this->fail('a role can be created in '.$a->getTenantKey().' to probe with', $e->getMessage())];
        }

        tenancy()->end();

        try {
            $compiled = $probe->permissions()->pluck('name')->all();

            $results[] = $this->check(
                $compiled === ['estate.reports.view'] && $probe->tenant_id === (string) $a->getTenantKey(),
                "CONTROL - the probe role compiled to exactly estate.reports.view and belongs to {$a->getTenantKey()}",
                'compiled: '.implode(', ', $compiled).'; tenant: '.(string) $probe->tenant_id,
            );

            array_push($results, ...$this->invisibleIn($b, $adminB, $probe));
            array_push($results, ...$this->unreachableIn($b, $adminB, $probe));
            array_push($results, ...$this->unassignableOverHttp($b, $adminB, $probe, $request));
            array_push($results, ...$this->noGeminiModule($a, $adminA, $probe));
        } finally {
            try {
                $a->run(fn () => $this->roles->delete($adminA, $probe->fresh() ?? $probe));
            } catch (Throwable $e) {
                $results[] = $this->fail('the probe role is deleted again through RoleEditor', $e->getMessage());
            }

            tenancy()->end();
        }

        $results[] = $this->check(
            ! Role::query()->whereKey($probe->id)->exists(),
            'the probe role is gone afterwards',
            'role '.$probe->name.' is still in the roles table',
        );

        array_push($results, ...$this->compiledTableHoldsNoCrossConsolePermission());

        $results[] = $this->accountsHoldOnlyTheirOwnConsolesRoles();

        return $results;
    }

    /**
     * 8. No account holds a role of the other console - by the role it holds,
     * or by an active assignment carrying one (bug hunt B067).
     *
     * Before the estate's Users & roles was fenced, a committee could re-role
     * Gemini's Head of Security through their site row into an estate role, and
     * closing that path undoes no account it already reached. An estate role
     * now gives a Gemini account nothing (`User::canAccessEstate()`), but an
     * account in that state is still wrong, and the estate can no longer see it
     * to undo it. So the gate names each one, and a Gemini account is put right
     * from Platform admins by giving it a Gemini role again. An estate account
     * holding a Gemini role is the same fault the other way, and is named too.
     *
     * An INACTIVE assignment is not counted: a lapsed row is history the audit
     * reads, and it reaches nothing.
     *
     * Public, and run by `run()`, so the test suite can prove it fails.
     *
     * @return array{status: string, proves: string, detail: string}
     */
    public function accountsHoldOnlyTheirOwnConsolesRoles(): array
    {
        $held = DB::connection('mysql')
            ->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereColumn('roles.console', '!=', 'users.console')
            ->orderBy('users.id')
            ->limit(10)
            ->get(['users.email', 'users.console', 'roles.name as role'])
            ->map(fn (\stdClass $row): string => "{$row->console} account {$row->email} holds {$row->role}");

        $assigned = DB::connection('mysql')
            ->table('estate_assignments')
            ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')
            ->where('estate_assignments.is_active', true)
            ->whereColumn('roles.console', '!=', 'users.console')
            ->orderBy('estate_assignments.id')
            ->limit(10)
            ->get(['users.email', 'users.console', 'estate_assignments.tenant_id', 'roles.name as role'])
            ->map(fn (\stdClass $row): string => "{$row->console} account {$row->email} is assigned to {$row->tenant_id} as {$row->role}");

        $crossed = $held->merge($assigned);

        return $this->check(
            $crossed->isEmpty(),
            'no account holds a role of the other console, by the role it holds or by an active site assignment',
            $crossed->isEmpty() ? '' : 'CROSSED: '.$crossed->implode('; '),
        );
    }

    /** @return list<array{status: string, proves: string, detail: string}> */
    private function invisibleIn(Tenant $b, ?User $adminB, Role $probe): array
    {
        $key = $b->getTenantKey();

        $lists = $b->run(function () use ($adminB): array {
            $settings = app(Settings::class);

            $lists = ['Settings::invitableRoles()' => array_column($settings->invitableRoles(), 'name')];

            if ($adminB !== null) {
                $lists['Settings::matrixBoard()'] = array_column($settings->matrixBoard($adminB)['roles'], 'name');
                $lists['RoleEditor::board()'] = array_column($this->roles->board($adminB)['roles'], 'name');
                $lists['RoleEditor::assignable()'] = $this->roles->assignable($adminB)->pluck('name')->all();
            }

            return $lists;
        });

        tenancy()->end();

        $out = [];

        foreach ($lists as $where => $names) {
            $out[] = $this->check(
                ! in_array($probe->name, $names, true) && $names !== [],
                "{$where} inside {$key} does not list the other estate's role",
                $names === [] ? 'the list was empty, so it proves nothing' : 'LEAK: '.$probe->name.' was listed',
            );
        }

        if ($adminB === null) {
            $out[] = $this->skip("the matrix and editor lists inside {$key}", "no active Community Super Admin is assigned to {$key}");
        }

        return $out;
    }

    /** @return list<array{status: string, proves: string, detail: string}> */
    private function unreachableIn(Tenant $b, ?User $adminB, Role $probe): array
    {
        $key = $b->getTenantKey();

        if ($adminB === null) {
            return [$this->skip("RoleEditor refuses the other estate's role inside {$key}", "no active Community Super Admin is assigned to {$key}")];
        }

        $attempts = [
            'find by id' => fn () => $this->roles->find($adminB, $probe->id),
            'rename' => fn () => $this->roles->rename($adminB, $probe, 'Taken over'),
            're-grant' => fn () => $this->roles->setPermissions($adminB, $probe, ['residents' => ['level' => 'full']]),
            'delete' => fn () => $this->roles->delete($adminB, $probe),
            'copy' => fn () => $this->roles->copy($adminB, $probe, 'Copied across'),
        ];

        $out = [];

        foreach ($attempts as $act => $attempt) {
            $error = null;

            try {
                $b->run($attempt);
            } catch (Throwable $e) {
                $error = $e;
            }

            tenancy()->end();

            $out[] = $this->check(
                $error instanceof \DomainException && $error->getMessage() === RoleEditor::NOT_HERE_ESTATE,
                "{$key}'s administrator cannot {$act} the other estate's role, and is told it is not one of theirs",
                $error === null ? 'LEAK: the act succeeded' : 'refused for the wrong reason: '.$error->getMessage(),
            );
        }

        $out[] = $this->check(
            Role::query()->whereKey($probe->id)->value('label') === $probe->label
                && $probe->fresh()?->permissions()->pluck('name')->all() === ['estate.reports.view'],
            'the other estate\'s role is unchanged after all five attempts',
            'the role\'s label or permissions moved',
        );

        return $out;
    }

    /**
     * @param  callable(User, Tenant, string, string, array<string, mixed>): array{status: int, location: string|null}  $request
     * @return list<array{status: string, proves: string, detail: string}>
     */
    private function unassignableOverHttp(Tenant $b, ?User $adminB, Role $probe, callable $request): array
    {
        $key = $b->getTenantKey();

        if ($adminB === null) {
            return [$this->skip("POSTs inside {$key} naming the other estate's role", "no active Community Super Admin is assigned to {$key}")];
        }

        /*
         * NOT THROUGH THE CONTACT GATE. Every request from an administrator who
         * has not proven an alert address is redirected before it reaches a
         * controller, and the row checks below would then pass having proved
         * nothing. Skipped, with the fix named, rather than passed.
         */
        if ($adminB->needsContactVerification()) {
            return [$this->skip(
                "POSTs inside {$key} naming the other estate's role",
                "{$adminB->email} has not proven an alert address, so the contact gate would turn every request away ".
                'before it reached the refusal under test. They verify it at sign-in; a demonstration account is '.
                'verified by accounts:verify-demonstration',
            )];
        }

        $out = [];

        // THE POSITIVE CONTROL: this administrator's requests reach the estate's
        // own settings, so a refusal below is the controller's and not a door.
        $control = $request($adminB, $b, 'GET', 'settings/roles', []);

        if ($control['status'] !== 200) {
            return [$this->fail(
                "CONTROL - {$key}'s administrator can open settings/roles, so the POSTs below reach the controller",
                "HTTP {$control['status']}".($control['location'] === null ? '' : ' to '.$control['location']),
            )];
        }

        $out[] = $this->check(true, "CONTROL - {$key}'s administrator can open settings/roles (HTTP 200)", '');

        // The status, or null when the contact gate turned the POST away, which
        // proves nothing and is reported as a failure, never as a refusal.
        $post = function (string $path, array $data) use ($request, $adminB, $b): ?int {
            $response = $request($adminB, $b, 'POST', $path, $data);

            return str_contains((string) $response['location'], self::CONTACT_GATE_PATH) ? null : $response['status'];
        };

        $target = EstateAssignment::query()
            ->with('user')
            ->where('tenant_id', $key)
            ->where('is_active', true)
            ->where('user_id', '!=', $adminB->id)
            ->whereHas('role', fn ($role) => $role->where('console', Console::Estate->value)->where('name', '!=', Role::COMMUNITY_SUPER_ADMIN))
            ->orderBy('id')
            ->first();

        if ($target === null) {
            $out[] = $this->skip("POST settings/users/{assignment} inside {$key}", "no second committee member on {$key} to aim it at");
        } else {
            $status = $post('settings/users/'.$target->id, ['role' => $probe->name, 'status' => 'active']);
            $after = EstateAssignment::query()->whereKey($target->id)->value('role_id');

            $out[] = $this->check(
                $status !== null && (int) $after === (int) $target->role_id && ! $target->user->fresh()->hasRole($probe->name),
                "POST settings/users/{$target->id} inside {$key} naming the other estate's role changes nobody's role (HTTP ".($status ?? 'gate').')',
                $status === null ? 'the contact gate turned the POST away, so it proved nothing' : 'LEAK: the assignment now carries role '.(string) $after,
            );
        }

        $email = 'isolation-probe-'.Str::lower(Str::random(8)).'@'.$key.'.invalid';
        $status = $post('settings/users/invitations', ['email' => $email, 'role' => $probe->name]);
        $leaked = Invitation::query()->where('email', $email)->get();

        $out[] = $this->check(
            $status !== null && $leaked->isEmpty(),
            "POST settings/users/invitations inside {$key} naming the other estate's role invites nobody (HTTP ".($status ?? 'gate').')',
            $status === null ? 'the contact gate turned the POST away, so it proved nothing' : 'LEAK: an invitation into the other estate\'s role was created',
        );

        // Never left standing, whatever happened above.
        $leaked->each->delete();

        return $out;
    }

    /** @return list<array{status: string, proves: string, detail: string}> */
    private function noGeminiModule(Tenant $a, User $adminA, Role $probe): array
    {
        $out = [];

        foreach (['payroll_accounting', 'gemini.payroll_accounting', 'guard_workforce', 'clients'] as $key) {
            $error = null;

            try {
                $a->run(fn () => $this->roles->setPermissions($adminA, $probe, [$key => ['level' => 'view']]));
            } catch (Throwable $e) {
                $error = $e;
            }

            tenancy()->end();

            $out[] = $this->check(
                $error instanceof \DomainException,
                "an estate's own administrator cannot give its role the Gemini module [{$key}]",
                $error === null ? 'LEAK: the grant was accepted' : 'refused, but not by the editor: '.$error->getMessage(),
            );
        }

        $out[] = $this->check(
            $probe->fresh()?->permissions()->where('name', 'like', 'gemini.%')->doesntExist() ?? true,
            'the probe role holds no gemini.* permission after the attempts',
            'LEAK: a gemini.* permission was compiled onto an estate role',
        );

        return $out;
    }

    /** @return list<array{status: string, proves: string, detail: string}> */
    private function compiledTableHoldsNoCrossConsolePermission(): array
    {
        $crossed = DB::connection('mysql')
            ->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where(fn ($q) => $q
                ->where(fn ($e) => $e->where('roles.console', Console::Estate->value)->where('permissions.name', 'not like', 'estate.%'))
                ->orWhere(fn ($g) => $g->where('roles.console', Console::Gemini->value)->where('permissions.name', 'not like', 'gemini.%')))
            ->select('roles.name as role', 'permissions.name as permission')
            ->limit(10)
            ->get();

        $joined = DB::connection('mysql')
            ->table('role_module_access')
            ->join('roles', 'roles.id', '=', 'role_module_access.role_id')
            ->join('modules', 'modules.id', '=', 'role_module_access.module_id')
            ->whereColumn('roles.console', '!=', 'modules.console')
            ->count();

        return [
            $this->check(
                $crossed->isEmpty(),
                'no estate role holds anything but estate.* and no Gemini role anything but gemini.*, in the compiled table can: reads',
                'LEAK: '.$crossed->map(fn ($row): string => $row->role.' holds '.$row->permission)->implode('; '),
            ),
            $this->check(
                $joined === 0,
                'no matrix cell joins a role of one console to a module of the other',
                "{$joined} cell(s) cross the consoles",
            ),
        ];
    }

    /** @return array{status: string, proves: string, detail: string} */
    private function check(bool $held, string $proves, string $detail): array
    {
        return ['status' => $held ? 'pass' : 'fail', 'proves' => $proves, 'detail' => $detail];
    }

    /** @return array{status: string, proves: string, detail: string} */
    private function fail(string $proves, string $detail): array
    {
        return ['status' => 'fail', 'proves' => $proves, 'detail' => $detail];
    }

    /** @return array{status: string, proves: string, detail: string} */
    private function skip(string $proves, string $detail): array
    {
        return ['status' => 'skip', 'proves' => $proves, 'detail' => $detail];
    }
}
