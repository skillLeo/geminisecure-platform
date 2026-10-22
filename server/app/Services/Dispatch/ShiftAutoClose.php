<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ShiftAutoClosed;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Push\PushAnnouncer;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * THE RULE THAT STOPS A FORGOTTEN CLOCK-OUT FOLLOWING SOMEBODY HOME (M6 §2.1 part 4).
 *
 * A guard who never clocked out is "on duty" for ever as far as this platform is
 * concerned. Two things go wrong, and the second is the dangerous one:
 *
 *   - THEY KEEP BEING WOKEN. `PushNotifier::toGuardsOnDuty` pushes every panic at
 *     that estate to whoever is clocked on. An officer who forgot to clock out on
 *     Friday is still on that list on Sunday night, at home.
 *   - AND THE FALLBACK NEVER FIRES. Worse: because the system believes somebody IS
 *     on duty, the panic ladder in `PanicFanOut` never descends to the rostered
 *     officers or to the escalation contact. One forgotten tap quietly disables the
 *     safety net for the whole site. This is the "second rule" without which the
 *     fallback decays into everybody being permanently on duty.
 *
 * FOUR HOURS PAST THE ROSTERED END, CONFIGURABLE PER SITE (`config/shifts.php`, and
 * `tenants.data.shift_auto_close_hours` for one estate). Recorded as D-M122 and put
 * to the client for confirmation as Q-M41.
 *
 * A DURATION, SO ZONE-FREE. "Four hours past a rostered end" is arithmetic on two
 * instants — `rostered_end + 4h < now` — and it is deliberately NOT expressed as a
 * time of day, which would have to be read on the estate's calendar. `SiteTime` is
 * used here only for the human sentences, where a site's clock is what a reader
 * expects.
 *
 * IT CLOSES AT THE ROSTERED END, NOT AT THE MOMENT IT NOTICED. The platform has no
 * evidence of any hour later than the one the roster promised; writing `now` into
 * `actual_end` would invent four hours of paid attendance nobody witnessed, and
 * writing the rostered end without a flag would assert the officer left exactly on
 * time. So it writes the rostered end AND flags the row, which is the honest pair:
 * "this is the last hour anyone can evidence, and nobody confirmed it".
 *
 * UNLESS THE CLOCK-ON CAME AFTER THE ROSTERED END (bug hunt B100). An offline clock-on
 * uploaded late, or an officer covering late, starts a shift after the hour its roster
 * promised — and then the clock-on, not the rostered end, is the last hour anyone can
 * evidence. This closed such a shift at its rostered end: `actual_end` before
 * `actual_start`, thirty minutes after the officer clocked on at 18:30 on a shift
 * rostered to 14:00, so they dropped off the on-duty list a panic is pushed to while
 * standing at the post, and the push, the mail and the audit entry described a close
 * at a time before they had started. So both the rule's clock and the close run from
 * the LATER of the two (`evidencedUntil()`): the shift is closed its site's hours after
 * that, and at that. A shift never ends before it began.
 *
 * TOLD TWICE, NEVER SILENT. The guard is told their shift was closed automatically
 * (`PushAnnouncer::shiftAutoClosed`), and the closure is an ATTENDANCE EXCEPTION for
 * the Head of Security — the console bell derives an item from `auto_closed_at` the
 * way it does from `overridden_at`, and a mail goes out at once.
 *
 * IDEMPOTENT. A shift with an `actual_end` is never touched again, so running this
 * hourly, twice over, or by hand costs nothing.
 */
class ShiftAutoClose
{
    /** The narrowest and widest a site may set (`hoursFor`), so a typo cannot switch the rule off. */
    public const MIN_HOURS = 1;

    public const MAX_HOURS = 72;

    /** The per-estate override, kept in stancl's `data` column so this needs no migration. */
    public const SITE_KEY = 'shift_auto_close_hours';

    public function __construct(
        private readonly ShiftClock $clock,
        private readonly AuditLogger $audit,
        private readonly PushAnnouncer $announce,
    ) {}

