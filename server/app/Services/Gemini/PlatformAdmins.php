<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Rbac\RoleEditor;
use App\Support\PlatformCalendar;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who holds a Gemini Console account — board 42's "Platform admins" tab (12 §2,
 * item 43: build the missing section).
 *
 * THE PLATFORM'S OWN STAFF, ISSUED BY INVITATION. Accounts come into existence
 * only through `Invitations` — the same fourteen-day link, naming inviter and
 * role, that an estate uses — so this screen lists them, sends and withdraws
 * invitations, and changes an existing person's role, sites or standing.
 *
 * WHAT IT CANNOT DO IS WHAT MAKES IT SAFE. Nobody changes their own role or
 * suspends themselves (a slip would lock the platform's only administrator
 * out), the last active Director cannot be demoted, suspended or removed, and a
 * Gemini account is never given an estate role. This moves people between
 * roles; what a role may DO is changed in `App\Services\Rbac\RoleEditor` (M10
 * §4), and the roles offered here are the ones it says this console can see —
 * the six seeded ones and any the Director has made, never a row owned by an
 * estate.
 *
 * ADMINISTRATORS ARE MADE AND UNMADE ONLY BY ADMINISTRATORS. A role that can
 * change roles — the Director, or a custom role given Platform settings ·
 * approve — is handed out, taken away or suspended only by somebody who holds
 * that power themselves (`RoleEditor::assertMayHandOut()` and
 * `assertMayManageHolderOf()`).
 *
 * A SITE-SCOPED ROLE NEEDS SITES. A Head of Security scoped to assigned sites
 * sees nothing until sites are assigned, so the sites are set here with the
 * role, and cleared when a person moves to a role that covers every client.
 */
