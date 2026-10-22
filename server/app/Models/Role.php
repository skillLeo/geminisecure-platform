<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Console;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Roles live in gs_platform, never per estate.
 *
 * spatie/laravel-permission has no configuration key for the connection, so
 * its stock models resolve against Laravel's default connection. Under
 * DatabaseTenancyBootstrapper that default is swapped to the tenant database
 * for the duration of a tenant request, which would silently give every estate
 * its own private copy of the role table.
 *
 * The consequences are not cosmetic: the role-permission matrix drives runtime
 * navigation generation, so per-estate role tables would let two estates drift
 * into different definitions of what a "Treasurer" may see. Pinning to the
 * central connection keeps one authoritative matrix.
 *
 * ROLES ARE MADE AT RUNTIME NOW, AND `name` STILL NEVER MOVES (M10 §4). A
 * Director and a Community Super Admin can create, rename, re-grant and delete
 * roles through `App\Services\Rbac\RoleEditor`, and every one of those acts
 * leaves `name` exactly as it was written. It is the machine key: the last-
 * Director guard, Ruling 1's Property Manager lock, the panic escalation to a
 * Head of Security and the first-Director bootstrap all find a role by it, and a
 * rename that moved it would turn each of them into a silent failure. A rename
 * edits `label`, which is all a screen ever prints.
 *
 * THREE KINDS OF ROLE, told apart by two columns:
 *
 *   is_system, tenant_id NULL    one of the fourteen seeded roles. Never renamed,
 *                                never deleted. A Gemini one may have its
 *                                permissions changed by the Director; an estate
 *                                one is SHARED BY EVERY ESTATE, so no estate may
 *                                change it — that would change every community.
 *   custom, tenant_id NULL       a Gemini role the Director made. Platform-wide,
 *                                like the Gemini console itself.
 *   custom, tenant_id = estate   one estate's own role, invisible to and
 *                                unassignable from every other estate.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property Console $console
 * @property string|null $tenant_id
 * @property bool $is_system
 * @property int|null $copied_from_id
 * @property int|null $created_by_id
 * @property int|null $version_of_id  the shared seeded estate role this row is ONE ESTATE'S VERSION of (M11 B4), or null
 * @property Carbon|null $permissions_edited_at  set when somebody changes the role's permissions at runtime; a seeded
 *                                              Gemini role carrying it keeps its cells through a re-seed
 * @property int|null $permissions_edited_by_id
 * @property string|null $label
 * @property int $sort
 * @property AccessScope $scope_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RoleModuleAccess> $moduleAccess
 * @property-read int|null $module_access_count
 * @property-read Collection<int, Module> $modules
 * @property-read int|null $modules_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role ownedBy(?string $tenantId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role visibleTo(?string $tenantId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role system()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereScopeDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 *
 * @mixin \Eloquent
 */
class Role extends SpatieRole
{
    use CentralConnection;

    // --- Gemini Console (6) ------------------------------------------------
    public const DIRECTOR = 'gemini.director';

    public const OPERATIONS_MANAGER = 'gemini.operations_manager';

    public const HEAD_OF_SECURITY = 'gemini.head_of_security';

    public const DISPATCHER = 'gemini.dispatcher';

    public const ADMIN_ASSISTANT = 'gemini.admin_assistant';

    public const ACCOUNTANT = 'gemini.accountant';

    // --- Estate Console (7) ------------------------------------------------
    /**
     * Not shown in the matrix screen. From the audit note in wireframe 06:
     * full access within its OWN estate only, including user and role
     * management. Explicitly not the platform Director, and with no visibility
     * into any other estate or into Gemini's own payroll and HR (D-009).
     */
    public const COMMUNITY_SUPER_ADMIN = 'estate.community_super_admin';

    public const PRESIDENT = 'estate.president';

    public const VICE_PRESIDENT = 'estate.vice_president';

    public const SECRETARY = 'estate.secretary';

    public const PROPERTY_MANAGER = 'estate.property_manager';

    public const TREASURER = 'estate.treasurer';

    public const ESTATE_ADMIN_ASSISTANT = 'estate.admin_assistant';

    /**
     * THE ESTATE'S OWN HEAD OF SECURITY — M10 §2, and NOT Gemini's.
     *
     * There are two of these and they work for different organisations. Gemini's
     * `HEAD_OF_SECURITY` is a security-company role: it manages guards, standing
     * orders and shift oversight across the sites it is assigned, and it is a
     * `gemini.*` role on the company console.
     *
     * This one is the COMMUNITY's. It is the committee member responsible for
     * security at their own estate, and it sees the estate's security picture:
     * live panic alerts, incidents here, the visitor log, gate activity, and the
     * guard presence the estate is entitled to see — "guards on site: N" and
     * alert states, never guard payroll, never guard HR, never another estate.
     *
     * It holds NO money at all: no dues, no ledger, no payroll, no resident
     * financial position. A person responsible for the gate has no business in
     * what a household owes, and the data-ownership matrix is explicit that
     * guard employment records belong to Gemini rather than to the community
     * they stand at.
     */
    public const ESTATE_HEAD_OF_SECURITY = 'estate.head_of_security';

    /**
     * THE GEMINI ROLES A PANIC IS PUT IN FRONT OF — and therefore the Gemini roles
     * that must prove an alert address before the console opens (M10 §1.4).
     *
     * ONE LIST, read from the code that delivers an alert rather than from a
     * reading of the work order:
     *
     *   Director           §1.2 item 3, "dashboard alert". Holds Dispatch at Full,
     *                      so the live queue and the bell (`PlatformNotifications`)
     *                      put every open panic in front of them.
     *   Head of Security   §1.2 item 4, "dashboard alert plus email". The one
     *                      Gemini role `SiteEscalation::headsOfSecurity()` mails,
     *                      and the reason this gate exists at all.
     *   Dispatcher         §1.2 item 2, "the live alert queue". Subscribed to
     *                      `estate.{id}.alerts` by `gemini.dispatch.view`.
     *   Operations Manager Not named in §1.2, and included on the code's reading:
     *                      it holds Dispatch at Full, so it works the same live
     *                      queue a Dispatcher does and may acknowledge, resolve and
     *                      send an officer (`gemini.dispatch.update`).
     *
     * THE LINE IS `gemini.dispatch.update` — the roles that may ACT on a panic. The
     * Admin Assistant holds Dispatch at View: they may watch the queue and may not
     * acknowledge anything, so an alert that reached them has reached nobody who
     * can answer it. The Accountant holds no Dispatch at all. Neither is gated.
     *
     * A ROLE NAME LIST ON PURPOSE, unlike the estate side, which gates everyone who
     * signs in to an estate console whatever their role. A custom Gemini role added
     * under §4 is gated when somebody adds it here, deliberately rather than by
     * accident of which cells it was given.
     *
     * @var list<string>
     */
    public const GEMINI_ALERT_RECIPIENTS = [
        self::DIRECTOR,
        self::OPERATIONS_MANAGER,
        self::HEAD_OF_SECURITY,
        self::DISPATCHER,
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'console' => Console::class,
            'scope_default' => AccessScope::class,
            'sort' => 'integer',
            'is_system' => 'boolean',
            'copied_from_id' => 'integer',
            'version_of_id' => 'integer',
            'created_by_id' => 'integer',
            'permissions_edited_at' => 'datetime',
            'permissions_edited_by_id' => 'integer',
        ]);
    }

    /**
     * The fourteen seeded roles.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * THE TENANT FENCE, written once: the platform's own roles plus this estate's,
     * and nobody else's.
     *
     * Every query that lists or resolves a role an estate may see or hand out goes
     * through this — `Settings::invitableRoles()`, `matrixBoard()`, `manageUser()`,
     * `RoleEditor` — because the day an estate can make a role, a query that
     * filtered on console alone would offer estate A's roles to estate B.
     *
     * Null asks for the platform's own roles only, which is what the Gemini
     * console sees: a tenant-owned row must never appear on, or be assigned from,
     * the platform console.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeOwnedBy(Builder $query, ?string $tenantId): Builder
    {
        return $query->where(function (Builder $roles) use ($tenantId): void {
            $roles->whereNull('tenant_id');

            if ($tenantId !== null && $tenantId !== '') {
                $roles->orWhere('tenant_id', $tenantId);
            }
        });
    }

    /**
     * May this actor change what this role may do?
     *
     * NOT WHETHER THEY HOLD THE PERMISSION TO EDIT ROLES AT ALL — that is
     * `<console>.<settings>.approve`, checked by `RoleEditor`. This is the
     * ownership half: which roles are theirs to change.
     *
     *   a role of the other console      never
     *   an estate role, from an estate   only one carrying THIS estate's tenant
     *                                    id. A seeded estate role is shared by
     *                                    every community, so changing it from
     *                                    one estate would change all of them.
     *   a Gemini role                    any platform-wide one, seeded included:
     *                                    the Director sets the company console's
     *                                    roles (the lead's ruling on M10 §4), and
     *                                    the structural minimum RoleEditor keeps
     *                                    is what stops that locking them out.
     */
    public function isEditableBy(User $actor, ?string $tenantId = null): bool
    {
        if ($this->console !== $actor->console) {
            return false;
        }

        if ($this->console === Console::Estate) {
            $tenantId ??= tenant()?->getTenantKey();

            return $tenantId !== null && $this->tenant_id !== null && $this->tenant_id === (string) $tenantId;
        }

        return $this->tenant_id === null;
    }

    /** One of the eight seeded estate roles, which every estate holds in common. */
    public function isSharedAcrossEstates(): bool
    {
        return $this->console === Console::Estate && $this->tenant_id === null;
    }

    /**
     * ONE ESTATE'S OWN VERSION OF A SHARED ROLE (M11 B4).
     *
     * The first change a community makes to one of the eight shared roles makes this, behind
     * the scenes: an exact copy with the shared role's label and place, holding this estate's
     * people. From then on the estate sees it in the shared role's place.
     * "Reset to GeminiSecure default" moves everybody back and removes it.
     *
     * A version is NOT a copy made with "Copy…". A copy is a new role of the estate's own,
     * with a name and a label of its own, and stands for nothing. Both remember the seeded
     * role at their root (`copied_from_id`), which is what keeps Ruling 1 and the Head of
     * Security's panic text true for either.
     */
    public function isEstateVersion(): bool
    {
        return $this->version_of_id !== null;
    }

    /**
     * The machine name code across the platform means by this role: a version's is the shared
     * role it stands for, and every other role's is its own.
     *
     * Use this, never `name`, wherever a role is recognised by what it IS rather than by its
     * row: "the Community Super Admin", "the President first". A version's own `name` is a new,
     * unique key. Code that asked for `estate.community_super_admin` by name would not find
     * the one this estate's administrators now hold.
     */
    public function standsFor(): string
    {
        if ($this->version_of_id === null) {
            return (string) $this->name;
        }

        if ($this->relationLoaded('versionOf')) {
            return (string) ($this->versionOf->name ?? $this->name);
        }

        return (string) (static::query()->whereKey($this->version_of_id)->value('name') ?? $this->name);
    }

    /** @return BelongsTo<Role, $this> */
    public function versionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'version_of_id');
    }

    /**
     * `ownedBy()` as the estate sees it: the shared roles and its own, with every shared role it
     * has a version of left out, because the version stands in its place (M11 B4).
     *
     * The platform console (a null tenant) sees exactly what `ownedBy(null)` sees.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeVisibleTo(Builder $query, ?string $tenantId): Builder
    {
        $query->ownedBy($tenantId);

        if ($tenantId === null || $tenantId === '') {
            return $query;
        }

        return $query->whereNotIn('id', static fn ($versions) => $versions
            ->select('version_of_id')
            ->from('roles')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('version_of_id'));
    }

    /**
     * The role an estate means by a name: its own version of that shared role where it has
     * one, else the shared or estate-owned role by that name. Null when this estate has
     * nothing by that name.
     */
    public static function forEstate(string $name, string $tenantId): ?self
    {
        /** @var self|null $role */
        $role = static::query()->ownedBy($tenantId)->where('name', $name)->first();

        if ($role === null || ! $role->isSharedAcrossEstates()) {
            return $role;
        }

        /** @var self|null $version */
        $version = static::query()->where('tenant_id', $tenantId)->where('version_of_id', $role->id)->first();

        return $version ?? $role;
    }

    /**
     * The ids of every role that is `$name` at this estate: the role itself and, for a shared
     * role, this estate's version of it. It is what a "who holds the X here" query filters
     * on (M11 B4).
     *
     * @return list<int>
     */
    public static function idsStandingFor(string $name, string $tenantId): array
    {
        $base = static::query()->where('name', $name)->value('id');

        if ($base === null) {
            return [];
        }

        return static::query()
            ->where(static fn (Builder $roles) => $roles
                ->whereKey((int) $base)
                ->orWhere(static fn (Builder $version) => $version->where('version_of_id', (int) $base)->where('tenant_id', $tenantId)))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Is this role the named system role, or a copy made from it?
     *
     * A COPY INHERITS WHAT BINDS ITS ORIGINAL. Ruling 1 locks the Property
     * Manager out of the money modules, and the rbac migration says in terms that
     * no "role clone" may grant them either — so a runtime copy of the Property
     * Manager, however it has since been renamed, answers true here and
     * `Module::isLockedFor()` keeps refusing it. `copied_from_id` always names the
     * SYSTEM role at the root of the copy, so one hop is the whole lineage.
     */
    public function derivesFrom(string $systemRoleName): bool
    {
        if ($this->name === $systemRoleName) {
            return true;
        }

        if ($this->copied_from_id === null) {
            return false;
        }

        /*
         * The copied-from role as already loaded, where it is (M11 A2): the role editor
         * loads every role's `copiedFrom` and asked this once per locked module per
         * copy. `copied_from_id` always names a SYSTEM role, whose machine name code
         * across the platform relies on and nothing renames, so the loaded row and a
         * fresh read of it give the same name.
         */
        if ($this->relationLoaded('copiedFrom')) {
            return $this->copiedFrom?->name === $systemRoleName;
        }

        return static::query()->whereKey($this->copied_from_id)->value('name') === $systemRoleName;
    }

    /** @return BelongsTo<Role, $this> */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /**
     * Typed lookup by machine name.
     *
     * spatie's findByName() is declared as returning its own Contracts\Role
     * interface, which knows nothing about `label`, `console` or
     * `scope_default`. Every caller here wants this class, so narrowing it
     * once is better than casting at each call site.
     */
    public static function named(string $name, string $guard = 'web'): self
    {
        /** @var self $role */
        $role = static::findByName($name, $guard);

        return $role;
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'role_module_access')
            ->withPivot(['level', 'can_approve', 'scope'])
            ->withTimestamps();
    }

    /** @return HasMany<RoleModuleAccess, $this> */
    public function moduleAccess(): HasMany
    {
        return $this->hasMany(RoleModuleAccess::class);
    }

    /**
     * The navigation this role sees, generated from the matrix at runtime.
     *
     * Cross-cutting rule 9: a module a role cannot use is ABSENT from the
     * navigation, not disabled or greyed. That is why this filters rather than
     * returning every module with a flag — there is no code path that can
     * render a module at level `none`.
     *
     * VISIBLE MEANS "GRANTS VIEW", read off the cell and not off its level. A
     * custom cell (M10 §4) can hold export without view, and a module in the
     * sidebar of a role that may not open it would be exactly the greyed link
     * this rule forbids.
     *
     * @return Collection<int, Module>
     */
    public function navigableModules(): Collection
    {
        return $this->moduleAccess()
            ->with('module')
            ->get()
            ->filter(fn (RoleModuleAccess $a) => $a->grantsView())
            ->sortBy(fn (RoleModuleAccess $a) => $a->module->sort)
            ->map(fn (RoleModuleAccess $a) => $a->module)
            ->values();
    }
}
