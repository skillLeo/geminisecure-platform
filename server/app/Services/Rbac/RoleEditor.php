<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\NamedActs;
use Closure;
use Database\Seeders\RbacMatrixSeeder;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creating, renaming, re-granting and deleting roles — M10 §4, both consoles.
 *
 * ONE CLASS FOR BOTH CONSOLES, AND THAT IS THE POINT OF IT. The Director edits
 * the company console's roles and a Community Super Admin edits their own
 * estate's, and both must obey the same guard rails. Two copies would give the
 * two consoles two chances to disagree about what "nobody grants what they do
 * not hold" means, which is the drift D-012 pins the models to one connection
 * to prevent. Every rule below is enforced HERE, server-side, on values the
 * request cannot supply: the console is the actor's own and the estate is the
 * one the request is running in. A body that names a console or a tenant is not
 * validated — it is never read.
 *
 * THE GUARD RAILS, EACH A REFUSAL WITH THE SENTENCE THE SCREEN PRINTS
 * ===================================================================
 *
 *   1. MAY EDIT AT ALL. Changing what a role may do is an approve-level act on
 *      the console's settings module (D-013): `estate.settings.approve` or
 *      `gemini.platform_settings.approve`. The seeded matrix gives it to exactly
 *      the Community Super Admin and the Director.
 *
 *   2. WHOSE ROLE IT IS. An estate edits only roles carrying its own tenant id.
 *      The eight seeded estate roles are SHARED BY EVERY ESTATE — `roles` had no
 *      tenant column until this work — so changing one from one community would
 *      change them all; the refusal says so and offers the copy. The Director
 *      may change the Gemini console's roles, seeded ones included (the lead's
 *      ruling on M10 §4). Another estate's role is refused in the same words as
 *      a role that does not exist, so its existence is not disclosed.
 *
 *   3. NOBODY GRANTS WHAT THEY DO NOT HOLD — AND NOBODY TAKES AWAY WHAT THEY DO
 *      NOT HOLD EITHER. Every permission a change adds, and every one it
 *      removes, must be one the actor holds themselves, approve included. The
 *      first half is the client's rule; the second stops an approver who was
 *      given the power to edit roles from stripping a role wider than their own.
 *
 *   4. NOTHING CROSSES A CONSOLE OR A TENANT. A cell names a module of the
 *      actor's own console or it is refused — so `payroll_accounting`, the
 *      security company's payroll, cannot be reached from an estate even by
 *      posting its key. Ruling 1 (`Module::isLockedFor()`) refuses a Property
 *      Manager, or any copy of one, a locked money module.
 *
 *   5. STRUCTURAL ROLES. None of the fourteen seeded roles may be renamed or
 *      deleted, and the Director and the Community Super Admin keep Full ·
 *      Approver on their settings module whatever else is changed, because that
 *      cell is what lets somebody restore everything else.
 *
 *   6. EVERY CHANGE IS AUDITED, inside the same transaction as the change, with
 *      the actor, the role's machine name, and before and after as both the cell
 *      grid and the resolved permission list.
 *
 *   7. EVERY CHANGE TAKES EFFECT ON THE NEXT REQUEST. See `commit()`.
 *
 *   8. A PLATFORM UPDATE DOES NOT UNDO IT. A permission change marks the role
 *      (`permissions_edited_at` / `permissions_edited_by_id`), and
 *      `RbacMatrixSeeder` leaves a marked seeded Gemini role's cells alone on
 *      every re-seed. The deliberate way back is `resetToDefault()`, or
 *      `php artisan rbac:reset-role` on the server. A change that leaves a
 *      seeded role exactly at its default clears the mark, so the role follows
 *      the grid again.
 *
 * WHAT THIS CLASS DOES NOT DO: move people between roles. That stays where it
 * already lived — `App\Services\Estate\Settings::manageUser()` and
 * `removeUser()`, `App\Services\Gemini\PlatformAdmins::manage()` and `remove()`,
 * `App\Services\Invitations::invite()` — which call the four people-facing
 * methods here (`assignable()`, `assignableNamed()`, `assertMayHandOut()`,
 * `assertMayManageHolderOf()`) so that "which roles may this person hand out"
 * has one answer.
 *
 * THE GRID SHAPE every write takes, keyed by the module's `key`:
 *
 *     [
 *         'security'    => ['level' => 'full'],
 *         'reports'     => ['level' => 'custom', 'verbs' => ['view', 'export']],
 *         'payroll'     => ['level' => 'view', 'can_approve' => false],
 *         'dues_ledger' => ['level' => 'full', 'acts' => ['set_rates']],
 *     ]
 *
 * `level` is none | view | entry | full | custom. `verbs` is read only for
 * `custom`, from view, create, update, delete, export, configure. `approve` is
 * the Approver tag, `can_approve` — an `approve` found in `verbs` is moved there
 * and checked exactly the same way, never granted through the list. A custom
 * list that matches a preset is stored as that preset. Scope is never accepted:
 * it follows the role (see `scopeFor()`).
 *
 * `acts` (M11 B3) is the named acts a cell grants one by one, from
 * `App\Support\NamedActs` — the five dues approvals, the two on Accounting, on
 * Payroll and on Governance, the estate Settings' two update acts, Billing &
 * subscriptions' four update acts and Platform settings' three configure acts.
 * An act is checked like any other permission: nobody gives or takes one they do
 * not hold, and no cell grants one — listed on its own or through the verb above
 * it — without view. Canonical, like `verbs`: an act the cell's level or tag
 * already grants is dropped as implied, and every act of one verb listed one by
 * one is stored as that verb, so one grant always has one stored form.
 *
 * THE PUBLIC API, FOR THE CONTROLLERS THAT CALL IT
 * ================================================
 *
 * Every method takes the acting `User` first. On the estate console it must be
 * called INSIDE the estate's tenancy (an estate route already is); the estate is
 * read from `tenant()` and never passed in. Every refusal is a `DomainException`
 * whose message is the sentence to print — a controller catches it and returns
 * `back()->withErrors([...])`, exactly as its neighbours do. Nothing here
 * re-checks a route's `can:` gate and nothing here needs one re-checked.
 *
 *   reading
 *     editPermission(Console): string        the `can:` gate for every write route:
 *                                            estate.settings.approve or
 *                                            gemini.platform_settings.approve
 *     canEdit($actor): bool
 *     board($actor): array                   the whole editor payload — see its docblock
 *     find($actor, int|string): Role         an id (int) or machine name (string)
 *                                            this console and estate can see, else the
 *                                            not-one-of-ours refusal. Use it instead of
 *                                            route-model binding.
 *     refusal($actor, $role, 'rename'|'delete'|'permissions'|'reset'): ?string
 *     defaultDifference($role): ?array       what resetting would add and remove, and
 *                                            whether the role is at its default; null
 *                                            for a role with no GeminiSecure default
 *     copyableGrid($actor, $source): array   the grid a copy would hold and the
 *                                            permissions it would withhold, for the
 *                                            confirmation before a copy
 *     assignable($actor): Collection<Role>   the roles this actor may hand out
 *     assignableNamed($actor, $name): Role
 *     assertMayHandOut($actor, $role)        for a user-management service
 *     assertMayManageHolderOf($actor, $role) for a user-management service
 *
 *   writing — each audited, recompiled and flushed after its commit
 *     create($actor, $label, $grid = [], ?$scopeDefault = null): Role
 *     copy($actor, $source, ?$label = null): Role
 *     rename($actor, $role, $label): Role     label only; `name` never moves
 *     setPermissions($actor, $role, $grid): Role   only the modules named change; marks
 *                                            the role edited, so a re-seed keeps it. A
 *                                            grid that changes nothing writes nothing.
 *     resetToDefault($actor, $role): Role    "Reset to GeminiSecure default": a seeded
 *                                            role back to its seeded cells, mark cleared
 *     delete($actor, $role): void
 *
 *   the server's way back, with no actor and no request
 *     resetToDefaultByOperator($role): Role  for `rbac:reset-role` only
 *
 *   the people-facing half lives where it always did, and now takes custom roles
 *     Settings::manageUser($assignment, $roleName, $status, $by)
 *     Settings::removeUser($assignment, $by)
 *     PlatformAdmins::manage($target, $roleName, $status, $siteIds, $by)
 *     PlatformAdmins::remove($target, $by)
 *     Invitations::invite($email, $role, $tenantId, $by)
 */
final class RoleEditor
{
    /** The module on each console whose `approve` is the power to change a role. */
    public const SETTINGS_MODULE = [
        'estate' => 'settings',
        'gemini' => 'platform_settings',
    ];

    /**
     * The two structural roles, and the settings cell each must keep.
     *
     * The Director and the Community Super Admin are the roles the client names
     * as the ones who change permissions. If either could be edited out of Full ·
     * Approver on its own settings module, one careless save would leave its
     * console with nobody who could put anything back.
     */
    private const STRUCTURAL = [
        Role::DIRECTOR => 'platform_settings',
        Role::COMMUNITY_SUPER_ADMIN => 'settings',
    ];

    public const LABEL_MAX = 60;

    /** Every refusal about a role that is not this console's or not this estate's. */
    public const NOT_HERE_ESTATE = 'That is not one of this estate\'s roles.';

    public const NOT_HERE_GEMINI = 'That is not one of the Gemini Console\'s roles.';

    /** The default of a module the seeded grid has no row for: no access. */
    private const NO_DEFAULT = ['level' => AccessLevel::None, 'can_approve' => false, 'scope' => AccessScope::All, 'acts' => []];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * WHAT A BOARD HAS ALREADY READ, WHILE IT IS BEING DRAWN (M11 A2).
     *
     * The roles board asks the same questions of the same actor for every role and
     * every module — who the actor is and where, which modules the console has,
     * what each role holds, whether a role may be deleted — and each question was a
     * query: 234 of them for the estate's eight roles. While a board is being drawn
     * nothing is written, so the answers cannot change underneath it; they are read
     * once and reused for the rest of that one pass, and thrown away when it ends.
     *
     * NEVER ACROSS A WRITE. Every write path — create, copy, rename, re-grant, delete,
     * reset, hand out, manage — runs outside a reading pass and reads afresh, exactly
     * as it did, so no refusal is ever decided on a stale answer.
     *
     * @var array{actor: int|string, context: array{0: Console, 1: string|null}, modules: array<string, Collection<int, Module>>, roles: Collection<int, Role>|null, delete: array<int, array{holders: int, pending: bool, history: bool}>|null, editors: array<int, string>, held: array<string, bool>, versions: array<string, Collection<int, Role>>}|null
     */
    private ?array $reading = null;

    /**
     * Run a read-only board with its answers read once (see `$reading`).
     *
     * FOR A BOARD THAT WRITES NOTHING. `board()` uses it, and so does the estate's
     * users board, which asks `assertMayManageHolderOf()` of every row. The actor's
     * context is checked exactly as every public method checks it, so a pass cannot
     * be opened by somebody who could not have asked the questions one by one — and
     * where it would refuse them, the read runs WITHOUT a pass, asking every question
     * afresh, which is precisely what it did before passes existed. A caller that
     * refuses such an actor still refuses them, in its own words.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function whileReading(User $actor, Closure $read): mixed
    {
        try {
            $context = $this->context($actor);
        } catch (DomainException) {
            return $read();
        }

        $outer = $this->reading;

        $this->reading = ['actor' => $actor->getKey(), 'context' => $context, 'modules' => [], 'roles' => null, 'delete' => null, 'editors' => [], 'held' => [], 'versions' => []];

        try {
            return $read();
        } finally {
            $this->reading = $outer;
        }
    }

    /* ------------------------------------------------------------------ */
    /* reading */
    /* ------------------------------------------------------------------ */

