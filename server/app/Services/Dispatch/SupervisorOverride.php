<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\Console;
use App\Models\Guard;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\SupervisorOverrideUsed;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * A supervisor's PIN standing in for the phone's identity check at clock-in (M4 §3.4).
 *
 * "Build it. Server sets and verifies the PIN. Every use is logged, flags the shift
 * record, and is reported to the Head of Security."
 *
 * WHO A SUPERVISOR IS. There is no role of that name: in this console the people who
 * decide things about a guard — approve a handset rebind, suspend, reassign — are the
 * ones holding `gemini.guard_workforce.update`, and `routes/gemini/guards.php` calls
 * exactly that "a supervisor's decision". So a supervisor here is an ACTIVE Gemini
 * Console user who holds that permission AND may reach the estate the shift is at — a
 * Head of Security scoped to two sites cannot vouch for a guard at a third.
 *
 * THE PIN NEVER EXISTS IN THE CLEAR ON THE SERVER. It arrives, is compared against a
 * password hash (`Hash::check` → `password_verify`, constant time), and is gone. It is
 * not in the audit entry, not in a log line, not in the idempotency record (the route
 * is `opaque`: the stored request hash covers the path only — a SHA-256 of six digits
 * is six digits).
 *
 * ONE REFUSAL FOR EVERYTHING THAT IS NOT "YES". An address nobody has, somebody who is
 * not a supervisor, a supervisor of other sites, no PIN set, a locked PIN and a wrong
 * PIN all answer `false` from `verify()`, after the same amount of hashing — the phone
 * cannot be used to find out which addresses are supervisors or how close a guess was.
 *
 * FIVE WRONG TRIES LOCK THE PIN until its owner sets a new one in the console. The
 * counter is per supervisor, not per phone, so spreading guesses across handsets does
 * not help; the route is also throttled per handset.
 */
class SupervisorOverride
{
    public const PERMISSION = 'gemini.guard_workforce.update';

    public const PIN_DIGITS = 6;

    public const MAX_FAILED_ATTEMPTS = 5;

    public const REASON_BIOMETRIC_FAILED = 'biometric_failed';

    public const REASON_NO_BIOMETRICS = 'no_biometrics';

    /**
     * WHERE, as well as WHO (M12 §1.3). A guard standing outside the rostered post's geofence — or whose phone could
     * not establish a position at all — may be started by a supervisor who vouches that they are on post. Logged and
     * flagged exactly as an identity override is, and reported to the Head of Security the same way, with the
     * distance and the accuracy the phone saw.
     */
    public const REASON_OUTSIDE_GEOFENCE = 'outside_geofence';

    public const REASON_POSITION_UNCONFIRMED = 'position_unconfirmed';

    public const REASONS = [self::REASON_BIOMETRIC_FAILED, self::REASON_NO_BIOMETRICS, self::REASON_OUTSIDE_GEOFENCE, self::REASON_POSITION_UNCONFIRMED];

    /** The reasons about where the guard is, rather than who. */
    public const LOCATION_REASONS = [self::REASON_OUTSIDE_GEOFENCE, self::REASON_POSITION_UNCONFIRMED];

    /**
     * `shifts.start_method` for a shift started this way — the value the column was
     * designed with ("biometric | supervisor_pin ... A PIN start is always recorded,
     * never hidden").
     */
    public const START_METHOD = 'supervisor_pin';

    /** How long a used override stays on the Head of Security's list. */
    public const REPORT_DAYS = 14;

    /**
     * What an unknown supervisor's PIN is compared against, so that "nobody by that
     * address" costs the same hash as "wrong PIN". Built once per process, from
     * nothing anybody could type.
     */
    private static ?string $decoyHash = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /** May this console user vouch for a guard at all? Decides whether the console offers the PIN form. */
    public function isSupervisor(User $user): bool
    {
        return $user->status === 'active'
            && $user->console === Console::Gemini
            && $user->checkPermissionTo(self::PERMISSION, 'web');
    }

    /**
     * What the console shows a supervisor about their own PIN. Never the PIN, never the hash.
     *
     * @return array{is_set: bool, locked: bool, set_at: string|null, last_used_at: string|null, failed_attempts: int}
     */
    public function status(User $user): array
    {
        $row = DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $user->id)->first();

