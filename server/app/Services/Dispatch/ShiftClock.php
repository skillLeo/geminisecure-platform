<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Events\ShiftClocked;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Services\Tracking\GuardSignal;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Clocking on and off a post, from a handset.
 *
 * The other half of what makes the Gemini console live: `AlertIntake` records
 * what goes wrong at an estate, `GateLog` records who comes in, and this records
 * whether anybody was there at all. `PostCoverage` and the dispatch map read
 * `shifts.actual_start`; until something wrote it, every post on the live map
 * was rostered and none was manned.
 *
 * BOTH TRANSITIONS ARE IDEMPOTENT AND NEITHER OVERWRITES AN EARLIER ONE, for
 * `AlertIntake::acknowledge()`'s reason: the first clock-in is the one that
 * happened. A double tap, a retry from a tunnel, or a handset syncing a queue it
 * captured offline must not rewrite when somebody arrived — that timestamp is
 * what a shift is paid against and what a coverage report is built from.
 *
 * A MOCK LOCATION IS RECORDED, NEVER REFUSED. A guard whose handset is
 * reporting a spoofed position is a serious thing, and the instinct is to
 * reject the clock-in. That would be wrong twice over: it would leave a post
 * reading as unmanned when somebody is standing at it, and it would tell
 * whoever spoofed the location that they had been caught. The shift starts, the
 * flag is set, and it surfaces where it belongs — in front of a supervisor.
 *
 * THE GEOFENCE IS DECIDED BEFORE THIS IS CALLED (M12 §1, ClockInFence; it was deferred until then, D-033). This
 * records what it was decided on — the fix, its accuracy, which fence, the outcome — so a start can always be
 * accounted for, and it writes what a supervisor must look at into `review_reasons`: a mocked location, a queued
 * start that the server judged outside when it arrived, a post with no geofence configured, a phone whose clock
 * disagrees with the server's or was changed while the act waited. Each of those is audited, and the Head of
 * Security sees it on the bell (PlatformNotifications). Nothing here refuses: the refusal is the caller's.
 */
class ShiftClock
{
    /** Started from the handset at the post, which is the ordinary way. */
    public const METHOD_APP = 'app';

    /** Started by a supervisor on the guard's behalf. */
    public const METHOD_MANUAL = 'manual';

    /** The phone reported a mocked location. Never refused alone; always in front of the Head of Security. */
    public const REVIEW_MOCK_LOCATION = 'mock_location';

    /** A start queued with no signal that the server, when it arrived, judged outside the fence. */
    public const REVIEW_OUTSIDE_AT_CAPTURE = 'outside_at_capture';

    /** The post, and the estate, have no geofence: where the guard was could not be checked (D-178). */
    public const REVIEW_NO_GEOFENCE = 'no_geofence';

    /** The phone's clock was off from the server's by more than the tolerance when the start was sent. */
    public const REVIEW_CLOCK_DISAGREES = 'clock_disagrees';

    /** The phone's clock was changed between the tap and the send (its wall clock and its steady clock disagree). */
    public const REVIEW_CLOCK_CHANGED = 'clock_changed';

    /**
     * Each reason in the words the console prints.
     *
     * @param  list<string>  $reasons
     */
    public static function reviewWords(array $reasons, ?int $offsetSeconds = null): string
    {
        $words = [
            self::REVIEW_MOCK_LOCATION => 'the phone reported a MOCKED location',
            self::REVIEW_OUTSIDE_AT_CAPTURE => 'sent after a spell with no signal and judged outside the geofence when it arrived',
            self::REVIEW_NO_GEOFENCE => 'no geofence configured, so where the guard was could not be checked',
            self::REVIEW_CLOCK_DISAGREES => 'the phone’s clock was '.($offsetSeconds === null ? 'off' : self::minutesOff($offsetSeconds)).' from the server’s',
            self::REVIEW_CLOCK_CHANGED => 'the phone’s clock was changed while the clock-in waited to be sent',
        ];

        return implode(' · ', array_map(static fn (string $r): string => $words[$r] ?? $r, $reasons));
    }