    /**
     * Close every shift that is more than its site's hours past the last hour anyone
     * can evidence: its rostered end, or its clock-on when that came later.
     *
     * @return array{closed: int, sites: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $closed = 0;
        $sites = $this->sitesWithOpenShifts();

        if ($sites === []) {
            return ['closed' => 0, 'sites' => 0];
        }

        /*
         * EVERY SITE'S HOURS, AND EVERY SITE'S OVERDUE SHIFTS, IN ONE READ EACH (M11 A2). This
         * read each site's hours and then its overdue shifts, site by site — a cross-estate loop
         * with two queries each, and the hours read again for every shift it closed.
         *
         * The shifts are read once for all the sites, against the LOOSEST of their cutoffs, and
         * each site then keeps exactly the rows its own cutoff would have fetched: the same two
         * comparisons, made the way MySQL made them — the stored time against the cutoff as the
         * connection bound it, moved to UTC (`UtcMySqlConnection`) and written `Y-m-d H:i:s`, to
         * the second. Sites are worked in the same order, and each site's shifts in id order, as
         * before.
         */
        $hours = $this->hoursForEach($sites);
        $format = static fn (Carbon $cutoff): string => $cutoff->copy()->utc()->format('Y-m-d H:i:s');
        $loosest = $now->copy()->subHours(min($hours));

