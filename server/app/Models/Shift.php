<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SiteDateTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One guard, one post, one window of time.
 *
 * The Build Spec's `shift` entity. Central alongside guards and posts, because
 * rostering is a Gemini operation spanning every client rather than something
 * each estate does for itself.
 *
 * ROSTERED AND ACTUAL ARE KEPT APART. The roster is a plan; the device reports
 * what happened. A coverage board that showed only the plan would report a post
 * as manned all night when nobody ever arrived, and that is the single failure
 * the board exists to catch.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $guard_id
 * @property int $post_id
 * @property string $duty `post` (stands the post) or `patrol` (a patrol that starts and clocks in at the post, M27)
 * @property Carbon $rostered_start
 * @property Carbon $rostered_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property string|null $start_method
 * @property int|null $geofence_distance_m
 * @property bool $mock_location_flag
 * @property bool $is_simulated
 * @property string $status
 * @property string|null $released_reason
 * @property int|null $posted_by_id
 * @property string|null $posted_by_name
 * @property string|null $handover_note what the guard left for the next shift (13 D2)
 * @property int|null $overridden_by the console user whose PIN started this shift (M4 §3.4), or null
 * @property string|null $override_reason `biometric_failed`, `no_biometrics`, `outside_geofence` or `position_unconfirmed`
 * @property float|null $clock_in_latitude the fix the start was judged on (M12 §1)
 * @property float|null $clock_in_longitude
 * @property int|null $clock_in_accuracy_m how sure the phone was of it, in metres
 * @property string|null $geofence_source `post`, `estate` (the post had none) or `none` (neither had one)
 * @property string|null $geofence_outcome `inside`, `inside_margin`, `outside`, `poor_fix`, `no_position` or `no_geofence`
 * @property int|null $geofence_radius_m the fence's radius at that moment
 * @property Carbon|null $clock_in_device_at the phone's own time of the tap, as sent
 * @property int|null $clock_offset_s the server's clock minus the phone's, on the request that brought it
 * @property list<string>|null $review_reasons what a supervisor must look at (ShiftClock::REVIEW_*), or null
 * @property Carbon|null $overridden_at
 * @property Carbon|null $auto_closed_at when the platform closed this shift because nobody clocked out (M6 §2.1)
 * @property int|null $auto_close_hours the hours-past-rostered-end in force at that moment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Post|null $post
 * @property-read Tenant|null $estate
 *
 * @method static Builder<static>|Shift covering(Carbon $moment)
 * @method static Builder<static>|Shift newModelQuery()
 * @method static Builder<static>|Shift newQuery()
 * @method static Builder<static>|Shift query()
 *
 * @mixin \Eloquent
 */
class Shift extends Model
{
    use CentralConnection;

    /** A shift nobody turned up for, or abandoned. */
    public const SETTLED = ['completed', 'missed'];

    /**
     * A post that needs somebody, and nobody yet (12 §2, Wave 5).
     *
     * It is a real row with a real window, so the coverage board shows the gap
     * — nobody clocked in because nobody was rostered, which is exactly what it
     * should say. A placeholder guard would instead show somebody standing a
     * gate that is empty.
     */
    public const OPEN = 'open';

    public const ROSTERED = 'rostered';

    /** The shift stands its post: a gate officer. */
    public const DUTY_POST = 'post';

    /**
     * THE SHIFT IS A PATROL THAT STARTS AT ITS POST (M27, D-225). The client: "their patrol would start at the main
     * gate", in a vehicle, across five phases. Clocked in at the post exactly as a gate officer is, inside the post's
     * own geofence; the patrol itself is evidenced by its checkpoint scans and the on duty position trail, because no
     * circle holds a vehicle across an estate. It never counts as the post being stood.
     */
    public const DUTY_PATROL = 'patrol';

    public const DUTIES = [self::DUTY_POST, self::DUTY_PATROL];

    protected $fillable = [
        'tenant_id',
        'guard_id',
        'post_id',
        'duty',
        'rostered_start',
        'rostered_end',
        'actual_start',
        'actual_end',
        'start_method',
        'geofence_distance_m',
        'mock_location_flag',
        'status',
        'released_reason',
        'posted_by_id',
        'posted_by_name',
    ];

    /**
     * ONE TIME RULE (M4 §3.1). Stored true UTC; read on the clock of the estate the shift is
     * rostered at, so `->format('g:i A')` prints what the office typed and `->hour` is the
     * site's hour. A string with no offset written to one of these is a wall-clock time at
     * that estate. Send them to a phone through `SiteTime::iso()`.
     */
    protected function casts(): array
    {
        return [
            'rostered_start' => SiteDateTime::class.':tenant_id',
            'rostered_end' => SiteDateTime::class.':tenant_id',
            'actual_start' => SiteDateTime::class.':tenant_id',
            'actual_end' => SiteDateTime::class.':tenant_id',
            // When a supervisor's PIN stood in for the phone's identity check (M4 §3.4). Null otherwise.
            'overridden_at' => SiteDateTime::class.':tenant_id',

            /*
             * When the platform closed this shift itself, because nobody clocked out
             * (M6 §2.1 part 4, `Services\Dispatch\ShiftAutoClose`). Null on every shift
             * a person ended. `actual_end` on such a row is the ROSTERED end — the last
             * hour anyone can evidence — and this flag is what says so, which is the
             * difference between an attendance exception and a silent correction.
             */
            'auto_closed_at' => SiteDateTime::class.':tenant_id',
            'auto_close_hours' => 'integer',
            'mock_location_flag' => 'boolean',
            'is_simulated' => 'boolean',

            // What the start was judged on (M12 §1; ClockInFence): the fix, the fence, the outcome, the phone's clock.
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_in_accuracy_m' => 'integer',
            'geofence_radius_m' => 'integer',
            'clock_in_device_at' => SiteDateTime::class.':tenant_id',
            'clock_offset_s' => 'integer',
            'review_reasons' => 'array',
        ];
    }