    /** `estate.settings.approve` or `gemini.platform_settings.approve`. */
    public static function editPermission(Console $console): string
    {
        return $console->value.'.'.self::SETTINGS_MODULE[$console->value].'.approve';
    }

    /** Does this actor hold the power to change roles on their own console? */
    public function canEdit(User $actor): bool
    {
        return $actor->status === 'active' && $this->holds($actor, self::editPermission($actor->console));
    }

    /**
     * Does the actor hold this permission? `checkPermissionTo()`, asked once per name
     * inside a reading pass (M11 A2).
     *
     * The roles screen asks it of the same few dozen names hundreds of times — every
     * module's every verb for the grantable row, and again for each role's copy — and
     * each ask walks the permission list. Within a pass nothing is written, so the
     * actor's permissions cannot change underneath it: the first answer for a name is
     * the answer. Outside a pass, and for any other user, it is asked afresh.
     */
    private function holds(User $actor, string $permission): bool
    {
        if ($this->reading === null || $this->reading['actor'] !== $actor->getKey()) {
            return $actor->checkPermissionTo($permission);
        }

        return $this->reading['held'][$permission] ??= $actor->checkPermissionTo($permission);
    }

    /**
     * Everything a role editor screen needs, for the actor's own console.
     *
     * DERIVED FROM THE ACTOR, never from a parameter: an estate actor gets this
     * estate's roles (the eight shared ones and its own), a Gemini actor the
     * platform's. Every role carries its MACHINE NAME, which the screen keys on,
     * and for each of the three acts a boolean plus the sentence that explains a
     * refusal before anybody presses anything — "the interface explains why
     * rather than failing silently".
     *
     *   can_edit / edit_blocked_reason   may this actor change roles at all
     *   verbs                            the seven, in order, with labels
     *   levels                           the five, with the verbs each grants
     *   modules                          this console's, in sidebar order, each
     *                                    with its named `acts` (key, verb, label,
     *                                    short, description) — empty for most
     *   grantable[module][verb]          whether the actor holds it — what the
     *                                    screen may offer as a live checkbox. A
     *                                    convenience only: `setPermissions()`
     *                                    enforces it regardless. A split verb is
     *                                    held only when every act under it is.
     *   grantable[module][act]           the same, for each named act
     *   roles[]                          id, name, label, is_system, is_own,
     *                                    is_shared, copied_from, scope, holders,
     *                                    can_rename/can_delete/can_edit_permissions/
     *                                    can_reset, each with its *_refusal sentence,
     *                                    is_default, permissions_edited_at (ISO 8601)
     *                                    and permissions_edited_by (a name), note,
     *                                    cells[module] and the compiled
     *                                    permission list
     *
     * @return array<string, mixed>
     */
    public function board(User $actor): array
    {
        return $this->whileReading($actor, fn (): array => $this->drawBoard($actor));
    }

    /**
     * `board()`, inside its reading pass.
     *
     * @return array<string, mixed>
     */
    private function drawBoard(User $actor): array
    {
        [$console, $tenantId] = $this->context($actor);

        $modules = $this->modules($console);

        // Every role WITH what each holds and what it was copied from, read once for the
        // whole board rather than once per question asked of each role (M11 A2).
        $roles = $this->visibleRoles($console, $tenantId)->load(['moduleAccess.module', 'copiedFrom', 'versionOf', 'permissions']);
        $this->reading['roles'] = $roles;

        // Who last changed each role's permissions, in one read rather than one a role.
        $editors = $roles->pluck('permissions_edited_by_id')->filter()->unique()->values()->all();
        $this->reading['editors'] = $editors === [] ? [] : User::query()->whereKey($editors)->pluck('name', 'id')->all();

        $holders = $this->holderCounts($console, $tenantId, $roles);
        $canEdit = $this->canEdit($actor);

        $grantable = [];

        foreach ($modules as $module) {
            foreach (PermissionVerb::cases() as $verb) {
                $grantable[$module->key][$verb->value] = $this->holdsVerb($actor, $module, $verb);
            }

            foreach (NamedActs::of($console, $module->key) as $key => $act) {
                $grantable[$module->key][$key] = $this->holds($actor, $act['permission']);
            }
        }

        return [
            'console' => $console->value,
            'tenant_id' => $tenantId,
            'can_edit' => $canEdit,
            'edit_blocked_reason' => $canEdit ? null : $this->mayNotEditSentence($console),
            'edit_permission' => self::editPermission($console),
            'verbs' => array_map(
                static fn (PermissionVerb $verb): array => ['key' => $verb->value, 'label' => ucfirst($verb->value)],
                PermissionVerb::cases(),
            ),
            'levels' => array_map(
                static fn (AccessLevel $level): array => [
                    'key' => $level->value,
                    'label' => $level->label(),
                    'verbs' => array_map(static fn (PermissionVerb $verb): string => $verb->value, $level->verbs()),
                ],
                AccessLevel::cases(),
            ),
            'modules' => $modules->map(static fn (Module $module): array => [
                'id' => $module->id,
                'key' => $module->key,
                'label' => $module->label,
                'section' => $module->section,
                'locked_financial' => $module->is_locked_financial,

                // The named acts one of its verbs is split into (M11 B3), in the
                // order every screen lists them. Empty for most modules.
                'acts' => array_values(array_map(
                    static fn (array $act): array => [
                        'key' => $act['key'],
                        'verb' => $act['verb'],
                        'label' => $act['label'],
                        'short' => $act['short'],
                        'description' => $act['description'],
                    ],
                    NamedActs::of($module->console, $module->key),
                )),
            ])->values()->all(),
            'grantable' => $grantable,
            'grantable_reason' => 'You can only give a role, or take from it, what your own role holds.',
            'roles' => $roles->map(fn (Role $role): array => $this->roleRow($actor, $role, $modules, $holders[$role->id] ?? 0))
                ->values()
                ->all(),
        ];
    }

    /**
     * The role with this id or machine name, if this actor's console and estate
     * can see it — and a refusal otherwise.
     *
     * FOR A CONTROLLER THAT HAS AN ID FROM A URL. Another estate's role, the
     * other console's role and a role that does not exist all refuse with the
     * same sentence, so a guessed id tells an estate nothing about anybody else.
     * Prefer this to route-model binding, which would 404 one and resolve the
     * other and so answer the question this is built not to answer.
     *
     * An `int` is an id and a `string` is a machine name — so a controller
     * passes `(int) $id` from a route segment, never the raw string.
     */
    public function find(User $actor, int|string $role): Role
    {
        [$console, $tenantId] = $this->context($actor);

        $found = Role::query()
            ->where('console', $console->value)
            ->ownedBy($tenantId)
            ->where(is_int($role) ? 'id' : 'name', $role)
            ->first();

        if ($found === null) {
            throw new DomainException($this->notHereSentence($console));
        }

        // A shared role this estate has made its own is found as its version (M11 B4): the people-facing
        // lookups — seat somebody as Treasurer, invite a Treasurer — land on the role this estate means.
        return $this->here($found, $tenantId);
    }

    /**
     * The roles this actor may hand out — assign, or invite somebody into.
     *
     * An estate: the eight shared estate roles and its own, never another
     * estate's and never a Gemini one. The Gemini console: its platform-wide
     * roles, never a tenant-owned row. Seeded roles first, then in sort order.
     *
     * @return Collection<int, Role>
     */
    public function assignable(User $actor): Collection
    {
        [$console, $tenantId] = $this->context($actor);

        return $this->visibleRoles($console, $tenantId);
    }

    /** The role by machine name, if this actor may hand it out, or the refusal. */
    public function assignableNamed(User $actor, string $name): Role
    {
        return $this->find($actor, $name);
    }

    /**
     * May this actor give somebody this role?
     *
     * A ROLE THAT CAN CHANGE ROLES IS HANDED OUT ONLY BY SOMEBODY WHO CAN. That
     * is the client's "nobody grants what they do not hold" applied to the one
     * permission that would otherwise escalate: somebody given Settings'
     * Manage people (`manage_people`, the half of `update` that seats people
     * since M11 B3) to manage the committee could otherwise make a friend a Community Super
     * Admin. The rest of a role's permissions are NOT checked against the
     * actor's, deliberately — the Community Super Admin holds no approve on the
     * money or on governance, by the separation D-013 draws, and must still be
     * able to seat a Treasurer and a President.
     */
    public function assertMayHandOut(User $actor, Role $role): void
    {
        $power = self::editPermission($role->console);

        if ($this->carries($role, $power) && ! $this->holds($actor, $power)) {
            throw new DomainException(sprintf(
                '%s can change what every role here may do, so only somebody who can do that themselves may give it '.
                'to anyone. Your role cannot.',
                $this->labelOf($role),
            ));
        }
    }

    /**
     * May this actor change the role or standing of somebody who holds this one?
     *
     * The mirror of `assertMayHandOut()`: an administrator is demoted, suspended
     * or removed only by another administrator, never by somebody they
     * delegated the committee to.
     */
    public function assertMayManageHolderOf(User $actor, Role $current): void
    {
        $power = self::editPermission($current->console);

        if ($this->carries($current, $power) && ! $this->holds($actor, $power)) {
            throw new DomainException(sprintf(
                'This person is a %s, who can change what every role here may do. Only somebody who holds that '.
                'power themselves may change their role or their access.',
                $this->labelOf($current),
            ));
        }
    }

    /**
     * Why this actor may not do this to this role, or null if they may.
     *
     * `$act` is 'rename', 'delete', 'permissions' or 'reset'. The board calls it
     * for every role so a control is drawn disabled with its reason; the write
     * methods call it again, because a control nobody draws is still one
     * somebody can post to.
     */
    public function refusal(User $actor, Role $role, string $act): ?string
    {
        [$console, $tenantId] = $this->context($actor);

        if (! $this->canEdit($actor)) {
            return $this->mayNotEditSentence($console);
        }

        if ($role->console !== $console || ($role->tenant_id !== null && $role->tenant_id !== $tenantId)) {
            return $this->notHereSentence($console);
        }

        // A shared role this estate has made its own is answered for as its version (M11 B4).
        $role = $this->here($role, $tenantId);

        if ($role->is_system && in_array($act, ['rename', 'delete'], true)) {
            return sprintf(
                '%s is one of the platform\'s own roles, and code across the platform relies on it by name, so it '.
                'cannot be %s. %s',
                $this->labelOf($role),
                $act === 'rename' ? 'renamed' : 'deleted',
                $role->isSharedAcrossEstates()
                    ? 'Its permissions can still be changed here: the first change makes this community\'s own version of it, and "Reset to GeminiSecure default" puts it back.'
                    : ($act === 'rename' ? 'Its permissions can still be changed.' : 'Its permissions can be narrowed instead, down to what it needs to restore the rest.'),
            );
        }

        /*
         * THIS COMMUNITY'S VERSION OF A SHARED ROLE (M11 B4) keeps the shared role's name on the
         * screen and its place in every rule that recognises it by what it is, so it is neither
         * renamed nor deleted. "Reset to GeminiSecure default" is how it goes: everybody back on
         * the shared role, and the version removed.
         */
        if ($role->isEstateVersion() && in_array($act, ['rename', 'delete'], true)) {
            return sprintf(
                '%s is this community\'s own version of one of the platform\'s roles, so it keeps that role\'s name '.
                'and cannot be %s. "Reset to GeminiSecure default" puts everybody here back on the shared %s and '.
                'removes this version.',
                $this->labelOf($role),
                $act === 'rename' ? 'renamed' : 'deleted',
                $this->labelOf($role),
            );
        }

        if ($role->isSharedAcrossEstates()) {
            /*
             * EDITED HERE, NOT REFUSED (M11 B4). The client: "Copy it first, then edit the copy"
             * isn't letting an estate edit its roles. The first change makes this community's
             * own version, and only this community's holders move onto it.
             */
            return $act === 'reset'
                ? sprintf('%s is at GeminiSecure\'s default for this community: nothing has been changed here to reset.', $this->labelOf($role))
                : null;
        }

        if (! $role->isEditableBy($actor, $tenantId)) {
            return $this->notHereSentence($console);
        }

        // A version is reset by removing it, whatever it holds now.
        if ($role->isEstateVersion() && $act === 'reset') {
            return null;
        }

        if ($act === 'delete') {
            return $this->deleteRefusal($role);
        }

        if ($act === 'reset') {
            return $this->resetRefusal($role);
        }

        return null;
    }