        // max(rostered_end, actual_start) < cutoff, written as both — `evidencedUntil()`.
        $candidates = Shift::query()
            ->with(['officer', 'post', 'estate'])
            ->whereIn('tenant_id', $sites)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->where('rostered_end', '<', $loosest)
            ->where('actual_start', '<', $loosest)
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (Shift $shift): string => (string) $shift->tenant_id);

        foreach ($sites as $tenantId) {
            $cutoff = $format($now->copy()->subHours($hours[$tenantId]));

            foreach ($candidates->get($tenantId, collect()) as $shift) {
                if ((string) $shift->getRawOriginal('rostered_end') < $cutoff && (string) $shift->getRawOriginal('actual_start') < $cutoff) {
                    $closed += $this->close($shift, $now, $hours[$tenantId]) ? 1 : 0;
                }
            }
        }

        return ['closed' => $closed, 'sites' => count($sites)];
    }

    /**
     * `hoursFor()` for every one of these sites, from one read of them (M11 A2).
     *
     * @param  list<string>  $tenantIds
     * @return array<string, int>
     */
    public function hoursForEach(array $tenantIds): array
    {
        /** @var array<string, mixed> $settings each estate's own setting, by id */
        $settings = [];

        try {
            foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $estate) {
                $settings[(string) $estate->getKey()] = $estate->getAttribute(self::SITE_KEY);
            }
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }

        $hours = [];

        foreach ($tenantIds as $tenantId) {
            $hours[$tenantId] = self::clampHours($tenantId === '' ? null : ($settings[$tenantId] ?? null));
        }

        return $hours;
    }

    /**
     * How many hours past a rostered end this estate closes a shift.
     *
     * The site's own number when it has one, the platform default otherwise, always
     * inside MIN_HOURS..MAX_HOURS. A site that has written nonsense into its settings
     * gets the clamp, never a rule that never fires.
     */
    public function hoursFor(?string $tenantId): int
    {
        $site = null;

        try {
            $site = $tenantId === null || $tenantId === ''
                ? null
                : Tenant::query()->whereKey($tenantId)->first()?->getAttribute(self::SITE_KEY);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }

        return self::clampHours($site);
    }

    /** The site's own number when it is one, the platform default otherwise, inside MIN_HOURS..MAX_HOURS. */
    private static function clampHours(mixed $site): int
    {
        $hours = is_numeric($site) ? (int) $site : (int) config('shifts.auto_close_hours', 4);

        return max(self::MIN_HOURS, min(self::MAX_HOURS, $hours));
    }

    /**
     * One shift. Returns whether it was closed by this call.
     *
     * The close itself goes through `ShiftClock::clockOut`, which is the ONE place a
     * shift ends: the same row shape, the same idempotency, and the same
     * `ShiftClocked` broadcast, so the coverage board and the live map learn of an
     * automatic close exactly as they learn of a real one.
     *
     * `$hours` is the site's, when the caller has already read it (`run()`); left out, it is read here.
     */
    public function close(Shift $shift, ?Carbon $now = null, ?int $hours = null): bool
    {
        $now ??= Carbon::now();

        if ($shift->actual_start === null || $shift->actual_end !== null) {
            return false;
        }

        $hours ??= $this->hoursFor((string) $shift->tenant_id);
        $endsAt = self::evidencedUntil($shift);
        $wasStatus = (string) $shift->status;

        try {
            $this->clock->clockOut($shift, $endsAt);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return false;
        }

        $shift->forceFill(['auto_closed_at' => $now, 'auto_close_hours' => $hours])->save();

        $this->audit->record(
            action: 'shift.auto_closed',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            before: ['actual_end' => null, 'status' => $wasStatus],
            after: [
                'actual_end' => SiteTime::iso($shift->actual_end),
                'status' => $shift->status,
                'auto_close_hours' => $hours,
                'reason' => self::closedAtClockOn($shift)
                    ? 'No clock-out was recorded. Closed at the time it was clocked on, which was after its rostered end.'
                    : 'No clock-out was recorded. Closed at the rostered end.',
            ],
            tenantId: (string) $shift->tenant_id,
        );

        // Neither of these may undo the close: the row is already written.
        rescue(fn () => $this->announce->shiftAutoClosed($shift, $hours), report: false);
        $this->report($shift, $hours);

        return true;
    }

    /**
     * The last hour anyone can evidence for a shift nobody clocked out of: its rostered
     * end, or its clock-on when that came later (bug hunt B100). The rule's hours run
     * from here, and an automatic close is written here — never before the shift began.
     */
    public static function evidencedUntil(Shift $shift): Carbon
    {
        $rosteredEnd = $shift->rostered_end->copy();

        return $shift->actual_start !== null && $shift->actual_start->greaterThan($rosteredEnd)
            ? $shift->actual_start->copy()
            : $rosteredEnd;
    }

    /**
     * Whether this closed shift was closed at its CLOCK-ON rather than its rostered end —
     * read from the row as written, so every sentence about the close (the push, the
     * mail, the bell, the audit entry) says the time that is actually on it. A row closed
     * at its rostered end, including one closed that way before B100, answers false.
     */
    public static function closedAtClockOn(Shift $shift): bool
    {
        return $shift->actual_start !== null
            && $shift->actual_end !== null
            && $shift->actual_start->greaterThan($shift->rostered_end)
            && $shift->actual_end->equalTo($shift->actual_start);
    }

    /**
     * "Flagged for the Head of Security as an attendance exception."
     *
     * The bell item is derived from `auto_closed_at` by `PlatformNotifications`, so it
     * appears whether or not this mail lands; this is the one that reaches somebody
     * who is not looking at the console. Never from the demonstration estate (M5 §3).
     */
    private function report(Shift $shift, int $hours): void
    {
        rescue(function () use ($shift, $hours): void {
            if ($shift->is_simulated || OperatorEstates::isDemonstration((string) $shift->tenant_id)) {
                return;
            }

            // `canAccessEstate`'s rule, asked of every Head of Security in one read (M11 A2).
            $heads = User::whoCanAccessEstate(
                User::query()->role(Role::HEAD_OF_SECURITY, 'web')->where('status', 'active')->get(),
                (string) $shift->tenant_id,
            )

                // M10 §1.4: to a PROVEN address or not at all; the bell item and the
                // flag on the shift are written either way.
                ->filter(static fn (User $head): bool => $head->alertEmail() !== null)
                ->values();

            if ($heads->isEmpty()) {
                return;
            }

            $tenantId = (string) $shift->tenant_id;

            // Pinned to the central queue (`Support\CentralQueue`). This one runs from a console
            // command with no estate open, so the default would be right today — but it is one
            // line, and a future caller from inside an estate would otherwise lose the mail.
            Notification::send($heads, CentralQueue::pin(new ShiftAutoClosed(
                guardName: (string) ($shift->officer->full_name ?? 'An officer'),
                employeeNumber: (string) ($shift->officer->employee_number ?? '—'),
                estate: (string) ($shift->estate->name ?? $tenantId),
                post: $shift->post?->label() ?? 'a post',
                startedAt: $shift->actual_start === null ? '—' : SiteTime::stamp($shift->actual_start, $tenantId, 'M j, Y g:i A'),
                rosteredEnd: SiteTime::stamp($shift->rostered_end, $tenantId, 'M j, Y g:i A'),
                hours: $hours,
                shiftId: (int) $shift->id,
                closedAtClockOn: self::closedAtClockOn($shift),
            )));
        }, report: false);
    }

    /**
     * The estates that have a shift open right now — so the per-site hours are read
     * once each, for the handful of sites that can actually have one, rather than for
     * every estate on the platform.
     *
     * @return list<string>
     */
    private function sitesWithOpenShifts(): array
    {
        return Shift::query()
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
