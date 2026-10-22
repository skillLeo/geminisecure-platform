<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seven verbs become one permission per power (M11 B3, PRICE-02, D-150).
 *
 * THE FAULT. One permission gated several powers with different consequences, so
 * granting one granted all of them. The client's case: to let the Community Super
 * Admin change a dues rate, its Dues & ledger cell became an Approver (D-141), and
 * `estate.dues_ledger.approve` also reversed posted months, activated payment
 * plans, flagged households and put reminder wording in force. The audit behind
 * D-150 found six more verbs bundled the same way, and each is split here too.
 * `App\Support\NamedActs` is the list the code reads; the same list is written out
 * below, because a migration has to mean the same thing on the day it runs
 * whatever that class says later.
 *
 * WHAT THIS DOES, IN ORDER:
 *
 *   1. `role_module_access.acts`, the one new thing a cell can say: named acts it
 *      grants one by one, without the verb above them. NULL on every existing
 *      row. Under the new code a cell granting a split verb grants every act under
 *      it — the Treasurer's Approver tag is all five dues approvals — so every
 *      cell but one (step 3) grants exactly what it did.
 *
 *   2. The compiled table follows the cells without waiting for a re-seed. The
 *      deploy does NOT run `RbacMatrixSeeder` (STATE.md), and the new routes check
 *      the new permissions. So every role and every account holding a retired
 *      permission is given all of its replacements here — the same powers, under
 *      their new names — and then the retired permission is removed. A custom role
 *      an estate or the Director made that held `estate.dues_ledger.approve` holds
 *      all five dues acts after this, exactly as the brief asks: "migrated to the
 *      split ones with the same powers".
 *
 *   3. THE ONE ROLE WHOSE POWERS DO CHANGE: the seeded Community Super Admin. Its
 *      Dues & ledger cell becomes Full plus `set_rates` alone — the one approval
 *      D-141 was for — and the other four dues acts come off its compiled table.
 *      This is what the re-seed would write, done here so that the deploy alone
 *      delivers PRICE-02: left to the re-seed, the role would keep the reversal
 *      and the plans until somebody ran it, and every role its editor made or
 *      re-granted in that window could be given them too. Safe to do here because
 *      the eight seeded estate roles are never edited at runtime (`seedRoles()`
 *      clears any mark), so there is no change of anybody's to keep. NARROWING
 *      ONLY: a cell that approves nothing is left for the seeder.
 *
 *   4. AN ESTATE'S OWN ROLE WIDER THAN ITS ADMINISTRATOR IS RECORDED. Nobody gives
 *      or takes away what they do not hold (RoleEditor, guard rail 3), and after
 *      step 3 no estate role holds both Settings' approval and the four dues acts
 *      the Community Super Admin gave up. So a community's own role that step 2
 *      gave them — a copy of the Treasurer or of the Community Super Admin made
 *      while D-141 was in force — cannot be narrowed from its estate's console.
 *      Each such role gets one audit entry (`role.wider_than_its_administrator`,
 *      with its estate, the permissions its administrator does not hold and how
 *      many people hold it), for the Director and the client to decide on. What
 *      to do about one is D-150's and QUESTIONS PERM-01's.
 *
 *   5. The permission cache is flushed after the change, so the next request
 *      reads the new grants rather than a copy of the old ones for a day.
 *
 * NO RE-SEED IS NEEDED AFTER THIS. One does no harm: it writes the cells this
 * leaves, and keeps every seeded Gemini role the Director changed as they left it.
 *
 * PUTTING THE PREVIOUS RELEASE BACK WITHOUT REVERSING THIS (deploy.sh does, on a
 * failed health check) leaves the previous code asking permissions this deleted:
 * its routes on them refuse everybody, and its role editor fails to save any role
 * holding one. That fails closed, and the previous release's own
 * `db:seed --class=RbacMatrixSeeder` puts the permissions and the grid back
 * (STATE.md); this release's seeder, run after deploying it again, retires them.
 *
 * Central only. `role_module_access`, `permissions`, `role_has_permissions`,
 * `model_has_permissions` and `roles` are all in `GrantedTables::CENTRAL_MUTABLE`
 * with table-level grants, and `audit_log` takes inserts, so no grant changes. Run
 * as the schema owner, like every central migration: `migrate --database=mysql_owner`.
 */
