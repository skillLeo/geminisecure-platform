<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHO CURRENTLY HOLDS SUPERVISOR-OVERRIDE AUTHORITY (Q-M28, D-M129).
 *
 * THE QUESTION, AND THE CLIENT'S ANSWER. A guard whose phone cannot confirm who
 * they are — the fingerprint fails, or the handset has no biometrics — can still
 * start a shift if a supervisor types a PIN on it. The question put to the client
 * was who should be allowed to hold that PIN, by name or by role. They took the
 * recommendation: it stays with whoever the console already treats as making a
 * supervisor's decision about a guard, which is the holder of
 * `gemini.guard_workforce.update` — the same verb that approves a handset rebind,
 * suspends an officer and reassigns them (`routes/gemini/guards.php`).
 *
 * SO THE RULE DID NOT CHANGE. WHAT CHANGED IS THAT IT CAN BE READ.
 *
 * A permission nobody can enumerate is a permission nobody can review. Before
 * this, answering "who can start a shift at our gate without the guard's
 * fingerprint" meant reading the role matrix, working out which roles carry that
 * verb, then listing the users in each of them and crossing off the ones whose
 * scope does not reach the estate — four screens and an inference. The office has
 * to be able to answer it in one, because the review only happens if it is cheap.
 *
 * WHAT A ROW SAYS, AND WHAT IT DELIBERATELY DOES NOT.
 *
 *   IT SAYS: the person, their role, the estates they may vouch at, whether they
 *   have set a PIN at all, whether it is locked, when it was last used, and how
 *   many clock-ins they have authorised in the reporting window.
 *
 *   IT NEVER SAYS: the PIN, or anything a PIN could be worked back from. Only the
 *   hash is stored and this never reads it. `failed_attempts` is shown as a count
 *   because a supervisor accumulating wrong tries is the signal that somebody is
 *   guessing at their PIN on a handset, and that is exactly what a register is for.
 *
 * AUTHORITY WITHOUT A PIN IS STILL AUTHORITY, and this is the part a list of PIN
 * holders would have missed. Somebody who holds the permission and has never set
 * a PIN cannot override today — but they can set one this afternoon, from their
 * own account screen, with nobody asked. So they are on the register, marked "no
 * PIN set". The register answers "who may", not "who has".
 *
 * SCOPED LIKE EVERY OTHER CONSOLE READ. A Head of Security who covers two estates
 * sees the supervisors who can vouch at those two. `OperatorEstates` is what says
 * which estates an operator is looking at, and the demonstration estate is not one
 * of them (M5 §3).
 */
class SupervisorRegister
{
    /**
     * How far back the "overrides authorised" count reaches.
     *
     * The same fourteen days an override stays on the Head of Security's own list
     * (`SupervisorOverride::REPORT_DAYS`), so the two numbers a reviewer sees in one
     * afternoon are about the same fortnight rather than quietly about different ones.
     */
    public const WINDOW_DAYS = SupervisorOverride::REPORT_DAYS;

    /** How a PIN's standing orders (M32 F5): the two worth acting on first. */
    private const STANDING_ORDER = ['locked' => 1, 'attempts' => 2, 'no-pin' => 3, 'ready' => 4];

    /**
     * What the register orders by (M32 F5): its rows' own fields. Name order by default — it is a register.
     *
     * @return array<string, string|\Closure>
     */
    public static function sorts(): array
    {
        return [
            'name' => 'name',
            'role' => 'role',
            'pin' => static fn (array $row): int => self::STANDING_ORDER[$row['standing']] ?? 9,
            'last_used' => 'last_used_sort',
            'authorised' => 'authorised',
        ];
    }

