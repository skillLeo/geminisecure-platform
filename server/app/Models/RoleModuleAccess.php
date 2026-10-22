<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\PermissionVerb;
use App\Support\NamedActs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One cell of a role access matrix.
 *
 * Three orthogonal facts, not one enum (D-007): what may be done (level),
 * whether the irreversible act may be committed (can_approve), and which
 * records are in reach (scope).
 *
 * THIS CLASS IS THE ONLY PLACE A CELL BECOMES A PERMISSION NAME, and M10 §4 is
 * why that matters more than it did. `can:` resolves against spatie's compiled
 * `role_has_permissions`, not against this table; the seeder and `RoleEditor`
 * both compile a role by asking each of its cells for `permissionNames()`. So a
 * `custom` cell's verb list is taught to exactly one method, and navigation,
 * route gates, `rbac:show` and both matrix screens all follow it.
 *
 * AND A FOURTH FACT, SINCE M11 B3: NAMED ACTS (`App\Support\NamedActs`). Seven
 * verbs are split into one permission per power — the Approver tag on Dues &
 * ledger into five, update on Billing & subscriptions into four, configure on
 * Platform settings into three, and so on. A cell
 * that grants a split verb grants every act under it, so no cell written before
 * the split grants anything different; `acts` lists the ones a cell grants ONE
 * BY ONE, without the verb above them. The split verb's own permission is never
 * compiled, because its whole power is its acts.
 *
 * @property int $id
 * @property int $role_id
 * @property int $module_id
 * @property AccessLevel $level
 * @property list<string>|null $verbs
 * @property list<string>|null $acts
 * @property bool $can_approve
 * @property AccessScope $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Module $module
 * @property-read Role $role
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCanApprove($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereModuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class RoleModuleAccess extends Model
{
    use CentralConnection;

    protected $table = 'role_module_access';

    protected $fillable = [
        'role_id',
        'module_id',
        'level',
        'verbs',
        'acts',
        'can_approve',
        'scope',
    ];

    protected function casts(): array
    {
        return [
            'level' => AccessLevel::class,
            'verbs' => 'array',
            'acts' => 'array',
            'scope' => AccessScope::class,
            'can_approve' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * The verbs this cell grants, before can_approve is applied.
     *
     * A preset level answers for itself; a `custom` cell answers from its own
     * stored list. An unknown string in that list is dropped rather than thrown
     * on, because this runs on every request that builds a sidebar, and a
     * request is the wrong place to discover a bad row — `RoleEditor` refuses one
     * on the way in.
     *
     * `approve` IS NEVER ONE OF THEM, whatever the list says. It is a separate
     * axis (D-008), set by the Approver tag and checked against the editor's own
     * holdings as one; a verb list that could carry it would be a second, quieter
     * way in.
     *
     * @return list<PermissionVerb>
     */
    public function grantedVerbs(): array
    {
        if ($this->level !== AccessLevel::Custom) {
            return $this->level->verbs();
        }

        $verbs = [];

        foreach ((array) ($this->verbs ?? []) as $value) {
            $verb = PermissionVerb::tryFrom((string) $value);

            if ($verb !== null && $verb !== PermissionVerb::Approve && ! in_array($verb, $verbs, true)) {
                $verbs[] = $verb;
            }
        }

        // In the enum's own order, so two cells granting the same verbs compile
        // to the same list and compare equal in an audit before/after.
        return array_values(array_filter(
            PermissionVerb::cases(),
            static fn (PermissionVerb $verb): bool => in_array($verb, $verbs, true),
        ));
    }

    /**
     * Does this cell put its module in the navigation?
     *
     * Only if it grants `view`. For the four presets that is the same answer as
     * `AccessLevel::isVisible()`; for a custom cell it is the only right one. A
     * cell holding `export` alone would otherwise land the module in the sidebar
     * of a role that is refused the moment it clicks — the greyed link rule 9
     * exists to rule out, arriving by a different door.
     */
    public function grantsView(): bool
    {
        return in_array(PermissionVerb::View, $this->grantedVerbs(), true);
    }

    /**
     * Does this cell grant everything `Full` does — whatever it is called?
     *
     * For the "Full access" summary on the platform's admin screen, which must
     * not be fooled in either direction by a custom cell that ticks every box.
     */
    public function grantsFull(): bool
    {
        return array_diff(
            array_map(static fn (PermissionVerb $verb): string => $verb->value, AccessLevel::Full->verbs()),
            array_map(static fn (PermissionVerb $verb): string => $verb->value, $this->grantedVerbs()),
        ) === [];
    }

    /**
     * The named acts this cell grants one by one (M11 B3), in registry order.
     *
     * Read off `acts`, and — like an unknown verb — an unknown key is dropped
     * rather than thrown on, because this runs on every request that builds a
     * sidebar. `RoleEditor` refuses one on the way in. An act whose verb the cell
     * already grants is left out: it is implied, not listed.
     *
     * @return list<string>
     */
    public function explicitActs(): array
    {
        $listed = array_map('strval', (array) ($this->acts ?? []));

        // Nearly every cell lists none, and then the module is not needed at all
        // — so a pill printed from a cell loaded without its module costs nothing.
        if ($listed === []) {
            return [];
        }

        $known = NamedActs::of($this->module->console, $this->module->key);
        $out = [];

        foreach ($known as $key => $act) {
            if (in_array($key, $listed, true) && ! $this->grantsVerb($act['verb'])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Every named act this cell grants: those under a verb it grants, and those
     * it lists one by one. In registry order.
     *
     * @return list<string>
     */
    public function grantedActs(): array
    {
        $explicit = $this->explicitActs();
        $out = [];

        foreach (NamedActs::of($this->module->console, $this->module->key) as $key => $act) {
            if ($this->grantsVerb($act['verb']) || in_array($key, $explicit, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * The permission strings this cell grants, as `module.verb` or
     * `module.act`.
     *
     * `approve` is appended from can_approve rather than from the level,
     * because Full without the Approver tag must not grant it (D-008).
     *
     * A SPLIT VERB COMPILES TO ITS ACTS, AND NEVER TO ITSELF (M11 B3). The
     * Approver tag on Dues & ledger is `estate.dues_ledger.set_rates` and the
     * four others, and `estate.dues_ledger.approve` is not a permission any more.
     * Then whatever acts the cell lists on their own.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $prefix = $this->module->permissionPrefix();
        $console = $this->module->console;
        $key = $this->module->key;

        $verbs = $this->grantedVerbs();

        if ($this->can_approve) {
            $verbs[] = PermissionVerb::Approve;
        }

        $names = [];

        foreach ($verbs as $verb) {
            $split = NamedActs::under($console, $key, $verb);

            if ($split === []) {
                $names[] = "{$prefix}.{$verb->value}";

                continue;
            }

            foreach ($split as $act) {
                $names[] = "{$prefix}.{$act}";
            }
        }

        foreach ($this->explicitActs() as $act) {
            $names[] = "{$prefix}.{$act}";
        }

        return array_values(array_unique($names));
    }

    /**
     * The pill text as drawn, including the Approver tag.
     *
     * A custom cell names its verbs — "Custom (view, export)" — because the word
     * alone would tell a committee nothing about what the role can do. A cell
     * holding named acts on their own names them too — "Full · Sets dues rates" —
     * because "Full" alone would say the role approves nothing, and the Approver
     * tag would say it approves everything.
     */
    public function pillLabel(): string
    {
        $label = $this->level === AccessLevel::Custom
            ? sprintf('Custom (%s)', implode(', ', array_map(
                static fn (PermissionVerb $verb): string => $verb->value,
                $this->grantedVerbs(),
            )) ?: 'nothing')
            : $this->level->label();

        if ($this->can_approve) {
            $label .= ' · Approver';
        }

        $acts = $this->actsLabel();

        return $acts === null ? $label : $label.' · '.$acts;
    }

    /**
     * "Sets dues rates, flags households" — the acts this cell lists on their
     * own, as the pill prints them, or null when it lists none.
     */
    public function actsLabel(): ?string
    {
        $explicit = $this->explicitActs();

        if ($explicit === []) {
            return null;
        }

        $known = NamedActs::of($this->module->console, $this->module->key);

        return ucfirst(implode(', ', array_map(static fn (string $act): string => $known[$act]['short'], $explicit)));
    }

    /** Does this cell grant the verb a named act hangs off? */
    private function grantsVerb(string $verb): bool
    {
        if ($verb === PermissionVerb::Approve->value) {
            return $this->can_approve;
        }

        return in_array(PermissionVerb::from($verb), $this->grantedVerbs(), true);
    }
}