        return [
            'is_set' => $row !== null,
            'locked' => $row !== null && $row->locked_at !== null,
            'set_at' => $row === null ? null : Carbon::parse((string) $row->set_at)->toIso8601String(),
            'last_used_at' => $row === null || $row->last_used_at === null ? null : Carbon::parse((string) $row->last_used_at)->toIso8601String(),
            'failed_attempts' => $row === null ? 0 : (int) $row->failed_attempts,
        ];
    }

    /**
     * Set — or rotate — a supervisor's own PIN. Setting one clears a lock: that is the
     * only way a locked PIN comes back.
     */
    public function setPin(User $user, string $pin): void
    {
        if (! $this->isSupervisor($user)) {
            throw new InvalidArgumentException('Only a supervisor has an override PIN.');
        }

        if (preg_match('/^\d{'.self::PIN_DIGITS.'}$/', $pin) !== 1) {
            throw new InvalidArgumentException('An override PIN is exactly '.self::PIN_DIGITS.' digits.');
        }

        $now = Carbon::now();
        $existed = DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $user->id)->exists();

        DB::connection('mysql')->table('supervisor_override_pins')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'pin_hash' => Hash::make($pin),
                'failed_attempts' => 0,
                'locked_at' => null,
                'set_at' => $now,
                'updated_at' => $now,
                ...($existed ? [] : ['created_at' => $now]),
            ],
        );

        // That it was set, by whom and when. Never what it is.
        $this->audit->record(
            action: $existed ? 'user.override_pin_rotated' : 'user.override_pin_set',
            entityType: 'User',
            entityId: (string) $user->id,
        );
    }

    /**
     * Is this the PIN of a supervisor who may vouch at this estate?
     *
     * Returns the supervisor, or null — and null means every kind of "no" at once.
     */
    public function verify(string $identifier, string $pin, string $tenantId): ?User
    {
        $supervisor = User::query()->where('email', mb_strtolower(trim($identifier)))->first();

        $eligible = $supervisor !== null
            && $this->isSupervisor($supervisor)
            && $supervisor->canAccessEstate($tenantId);

        $row = $eligible
            ? DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $supervisor->id)->first()
            : null;

        // Always one hash comparison, whoever was named: a missing supervisor must not answer faster than a wrong PIN.
        $matches = Hash::check($pin, $row->pin_hash ?? self::decoy());

        // A PIN row is only ever read for an eligible supervisor, so none means "no" — and a
        // row means `$supervisor` is that supervisor.
        if ($row === null) {
            return null;
        }

        if ($row->locked_at !== null) {
            return null;
        }

        if (! $matches) {
            $this->recordFailure($supervisor, $tenantId);

            return null;
        }

        DB::connection('mysql')->table('supervisor_override_pins')->where('id', $row->id)->update([
            'failed_attempts' => 0,
            'last_used_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $supervisor;
    }

    /**
     * Flag the shift, write the audit entry and tell the Head of Security. Called once
     * the shift has started; never for a shift that had already started some other way.
     */
    public function record(Shift $shift, User $supervisor, Guard $guard, string $reason): Shift
    {
        $shift->forceFill([
            'overridden_by' => $supervisor->id,
            'override_reason' => $reason,
            'overridden_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            action: 'shift.supervisor_override',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            before: ['actual_start' => null],
            after: [
                'actual_start' => SiteTime::iso($shift->actual_start),
                'start_method' => $shift->start_method,
                'override_reason' => $reason,
                'overridden_by' => $supervisor->id,
                'overridden_by_name' => $supervisor->name,
                'guard' => $guard->full_name,
                'geofence_distance_m' => $shift->geofence_distance_m,
                'geofence_source' => $shift->geofence_source,
                'geofence_outcome' => $shift->geofence_outcome,
                'accuracy_m' => $shift->clock_in_accuracy_m,
                'mock_location_flag' => (bool) $shift->mock_location_flag,
            ],
            tenantId: (string) $shift->tenant_id,
        );

        $this->report($shift, $supervisor, $guard, $reason);

        return $shift;
    }

    /**
     * "Reported to the Head of Security."
     *
     * Two ways, both the console's own: the bell (`PlatformNotifications` derives an
     * item from the flag on the shift, scoped to the sites a Head of Security covers)
     * and a mail to every active Head of Security who covers this estate. Telling them
     * must never undo the clock-in, so a mail that cannot be sent is reported and the
     * shift stays started — the bell and the audit entry are already written.
     */
    private function report(Shift $shift, User $supervisor, Guard $guard, string $reason): void
    {
        /*
         * NOT FROM THE DEMONSTRATION ESTATE (M5 §3).
         *
         * An override there is somebody exercising the review walk, and this
         * would put a mail headed "Supervisor override at clock-in" in front of
         * every Head of Security who covers every estate — about an officer who
         * does not exist, at a gate that does not exist, at whatever hour the
         * store happened to look. The clock-in, the audit entry and the flag on
         * the shift are all still written; what is withheld is waking somebody.
         */
        if (OperatorEstates::isDemonstration((string) $shift->tenant_id)) {
            return;
        }

        rescue(static function () use ($shift, $supervisor, $guard, $reason): void {
            // `canAccessEstate`'s rule, asked of every Head of Security in one read (M11 A2) — this
            // runs inside POST /shifts/{shift}/clock-in/override.
            $heads = User::whoCanAccessEstate(
                User::query()->role(Role::HEAD_OF_SECURITY, 'web')->where('status', 'active')->get(),
                (string) $shift->tenant_id,
            )

                // M10 §1.4: an alert goes to a PROVEN address or not at all. A Head
                // of Security who has not proven one still has the bell item.
                ->filter(static fn (User $head): bool => $head->alertEmail() !== null)
                ->values();

            if ($heads->isEmpty()) {
                return;
            }

            /*
             * PINNED TO THE CENTRAL QUEUE (`Support\CentralQueue`), and this is the whole of
             * the "reported to the Head of Security" promise. This runs inside
             * `POST /api/v1/shifts/{shift}/clock-in/override`, where `ResolveDevice` has opened
             * the ESTATE and tenancy has made its database the default — which has no `jobs`
             * table. Left to the default, the mail was written nowhere, threw, was rescued
             * below, and no Head of Security was ever told of an override. Every other queued
             * send from inside an estate is pinned the same way.
             */
            Notification::send($heads, CentralQueue::pin(new SupervisorOverrideUsed(
                guardName: $guard->full_name,
                employeeNumber: $guard->employee_number,
                estate: (string) ($shift->estate->name ?? $shift->tenant_id),
                post: $shift->post === null ? 'a post' : $shift->postLabel(),
                supervisorName: $supervisor->name,
                reason: $reason,
                startedAt: $shift->actual_start === null ? '' : SiteTime::stamp($shift->actual_start, (string) $shift->tenant_id, 'M j, Y g:i A'),
                distanceMetres: $shift->geofence_distance_m,
                mockLocation: (bool) $shift->mock_location_flag,
                shiftId: (int) $shift->id,
                accuracyMetres: $shift->clock_in_accuracy_m,
            )));
        });

        /*
         * PUSH — CALL SITE FOR THE ARCHITECT (M4 §3.2). `App\Services\Push` reaches
         * PHONES (a guard's handset, a Resident App account); a Head of Security is a
         * console user and has no push address, so nothing is sent from here today.
         * If console staff are ever given one, this is the line:
         *
         *     app(\App\Services\Push\...)->toUsers($heads, 'supervisor_override', $shift->id);
         */
    }

    /**
     * One more wrong try against this supervisor's PIN, and the lock if that was the fifth.
     *
     * COUNTED IN THE DATABASE, NOT IN PHP. It used to read `failed_attempts`, add one in PHP and
     * write the sum back — so two wrong guesses arriving together both read 3 and both wrote 4,
     * and a burst of tries cost the attacker one. The lock is the ONLY thing standing between a
     * six-digit PIN and somebody with time, so it is not allowed to lose count: the increment is
     * now a single statement the database does to itself, and `LEAST(…, 255)` keeps it inside the
     * unsigned byte the column is.
     *
     * The lock is then claimed with `whereNull('locked_at')`, so whichever request crosses the
     * line first writes it and the audit entry is written EXACTLY ONCE however many arrived
     * together. Semantics are unchanged: five wrong tries lock the PIN until its owner sets a new
     * one, and `setPin()` is still the only way back.
     */
    private function recordFailure(User $supervisor, string $tenantId): void
    {
        $now = Carbon::now();
        $pins = DB::connection('mysql')->table('supervisor_override_pins');

        (clone $pins)->where('user_id', $supervisor->id)->update([
            'failed_attempts' => DB::raw('LEAST(failed_attempts + 1, 255)'),
            'updated_at' => $now,
        ]);

        $attempts = (int) (clone $pins)->where('user_id', $supervisor->id)->value('failed_attempts');

        if ($attempts < self::MAX_FAILED_ATTEMPTS) {
            return;
        }

        $locked = (clone $pins)->where('user_id', $supervisor->id)->whereNull('locked_at')->update([
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($locked === 1) {
            // The supervisor finds their PIN locked the next time they need it; the log says when and at which estate.
            $this->audit->record(
                action: 'user.override_pin_locked',
                entityType: 'User',
                entityId: (string) $supervisor->id,
                after: ['failed_attempts' => $attempts],
                tenantId: $tenantId,
            );
        }
    }

    private static function decoy(): string
    {
        return self::$decoyHash ??= Hash::make(bin2hex(random_bytes(16)));
    }
}