    public static function table(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sorts(), 'name');
    }

    /**
     * Everybody who may authorise a clock-in override, as the register draws them.
     *
     * @return list<array<string, mixed>>
     */
    public function holders(User $viewer): array
    {
        $visible = $this->estatesVisibleTo($viewer);

        /*
         * `permissions` and `assignments` loaded with the roles (M11 A2): the permission check
         * below reads a person's direct permissions, and a site-scoped supervisor's estates are
         * their assignment rows — each a query per person when left to load on first use.
         */
        $candidates = User::query()
            ->where('console', Console::Gemini->value)
            ->where('status', 'active')
            ->with(['roles', 'permissions', 'assignments'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo(SupervisorOverride::PERMISSION, 'web'))
            ->values();

        $pins = DB::connection('mysql')
            ->table('supervisor_override_pins')
            ->whereIn('user_id', $candidates->pluck('id')->all())
            ->get()
            ->keyBy('user_id');

        $authorised = $this->overridesAuthorised($candidates->pluck('id')->all());

        $rows = [];

        foreach ($candidates as $supervisor) {
            $covers = $this->estatesCovered($supervisor, $visible);

            /*
             * A supervisor whose scope reaches NONE of the estates this viewer is
             * looking at is left out rather than shown with an empty list. Their
             * name and role would be a fact about a part of the platform this
             * viewer is not being shown, and the scope rule is not a decoration on
             * the estates column — it governs the row.
             */
            if ($covers === []) {
                continue;
            }

            $pin = $pins->get($supervisor->id);

            $rows[] = [
                'id' => (int) $supervisor->id,
                'name' => (string) $supervisor->name,
                'initials' => $this->initials((string) $supervisor->name),

                // The role is what CARRIES the authority; the person merely holds the role.
                'role' => $supervisor->roles->isEmpty()
                    ? 'No role assigned'
                    : (string) $supervisor->roles->first()->label,

                'covers' => $covers,
                'covers_all' => $supervisor->widestScope() === AccessScope::All,

                'pin_set' => $pin !== null,
                'pin_locked' => $pin !== null && $pin->locked_at !== null,
                'failed_attempts' => $pin === null ? 0 : (int) $pin->failed_attempts,

                'pin_set_at' => $this->stamp($pin?->set_at),
                'last_used_at' => $this->stamp($pin?->last_used_at),
                // What "Last used" orders by (M32 F5): the moment, as stored, not the words it is printed in.
                'last_used_sort' => $pin?->last_used_at === null ? null : (string) $pin->last_used_at,

                'authorised' => (int) ($authorised[$supervisor->id] ?? 0),

                /*
                 * WHAT THE OFFICE SHOULD DO ABOUT THIS ROW, in the words of what is
                 * true about it. Written here rather than in the template because
                 * the four states are a rule, not a presentation choice.
                 */
                'standing' => match (true) {
                    $pin === null => 'no-pin',
                    $pin->locked_at !== null => 'locked',
                    (int) $pin->failed_attempts > 0 => 'attempts',
                    default => 'ready',
                },
            ];
        }

        return $rows;
    }

    /**
     * The overrides each of these supervisors authorised inside the window.
     *
     * READ OFF THE SHIFTS THEMSELVES. `shifts.overridden_by` is written by
     * `SupervisorOverride::record()` at the moment the shift starts, and it is the
     * same row the audit entry and the Head of Security's mail are built from — so
     * this count cannot disagree with them. Counting a separate tally would be a
     * second version of the same fact.
     *
     * @param  list<int>  $supervisorIds
     * @return array<int, int>
     */
    private function overridesAuthorised(array $supervisorIds): array
    {
        if ($supervisorIds === []) {
            return [];
        }

        $rows = Shift::query()
            ->tap(static fn ($query) => OperatorEstates::exclude($query))
            ->whereIn('overridden_by', $supervisorIds)
            ->where('overridden_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('overridden_by, COUNT(*) as total')
            ->groupBy('overridden_by')
            ->pluck('total', 'overridden_by');

        /** @var array<int, int> $counts */
        $counts = $rows->mapWithKeys(static fn ($total, $id): array => [(int) $id => (int) $total])->all();

        return $counts;
    }

    /**
     * The estates this viewer is looking at, id => name.
     *
     * @return array<string, string>
     */
    private function estatesVisibleTo(User $viewer): array
    {
        $query = Tenant::query();
        OperatorEstates::narrow($query, $viewer, 'tenants.id');

        /** @var array<string, string> $estates */
        $estates = $query->orderBy('name')->pluck('name', 'id')->all();

        return $estates;
    }

    /**
     * Which of those estates this supervisor may actually vouch at.
     *
     * `SupervisorOverride::verify()` refuses a supervisor who cannot reach the
     * estate the shift is at, so this column is the same question that route asks,
     * answered in advance — a platform-wide role covers every estate on the list, a
     * site-scoped one covers only its assignments.
     *
     * @param  array<string, string>  $visible
     * @return list<string>
     */
    private function estatesCovered(User $supervisor, array $visible): array
    {
        if ($supervisor->widestScope() === AccessScope::All) {
            return array_values($visible);
        }

        $names = [];

        // `accessibleEstateIds()`'s rows — the active assignments — from the relation `holders()`
        // loaded for every candidate at once.
        $ids = $supervisor->relationLoaded('assignments')
            ? $supervisor->assignments->where('is_active', true)->pluck('tenant_id')->all()
            : $supervisor->accessibleEstateIds();

        foreach ($ids as $id) {
            if (isset($visible[$id])) {
                $names[] = $visible[$id];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * A stored instant as the office reads it.
     *
     * THE PLATFORM'S OWN CLOCK, NOT A SITE'S, AND THIS IS THE ONE PLACE THAT IS RIGHT.
     * Every other time in this console belongs to an estate and is rendered in that
     * estate's zone by IANA name (M4 §3.1). These two do not: when a supervisor set
     * their PIN, and when they last used it, are facts about a Gemini employee and
     * their account, spanning whatever estates they cover — there is no one site to
     * read them on. `SiteTime::local` with no estate and no estate open resolves to
     * the platform's own zone by name, which is the office's clock. Stored UTC either
     * way; nothing here is ever a fixed offset.
     */
    private function stamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SiteTime::fmt(SiteTime::local(Carbon::parse((string) $value, 'UTC'), null), 'M j, Y · g:i A');
    }

    /** Two characters, uppercase, as the boards draw an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