    /**
     * What "Reset to GeminiSecure default" would do to this role, or null for a
     * role that has no default (every role made at runtime).
     *
     * `add` and `remove` are permission names; `is_default` is whether the
     * role's cells are already exactly the seeded ones; `edited_at` is when the
     * role was last changed at runtime, if it is marked. For the screen to show
     * before the button is pressed, and for `rbac:reset-role` to print before
     * it is confirmed.
     *
     * @return array{add: list<string>, remove: list<string>, is_default: bool, edited_at: string|null}|null
     */
    public function defaultDifference(Role $role): ?array
    {
        // A community's version of a shared role goes back to what that role ships with (M11 B4).
        $defaults = $this->defaults()->defaultsFor($role->standsFor());

        if ($defaults === null || ! ($role->is_system || $role->isEstateVersion())) {
            return null;
        }

        $modules = $this->modules($role->console)->keyBy('key');
        $current = RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->keyBy('module_id');

        $before = $this->namesOf($current->all());
        $after = $this->namesOf($this->defaultCells($modules, $defaults));

        return [
            'add' => array_values(array_diff($after, $before)),
            'remove' => array_values(array_diff($before, $after)),
            'is_default' => $this->matchesDefault($role),
            'edited_at' => $role->permissions_edited_at?->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * A new role for the actor's own console — and, from an estate, owned by
     * that estate and nobody else.
     *
     * Every module of the console gets a cell: those the grid names, as named,
     * and the rest at no access, so the matrix is complete and a missing cell
     * never has to be interpreted. The machine name is made once from the first
     * label — `estate.custom.<estate>.<slug>` or `gemini.custom.<slug>` — and
     * never changes, whatever the role is renamed to.
     *
     * `$scopeDefault` is the Gemini console's only: `all` or `assigned_sites`,
     * and an actor whose own reach is narrowed cannot make a role that reaches
     * every client. An estate role reaches the whole of that estate and nothing
     * beyond it, which tenancy enforces; it has no scope to set.
     *
     * @param  array<string, array<string, mixed>>  $grid
     */
    public function create(User $actor, string $label, array $grid = [], ?string $scopeDefault = null): Role
    {
        return $this->make($actor, $label, $grid, $scopeDefault, null, []);
    }

    /**
     * What a copy of this role would hold if this actor made it — and what it
     * would have to leave out.
     *
     * A COPY IS STILL A GRANT. A Community Super Admin copying the Treasurer
     * would otherwise hand a new role the approvals on the money that the
     * Community Super Admin does not hold themselves; so every permission the
     * actor does not hold is withheld from the copy and named, for the screen to
     * say before the copy is made rather than after.
     *
     * A SPLIT VERB IS COPIED ACT BY ACT (M11 B3). A copier who holds only some of
     * the dues approvals — the Community Super Admin holds `set_rates` — copying
     * the Treasurer's Approver tag gives the copy the approvals they hold, one by
     * one, and names the rest as withheld.
     *
     * @return array{grid: array<string, array{level: string, verbs: list<string>|null, can_approve: bool, acts: list<string>|null}>, withheld: list<string>}
     */
    public function copyableGrid(User $actor, Role $source): array
    {
        [$console, $tenantId] = $this->context($actor);
        $source = $this->find($actor, $source->id);

        // The role's cells in one read, not one per module (M11 A2). The pair is unique,
        // so keyed by module these are exactly the cells a per-module lookup found.
        $cells = RoleModuleAccess::query()->where('role_id', $source->id)->get()->keyBy('module_id');

        return $this->copyable($actor, $this->modules($console), $cells);
    }

    /**
     * `copyableGrid()`'s withheld list for every role this actor's console and estate
     * can see, keyed by role id — what the roles screen prints beside each role's
     * "Copy" before anything is made (M11 A2).
     *
     * ONE READ OF EVERY ROLE'S CELLS, where the screen used to ask `find()` and
     * `copyableGrid()` of each role in turn — a context check, a lookup and fourteen
     * cell reads a role. The roles are the ones `find()` would have admitted: this
     * console's, and this estate's own or shared. Each list is the one
     * `copyableGrid()` returns for that role.
     *
     * Keyed by the role's id as an integer — which is what PHP makes of the id
     * however it is written as a key, and what the screen's `copyOffers` map has
     * always been keyed by once encoded.
     *
     * @return array<int, list<string>>
     */
    public function copyWithheld(User $actor): array
    {
        return $this->whileReading($actor, function () use ($actor): array {
            [$console, $tenantId] = $this->context($actor);

            $modules = $this->modules($console);
            $offers = [];

            foreach ($this->visibleRoles($console, $tenantId)->load('moduleAccess') as $role) {
                $offers[(int) $role->id] = $this->copyable($actor, $modules, $role->moduleAccess->keyBy('module_id'))['withheld'];
            }

            return $offers;
        });
    }

    /**
     * What a copy of a role with these cells would hold, and what it would leave out.
     *
     * @param  Collection<int, Module>  $modules
     * @param  \Illuminate\Support\Collection<int, RoleModuleAccess>  $cells  keyed by module id
     * @return array{grid: array<string, array{level: string, verbs: list<string>|null, can_approve: bool, acts: list<string>|null}>, withheld: list<string>}
     */
    private function copyable(User $actor, Collection $modules, \Illuminate\Support\Collection $cells): array
    {
        $grid = [];
        $withheld = [];

        foreach ($modules as $module) {
            $cell = $cells->get($module->id);

            if ($cell === null) {
                continue;
            }

            $cell->setRelation('module', $module);

            $kept = [];
            $acts = [];

            // A split verb the actor holds only part of keeps the acts they hold.
            $actByAct = function (PermissionVerb $verb) use ($actor, $module, &$acts, &$withheld): void {
                foreach (NamedActs::under($module->console, $module->key, $verb) as $act) {
                    if ($this->holds($actor, $module->permissionPrefix().'.'.$act)) {
                        $acts[] = $act;
                    } else {
                        $withheld[] = $this->permissionLabel($module, $act);
                    }
                }
            };

            foreach ($cell->grantedVerbs() as $verb) {
                if ($this->holdsVerb($actor, $module, $verb)) {
                    $kept[] = $verb;
                } elseif (NamedActs::under($module->console, $module->key, $verb) !== []) {
                    $actByAct($verb);
                } else {
                    $withheld[] = $this->permissionLabel($module, $verb->value);
                }
            }

            $approve = $cell->can_approve;

            if ($approve && ! $this->holdsVerb($actor, $module, PermissionVerb::Approve)) {
                $approve = false;

                if (NamedActs::under($module->console, $module->key, PermissionVerb::Approve) !== []) {
                    $actByAct(PermissionVerb::Approve);
                } else {
                    $withheld[] = $this->permissionLabel($module, 'approve');
                }
            }

            foreach ($cell->explicitActs() as $act) {
                if ($this->holds($actor, $module->permissionPrefix().'.'.$act)) {
                    $acts[] = $act;
                } else {
                    $withheld[] = $this->permissionLabel($module, $act);
                }
            }

            // An Approver tag, or a named act, with nothing left to open is not a
            // cell anybody could use, and `normalise()` refuses one — an act under
            // a verb the copy would keep included, so that verb goes too.
            if (! in_array(PermissionVerb::View, $kept, true)) {
                if ($approve) {
                    $withheld[] = $this->permissionLabel($module, 'approve');
                }

                foreach ($acts as $act) {
                    $withheld[] = $this->permissionLabel($module, $act);
                }

                foreach ($kept as $i => $verb) {
                    $under = NamedActs::under($module->console, $module->key, $verb);

                    if ($under === []) {
                        continue;
                    }

                    foreach ($under as $act) {
                        $withheld[] = $this->permissionLabel($module, $act);
                    }

                    unset($kept[$i]);
                }

                $kept = array_values($kept);
                $approve = false;
                $acts = [];
            }

            $level = AccessLevel::presetFor($kept) ?? AccessLevel::Custom;

            $grid[$module->key] = [
                'level' => $level->value,
                'verbs' => $level === AccessLevel::Custom ? array_map(static fn (PermissionVerb $v): string => $v->value, $kept) : null,
                'can_approve' => $approve,
                'acts' => $acts === [] ? null : $this->orderedActs($module, $acts),
            ];
        }

        return ['grid' => $grid, 'withheld' => array_values(array_unique($withheld))];
    }

    /**
     * A new role of this estate's (or the platform's) own, starting from another
     * role's grid — the way an estate gets "our own Treasurer" when the shared
     * Treasurer is not theirs to change.
     *
     * Whatever `copyableGrid()` withholds is left out and named in the audit
     * entry. The copy remembers the SYSTEM role at the root of its lineage, which
     * is what keeps a copy of the Property Manager bound by Ruling 1.
     */
    public function copy(User $actor, Role $source, ?string $label = null): Role
    {
        $source = $this->find($actor, $source->id);
        ['grid' => $grid, 'withheld' => $withheld] = $this->copyableGrid($actor, $source);

        $label ??= 'Copy of '.$this->labelOf($source);

        return $this->make(
            $actor,
            $label,
            $grid,
            $source->console === Console::Gemini ? $source->scope_default->value : null,
            $source->is_system ? $source->id : $source->copied_from_id,
            ['copied_from' => $source->name, 'withheld' => $withheld],
        );
    }

    /**
     * Rename a role. `label` changes; `name`, the machine key, never does.
     *
     * The fourteen seeded roles are refused: code across the platform finds them
     * by name, and a screen label drifting from the role the code means is how a
     * "Treasurer" that is not the Treasurer would come about.
     */
    public function rename(User $actor, Role $role, string $label): Role
    {
        [$console, $tenantId] = $this->context($actor);
        $this->refuseIf($this->refusal($actor, $role, 'rename'));

        $label = $this->cleanLabel($label, $console, $tenantId, $role);
        $before = $this->snapshot($role);

        if ($before['label'] === $label) {
            return $role;
        }

        return $this->commit(function () use ($actor, $role, $label, $before, $tenantId): Role {
            $role->forceFill(['label' => $label])->save();

            $this->audit->record(
                action: 'role.renamed',
                entityType: 'Role',
                entityId: $role->name,
                before: $before,
                after: $this->snapshot($role),
                tenantId: $tenantId,
                actor: $actor,
            );

            return $role;
        });
    }

    /**
     * Change what a role may do.
     *
     * Only the modules named in `$grid` change; every other cell stays exactly
     * as it was, so a screen may post one column or one cell. The whole role is
     * then recompiled into spatie's `role_has_permissions` — the table `can:`
     * actually reads — because a write to `role_module_access` alone would show
     * the change on the matrix and enforce nothing.
     *
     * @param  array<string, array<string, mixed>>  $grid
     */
    public function setPermissions(User $actor, Role $role, array $grid): Role
    {
        [$console, $tenantId] = $this->context($actor);
        $role = $this->here($role, $tenantId);
        $this->refuseIf($this->refusal($actor, $role, 'permissions'));

        $modules = $this->modules($console)->keyBy('key');
        $cells = $this->normalise($console, $modules, $grid);
        $scope = $this->scopeFor($role->scope_default);

        $this->assertRulingOne($role, $cells);

        $current = RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->keyBy('module_id');

        $beforeNames = $this->namesOf($current->all());
        $afterCells = $current->all();

        foreach ($cells as $key => $cell) {
            $module = $modules[$key];
            $afterCells[$module->id] = $this->cellFor($module, $cell, $scope);
        }

        $afterNames = $this->namesOf($afterCells);

        $this->assertHoldsTheDifference($actor, $beforeNames, $afterNames, $modules);
        $this->assertStructuralMinimum($role, $afterNames);

        /*
         * A SAVE THAT CHANGES NOTHING IS NOT AN EDIT. Written anyway, it would
         * mark a seeded role as the Director's and freeze it against every later
         * change to the grid, for a click that changed nothing.
         */
        $unchanged = true;

        foreach ($cells as $key => $cell) {
            if (! $this->sameCell($current->get($modules[$key]->id), $cell, $scope)) {
                $unchanged = false;

                break;
            }
        }

        if ($unchanged) {
            return $role->fresh(['moduleAccess.module']);
        }

        $before = $this->snapshot($role);

        return $this->commit(function () use ($actor, $role, $cells, $modules, $scope, $before, $tenantId): Role {
            /*
             * THE FIRST CHANGE TO A SHARED ROLE MAKES THIS COMMUNITY'S VERSION OF IT (M11 B4), in the
             * same transaction as the change: every rule above was asked of the shared role, whose
             * cells the version starts from exactly, so a refused change makes no version.
             */
            if ($role->isSharedAcrossEstates() && $tenantId !== null) {
                $role = $this->makeVersion($actor, $role, $tenantId);
            }

            foreach ($cells as $key => $cell) {
                RoleModuleAccess::query()->updateOrCreate(
                    ['role_id' => $role->id, 'module_id' => $modules[$key]->id],
                    [
                        'level' => $cell['level']->value,
                        'verbs' => $cell['verbs'],
                        'acts' => $cell['acts'],
                        'can_approve' => $cell['can_approve'],
                        'scope' => ($cell['level'] === AccessLevel::None ? AccessScope::All : $scope)->value,
                    ],
                );
            }

            $this->compile($role);
            $this->recordEdit($role, $actor);

            $this->audit->record(
                action: 'role.permissions_changed',
                entityType: 'Role',
                entityId: $role->name,
                before: $before,
                after: $this->snapshot($role),
                tenantId: $tenantId,
                actor: $actor,
            );

            return $role->fresh(['moduleAccess.module']);
        });
    }

    /**
     * "Reset to GeminiSecure default": a seeded role's cells back to the ones the
     * platform ships, and its edit mark cleared, so it follows the grid again on
     * every later re-seed.
     *
     * Only a seeded role has a default. Only the Gemini console's six can
     * actually differ from it: the estate's eight are shared by every estate and
     * never change at runtime, so from an estate this refuses with the same
     * sentence as any change to them. A role already at its default, and not
     * marked, is refused as having nothing to reset, so the audit log does not
     * fill with resets that changed nothing.
     *
     * THE SAME RULE AS ANY OTHER CHANGE: nobody gives or takes away what they do
     * not hold. A reset is still a change to what a role may do, and an
     * administrator whose own role is narrower than the default cannot use it to
     * widen another. That includes the Director resetting the Director after
     * narrowing it. `rbac:reset-role` on the server is the way back from that.
     */
    public function resetToDefault(User $actor, Role $role): Role
    {
        [$console, $tenantId] = $this->context($actor);
        $role = $this->here($role, $tenantId);
        $this->refuseIf($this->refusal($actor, $role, 'reset'));

        /*
         * THIS COMMUNITY'S VERSION OF A SHARED ROLE (M11 B4) is reset by removing it: everybody here
         * goes back on the shared role, which is GeminiSecure's default for every community. The same
         * rule as any change: what the move takes away or gives back must be what the actor holds.
         */
        if ($role->isEstateVersion() && $tenantId !== null) {
            $shared = Role::query()->findOrFail($role->version_of_id);
            $modules = $this->modules($console)->keyBy('key');

            $beforeNames = $this->namesOf(RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->all());
            $afterNames = $this->namesOf(RoleModuleAccess::query()->with('module')->where('role_id', $shared->id)->get()->all());

            $this->assertHoldsTheDifference($actor, $beforeNames, $afterNames, $modules);

            return $this->commit(fn (): Role => $this->dissolveVersion($actor, $role, $shared, $tenantId));
        }

        /** @var array<string, array{level: AccessLevel, can_approve: bool, scope: AccessScope}> $defaults */
        $defaults = $this->defaults()->defaultsFor($role->name);
        $modules = $this->modules($console)->keyBy('key');

        $beforeNames = $this->namesOf(
            RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->all()
        );
        $afterNames = $this->namesOf($this->defaultCells($modules, $defaults));

        $this->assertHoldsTheDifference($actor, $beforeNames, $afterNames, $modules);
        $this->assertStructuralMinimum($role, $afterNames);

        return $this->commit(fn (): Role => $this->restore($role, $modules, $defaults, $actor, $tenantId, 'console'));
    }

    /**
     * The same reset, for `php artisan rbac:reset-role` and nothing else.
     *
     * NO ACTOR AND NO PERMISSION CHECK, deliberately, exactly like the seeder it
     * undoes. Whoever can run artisan on the server can already re-seed the
     * whole matrix, so there is nothing a check here could withhold from them.
     * It is the way back when nobody left on the console may make the change,
     * such as a Director who narrowed their own role and can no longer grant
     * what the default holds. Audited with no actor and `via` naming the command.
     * Any of the fourteen seeded roles; nothing else.
     *
     * NEVER CALL IT FROM A REQUEST. `resetToDefault()` is the console's.
     */
    public function resetToDefaultByOperator(Role $role): Role
    {
        $defaults = $this->defaults()->defaultsFor($role->name);

        if ($defaults === null || ! $role->is_system) {
            throw new DomainException(sprintf(
                '%s is not one of GeminiSecure\'s own roles, so it has no default to go back to.',
                $this->labelOf($role),
            ));
        }

        $modules = $this->modules($role->console)->keyBy('key');

        return $this->commit(fn (): Role => $this->restore($role, $modules, $defaults, null, null, 'rbac:reset-role'));
    }

    /**
     * Delete a role nobody holds.
     *
     * Refused for a seeded role, for one anybody holds, for one an open
     * invitation names, and for one somebody who has left is recorded as having
     * held — `estate_assignments` would lose that row to the cascade, and who
     * held what, when, is the history an audit of a decision reads. Each refusal
     * says what to do instead.
     */
    public function delete(User $actor, Role $role): void
    {
        [, $tenantId] = $this->context($actor);
        $this->refuseIf($this->refusal($actor, $role, 'delete'));

        $before = $this->snapshot($role);

        $this->commit(function () use ($actor, $role, $before, $tenantId): void {
            // The audit row first: it is the only thing that will remember the
            // role once the cascade has taken its cells and its permissions.
            $this->audit->record(
                action: 'role.deleted',
                entityType: 'Role',
                entityId: $role->name,
                before: $before,
                after: null,
                tenantId: $tenantId,
                actor: $actor,
            );

            $role->delete();
        });
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * The one place every write goes through.
     *
     * THE FLUSH IS AFTER THE COMMIT AND OUTSIDE THE TRANSACTION, DELIBERATELY.
     * spatie's Role already clears the permission cache on `saved` and
     * `deleted` (RefreshesPermissionCache), and `syncPermissions()` clears it
     * again — but all of those fire INSIDE the transaction, before the rows are
     * committed. A request that arrives in that window rebuilds the cache from
     * what it can see, which is the old rows, and the cache then holds them for
     * twenty-four hours (config/permission.php). That is exactly the stale cache
     * that once locked a committee out, and the client named it in M10 §4. The
     * cache store is central and shared by every worker (config/cache.php), so
     * this one flush, after the commit, is what makes a change take effect on
     * the very next request.
     *
     * A Closure, as `Connection::transaction()` takes: handed a bare callable, the
     * type of what the work returns was lost on the way through.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function commit(Closure $work): mixed
    {
        $result = DB::connection('mysql')->transaction($work);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $result;
    }

    /**
     * THIS COMMUNITY'S OWN VERSION OF A SHARED ROLE (M11 B4), made the moment the community first
     * changes it. Inside `commit()`, always.
     *
     * An EXACT copy, not `copy()`'s, which withholds what the copier does not hold: the people
     * who move onto it must hold exactly what they held a moment ago, and the change that
     * follows is the only one checked against the actor. It carries:
     * - the shared role's label, place and scope;
     * - `copied_from_id`, which keeps Ruling 1 and the Head of Security's panic text true for it;
     * - `version_of_id`, what it stands for.
     * This community's assignments, their role and its invitations move onto it. No other
     * community's do.
     *
     * REFUSED when somebody who holds the shared role here also holds it at another community:
     * a role is one grant wherever its holder reaches, so moving them would change what they may
     * do there too.
     */
    private function makeVersion(User $actor, Role $shared, string $tenantId): Role
    {
        $holders = EstateAssignment::query()->where('tenant_id', $tenantId)->where('role_id', $shared->id)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        if ($holders !== [] && EstateAssignment::query()->whereIn('user_id', $holders)->where('tenant_id', '!=', $tenantId)->where('role_id', $shared->id)->where('is_active', true)->exists()) {
            throw new DomainException(sprintf(
                'Somebody who holds %s here also holds it at another community on GeminiSecure, so a version of it '.
                'for this community alone cannot be made without changing what they may do there. Ask Gemini to '.
                'look at their account first.',
                $this->labelOf($shared),
            ));
        }

        $base = 'estate.version.'.$tenantId.'.'.(Str::afterLast((string) $shared->name, '.') ?: 'role');
        $name = $base;

        for ($n = 2; Role::query()->where('name', $name)->where('guard_name', 'web')->exists(); $n++) {
            $name = "{$base}_{$n}";
        }

        $version = new Role;
        $version->forceFill([
            'name' => $name,
            'guard_name' => 'web',
            'console' => Console::Estate->value,
            'tenant_id' => $tenantId,
            'is_system' => false,
            'copied_from_id' => $shared->id,
            'version_of_id' => $shared->id,
            'created_by_id' => $actor->id,
            'label' => $shared->label,
            'sort' => $shared->sort,
            'scope_default' => $shared->scope_default->value,
        ])->save();

        // Every cell exactly as the shared role holds it, in one insert.
        $now = (new RoleModuleAccess)->freshTimestampString();
        $rows = RoleModuleAccess::query()->where('role_id', $shared->id)->get()
            ->map(static function (RoleModuleAccess $cell) use ($version, $now): array {
                $attributes = $cell->getAttributes();
                unset($attributes['id']);

                return [...$attributes, 'role_id' => $version->id, 'created_at' => $now, 'updated_at' => $now];
            })
            ->all();

        if ($rows !== []) {
            RoleModuleAccess::query()->insert($rows);
        }

        $this->compile($version);

        $moved = $this->moveHolders($shared, $version, $tenantId);

        $this->audit->record(
            action: 'role.version_created',
            entityType: 'Role',
            entityId: $version->name,
            before: null,
            after: [...$this->snapshot($version), 'version_of' => $shared->name, 'holders_moved' => $moved['holders'], 'invitations_moved' => $moved['invitations']],
            tenantId: $tenantId,
            actor: $actor,
        );

        return $version;
    }

    /**
     * "Reset to GeminiSecure default" on this community's version (M11 B4): everybody here back on
     * the shared role, the version's record audited, and the version removed. Inside `commit()`.
     */
    private function dissolveVersion(User $actor, Role $version, Role $shared, string $tenantId): Role
    {
        $before = $this->snapshot($version);
        $moved = $this->moveHolders($version, $shared, $tenantId);

        // The audit row first: it is the only thing that will remember the version once it is gone.
        $this->audit->record(
            action: 'role.version_reset',
            entityType: 'Role',
            entityId: $version->name,
            before: $before,
            after: ['back_on' => $shared->name, 'holders_moved' => $moved['holders'], 'invitations_moved' => $moved['invitations']],
            tenantId: $tenantId,
            actor: $actor,
        );

        $version->delete();

        return $shared->fresh(['moduleAccess.module']) ?? $shared;
    }

    /**
     * This community's people and invitations from one role to another: every assignment here
     * (active or not, so the record of who held what still reads as the same role), the role each
     * of those people holds, and every invitation this community sent into it.
     *
     * @return array{holders: int, invitations: int}
     */
    private function moveHolders(Role $from, Role $to, string $tenantId): array
    {
        $userIds = EstateAssignment::query()->where('tenant_id', $tenantId)->where('role_id', $from->id)
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        EstateAssignment::query()->where('tenant_id', $tenantId)->where('role_id', $from->id)->update(['role_id' => $to->id]);

        $db = DB::connection('mysql');
        $morph = (new User)->getMorphClass();

        foreach ($userIds as $userId) {
            $held = $db->table('model_has_roles')->where('model_type', $morph)->where('model_id', $userId)->where('role_id', $from->id);

            if (! $held->exists()) {
                continue;
            }

            $db->table('model_has_roles')->insertOrIgnore(['role_id' => $to->id, 'model_type' => $morph, 'model_id' => $userId]);
            $held->delete();
        }

        $invitations = Invitation::query()->where('tenant_id', $tenantId)->where('role_id', $from->id)->update(['role_id' => $to->id]);

        return ['holders' => count($userIds), 'invitations' => $invitations];
    }

    /**
     * create() and copy() share this.
     *
     * @param  array<string, array<string, mixed>>  $grid
     * @param  array<string, mixed>  $provenance
     */
    private function make(User $actor, string $label, array $grid, ?string $scopeDefault, ?int $copiedFromId, array $provenance): Role
    {
        [$console, $tenantId] = $this->context($actor);

        if (! $this->canEdit($actor)) {
            throw new DomainException($this->mayNotEditSentence($console));
        }

        $label = $this->cleanLabel($label, $console, $tenantId, null);
        $scope = $this->scopeDefaultFor($actor, $console, $scopeDefault);
        $modules = $this->modules($console)->keyBy('key');
        $cells = $this->normalise($console, $modules, $grid);

        // A throwaway role, unsaved, so Ruling 1 can be asked about the lineage
        // the real one will have before anything is written.
        $probe = new Role;
        $probe->forceFill(['name' => '', 'console' => $console->value, 'copied_from_id' => $copiedFromId]);
        $this->assertRulingOne($probe, $cells, $modules);

        $afterCells = [];

        foreach ($cells as $key => $cell) {
            $afterCells[] = $this->cellFor($modules[$key], $cell, $this->scopeFor($scope));
        }

        $this->assertHoldsTheDifference($actor, [], $this->namesOf($afterCells), $modules);

        return $this->commit(function () use ($actor, $console, $tenantId, $label, $scope, $modules, $cells, $copiedFromId, $provenance): Role {
            $role = new Role;
            $role->forceFill([
                'name' => $this->machineName($console, $tenantId, $label),
                'guard_name' => 'web',
                'console' => $console->value,
                'tenant_id' => $tenantId,
                'is_system' => false,
                'copied_from_id' => $copiedFromId,
                'created_by_id' => $actor->id,
                'label' => $label,
                'sort' => $this->nextSort($console, $tenantId),
                'scope_default' => $scope->value,
            ])->save();

            /*
             * EVERY MODULE'S CELL IN ONE INSERT (M11 A2), where it was one `create()` a
             * module. Each row is the model's own attributes, its casts applied, with the
             * timestamps `create()` stamps, so the rows are the rows it wrote. The model
             * has no creating hook or observer to skip.
             */
            $now = (new RoleModuleAccess)->freshTimestampString();
            $rows = [];

            foreach ($modules as $key => $module) {
                $cell = $cells[$key] ?? ['level' => AccessLevel::None, 'verbs' => null, 'can_approve' => false, 'acts' => null];

                $rows[] = [
                    ...(new RoleModuleAccess([
                        'role_id' => $role->id,
                        'module_id' => $module->id,
                        'level' => $cell['level']->value,
                        'verbs' => $cell['verbs'],
                        'acts' => $cell['acts'] ?? null,
                        'can_approve' => $cell['can_approve'],
                        'scope' => ($cell['level'] === AccessLevel::None ? AccessScope::All : $this->scopeFor($scope))->value,
                    ]))->getAttributes(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                RoleModuleAccess::query()->insert($rows);
            }

            $this->compile($role);

            $this->audit->record(
                action: 'role.created',
                entityType: 'Role',
                entityId: $role->name,
                after: [...$this->snapshot($role), ...$provenance],
                tenantId: $tenantId,
                actor: $actor,
            );

            return $role->fresh(['moduleAccess.module']);
        });
    }

    /**
     * The console and estate an actor is acting in, derived and never accepted.
     *
     * An estate actor must be inside an estate they belong to: acting inside
     * estate B with an estate A account is refused here even if a route somehow
     * let it through, because "nothing crosses a tenant" is this class's rule
     * and not only the router's.
     *
     * @return array{0: Console, 1: string|null}
     */
    private function context(User $actor): array
    {
        // Already asked of this actor in this reading pass, and nothing has been written since.
        if ($this->reading !== null && $this->reading['actor'] === $actor->getKey()) {
            return $this->reading['context'];
        }

        if ($actor->status !== 'active') {
            throw new DomainException('This account is not active, so it cannot change anybody\'s access.');
        }

        if ($actor->console === Console::Gemini) {
            return [Console::Gemini, null];
        }

        $tenantId = tenant()?->getTenantKey();

        if ($tenantId === null) {
            throw new DomainException('An estate\'s roles are changed from inside that estate\'s own console.');
        }

        $tenantId = (string) $tenantId;

        if (! $actor->canAccessEstate($tenantId)) {
            throw new DomainException(self::NOT_HERE_ESTATE);
        }

        return [Console::Estate, $tenantId];
    }

    /**
     * The roles one console and estate can see: seeded first, then by sort.
     *
     * @return Collection<int, Role>
     */
    private function visibleRoles(Console $console, ?string $tenantId): Collection
    {
        // An estate's own version of a shared role stands in that role's place, with its sort (M11 B4).
        return Role::query()
            ->where('console', $console->value)
            ->visibleTo($tenantId)
            ->orderByRaw('CASE WHEN is_system = 1 OR version_of_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * The role this estate means by `$role`: its own version where `$role` is a shared role it
     * has one of, else `$role` itself (M11 B4). The platform console has no versions.
     */
    private function here(Role $role, ?string $tenantId): Role
    {
        if ($tenantId === null || ! $role->isSharedAcrossEstates()) {
            return $role;
        }

        // Inside a reading pass the estate's versions are read once, not once per role the board asks about.
        if ($this->reading !== null) {
            $versions = $this->reading['versions'][$tenantId] ??= Role::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('version_of_id')
                ->get()
                ->keyBy('version_of_id');

            return $versions->get($role->id) ?? $role;
        }

        return Role::query()->where('tenant_id', $tenantId)->where('version_of_id', $role->id)->first() ?? $role;
    }

    /** @return Collection<int, Module> */
    private function modules(Console $console): Collection
    {
        if ($this->reading !== null) {
            return $this->reading['modules'][$console->value] ??= Module::query()->where('console', $console->value)->orderBy('sort')->get();
        }

        return Module::query()->where('console', $console->value)->orderBy('sort')->get();
    }

    /**
     * How many people hold each role HERE — this estate's active assignments, or
     * the Gemini console's own accounts — not across the platform.
     *
     * @param  Collection<int, Role>  $roles
     * @return array<int, int>
     */
    private function holderCounts(Console $console, ?string $tenantId, Collection $roles): array
    {
        if ($console === Console::Estate) {
            return EstateAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereIn('role_id', $roles->modelKeys())
                ->selectRaw('role_id, COUNT(*) AS holders')
                ->groupBy('role_id')
                ->pluck('holders', 'role_id')
                ->map(static fn ($n): int => (int) $n)
                ->all();
        }

        /*
         * ONE GROUPED COUNT FOR EVERY ROLE ON THE BOARD (M11 A2), where it was a count per role.
         * The same rows `$role->users()->where('console', gemini)->count()` counted: people
         * holding the role through `model_has_roles`, as a user, on the Gemini console.
         */
        $counts = array_fill_keys(array_map('intval', $roles->modelKeys()), 0);

        if ($counts === []) {
            return $counts;
        }

        $held = DB::connection('mysql')
            ->table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->whereIn('model_has_roles.role_id', array_keys($counts))
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('users.console', Console::Gemini->value)
            ->groupBy('model_has_roles.role_id')
            ->selectRaw('model_has_roles.role_id as role_id, COUNT(*) as holders')
            ->pluck('holders', 'role_id');

        foreach ($held as $roleId => $n) {
            $counts[(int) $roleId] = (int) $n;
        }

        return $counts;
    }

    /**
     * One role's column on the board.
     *
     * @param  Collection<int, Module>  $modules
     * @return array<string, mixed>
     */
    private function roleRow(User $actor, Role $role, Collection $modules, int $holders): array
    {
        $cells = [];
        $byModule = $role->moduleAccess->keyBy('module_id');
        // What the role IS, so a community's version of the Community Super Admin keeps the same floor (M11 B4).
        $seeded = $role->is_system || $role->isEstateVersion();
        $minimum = self::STRUCTURAL[$role->standsFor()] ?? null;

        foreach ($modules as $module) {
            /** @var RoleModuleAccess|null $cell */
            $cell = $byModule->get($module->id);
            $locked = $module->isLockedFor($role);
            $isMinimum = $minimum === $module->key && $seeded;

            $cell?->setRelation('module', $module);

            $cells[$module->key] = [
                'level' => $cell->level->value ?? AccessLevel::None->value,
                'verbs' => $cell === null ? [] : array_map(static fn (PermissionVerb $v): string => $v->value, $cell->grantedVerbs()),
                'can_approve' => $cell->can_approve ?? false,

                // Named acts (M11 B3): the ones this cell lists one by one, and
                // every one it grants, its verbs' included.
                'acts' => $cell?->explicitActs() ?? [],
                'granted_acts' => $cell?->grantedActs() ?? [],
                'scope' => $cell?->scope->value ?? AccessScope::All->value,
                'grants_view' => $cell?->grantsView() ?? false,
                'pill' => $cell?->pillLabel() ?? AccessLevel::None->label(),
                'locked' => $locked,
                'locked_reason' => match (true) {
                    $locked => 'Closed to the Property Manager, and to any copy of it, by platform invariant: whoever commissions work must never be able to pay for it, nor see a resident\'s financial position.',
                    // The structural minimum says its own reason (M62 B5): the screen fell back to the line above.
                    $isMinimum => $role->label.' keeps Full and Approver on '.$module->label.', so somebody can always change what every role here may do.',
                    default => null,
                },
                'minimum' => $isMinimum,
            ];
        }

        $rename = $this->refusal($actor, $role, 'rename');
        $delete = $this->refusal($actor, $role, 'delete');
        $permissions = $this->refusal($actor, $role, 'permissions');
        $reset = $this->refusal($actor, $role, 'reset');

        $isDefault = $seeded && $this->matchesDefault($role);
        $editedBy = match (true) {
            $role->permissions_edited_by_id === null => null,
            // Read for the whole board at once, inside its reading pass (M11 A2).
            $this->reading !== null && array_key_exists((int) $role->permissions_edited_by_id, $this->reading['editors']) => $this->reading['editors'][(int) $role->permissions_edited_by_id],
            default => User::query()->whereKey($role->permissions_edited_by_id)->value('name'),
        };

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $this->labelOf($role),
            'sort' => $role->sort,
            'is_system' => $role->is_system,
            'is_own' => $role->tenant_id !== null,
            'is_shared' => $role->isSharedAcrossEstates(),
            // This community's own version of a shared role (M11 B4), and the shared role it stands for.
            'is_version' => $role->isEstateVersion(),
            'stands_for' => $role->isEstateVersion() ? $role->standsFor() : null,
            'copied_from' => $role->copiedFrom === null ? null : $this->labelOf($role->copiedFrom),
            'scope' => $role->scope_default->value,
            'scope_label' => $role->scope_default->label(),
            'holders' => $holders,
            'can_rename' => $rename === null,
            'rename_refusal' => $rename,
            'can_delete' => $delete === null,
            'delete_refusal' => $delete,
            'can_edit_permissions' => $permissions === null,
            'permissions_refusal' => $permissions,
            'can_reset' => $reset === null,
            'reset_refusal' => $reset,
            'is_default' => $isDefault,
            'permissions_edited_at' => $role->permissions_edited_at?->toIso8601String(),
            'permissions_edited_by' => $editedBy,
            'note' => match (true) {
                $role->isEstateVersion() => sprintf(
                    'This community\'s own version of %s, changed here%s. Every other community keeps GeminiSecure\'s. '.
                    '"Reset to GeminiSecure default" puts everybody here back on it.',
                    $this->labelOf($role),
                    // A comma where an em dash was (M53 law 3; the Settings pass, 12 Oct).
                    $minimum !== null ? sprintf(', and it always keeps Full · Approver on %s', (string) ($modules->firstWhere('key', $minimum)->label ?? $minimum)) : '',
                ),
                $minimum !== null && $role->is_system => sprintf(
                    '%s always keeps Full · Approver on %s: it is what lets somebody restore every other role.',
                    $this->labelOf($role),
                    (string) ($modules->firstWhere('key', $minimum)->label ?? $minimum),
                ),
                $role->isSharedAcrossEstates() => 'Shared by every community, at GeminiSecure\'s default here. Changing its '.
                    'permissions makes this community\'s own version of it, and only this community\'s people move onto it.',
                $role->is_system && $role->console === Console::Gemini && ! $isDefault => 'A seeded role, changed on this '.
                    'platform. Platform updates keep your changes; "Reset to GeminiSecure default" puts back the '.
                    'permissions it shipped with.',
                $role->is_system && $role->console === Console::Gemini => 'A seeded role, at GeminiSecure\'s default. '.
                    'It follows platform updates until you change it; after that, updates keep your changes.',
                default => null,
            },
            'cells' => $cells,

            // The permissions the board loaded with the role, inside its reading pass (M11 A2).
            'permissions' => ($this->reading !== null && $role->relationLoaded('permissions') ? $role->permissions : $role->permissions()->get())
                ->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * The request's grid, checked and put in canonical form.
     *
     * @param  Collection<string, Module>  $modules  keyed by module key
     * @param  array<mixed>  $grid
     * @return array<string, array{level: AccessLevel, verbs: list<string>|null, can_approve: bool, acts: list<string>|null}>
     */
    private function normalise(Console $console, Collection $modules, array $grid): array
    {
        $out = [];

        foreach ($grid as $key => $cell) {
            $key = (string) $key;
            $module = $modules->get($key);

            if ($module === null) {
                throw new DomainException($this->unknownModuleSentence($console, $key));
            }

            if (! is_array($cell)) {
                throw new DomainException(sprintf('The %s cell was not a cell. Choose a level for it.', $module->label));
            }

            $level = AccessLevel::tryFrom((string) ($cell['level'] ?? ''));

            if ($level === null) {
                throw new DomainException(sprintf(
                    '"%s" is not a level. A %s cell is none, view, entry, full or custom.',
                    (string) ($cell['level'] ?? ''),
                    $module->label,
                ));
            }

            $approve = (bool) ($cell['can_approve'] ?? false);
            $verbs = null;

            if ($level === AccessLevel::Custom) {
                $picked = [];

                foreach ((array) ($cell['verbs'] ?? []) as $value) {
                    $verb = PermissionVerb::tryFrom((string) $value);

                    if ($verb === null) {
                        throw new DomainException(sprintf(
                            '"%s" is not something a role can be allowed to do. The actions are view, create, update, '.
                            'delete, approve, export and configure.',
                            (string) $value,
                        ));
                    }

                    // The Approver tag, however it arrives: moved to its own
                    // axis and checked exactly as the tag is.
                    if ($verb === PermissionVerb::Approve) {
                        $approve = true;

                        continue;
                    }

                    $picked[] = $verb;
                }

                $picked = array_values(array_filter(
                    PermissionVerb::cases(),
                    static fn (PermissionVerb $verb): bool => in_array($verb, $picked, true),
                ));

                $preset = AccessLevel::presetFor($picked);

                if ($preset !== null) {
                    $level = $preset;
                } else {
                    $verbs = array_map(static fn (PermissionVerb $verb): string => $verb->value, $picked);
                }
            }

            [$level, $verbs, $approve, $acts] = $this->normaliseActs($console, $module, $level, $verbs, $approve, $cell['acts'] ?? []);

            $grantsView = $level === AccessLevel::Custom
                ? in_array(PermissionVerb::View->value, (array) $verbs, true)
                : $level !== AccessLevel::None;

            if ($approve && ! $grantsView) {
                throw new DomainException(sprintf(
                    'Give %s at least view before tagging it Approver. Nobody can approve what they cannot open.',
                    $module->label,
                ));
            }

            /*
             * AN ACT NEEDS VIEW, HOWEVER IT IS GRANTED. Asked of every act the
             * cell will compile to — the ones it lists on their own and the ones
             * under a verb it grants — and after `normaliseActs()`, because that
             * folds every act of a verb, posted one by one, into the verb: asked
             * of the list alone, all three Platform settings configure acts
             * posted on a cell with no view were stored as Custom (configure) and
             * compiled, while one of them alone was refused.
             */
            $granted = $this->actsGranted($console, $module, $level, $verbs, $approve, $acts);

            if ($granted !== [] && ! $grantsView) {
                $known = NamedActs::of($console, $module->key);

                throw new DomainException(sprintf(
                    'Give %s at least view before giving it %s. Nobody can do what they cannot open.',
                    $module->label,
                    implode(', ', array_map(static fn (string $act): string => $known[$act]['label'], $granted)),
                ));
            }

            $out[$key] = ['level' => $level, 'verbs' => $verbs, 'can_approve' => $approve, 'acts' => $acts];
        }

        return $out;
    }

    /**
     * A cell's named acts, checked and put in canonical form (M11 B3).
     *
     * An act that is not this module's is refused in words. An act the cell's
     * level or Approver tag already grants is dropped, because it is implied. And
     * every act of one verb, listed one by one, becomes that verb — the Approver
     * tag, or the verb on the cell's list — so the same grant is never stored two
     * ways, and a screen reading the tag reads the whole of it.
     *
     * @param  list<string>|null  $verbs
     * @return array{0: AccessLevel, 1: list<string>|null, 2: bool, 3: list<string>|null}
     */
    private function normaliseActs(Console $console, Module $module, AccessLevel $level, ?array $verbs, bool $approve, mixed $posted): array
    {
        $known = NamedActs::of($console, $module->key);
        $wanted = [];

        foreach ((array) $posted as $value) {
            $act = (string) $value;

            if (! isset($known[$act])) {
                throw new DomainException($known === []
                    ? sprintf('"%s" is not something a role can be given on %s, which has no named acts: what a role may do there is its level and the Approver tag.', $act, $module->label)
                    : sprintf(
                        '"%s" is not something a role can be given on %s. Its named acts are: %s.',
                        $act,
                        $module->label,
                        implode(', ', array_column($known, 'label')),
                    ));
            }

            $wanted[$act] = true;
        }

        foreach ($known as $act => $definition) {
            $implied = $definition['verb'] === PermissionVerb::Approve->value
                ? $approve
                : in_array($definition['verb'], self::verbValues($level, $verbs), true);

            if ($implied) {
                unset($wanted[$act]);
            }
        }

        foreach (array_unique(array_column($known, 'verb')) as $parent) {
            $under = NamedActs::under($console, $module->key, $parent);

            if (array_diff($under, array_keys($wanted)) !== []) {
                continue;
            }

            foreach ($under as $act) {
                unset($wanted[$act]);
            }

            if ($parent === PermissionVerb::Approve->value) {
                $approve = true;

                continue;
            }

            $list = [...self::verbValues($level, $verbs), $parent];
            $ordered = array_values(array_filter(
                PermissionVerb::cases(),
                static fn (PermissionVerb $verb): bool => in_array($verb->value, $list, true),
            ));

            $preset = AccessLevel::presetFor($ordered);

            if ($preset !== null) {
                $level = $preset;
                $verbs = null;
            } else {
                $level = AccessLevel::Custom;
                $verbs = array_map(static fn (PermissionVerb $verb): string => $verb->value, $ordered);
            }
        }

        $acts = array_keys($wanted);

        return [$level, $verbs, $approve, $acts === [] ? null : $this->orderedActs($module, $acts)];
    }

    /**
     * Every named act a cell in this canonical form would grant, in registry
     * order: those under a verb it grants, the Approver tag's among them, and
     * those it lists one by one. What `RoleModuleAccess::grantedActs()` answers
     * for a stored cell, asked of one not yet written.
     *
     * @param  list<string>|null  $verbs
     * @param  list<string>|null  $acts
     * @return list<string>
     */
    private function actsGranted(Console $console, Module $module, AccessLevel $level, ?array $verbs, bool $approve, ?array $acts): array
    {
        $granted = [];

        foreach (NamedActs::of($console, $module->key) as $key => $act) {
            $viaVerb = $act['verb'] === PermissionVerb::Approve->value
                ? $approve
                : in_array($act['verb'], self::verbValues($level, $verbs), true);

            if ($viaVerb || in_array($key, $acts ?? [], true)) {
                $granted[] = $key;
            }
        }

        return $granted;
    }

    /**
     * The verbs a level, or a custom list, grants — as strings.
     *
     * @param  list<string>|null  $verbs
     * @return list<string>
     */
    private static function verbValues(AccessLevel $level, ?array $verbs): array
    {
        return $level === AccessLevel::Custom
            ? array_map('strval', $verbs ?? [])
            : array_map(static fn (PermissionVerb $verb): string => $verb->value, $level->verbs());
    }

    /**
     * Acts in registry order, the one order they are stored and compared in.
     *
     * @param  list<string>  $acts
     * @return list<string>
     */
    private function orderedActs(Module $module, array $acts): array
    {
        return array_values(array_filter(
            array_keys(NamedActs::of($module->console, $module->key)),
            static fn (string $act): bool => in_array($act, $acts, true),
        ));
    }

    /**
     * Does this actor hold this verb on this module — every act under it, for a
     * verb split into named acts (M11 B3)? Asked through `holds()`, so once per name
     * inside a reading pass and afresh everywhere else (M11 A2).
     */
    private function holdsVerb(User $actor, Module $module, PermissionVerb $verb): bool
    {
        $split = NamedActs::under($module->console, $module->key, $verb);

        if ($split === []) {
            return $this->holds($actor, $module->permissionPrefix().'.'.$verb->value);
        }

        foreach ($split as $act) {
            if (! $this->holds($actor, $module->permissionPrefix().'.'.$act)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A cell that would be written, unsaved, for asking what it grants.
     *
     * @param  array{level: AccessLevel, verbs: list<string>|null, can_approve: bool, acts?: list<string>|null}  $cell
     */
    private function cellFor(Module $module, array $cell, AccessScope $scope): RoleModuleAccess
    {
        $access = new RoleModuleAccess([
            'module_id' => $module->id,
            'level' => $cell['level'],
            'verbs' => $cell['verbs'],
            'acts' => $cell['acts'] ?? null,
            'can_approve' => $cell['can_approve'],
            'scope' => $cell['level'] === AccessLevel::None ? AccessScope::All : $scope,
        ]);

        $access->setRelation('module', $module);

        return $access;
    }

    /**
     * @param  array<int|string, RoleModuleAccess>  $cells
     * @return list<string>
     */
    private function namesOf(array $cells): array
    {
        $names = [];

        foreach ($cells as $cell) {
            foreach ($cell->permissionNames() as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Guard rail 3, both halves: what the change adds and what it removes must
     * each be the actor's own to give.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  Collection<string, Module>  $modules
     */
    private function assertHoldsTheDifference(User $actor, array $before, array $after, Collection $modules): void
    {
        foreach (array_diff($after, $before) as $name) {
            if (! $actor->checkPermissionTo($name)) {
                throw new DomainException(sprintf(
                    'You do not hold %s yourself, so you cannot give it to a role.',
                    $this->permissionLabelFor($name, $modules),
                ));
            }
        }

        foreach (array_diff($before, $after) as $name) {
            if (! $actor->checkPermissionTo($name)) {
                throw new DomainException(sprintf(
                    'You do not hold %s yourself, so you cannot take it away from a role either. That is for '.
                    'somebody whose own role holds it.',
                    $this->permissionLabelFor($name, $modules),
                ));
            }
        }
    }

    /**
     * Guard rail 4's second half: Ruling 1, asked of the role and its lineage.
     * An Approver tag or a named act is a grant too, even on a cell at no access —
     * which `normalise()` already refuses, and this refuses again.
     *
     * @param  array<string, array{level: AccessLevel, verbs: list<string>|null, can_approve: bool, acts: list<string>|null}>  $cells
     * @param  Collection<string, Module>|null  $modules  the role's console's modules keyed by key, already read — or null to look each one up
     */
    private function assertRulingOne(Role $role, array $cells, ?Collection $modules = null): void
    {
        foreach ($cells as $key => $cell) {
            if ($cell['level'] === AccessLevel::None && ! $cell['can_approve'] && ($cell['acts'] ?? null) === null) {
                continue;
            }

            // (console, key) is unique, so the module read with the console's others is this one (M11 A2).
            $module = $modules !== null
                ? $modules->get($key)
                : Module::query()->where('console', $role->console->value)->where('key', $key)->first();

            if ($module !== null && $module->isLockedFor($role)) {
                throw new DomainException(sprintf(
                    '%s is closed to the Property Manager, and to any role copied from it, by platform invariant: '.
                    'whoever commissions work must never be able to pay for it, nor see a resident\'s financial position.',
                    $module->label,
                ));
            }
        }
    }

    /**
     * Guard rail 5's second half: the cell a structural role may never lose.
     *
     * @param  list<string>  $after
     */
    private function assertStructuralMinimum(Role $role, array $after): void
    {
        // A community's version of the Community Super Admin keeps the same cell (M11 B4).
        $moduleKey = self::STRUCTURAL[$role->standsFor()] ?? null;

        if ($moduleKey === null || ! ($role->is_system || $role->isEstateVersion())) {
            return;
        }

        /*
         * Whatever a Full · Approver cell on that module compiles to, asked of the
         * one place that compiles cells — so a verb there split into named acts
         * (Platform settings' configure is, M11 B3) is required act by act, and
         * the minimum can never name a permission that no longer exists.
         */
        $module = Module::query()->where('console', $role->console->value)->where('key', $moduleKey)->first();

        if ($module === null) {
            return;
        }

        $required = $this->cellFor(
            $module,
            ['level' => AccessLevel::Full, 'verbs' => null, 'can_approve' => true, 'acts' => null],
            AccessScope::All,
        )->permissionNames();

        if (array_diff($required, $after) !== []) {
            throw new DomainException($role->name === Role::DIRECTOR
                ? 'Director is the role that can always change permissions on the Gemini Console. Taking Full · '.
                  'Approver on Platform settings away from it would leave nobody certain to be able to give it back.'
                : 'Community Super Admin is the role on this estate that can change permissions. Taking Settings '.
                  'away from it would leave nobody who could give it back.');
        }
    }

    /** Why a role cannot be deleted right now, or null. */
    private function deleteRefusal(Role $role): ?string
    {
        $label = $this->labelOf($role);
        $facts = $this->deleteFacts($role);

        $holders = $facts['holders'] ?? $role->users()->count();

        if ($holders > 0) {
            return sprintf(
                '%d %s %s. Move %s to another role first: deleting it would leave them with no role and no access.',
                $holders,
                $holders === 1 ? 'person holds' : 'people hold',
                $label,
                $holders === 1 ? 'them' : 'them all',
            );
        }

        if ($facts !== null ? $facts['pending'] : Invitation::query()->where('role_id', $role->id)->whereNull('accepted_at')->exists()) {
            return sprintf(
                'An invitation still names %s. Withdraw it first: deleting the role would silently cancel the '.
                'invitation, and the person asked would find a link that opens nothing.',
                $label,
            );
        }

        /*
         * HISTORY THE CASCADE WOULD TAKE WITH IT. `estate_assignments.role_id`
         * and `invitations.role_id` both cascade on delete, so deleting a role
         * somebody once held — or was once invited into and accepted — would
         * erase the record that they did. Who held what, when, is the history an
         * audit of a decision reads.
         */
        if ($facts !== null
            ? $facts['history']
            : (EstateAssignment::query()->where('role_id', $role->id)->exists() || Invitation::query()->where('role_id', $role->id)->exists())) {
            return sprintf(
                '%s is named in the record of somebody who has since left it. It stays so that record still reads '.
                'true; rename it or take its permissions away instead.',
                $label,
            );
        }

        return null;
    }

    /**
     * What `deleteRefusal()` asks of a role — holders, an open invitation, any history —
     * read for every role on the board at once, the first time a board asks (M11 A2).
     *
     * Null outside a reading pass, or for a role the pass did not load, and then
     * `deleteRefusal()` asks the database directly, as it always has. The holder count is
     * the same `users` relation counted, through `loadCount`, for all of them together.
     *
     * @return array{holders: int, pending: bool, history: bool}|null
     */
    private function deleteFacts(Role $role): ?array
    {
        if ($this->reading === null || $this->reading['roles'] === null || ! $this->reading['roles']->contains($role)) {
            return null;
        }

        if ($this->reading['delete'] === null) {
            /** @var Collection<int, Role> $roles */
            $roles = $this->reading['roles'];
            $ids = $roles->modelKeys();

            // `get()` before `pluck()`: a pluck replaces the select, and would drop the count.
            $holders = $roles->toQuery()->withCount('users')->get()->pluck('users_count', 'id');
            $pending = Invitation::query()->whereIn('role_id', $ids)->whereNull('accepted_at')->distinct()->pluck('role_id')->map(static fn ($id): int => (int) $id)->all();
            $assigned = EstateAssignment::query()->whereIn('role_id', $ids)->distinct()->pluck('role_id')->map(static fn ($id): int => (int) $id)->all();
            $invited = Invitation::query()->whereIn('role_id', $ids)->distinct()->pluck('role_id')->map(static fn ($id): int => (int) $id)->all();

            $facts = [];

            foreach ($ids as $id) {
                $facts[(int) $id] = [
                    'holders' => (int) ($holders[$id] ?? 0),
                    'pending' => in_array((int) $id, $pending, true),
                    'history' => in_array((int) $id, $assigned, true) || in_array((int) $id, $invited, true),
                ];
            }

            $this->reading['delete'] = $facts;
        }

        return $this->reading['delete'][(int) $role->id] ?? null;
    }

    /** Why this role cannot be reset right now, or null. */
    private function resetRefusal(Role $role): ?string
    {
        if (! $role->is_system || $this->defaults()->defaultsFor($role->name) === null) {
            return sprintf('%s was made here, so it has no GeminiSecure default to go back to.', $this->labelOf($role));
        }

        if ($role->permissions_edited_at === null && $this->matchesDefault($role)) {
            return sprintf('%s already has GeminiSecure\'s default permissions.', $this->labelOf($role));
        }

        return null;
    }

    /**
     * The seeded defaults. Resolved through the container so a test can put a
     * seeder with a changed grid in its place.
     */
    private function defaults(): RbacMatrixSeeder
    {
        return app(RbacMatrixSeeder::class);
    }

    /**
     * Are this seeded role's cells exactly the ones the seeder would write?
     *
     * Compared cell by cell (level, verb list, Approver tag, named acts and
     * scope), the way `seedGrid()` writes them, so "at its default" means the
     * same thing here as it does to a re-seed.
     */
    private function matchesDefault(Role $role): bool
    {
        // A community's version is at its default when it holds what the shared role it stands for ships with (M11 B4).
        $defaults = $this->defaults()->defaultsFor($role->standsFor());

        if ($defaults === null || ! ($role->is_system || $role->isEstateVersion())) {
            return false;
        }

        /*
         * The cells the board loaded with the role, inside its reading pass (M11 A2) —
         * and read afresh everywhere else, because `recordEdit()` asks this straight after
         * a write, where only the stored cells are the truth.
         */
        $cells = $this->reading !== null && $role->relationLoaded('moduleAccess')
            ? $role->moduleAccess->keyBy('module_id')
            : RoleModuleAccess::query()->where('role_id', $role->id)->get()->keyBy('module_id');

        foreach ($this->modules($role->console) as $module) {
            $default = $defaults[$module->key] ?? self::NO_DEFAULT;

            $expected = [
                'level' => $default['level'],
                'verbs' => null,
                'can_approve' => $default['can_approve'],
                'acts' => $default['acts'] === [] ? null : $default['acts'],
            ];

            if (! $this->sameCell($cells->get($module->id), $expected, $default['scope'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Would writing this cell leave the stored one exactly as it is?
     *
     * @param  array{level: AccessLevel, verbs: list<string>|null, can_approve: bool, acts?: list<string>|null}  $cell
     */
    private function sameCell(?RoleModuleAccess $existing, array $cell, AccessScope $scope): bool
    {
        $acts = $cell['acts'] ?? null;

        if ($existing === null) {
            return $cell['level'] === AccessLevel::None && ! $cell['can_approve'] && $acts === null;
        }

        $stored = ($existing->acts ?? []) === [] ? null : $existing->acts;

        return $existing->level === $cell['level']
            && $existing->verbs === $cell['verbs']
            && $existing->can_approve === $cell['can_approve']
            && $stored === $acts
            // A no-access cell reaches nothing, so where it would reach is moot.
            && ($cell['level'] === AccessLevel::None || $existing->scope === $scope);
    }

    /**
     * Mark the role as changed at runtime, which is what a re-seed reads, or
     * clear the mark when the change left a seeded role exactly at its default.
     * A role put back to its default by hand follows the grid again, as if it
     * had never been changed.
     */
    private function recordEdit(Role $role, User $actor): void
    {
        // A community's version stays marked while it stands, even back at the default: it is still this community's (M11 B4).
        $role->forceFill($role->is_system && $this->matchesDefault($role)
            ? ['permissions_edited_at' => null, 'permissions_edited_by_id' => null]
            : ['permissions_edited_at' => now(), 'permissions_edited_by_id' => $actor->id]
        )->save();
    }

    /**
     * The cells a role would hold at its default, unsaved, for asking what they
     * grant.
     *
     * @param  Collection<string, Module>  $modules  keyed by module key
     * @param  array<string, array{level: AccessLevel, can_approve: bool, scope: AccessScope, acts?: list<string>}>  $defaults
     * @return list<RoleModuleAccess>
     */
    private function defaultCells(Collection $modules, array $defaults): array
    {
        $cells = [];

        foreach ($modules as $key => $module) {
            $default = $defaults[$key] ?? self::NO_DEFAULT;

            $cell = new RoleModuleAccess([
                'module_id' => $module->id,
                'level' => $default['level'],
                'verbs' => null,
                'acts' => ($default['acts'] ?? []) === [] ? null : $default['acts'],
                'can_approve' => $default['can_approve'],
                'scope' => $default['scope'],
            ]);

            $cell->setRelation('module', $module);
            $cells[] = $cell;
        }

        return $cells;
    }

    /**
     * Write a seeded role's default cells, recompile it, clear its edit mark and
     * audit the reset. Inside `commit()`, so the flush follows the commit.
     *
     * @param  Collection<string, Module>  $modules  keyed by module key
     * @param  array<string, array{level: AccessLevel, can_approve: bool, scope: AccessScope, acts?: list<string>}>  $defaults
     */
    private function restore(Role $role, Collection $modules, array $defaults, ?User $actor, ?string $tenantId, string $via): Role
    {
        $before = [
            ...$this->snapshot($role),
            'edited_at' => $role->permissions_edited_at?->toIso8601String(),
            'edited_by_id' => $role->permissions_edited_by_id,
        ];

        foreach ($modules as $key => $module) {
            $default = $defaults[$key] ?? self::NO_DEFAULT;

            RoleModuleAccess::query()->updateOrCreate(
                ['role_id' => $role->id, 'module_id' => $module->id],
                [
                    'level' => $default['level']->value,
                    'verbs' => null,
                    'acts' => ($default['acts'] ?? []) === [] ? null : $default['acts'],
                    'can_approve' => $default['can_approve'],
                    'scope' => $default['scope']->value,
                ],
            );
        }

        $this->compile($role);

        $role->forceFill(['permissions_edited_at' => null, 'permissions_edited_by_id' => null])->save();

        $this->audit->record(
            action: 'role.reset_to_default',
            entityType: 'Role',
            entityId: $role->name,
            before: $before,
            after: [...$this->snapshot($role), 'via' => $via],
            tenantId: $tenantId,
            actor: $actor,
        );

        return $role->fresh(['moduleAccess.module']);
    }

    /**
     * The role as the audit log records it: its label, its whole grid and the
     * permission names that grid compiles to.
     *
     * @return array{label: string, grid: array<string, array<string, mixed>>, permissions: list<string>}
     */
    private function snapshot(Role $role): array
    {
        $grid = [];

        $cells = RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()
            ->sortBy(static fn (RoleModuleAccess $cell): int => $cell->module->sort);

        foreach ($cells as $cell) {
            $grid[$cell->module->key] = [
                'level' => $cell->level->value,
                'verbs' => array_map(static fn (PermissionVerb $v): string => $v->value, $cell->grantedVerbs()),
                'can_approve' => $cell->can_approve,
                'acts' => $cell->explicitActs(),
                'scope' => $cell->scope->value,
            ];
        }

        return [
            'label' => $this->labelOf($role),
            'grid' => $grid,
            'permissions' => $this->namesOf($cells->all()),
        ];
    }

    /**
     * Project the role's cells onto spatie's compiled table, exactly as the
     * matrix seeder does — the step that makes `can:` see the change.
     */
    private function compile(Role $role): void
    {
        $role->syncPermissions($this->namesOf(
            RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->all()
        ));
    }

    /** A role's cells follow its scope: narrowed for a site-scoped role, all otherwise. */
    private function scopeFor(AccessScope $roleScope): AccessScope
    {
        return $roleScope === AccessScope::AssignedSites ? AccessScope::AssignedSites : AccessScope::All;
    }

    private function scopeDefaultFor(User $actor, Console $console, ?string $requested): AccessScope
    {
        if ($console === Console::Estate) {
            if ($requested !== null && $requested !== AccessScope::All->value) {
                throw new DomainException(
                    'An estate role reaches the whole of this estate and nothing beyond it. That is not a setting, '.
                    'so it has no scope to choose.'
                );
            }

            return AccessScope::All;
        }

        $scope = AccessScope::tryFrom($requested ?? AccessScope::All->value);

        if ($scope === null || $scope === AccessScope::OwnRecords) {
            throw new DomainException('A Gemini Console role reaches every client or only its assigned sites.');
        }

        if ($scope === AccessScope::All && $actor->widestScope() !== AccessScope::All) {
            throw new DomainException(
                'Your own access is limited to your assigned sites, so you cannot make a role that reaches every client.'
            );
        }

        return $scope;
    }

    private function cleanLabel(string $label, Console $console, ?string $tenantId, ?Role $except): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));

        if ($label === '') {
            throw new DomainException(
                'A role needs a name people will recognise. It is what the sidebar and every list of users print.'
            );
        }

        if (mb_strlen($label) > self::LABEL_MAX) {
            throw new DomainException('Keep a role\'s name to '.self::LABEL_MAX.' characters. It is printed in narrow places.');
        }

        $clash = $this->visibleRoles($console, $tenantId)->first(
            fn (Role $role): bool => mb_strtolower($this->labelOf($role)) === mb_strtolower($label)
                && ($except === null || ! $role->is($except)),
        );

        if ($clash !== null) {
            throw new DomainException(sprintf(
                'There is already a role called %s here. Two roles with one name would make every list of users ambiguous.',
                $this->labelOf($clash),
            ));
        }

        return $label;
    }

    /**
     * `estate.custom.<estate>.<slug>` or `gemini.custom.<slug>`, made once.
     *
     * Tenant-qualified, so two estates each making "Gardens Officer" never meet
     * spatie's unique (name, guard) index; suffixed on a clash within one
     * estate, which a rename followed by a new role of the old name produces.
     */
    private function machineName(Console $console, ?string $tenantId, string $label): string
    {
        $slug = Str::limit(Str::slug($label, '_'), 40, '') ?: 'role';

        $base = $console === Console::Estate
            ? "estate.custom.{$tenantId}.{$slug}"
            : "gemini.custom.{$slug}";

        $name = $base;

        for ($n = 2; Role::query()->where('name', $name)->where('guard_name', 'web')->exists(); $n++) {
            $name = "{$base}_{$n}";
        }

        return $name;
    }

    /** After every role this console and estate already has. */
    private function nextSort(Console $console, ?string $tenantId): int
    {
        return (int) Role::query()->where('console', $console->value)->ownedBy($tenantId)->max('sort') + 10;
    }

    private function carries(Role $role, string $permission): bool
    {
        /*
         * The role's permissions as a board loaded them, inside a reading pass (M11 A2):
         * the users board asks this of every row. Anywhere else — every write path —
         * the database is asked, as it always was, so a guard is never decided on a
         * permission list loaded before a change.
         */
        if ($this->reading !== null && $role->relationLoaded('permissions')) {
            return $role->permissions->contains('name', $permission);
        }

        return $role->permissions()->where('name', $permission)->exists();
    }

    private function refuseIf(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }
    }

    private function labelOf(Role $role): string
    {
        return (string) ($role->label ?? $role->name);
    }

    /**
     * "Security · approve" — or, for a named act, "Dues & ledger · Set dues
     * rates", its own label, because "set_rates" is a key and not a sentence.
     */
    private function permissionLabel(Module $module, string $verb): string
    {
        $act = NamedActs::find($module->console, $module->key, $verb);

        return $module->label.' · '.($act['label'] ?? $verb);
    }

    /** @param  Collection<string, Module>  $modules */
    private function permissionLabelFor(string $name, Collection $modules): string
    {
        $parts = explode('.', $name);
        $verb = (string) array_pop($parts);
        $key = (string) ($parts[1] ?? '');

        $module = $modules->get($key);

        return $module === null ? $name : $this->permissionLabel($module, $verb);
    }

    private function mayNotEditSentence(Console $console): string
    {
        return $console === Console::Estate
            ? 'Changing what a role may do is an approve-level act on Settings. On this estate the Community Super '.
              'Admin holds it, and your role does not.'
            : 'Changing what a role may do is an approve-level act on Platform settings. On the Gemini Console the '.
              'Director holds it, and your role does not.';
    }

    private function notHereSentence(Console $console): string
    {
        return $console === Console::Estate ? self::NOT_HERE_ESTATE : self::NOT_HERE_GEMINI;
    }

    private function unknownModuleSentence(Console $console, string $key): string
    {
        $bare = str_contains($key, '.') ? substr($key, (int) strrpos($key, '.') + 1) : $key;
        $other = $console === Console::Estate ? Console::Gemini : Console::Estate;

        $foreign = Module::query()->where('console', $other->value)->where('key', $bare)->first();

        if ($foreign !== null && ! Module::query()->where('console', $console->value)->where('key', $bare)->exists()) {
            return $console === Console::Estate
                ? sprintf(
                    '%s belongs to the Gemini Console. A role on this estate can only be given this estate\'s own '.
                    'modules, and can never reach the security company\'s records or any other community.',
                    $foreign->label,
                )
                : sprintf(
                    '%s belongs to the Estate Console. A Gemini Console role is given the company console\'s own '.
                    'modules only.',
                    $foreign->label,
                );
        }

        return sprintf(
            'There is no "%s" module on the %s. A role here can only be given this console\'s own modules.',
            $key,
            $console->label(),
        );
    }
}