    private static function minutesOff(int $seconds): string
    {
        $minutes = (int) round(abs($seconds) / 60);

        return ($minutes < 1 ? abs($seconds).' s' : $minutes.' min').($seconds > 0 ? ' slow' : ' fast');
    }

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record that a guard has arrived and started work.
     *
     * Returns the shift unchanged if it already carries a start. That is not an
     * error: a handset with a queued clock-in reconnects and sends it again,
     * and answering with a failure would show a guard that their arrival had
     * not registered when it had.
     */
    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy_m?: int|null, verdict?: array<string, mixed>|null, device_at?: CarbonInterface|null, offset_s?: int|null, review?: list<string>}  $evidence
     *                                                                                                                                                                                                                    what the start was judged on (M12 §1)
     */
    public function clockIn(
        Shift $shift,
        ?CarbonInterface $at = null,
        string $method = self::METHOD_APP,
        ?int $geofenceDistanceMetres = null,
        bool $mockLocation = false,
        bool $isSimulated = false,
        array $evidence = [],
    ): Shift {
        if ($shift->actual_start !== null) {
            return $shift;
        }

        $verdict = $evidence['verdict'] ?? null;
        $review = array_values(array_unique([
            ...($evidence['review'] ?? []),
            ...($mockLocation ? [self::REVIEW_MOCK_LOCATION] : []),
            ...(($verdict['outcome'] ?? null) === ClockInFence::NO_GEOFENCE ? [self::REVIEW_NO_GEOFENCE] : []),
        ]));
        $distance = $geofenceDistanceMetres ?? (isset($verdict['distance_m']) ? (int) $verdict['distance_m'] : null);

        $shift->forceFill([
            'actual_start' => $at ?? Carbon::now(),
            'status' => 'on_duty',
            'start_method' => $method,
            // `shifts.geofence_distance_m` is an unsigned small integer.
            'geofence_distance_m' => $distance === null ? null : min($distance, 65535),
            'mock_location_flag' => $mockLocation,

            // Whether the simulator clocked this guard on, so the coverage
            // board can say its posts were manned by nobody real.
            'is_simulated' => $isSimulated,

            'clock_in_latitude' => isset($evidence['latitude']) ? round((float) $evidence['latitude'], 7) : null,
            'clock_in_longitude' => isset($evidence['longitude']) ? round((float) $evidence['longitude'], 7) : null,
            'clock_in_accuracy_m' => isset($evidence['accuracy_m']) ? min((int) $evidence['accuracy_m'], 65535) : null,
            'geofence_source' => $verdict['source'] ?? null,
            'geofence_outcome' => $verdict['outcome'] ?? null,
            'geofence_radius_m' => $verdict['radius_m'] ?? null,
            'clock_in_device_at' => $evidence['device_at'] ?? null,
            'clock_offset_s' => $evidence['offset_s'] ?? null,
            'review_reasons' => $review === [] ? null : $review,
        ])->save();

        /*
         * Audited only when something about it is worth a supervisor's
         * attention. A guard clocking on at their own post from their own
         * handset is the system working, and logging four hundred of those a
         * week would bury the two that matter. What is worth it: a manual start, anything for review, and a start
         * judged against the ESTATE's fence because the post had none — M12 §1.1 asks that the record say so.
         */
        $fellBack = ($verdict['source'] ?? null) === ClockInFence::SOURCE_ESTATE;

        if ($review !== [] || $method === self::METHOD_MANUAL || $fellBack) {
            $this->audit->record(
                action: 'shift.clocked_in',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                before: ['actual_start' => null],
                after: [
                    'actual_start' => SiteTime::iso($shift->actual_start),
                    'start_method' => $method,
                    'mock_location_flag' => $mockLocation,
                    'geofence_source' => $verdict['source'] ?? null,
                    'geofence_outcome' => $verdict['outcome'] ?? null,
                    'geofence_radius_m' => $verdict['radius_m'] ?? null,
                    'distance_m' => $distance,
                    'accuracy_m' => $evidence['accuracy_m'] ?? null,
                    'judged_against' => match ($verdict['source'] ?? null) {
                        ClockInFence::SOURCE_POST => 'the rostered post’s own geofence',
                        ClockInFence::SOURCE_ESTATE => 'the ESTATE’s geofence, as the rostered post has none of its own',
                        ClockInFence::SOURCE_NONE => 'nothing: neither the post nor the estate has a geofence configured',
                        default => null,
                    },
                    'clock_offset_s' => $evidence['offset_s'] ?? null,
                    'review_reasons' => $review,
                ],
                tenantId: $shift->tenant_id,
            );
        }

        $this->announce($shift, ShiftClocked::IN);

        return $shift;
    }

    /**
     * Record that a guard has finished.
     *
     * REFUSED IF THEY NEVER STARTED, and this one is a real refusal rather than
     * a quiet return. A shift with an end and no beginning is a row nothing can
     * interpret: it cannot be paid, cannot be counted as coverage, and would
     * appear on a roster as a guard who left without arriving. The handset has
     * a queued clock-in to send first.
     */
    public function clockOut(Shift $shift, ?CarbonInterface $at = null, ?string $handoverNote = null): Shift
    {
        if ($shift->actual_start === null) {
            throw new DomainException(
                'This shift has no start to end. A clock-out without a clock-in is a shift nobody can '.
                'pay or count as coverage. The handset has an arrival still to send.'
            );
        }

        if ($shift->actual_end !== null) {
            return $shift;
        }

        $end = $at ?? Carbon::now();

        /*
         * REFUSED IF IT WOULD END BEFORE IT BEGAN (bug hunt B100), for the same reason:
         * an `actual_end` before the `actual_start` is a shift of negative length that
         * nothing can pay or count. `ShiftAutoClose` wrote exactly that for a shift clocked
         * on after its rostered end; it now closes at `evidencedUntil()`, and this is what
         * stops the next caller that names an end from writing one.
         */
        if ($end->lessThan($shift->actual_start)) {
            throw new DomainException(
                'A shift cannot end before it began: it was clocked on at '.$shift->actual_start->toIso8601String().
                ' and the end given is '.$end->toIso8601String().'.'
            );
        }

        $shift->forceFill([
            'actual_end' => $end,
            'status' => 'completed',

            // What the next guard on this post should know (13 D2, board guard-app-05).
            'handover_note' => $handoverNote === null || trim($handoverNote) === '' ? null : trim($handoverNote),
        ])->save();

        /*
         * A No signal alert on this shift ends with it (GuardSignal), so the dispatcher's queue stops saying a guard who
         * has gone home is silent the moment they go, not at the watch's next minute. Never at the cost of the clock-out:
         * if this fails, the watch clears it within the minute.
         */
        rescue(static fn () => app(GuardSignal::class)->ended((int) $shift->id));

        $this->announce($shift, ShiftClocked::OUT);

        return $shift;
    }

    /**
     * Tell the dispatch screens (13 C1), and never let that fail the clock.
     *
     * The arrival is recorded before this runs. If Reverb is down, the push is
     * lost, the failure is reported, and the handset still gets its 201 — the
     * screens are in fallback polling in exactly that case, so they catch up on
     * their own. A guard told their clock-in failed because a websocket server
     * was restarting would clock in twice, or not at all.
     */
    private function announce(Shift $shift, string $direction): void
    {
        rescue(static fn () => ShiftClocked::dispatch((string) $shift->tenant_id, (int) $shift->id, $direction, (bool) $shift->is_simulated));
    }
}