final class PlatformAdmins
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleEditor $roles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(User $viewer): array
    {
        // A board that writes nothing, drawn inside the role editor's reading pass (M11 A2): each row's
        // refusal asks whether that person's role carries the power to change roles, and inside a pass
        // that is answered from the permissions loaded below instead of a query a row.
        return $this->roles->whileReading($viewer, fn (): array => $this->drawBoard($viewer));
    }

    /**
     * `board()`, inside its reading pass.
     *
     * @return array<string, mixed>
     */
    private function drawBoard(User $viewer): array
    {
        // `roles.permissions`: the refusal on each row asks whether that person's role carries the
        // power to change roles, which was a query per person while it loaded on demand (M11 A2).
        $users = User::query()
            ->with(['roles.permissions', 'assignments'])
            ->where('console', Console::Gemini->value)
            ->orderBy('name')
            ->get();

        /*
         * The site-assignment picker. Not the demonstration estate (M5 §3):
         * confining a Head of Security to it would give them a console with one
         * invented client in it and nothing real to watch.
         *
         * `save()` below still validates a posted assignment against
         * `Tenant::estates()` rather than this list, so somebody who genuinely
         * means to can still be assigned there deliberately. What is removed is
         * the offer, not the possibility.
         */
        $clients = OperatorEstates::estates()->sortBy('name')->mapWithKeys(static fn (Tenant $t): array => [(string) $t->getTenantKey() => (string) $t->name]);

        $directors = $this->activeDirectorIds();

        return [
            'people' => $users->map(function (User $user) use ($viewer, $clients, $directors): array {
                /** @var Role|null $role */
                $role = $user->roles->first();
                $scoped = $role !== null && $role->scope_default === AccessScope::AssignedSites;
                $sites = $user->assignments->where('is_active', true)->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->values()->all();

                return [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => $role?->name,
                    'role_label' => (string) ($role->label ?? $role->name ?? 'No role'),
                    'scoped' => $scoped,
                    'sites' => $sites,
                    'site_names' => $scoped
                        ? (count($sites) === 0 ? 'No sites assigned — sees nothing yet' : implode(', ', array_map(static fn (string $id): string => $clients[$id] ?? $id, $sites)))
                        : 'Every client',
                    'status' => (string) $user->status,
                    'is_self' => $user->is($viewer),
                    'reason' => match (true) {
                        $user->is($viewer) => 'This is your own account. Changing your own role or suspending yourself is done by another administrator, so a slip cannot lock you out.',
                        $directors === [$user->id] => 'This is the only active Director. Make somebody else a Director before changing this account.',
                        default => $this->holderRefusal($viewer, $user),
                    },
                ];
            })->all(),
            'invitations' => Invitation::query()
                ->with(['inviter', 'role'])
                ->whereNull('tenant_id')
                ->whereNull('accepted_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => (string) ($invitation->role->label ?? $invitation->role->name),
                    'invited_by' => (string) ($invitation->inviter->name ?? 'Not recorded'),
                    // Gemini's own calendar: the column is UTC, and an evening's expiry read as the next day.
                    'expires' => PlatformCalendar::local($invitation->expires_at)->format('M j, Y'),
                    'expired' => ! $invitation->isPending(),
                    // Whether its email went, in the row (DESIGN §11 row 2): `delivery`, `delivery_state`, `last_try`, `sent_at`, `resend_after`.
                    ...\App\Services\Invitations::deliveryShape($invitation),
                ])
                ->all(),
            /*
             * The platform's own roles only, seeded first: `ownedBy(null)` is
             * what keeps a row an estate owns off this console entirely, even a
             * mis-created one.
             */
            'roles' => Role::query()
                ->where('console', Console::Gemini->value)
                ->ownedBy(null)
                ->orderByDesc('is_system')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(static fn (Role $role): array => [
                    'name' => $role->name,
                    'label' => (string) ($role->label ?? $role->name),
                    'scoped' => $role->scope_default === AccessScope::AssignedSites,
                    'is_system' => $role->is_system,
                ])
                ->all(),
            'clients' => $clients->map(static fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values()->all(),
        ];
    }

    /**
     * Move a person to a role, set their sites, and set their standing.
     *
     * @param  list<string>  $siteIds
     */
    public function manage(User $target, string $roleName, string $status, array $siteIds, User $by): void
    {
        if ($target->console !== Console::Gemini) {
            throw new DomainException('That account belongs to an estate. It is managed from that estate\'s own Users & roles.');
        }

        if ($target->is($by)) {
            throw new DomainException('You cannot change your own role or suspend yourself. Another administrator does that, so a slip cannot lock you out.');
        }

        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('An account is active or suspended. Nothing deletes a person from the platform\'s history.');
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('console', Console::Gemini->value)
            ->ownedBy(null)
            ->first();

        if ($role === null) {
            throw new DomainException('Choose one of the Gemini Console\'s roles. A platform account never holds an estate role.');
        }

        $wasDirector = $target->hasRole(Role::DIRECTOR) && $target->status === 'active';
        $staysDirector = $role->name === Role::DIRECTOR && $status === 'active';

        if ($wasDirector && ! $staysDirector && $this->activeDirectorIds() === [$target->id]) {
            throw new DomainException('This is the only active Director. Make somebody else a Director first — otherwise nobody could change this back.');
        }

        /*
         * After the last-Director rule, so that refusal — the one that explains
         * the most — is the one a caller meets first when both apply.
         */
        foreach ($this->heldHere($target) as $current) {
            $this->roles->assertMayManageHolderOf($by, $current);
        }

        if (! $target->hasRole($role->name)) {
            $this->roles->assertMayHandOut($by, $role);
        }

        $scoped = $role->scope_default === AccessScope::AssignedSites;
        $sites = $scoped
            ? array_values(array_intersect(array_unique(array_map('strval', $siteIds)), Tenant::estates()->map(static fn (Tenant $t): string => (string) $t->getTenantKey())->all()))
            : [];

        $before = [
            'role' => $target->roles->first()?->name,
            'status' => $target->status,
            'sites' => $target->assignments()->where('is_active', true)->pluck('tenant_id')->all(),
        ];

        DB::connection('mysql')->transaction(function () use ($target, $role, $status, $scoped, $sites): void {
            $target->syncRoles([$role->name]);
            $target->forceFill(['status' => $status])->save();

            // Sites mean something only to a scoped role. Everyone else covers
            // every client, and a stale assignment row would be a scope nobody
            // can see on the screen.
            EstateAssignment::query()
                ->where('user_id', $target->id)
                ->when($scoped, static fn ($query) => $query->whereNotIn('tenant_id', $sites))
                ->update(['is_active' => false]);

            foreach ($sites as $tenantId) {
                EstateAssignment::query()->updateOrCreate(
                    ['user_id' => $target->id, 'tenant_id' => $tenantId],
                    ['role_id' => $role->id, 'is_active' => true],
                );
            }
        });

        /*
         * NO CACHE FLUSH, deliberately. spatie caches what each ROLE may do,
         * which this does not change; which role a person holds, their status
         * and their site assignments are all read fresh on every request, so
         * this applies to the person's very next request as it stands.
         *
         * A SUSPENSION KEEPS THE ROLE, so the role's gates alone would still let
         * a signed-in person through. What ends their session is the status:
         * `EndSessionOfInactiveAccount` signs out any account that is not active
         * on its next request, before `auth` or any gate is asked (B068).
         */
        $this->audit->record(
            action: 'platform.user_managed',
            entityType: 'User',
            entityId: (string) $target->id,
            before: $before,
            after: ['role' => $role->name, 'status' => $status, 'sites' => $sites, 'changed_by' => $by->name],
            actor: $by,
        );
    }

    /**
     * Take somebody off the Gemini Console (M10 §4, "remove administrators").
     *
     * REMOVED, NOT DELETED, for the reason `manage()` gives for suspension: the
     * account is suspended, left holding no role and no site, so it reaches
     * nothing; the row stays, because the audit log names this person against
     * everything they did, and a log whose actors vanish is one nobody can
     * question.
     *
     * The same rules as a role change: nobody removes themselves, the last
     * active Director cannot be removed, and an administrator is removed only by
     * somebody who holds the power they hold.
     */
    public function remove(User $target, User $by): void
    {
        if ($target->console !== Console::Gemini) {
            throw new DomainException('That account belongs to an estate. It is managed from that estate\'s own Users & roles.');
        }

        if ($target->is($by)) {
            throw new DomainException('You cannot remove yourself. Another administrator does that, so a slip cannot lock you out.');
        }

        if ($target->hasRole(Role::DIRECTOR) && $target->status === 'active' && $this->activeDirectorIds() === [$target->id]) {
            throw new DomainException('This is the only active Director. Make somebody else a Director first — otherwise nobody could change this back.');
        }

        foreach ($this->heldHere($target) as $current) {
            $this->roles->assertMayManageHolderOf($by, $current);
        }

        $before = [
            'role' => $target->roles->first()?->name,
            'status' => $target->status,
            'sites' => $target->assignments()->where('is_active', true)->pluck('tenant_id')->all(),
        ];

        DB::connection('mysql')->transaction(function () use ($target): void {
            $target->forceFill(['status' => 'suspended'])->save();
            $target->syncRoles([]);

            EstateAssignment::query()->where('user_id', $target->id)->update(['is_active' => false]);
        });

        $this->audit->record(
            action: 'platform.user_removed',
            entityType: 'User',
            entityId: (string) $target->id,
            before: $before,
            after: ['role' => null, 'status' => 'suspended', 'sites' => [], 'changed_by' => $by->name],
            actor: $by,
        );
    }

    /**
     * Why this viewer may not change or remove this person, when the reason is
     * the power the person holds rather than who they are.
     *
     * THE SCREEN SAYS IT BEFORE THE PRESS, IN THE REFUSAL'S OWN WORDS. An
     * administrator is changed only by somebody who holds the power they hold
     * (`RoleEditor::assertMayManageHolderOf()`), and a row whose Change button
     * worked until the save was refused would be a control claiming what it
     * cannot do. So the board asks the same question `manage()` and `remove()`
     * will, and carries the sentence it answers with.
     */
    private function holderRefusal(User $viewer, User $person): ?string
    {
        try {
            foreach ($this->heldHere($person) as $current) {
                $this->roles->assertMayManageHolderOf($viewer, $current);
            }
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * The roles a person holds that are this console's — the only ones whose
     * power the administrator rule here is about.
     *
     * AN ESTATE ROLE ON A GEMINI ACCOUNT IS NOT AN ADMINISTRATOR HERE (bug hunt
     * B067). Before the estate's Users & roles was fenced, a committee could
     * re-role Gemini's Head of Security into the Community Super Admin through
     * their site row. That role's power is to change an ESTATE's roles, which no
     * Gemini account can ever hold, so asking it of the Director refused them an
     * account nobody else could put right either, in a sentence naming a power
     * this console does not have. The role confers nothing on a Gemini account
     * (`User::canAccessEstate()`), and moving the account back to a Gemini role
     * here is what takes it away.
     *
     * @return Collection<int, Role>
     */
    private function heldHere(User $person): Collection
    {
        return $person->roles->filter(static fn (Role $role): bool => $role->console === Console::Gemini);
    }

    /**
     * Every active account holding the SEEDED Director role.
     *
     * `is_system` as well as the name, and the last-Director guard depends on
     * both: the name is the machine key M10 §4 keeps immutable precisely so this
     * query can never silently return nothing — which would make every
     * last-Director refusal quietly stop firing — and `is_system` means a role
     * somebody created later can never be mistaken for it.
     *
     * @return list<int>
     */
    private function activeDirectorIds(): array
    {
        return User::query()
            ->where('console', Console::Gemini->value)
            ->where('status', 'active')
            ->whereHas('roles', static fn ($query) => $query->where('name', Role::DIRECTOR)->where('is_system', true))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