return new class extends Migration
{
    /** Each retired permission and the permissions that replace it, in order. */
    private const SPLITS = [
        'estate.dues_ledger.approve' => [
            'estate.dues_ledger.set_rates',
            'estate.dues_ledger.reverse_runs',
            'estate.dues_ledger.activate_plans',
            'estate.dues_ledger.flag_units',
            'estate.dues_ledger.adopt_dunning',
        ],
        'estate.accounting_posting.approve' => [
            'estate.accounting_posting.approve_bills',
            'estate.accounting_posting.complete_reconciliations',
        ],
        'estate.payroll.approve' => [
            'estate.payroll.approve_runs',
            'estate.payroll.file_returns',
        ],
        'estate.governance.approve' => [
            'estate.governance.certify_elections',
            'estate.governance.publish_meetings',
        ],
        'estate.settings.update' => [
            'estate.settings.manage_people',
            'estate.settings.edit_profile',
        ],
        'gemini.billing_subscriptions.update' => [
            'gemini.billing_subscriptions.price_clients',
            'gemini.billing_subscriptions.raise_invoices',
            'gemini.billing_subscriptions.resend_invoices',
            'gemini.billing_subscriptions.issue_credit_notes',
        ],
        'gemini.platform_settings.configure' => [
            'gemini.platform_settings.set_prices',
            'gemini.platform_settings.configure_messaging',
            'gemini.platform_settings.run_simulator',
        ],
    ];

    /** The seeded role step 3 narrows, and the one dues approval it keeps. */
    private const COMMUNITY_SUPER_ADMIN = 'estate.community_super_admin';

    private const SET_RATES = 'set_rates';

    /** This file, as the audit entries it writes name it. */
    private const VIA = '2026_09_24_200000_one_permission_per_power_where_one_verb_carried_several';

    /** The longest `audit_log.entity_id` holds. */
    private const ENTITY_ID_MAX = 64;

    public function up(): void
    {
        Schema::table('role_module_access', function (Blueprint $table) {
            /*
             * The named acts a cell grants ONE BY ONE — a JSON list of act keys, or
             * NULL for none, which is every row that exists today. An act whose verb
             * the cell already grants is never stored: it is implied. `RoleEditor`
             * refuses an unknown act on the way in and `RoleModuleAccess` drops one
             * on the way out.
             */
            $table->json('acts')->nullable()->after('verbs');
        });

        $this->moveGrants();
        $this->narrowTheCommunitySuperAdmin();
        $this->recordRolesWiderThanTheirAdministrator();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Step 2 above: every holder of a retired permission holds all of its
     * replacements instead, and the retired permission is gone.
     *
     * Public, and separate from the schema change, so a test can plant the old
     * grants and prove that each holder ends with exactly the same powers under
     * the new names — without altering a table inside a test's transaction.
     * Idempotent: a second run finds no retired permission and does nothing.
     *
     * `$connection` is the one the migrator runs on when it is null — the schema
     * owner's, `migrate --database=mysql_owner` — and a test names the central one.
     *
     * @return array<string, int> retired permission => how many roles and accounts held it
     */
    public function moveGrants(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $moved = [];

        foreach (self::SPLITS as $retired => $replacements) {
            $ids = [];

            foreach ($replacements as $name) {
                $ids[] = $this->permissionId($db, $name, create: true);
            }

            $old = $this->permissionId($db, $retired, create: false);

            if ($old === null) {
                continue;
            }

            $roles = $db->table('role_has_permissions')->where('permission_id', $old)->pluck('role_id');
            $accounts = $db->table('model_has_permissions')->where('permission_id', $old)->get(['model_type', 'model_id']);

            foreach ($roles as $roleId) {
                foreach ($ids as $id) {
                    $db->table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
                }
            }

            foreach ($accounts as $holder) {
                foreach ($ids as $id) {
                    $db->table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $id,
                        'model_type' => $holder->model_type,
                        'model_id' => $holder->model_id,
                    ]);
                }
            }

            $db->table('role_has_permissions')->where('permission_id', $old)->delete();
            $db->table('model_has_permissions')->where('permission_id', $old)->delete();
            $db->table('permissions')->where('id', $old)->delete();

            $moved[$retired] = $roles->count() + $accounts->count();
        }

        return $moved;
    }

    /**
     * Step 3 above: the seeded Community Super Admin sets dues rates and approves
     * nothing else on Dues & ledger — its cell and its compiled table both.
     *
     * NARROWING ONLY. It acts on a cell carrying the Approver tag, which is what
     * D-141 left and what step 2 compiled to all five acts, and on nothing else:
     * a cell that approves nothing (a server seeded before D-141) is not given
     * `set_rates` here, because a migration that widened a role would be making
     * the seeder's decision for it. The re-seed does that.
     *
     * Public and idempotent for the reason `moveGrants()` is. Returns the
     * permissions it took off the role's compiled table, or none.
     *
     * @return list<string>
     */
    public function narrowTheCommunitySuperAdmin(?string $connection = null): array
    {
        $db = DB::connection($connection);

        $roleId = $db->table('roles')->where('name', self::COMMUNITY_SUPER_ADMIN)->where('guard_name', 'web')->value('id');
        $moduleId = $db->table('modules')->where('console', 'estate')->where('key', 'dues_ledger')->value('id');

        if ($roleId === null || $moduleId === null) {
            return [];
        }

        $cell = $db->table('role_module_access')->where('role_id', $roleId)->where('module_id', $moduleId)->first(['id', 'level', 'verbs', 'can_approve']);

        if ($cell === null || ! (bool) $cell->can_approve) {
            return [];
        }

        // The one approval it keeps needs something to open, like every act.
        $opens = $cell->level === 'custom'
            ? in_array('view', (array) json_decode((string) $cell->verbs, true), true)
            : $cell->level !== 'none';

        $db->table('role_module_access')->where('id', $cell->id)->update([
            'can_approve' => false,
            'acts' => $opens ? json_encode([self::SET_RATES]) : null,
            'updated_at' => now(),
        ]);

        $keeps = 'estate.dues_ledger.'.self::SET_RATES;
        $taken = array_values(array_filter(
            self::SPLITS['estate.dues_ledger.approve'],
            static fn (string $name): bool => $name !== $keeps || ! $opens,
        ));

        $ids = $db->table('permissions')->whereIn('name', $taken)->where('guard_name', 'web')->pluck('id');
        $db->table('role_has_permissions')->where('role_id', $roleId)->whereIn('permission_id', $ids)->delete();

        // And the one it keeps is on the table, whatever the table held before.
        if ($opens) {
            $db->table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $this->permissionId($db, $keeps, create: true),
                'role_id' => $roleId,
            ]);
        }

        return $taken;
    }

    /**
     * Step 4 above: one audit entry for every estate's own role holding a
     * permission the seeded Community Super Admin does not — which, by guard rail
     * 3, nobody on that estate can take away from it.
     *
     * Asked of the compiled tables after steps 2 and 3, and of every permission
     * rather than only the four dues acts, so it answers the question the rule
     * asks and not the one this migration expects. Before this change the answer
     * was always none: every estate role that can change roles holds what the
     * Community Super Admin holds or less, and a role is only ever given what its
     * editor holds.
     *
     * Public for a test. Returns what it recorded.
     *
     * @return list<array{role: string, tenant_id: string, permissions: list<string>, holders: int}>
     */
    public function recordRolesWiderThanTheirAdministrator(?string $connection = null): array
    {
        $db = DB::connection($connection);

        $administratorId = $db->table('roles')->where('name', self::COMMUNITY_SUPER_ADMIN)->where('guard_name', 'web')->value('id');

        if ($administratorId === null) {
            return [];
        }

        $administratorHolds = $db->table('role_has_permissions')->where('role_id', $administratorId)->pluck('permission_id')->all();

        $recorded = [];

        $roles = $db->table('roles')
            ->where('console', 'estate')
            ->whereNotNull('tenant_id')
            ->orderBy('tenant_id')
            ->orderBy('id')
            ->get(['id', 'name', 'label', 'tenant_id']);

        foreach ($roles as $role) {
            $wider = $db->table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $role->id)
                ->whereNotIn('role_has_permissions.permission_id', $administratorHolds)
                ->orderBy('permissions.name')
                ->pluck('permissions.name')
                ->all();

            if ($wider === []) {
                continue;
            }

            $holders = $db->table('estate_assignments')->where('role_id', $role->id)->where('is_active', true)->count();

            $db->table('audit_log')->insert([
                'tenant_id' => (string) $role->tenant_id,
                'actor_id' => null,
                'actor_name' => null,
                'actor_role' => null,
                'action' => 'role.wider_than_its_administrator',
                'entity_type' => 'Role',
                // Every role RoleEditor made has a name that fits, because its
                // creation was audited under that name in the same transaction.
                'entity_id' => strlen((string) $role->name) <= self::ENTITY_ID_MAX ? (string) $role->name : (string) $role->id,
                'before' => null,
                'after' => json_encode([
                    'role' => (string) $role->name,
                    'label' => $role->label,
                    'permissions' => $wider,
                    'holders' => $holders,
                    'why' => 'This role holds permissions the Community Super Admin does not, so nobody on its estate can take them away from it. See DECISIONS D-150.',
                    'via' => self::VIA,
                ]),
                'ip' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);

            $recorded[] = [
                'role' => (string) $role->name,
                'tenant_id' => (string) $role->tenant_id,
                'permissions' => $wider,
                'holders' => $holders,
            ];
        }

        return $recorded;
    }

    /**
     * Back to one permission per verb — FAILING CLOSED.
     *
     * The old model cannot say "some of them", so a holder of EVERY act of a verb
     * gets the verb back and a holder of only some gets nothing: widening a partial
     * grant to the whole verb would hand a role given only `edit_profile` the power
     * to seat a Treasurer, which is the bundling this migration removed. Each role
     * and account that loses an act that way is written to the audit log
     * (`role.act_lost_in_rollback`, `user.act_lost_in_rollback`), so the operator
     * can re-grant it under the old code knowingly. The seeded roles need nothing
     * of the kind: the previous release's `RbacMatrixSeeder` writes each of them
     * back exactly as it was — the Community Super Admin's Approver tag, which
     * this loses with its one act, included.
     */
    public function down(): void
    {
        $this->foldGrants();

        Schema::table('role_module_access', function (Blueprint $table) {
            $table->dropColumn('acts');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The way back, the data half: every holder of every act of a verb holds the
     * verb again, every act permission is gone, every cell that listed ALL of a
     * verb's acts one by one grants the verb instead, and a cell that listed some
     * loses them — so the compiled table and the cells agree before the column
     * goes, and each loss is on the audit log.
     *
     * Public and separate from the schema change for the reason `moveGrants()`
     * is: a test can plant the new grants and prove the rollback without
     * altering a table inside a transaction.
     *
     * @return array<string, array{restored: int, lost: int}> retired permission => how many roles and accounts hold it again, and how many lost acts of it
     */
    public function foldGrants(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $folded = [];

        foreach (self::SPLITS as $retired => $replacements) {
            $old = $this->permissionId($db, $retired, create: true);
            $acts = $db->table('permissions')->whereIn('name', $replacements)->where('guard_name', 'web')->pluck('name', 'id')->all();
            $ids = array_keys($acts);

            // Only a holder of every replacement holds the whole verb.
            $whole = count($acts) === count($replacements);

            $restored = 0;
            $lost = 0;

            foreach ($db->table('role_has_permissions')->whereIn('permission_id', $ids)->get(['role_id', 'permission_id'])->groupBy('role_id') as $roleId => $rows) {
                $held = $rows->pluck('permission_id')->map(static fn ($id): int => (int) $id)->all();

                if ($whole && count(array_unique($held)) === count($replacements)) {
                    $db->table('role_has_permissions')->insertOrIgnore(['permission_id' => $old, 'role_id' => (int) $roleId]);
                    $restored++;

                    continue;
                }

                $role = $db->table('roles')->where('id', $roleId)->first(['name', 'tenant_id']);
                $name = (string) ($role->name ?? '');

                $this->recordLoss(
                    $db,
                    'role',
                    'Role',
                    $name !== '' && strlen($name) <= self::ENTITY_ID_MAX ? $name : (string) $roleId,
                    $role->tenant_id ?? null,
                    $retired,
                    $this->namesOf($acts, $held),
                );
                $lost++;
            }

            foreach ($db->table('model_has_permissions')->whereIn('permission_id', $ids)->get(['model_type', 'model_id', 'permission_id'])->groupBy(static fn ($row): string => $row->model_type.'#'.$row->model_id) as $rows) {
                $holder = $rows->first();
                $held = $rows->pluck('permission_id')->map(static fn ($id): int => (int) $id)->all();

                if ($whole && count(array_unique($held)) === count($replacements)) {
                    $db->table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $old,
                        'model_type' => $holder->model_type,
                        'model_id' => $holder->model_id,
                    ]);
                    $restored++;

                    continue;
                }

                $this->recordLoss($db, 'user', 'User', (string) $holder->model_id, null, $retired, $this->namesOf($acts, $held));
                $lost++;
            }

            $db->table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            $db->table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            $db->table('permissions')->whereIn('id', $ids)->delete();

            $folded[$retired] = ['restored' => $restored, 'lost' => $lost];
        }

        foreach ($db->table('role_module_access')->whereNotNull('acts')->get(['id', 'module_id', 'level', 'verbs', 'acts']) as $cell) {
            $listed = (array) json_decode((string) $cell->acts, true);

            // The list goes, whatever it held: what it granted in full is on the
            // verb below, and a part of a verb the old model cannot grant.
            $update = ['acts' => null];

            $module = $listed === [] ? null : $db->table('modules')->where('id', $cell->module_id)->first(['console', 'key']);

            if ($module !== null) {
                $prefix = $module->console.'.'.$module->key.'.';

                foreach (self::SPLITS as $retired => $replacements) {
                    if (! str_starts_with($retired, $prefix)) {
                        continue;
                    }

                    $held = array_intersect($replacements, array_map(static fn (string $act): string => $prefix.$act, $listed));

                    // Every act of the verb, listed one by one, IS the verb. Fewer is
                    // nothing, as it is on the compiled table above.
                    if (count($held) !== count($replacements)) {
                        continue;
                    }

                    $verb = substr($retired, strlen($prefix));

                    if ($verb === 'approve') {
                        $update['can_approve'] = true;

                        continue;
                    }

                    // Any other verb goes on the cell's verb list: a preset cell becomes
                    // the custom list of its own verbs plus this one.
                    $verbs = $cell->level === 'custom'
                        ? (array) json_decode((string) $cell->verbs, true)
                        : match ($cell->level) {
                            'view' => ['view'],
                            'entry' => ['view', 'create', 'update'],
                            'full' => ['view', 'create', 'update', 'delete', 'export', 'configure'],
                            default => [],
                        };

                    if (! in_array($verb, $verbs, true)) {
                        $verbs[] = $verb;
                        $update['level'] = 'custom';
                        $update['verbs'] = json_encode(array_values($verbs));
                    }
                }
            }

            $db->table('role_module_access')->where('id', $cell->id)->update($update);
        }

        return $folded;
    }

    /**
     * One audit entry for one role or account that loses acts on the way back.
     *
     * @param  list<string>  $acts
     */
    private function recordLoss(ConnectionInterface $db, string $domain, string $entityType, string $entityId, mixed $tenantId, string $retired, array $acts): void
    {
        $db->table('audit_log')->insert([
            'tenant_id' => $tenantId === null ? null : (string) $tenantId,
            'actor_id' => null,
            'actor_name' => null,
            'actor_role' => null,
            'action' => $domain.'.act_lost_in_rollback',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => json_encode(['permissions' => $acts]),
            'after' => json_encode([
                'permissions' => [],
                'why' => sprintf('Held only some of what %s became, and the previous release can grant it only whole. Re-grant it deliberately if it is wanted.', $retired),
                'via' => self::VIA,
            ]),
            'ip' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * The names of the held permission ids, in the order they were split.
     *
     * @param  array<int, string>  $acts  id => name
     * @param  list<int>  $held
     * @return list<string>
     */
    private function namesOf(array $acts, array $held): array
    {
        return array_values(array_filter($acts, static fn (int $id): bool => in_array($id, $held, true), ARRAY_FILTER_USE_KEY));
    }

    /** A permission's id, created with the guard every permission here uses when asked to. */
    private function permissionId(ConnectionInterface $db, string $name, bool $create): ?int
    {
        $id = $db->table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        if ($id !== null || ! $create) {
            return $id === null ? null : (int) $id;
        }

        return (int) $db->table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