    /**
     * The estate first. A wall-clock string is read in the zone of `tenant_id`, and a cast is
     * applied as each attribute is set — so the estate has to be on the model before a time is.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes)
    {
        if (array_key_exists('tenant_id', $attributes)) {
            $attributes = ['tenant_id' => $attributes['tenant_id']] + $attributes;
        }

        return parent::fill($attributes);
    }

    /**
     * The guard standing this shift.
     *
     * Named `officer` rather than `guard` because Eloquent already has a
     * `guard()` — the mass-assignment one, `Model::guard(array $guarded)` —
     * and a relation of that name is a fatal signature clash rather than an
     * override. The foreign key is still guard_id.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** A patrol: the patrol duty, or a shift on a post that is itself a patrol route (the older shape). */
    public function isPatrol(): bool
    {
        return ($this->duty ?? self::DUTY_POST) === self::DUTY_PATROL || ($this->post?->isPatrolRoute() ?? false);
    }

    /**
     * The shift's post as the roster, the duty board and the Guard App name it (M26, M27): the post as the estate
     * knows it, and "· patrol" when the shift patrols from it — "Main Gate · patrol", "Back Gate (Gate 5, Phase 5
     * Gate)". The clock-in still names the post alone, because that is the fence the officer stands in.
     */
    public function postLabel(): string
    {
        $post = $this->post?->label() ?? 'Post';

        return ($this->duty ?? self::DUTY_POST) === self::DUTY_PATROL ? $post.' · patrol' : $post;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Shifts whose rostered window contains a moment.
     *
     * Half open on purpose: 7 PM belongs to the night shift and not to the day
     * shift that ends at it, so a post is never reported as doubly manned at
     * the handover minute.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, Carbon $moment): Builder
    {
        return $query->where('rostered_start', '<=', $moment)
            ->where('rostered_end', '>', $moment);
    }

    /**
     * Is the guard actually on this shift right now?
     *
     * Rostered is not present. A shift that was never started reads as a gap,
     * which is what it is.
     */
    public function isUnderway(): bool
    {
        return $this->actual_start !== null && $this->actual_end === null;
    }

    /**
     * THE SHIFT THIS GUARD IS CLOCKED IN TO NOW, or null (Hassam, 12 October 2026; D-232): started and not ended, on
     * any post. The only shift a position may be kept on. Live tracking runs from clock-in to clock-out, and a guard
     * with no open shift is off duty, so where they are is not the company's to hold. The newest, should a fault ever
     * leave two open.
     */
    public static function openFor(int $guardId): ?self
    {
        return self::query()->where('guard_id', $guardId)->whereNotNull('actual_start')->whereNull('actual_end')
            ->orderByDesc('actual_start')->first();
    }

    /**
     * THE LATEST MOMENT THIS GUARD WAS ON DUTY (M22 §1.3; `SessionWindow`): `$now` while a shift of theirs is under
     * way, otherwise the clock-out of the most recent one started in the last day, or null. A guard on shift stays
     * signed in for the shift, and after it the idle window runs from the clock-out. A shift nobody closes is closed by
     * `ShiftAutoClose`, so "under way" never lasts for ever.
     */
    public static function onDutyUntil(int $guardId, ?CarbonInterface $now = null): ?CarbonInterface
    {
        // Compared with the stored columns, which are true UTC.
        $now = ($now ?? Carbon::now())->copy()->utc();

        $latest = self::query()->where('guard_id', $guardId)->whereNotNull('actual_start')
            ->where('actual_start', '>', $now->copy()->subDay())->where('actual_start', '<=', $now)
            ->orderByRaw('actual_end IS NULL DESC')->orderByDesc('actual_end')
            ->first(['id', 'tenant_id', 'actual_start', 'actual_end']);

        if ($latest === null) {
            return null;
        }

        return $latest->actual_end === null ? $now : $latest->actual_end->copy()->utc();
    }

    /**
     * Which half of the day this shift is rostered for.
     *
     * Derived from the start rather than stored, so a roster change cannot
     * leave a column disagreeing with the hours beside it. Day is 7 AM to
     * 7 PM, the two windows the coverage board draws.
     */
    public function window(): string
    {
        return $this->rostered_start->hour >= 7 && $this->rostered_start->hour < 19
            ? 'day'
            : 'night';
    }
}
