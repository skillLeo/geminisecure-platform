<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Notifications\ResetPasswordLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A user account, always central.
 *
 * Accounts are issued, never self-created — there is no public registration
 * endpoint. A user belongs to exactly one console and never both.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Console $console
 * @property string $status
 * @property string|null $phone
 * @property string|null $mfa_secret the authenticator key, encrypted at rest (App\Services\Auth\TwoFactor)
 * @property bool $mfa_enabled
 * @property Carbon|null $mfa_confirmed_at
 * @property list<string>|null $mfa_recovery_codes SHA-256 of each unused recovery code, encrypted at rest
 * @property int|null $mfa_last_step
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string|null $contact_email
 * @property Carbon|null $contact_email_verified_at
 * @property string|null $contact_phone
 * @property string $password
 * @property Carbon|null $password_changed_at when the password was last changed (M32 F9); null before the first dated change
 * @property int $sign_in_generation raised to end every other session (M32 F9, App\Services\Account\AccountSessions)
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EstateAssignment> $assignments
 * @property-read int|null $assignments_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, Permission> $teams
 * @property-read int|null $teams_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property string|null $photo_path The profile picture on the `console_photos` disk (M12 §4.8)
 * @property string|null $photo_sha256
 * @property Carbon|null $photo_updated_at
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User team($teams, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTeam($teams)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use CentralConnection;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'console',
        'status',
        'phone',

        /*
         * The alert address and phone are fillable; the moment the address was
         * PROVEN is not. `contact_email_verified_at` is only ever written by
         * `ContactVerification::verify()` and by `DemonstrationAccounts`, both
         * through `forceFill`, so no form that mass-assigns a request can mark
         * an address verified by posting a timestamp.
         */
        'contact_email',
        'contact_phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_last_step',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'contact_email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'sign_in_generation' => 'integer',
            'console' => Console::class,
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_last_step' => 'integer',
            'photo_updated_at' => 'datetime',
        ];
    }

    /**
     * CHANGING THE ALERT ADDRESS CLEARS ITS VERIFICATION — M10 §1.4 — HERE, ON THE
     * MODEL, so no screen, command or seeder can forget to.
     *
     * A verification proves ONE address. An account whose address changed after it
     * was proven is an account whose alerts go somewhere nobody has shown they read,
     * and `alertEmail()` must not hand that out. So whenever `contact_email` is
     * dirty on save, the timestamp is cleared — UNLESS the same save also sets the
     * timestamp, which is exactly what the two legitimate writers do: the verify
     * step stores the proven address and the moment together, and a seeder stores a
     * demonstration account's address already verified.
     *
     * The address is normalised first (trimmed, lower-cased, blank to null), so
     * re-saving "A.Reid@Example.com" over "a.reid@example.com" is not a change and
     * does not cost anybody their verification.
     */
    protected static function booted(): void
    {
        static::saving(static function (User $user): void {
            $raw = $user->getAttribute('contact_email');

            if ($raw !== null) {
                $normalised = mb_strtolower(trim((string) $raw));
                $user->setAttribute('contact_email', $normalised === '' ? null : $normalised);
            }

            /*
             * Only when the address is DIRTY. A model loaded with a narrow
             * `select()` has no `contact_email` attribute at all, and treating that
             * absence as "cleared" would write NULL over a proven address on an
             * unrelated save.
             */
            if ($user->isDirty('contact_email') && ! $user->isDirty('contact_email_verified_at')) {
                $user->setAttribute('contact_email_verified_at', null);
            }
        });
    }

    /**
     * The address a panic may be emailed to, or null — M10 §1.4's contract.
     *
     * NULL UNLESS PROVEN. "An unverified address is never used for alerting": a
     * sign-in address nobody confirmed, or a contact address typed and never
     * verified, is not an address anything should claim to have reached. Every
     * alert route asks this, and none of them falls back to `email`.
     */
    public function alertEmail(): ?string
    {
        return $this->hasVerifiedContact() ? $this->contact_email : null;
    }

    /**
     * WHERE AN ALERT IS MAILED — `alertEmail()`, and nothing else (M10 §1.4).
     *
     * The route `AlertMailChannel` asks for, by the name `alerts`. It is a route of
     * its OWN rather than a change to where `mail` goes, because the two questions
     * have different answers and must keep them: a password reset, an invitation and
     * the verification code itself are mailed where they always were (the sign-in
     * address, the invited address, the address being proven), and only an ALERT is
     * held to "an unverified address is never used for alerting". Null means the
     * channel sends nothing — never a fallback to `email`.
     *
     * Read when the mail is actually SENT, which for a queued alert is in the
     * worker: an address unproven between the panic and the send is not mailed.
     */
    public function routeNotificationForAlerts(): ?string
    {
        return $this->alertEmail();
    }

    /** Whether this account holds a contact address and has proven it. */
    public function hasVerifiedContact(): bool
    {
        return $this->contact_email !== null
            && $this->contact_email !== ''
            && $this->contact_email_verified_at !== null;
    }

    /**
     * Whether this account meets the contact gate at all (M10 §1.4).
     *
     * EVERY ESTATE-CONSOLE ACCOUNT, WHATEVER ITS ROLE. The client listed the nine
     * community roles, and the rule is deliberately not that list: it is "signs in
     * to an estate console", so a custom estate role added under §4 is gated the
     * day it is created, without anybody remembering to add it anywhere. The whole
     * estate console is one community's committee, and a panic at a lot is put in
     * front of it (§1.2 item 6).
     *
     * AND THE GEMINI ROLES A PANIC REACHES, named once in
     * `Role::GEMINI_ALERT_RECIPIENTS` with the reading behind each of them.
     */
    public function isSubjectToContactGate(): bool
    {
        if ($this->console === Console::Estate) {
            return true;
        }

        return $this->roles
            ->pluck('name')
            ->intersect(Role::GEMINI_ALERT_RECIPIENTS)
            ->isNotEmpty();
    }

    /** True for an account the gate applies to whose alert address is not yet proven. */
    public function needsContactVerification(): bool
    {
        return $this->isSubjectToContactGate() && ! $this->hasVerifiedContact();
    }

    /** @return HasMany<EstateAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EstateAssignment::class);
    }

    /**
     * The reset email, on this user's own door.
     *
     * Overridden so the link lands on the estate's hostname for a committee
     * member and on the central door for Gemini staff — the framework's default
     * knows only one URL. See `ResetPasswordLink`.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink((string) $token));
    }

    /**
     * May this user reach the given estate?
     *
     * This is the single question every tenant-scoped request must ask, and it
     * is answered from the ROLE's scope plus explicit assignments — never from
     * anything in the request.
     *
     * Note the deliberate asymmetry: for a role scoped to `all`, the absence of
     * assignment rows means unrestricted; for `assigned_sites` it means no
     * access at all. Scope is therefore read from the role and never inferred
     * from whether rows happen to exist, because inferring it would silently
     * promote a Head of Security with no assignments yet into seeing every
     * estate on the platform.
     *
     * AN ESTATE ROLE GIVES A GEMINI ACCOUNT NOTHING (bug hunt B067). Before the
     * estate's Users & roles was fenced, a committee could hand Gemini's Head of
     * Security the Community Super Admin role through their site row. That
     * estate role's scope, All, then opened every estate on the platform to a
     * Gemini account, and the site row, now carrying the estate role, made it an
     * administrator of that estate whom its own committee cannot see. Closing
     * the path does not undo an account it already reached. This does, whatever
     * put the row there: the scope is read from roles of the account's own
     * console (`widestScope()`), and a Gemini account's site row counts only
     * while it carries a Gemini role, as every row Platform admins writes does.
     * `CrossTenantRoleProbe` names any such account so it can be put right.
     *
     * An estate account's assignment is its membership of the estate, and that
     * is read as it always was. No path gives an estate account a Gemini role,
     * and refusing one over a mislabelled role row would silently take a
     * committee member out of their estate and out of its panic audience. The
     * probe names that case too.
     */
    public function canAccessEstate(string $tenantId): bool
    {
        return $this->canAccessEstates([$tenantId])[$tenantId];
    }

    /**
     * `canAccessEstate()` for several estates, in ONE read of this account's assignments (M11 A2).
     *
     * A screen that asks about every alert on a list — the siren's "is anybody still waiting on
     * these?", polled every few seconds while it sounds — asked once per alert. This is the same
     * question for all of them: the estates the account holds a reaching assignment at, read
     * together, and each answered by `reachesWith()`, the rule itself. `canAccessEstate()` is
     * this for one estate, so there is one rule and never two to drift.
     *
     * @param  list<string>  $tenantIds
     * @return array<string, bool> every estate asked about, keyed by id
     */
    public function canAccessEstates(array $tenantIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $tenantIds)));
        $answers = [];

        if ($ids === []) {
            return $answers;
        }

        // Nothing to read for an account that reaches nothing: the rule refuses it first.
        $assigned = $this->status !== 'active' ? [] : $this->reachingAssignments()
            ->whereIn('tenant_id', $ids)
            ->pluck('tenant_id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        foreach ($ids as $id) {
            $answers[$id] = $this->reachesWith(isset($assigned[$id]));
        }

        return $answers;
    }

    /**
     * Of these accounts, the ones `canAccessEstate($tenantId)` answers yes for, in their order —
     * from two reads however many there are (M11 A2).
     *
     * The Heads of Security a panic, an override or an auto-closed shift is reported to are found
     * by asking every one of them in turn, and each asked twice: their roles, then their
     * assignments. Here the roles are loaded for all of them at once, and every one's reaching
     * assignment at this estate is read in a single query — a Gemini account's only while its
     * row carries a Gemini role (`reachingAssignments()`), anybody else's as it stands — and each
     * account is then answered by `reachesWith()`, the rule `canAccessEstate()` answers by.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public static function whoCanAccessEstate(Collection $users, string $tenantId): Collection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $users->loadMissing('roles');

        $active = $users->filter(static fn (User $user): bool => $user->status === 'active');
        $gemini = $active->filter(static fn (User $user): bool => $user->console === Console::Gemini)->modelKeys();
        $others = $active->filter(static fn (User $user): bool => $user->console !== Console::Gemini)->modelKeys();

        $reaching = [];

        if ($gemini !== [] || $others !== []) {
            $reaching = EstateAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(static function ($rows) use ($gemini, $others): void {
                    if ($others !== []) {
                        $rows->orWhereIn('user_id', $others);
                    }

                    if ($gemini !== []) {
                        $rows->orWhere(static fn ($own) => $own
                            ->whereIn('user_id', $gemini)
                            ->whereHas('role', static fn ($role) => $role->where('console', Console::Gemini->value)));
                    }
                })
                ->pluck('user_id')
                ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
                ->all();
        }

        return $users->filter(static fn (User $user): bool => $user->reachesWith(isset($reaching[(int) $user->getKey()])));
    }

    /**
     * THE RULE `canAccessEstate()` STATES, given whether the account holds a reaching assignment
     * at the estate: nothing for an account that is not active; an estate account only where it
     * is assigned; Gemini staff everywhere their role's scope is "all", and otherwise only where
     * assigned.
     */
    private function reachesWith(bool $hasAssignment): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Estate committee members live inside exactly one estate.
        if ($this->console === Console::Estate) {
            return $hasAssignment;
        }

        // Gemini staff: platform-wide unless their role narrows them.
        return $this->widestScope() === AccessScope::All || $hasAssignment;
    }

    /**
     * The least restrictive scope across this user's roles.
     *
     * A user with two roles gets the wider of the two, which is why this
     * cannot simply read the first role. Only roles of the account's own
     * console are read: see `canAccessEstate()`.
     */
    public function widestScope(): AccessScope
    {
        $scopes = $this->ownConsoleRoles()->pluck('scope_default');

        return $scopes->contains(AccessScope::All)
            ? AccessScope::All
            : ($scopes->first() ?? AccessScope::OwnRecords);
    }

    /**
     * The estate ids this user may reach, for a scoped listing — by the same
     * assignments `canAccessEstate()` counts, so the two never disagree.
     *
     * @return list<string>
     */
    public function accessibleEstateIds(): array
    {
        return $this->reachingAssignments()
            ->pluck('tenant_id')
            ->all();
    }

    /**
     * The roles this account holds that belong to its own console.
     *
     * @return Collection<int, Role>
     */
    private function ownConsoleRoles(): Collection
    {
        return $this->roles->filter(fn (Role $role): bool => $role->console === $this->console);
    }

    /**
     * The active assignments that let this account reach an estate: for a
     * Gemini account, only site rows carrying a Gemini role. See
     * `canAccessEstate()`.
     *
     * @return HasMany<EstateAssignment, $this>
     */
    private function reachingAssignments(): HasMany
    {
        $rows = $this->assignments()->where('is_active', true);

        if ($this->console === Console::Gemini) {
            $rows->whereHas('role', static fn ($role) => $role->where('console', Console::Gemini->value));
        }

        return $rows;
    }

    public function isGeminiStaff(): bool
    {
        return $this->console === Console::Gemini;
    }
}
