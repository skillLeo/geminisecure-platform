<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A point on a patrol route that has to be physically reached and scanned.
 *
 * The sequence is the tour order, and it is what makes "checkpoint 4 of 6"
 * answerable: a tour is not a set of tags, it is an ordered walk, and a guard
 * who scanned the last one first has not walked it.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $post_id the older shape: a route hanging from a post; null is the estate's own route (M27)
 * @property string $label
 * @property string|null $phase the phase it stands in, printed on its tag (M27 §4.2)
 * @property string $code
 * @property int $sequence
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $radius_m
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Post|null $post
 * @property-read Collection<int, CheckpointScan> $scans
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint query()
 *
 * @mixin \Eloquent
 */
class PatrolCheckpoint extends Model
{
    use CentralConnection;

    /**
     * How long a checkpoint may go unscanned before dispatch is told.
     *
     * The Build Spec calls a fifteen-minute cadence typical on night posts;
     * this is that cadence plus a grace period, so a guard held up by a
     * legitimate call-through is not reported as missing.
     */
    public const CADENCE_MINUTES = 15;

    public const GRACE_MINUTES = 10;

    /**
     * How close a phone must be to a placed checkpoint for its scan to count, when the checkpoint names no radius of
     * its own (M21 §1.1). A tag hangs on a wall; a phone beside it is sure to within 20–60 m indoors, and the fence
     * judge adds up to 50 m of that as margin (ClockInFence), so 25 m refuses the gatehouse and never the guard.
     */
    public const DEFAULT_RADIUS_M = 25;

    /** A tag's code: this prefix and twelve characters no guard could guess (the tag is the proof). */
    public const CODE_PREFIX = 'GSCP-';

    protected $fillable = [
        'tenant_id',
        'post_id',
        'label',
        'phase',
        'code',
        'sequence',
        'is_active',
        'latitude',
        'longitude',
        'radius_m',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'radius_m' => 'integer'];
    }

    /** A code for a new tag: unguessable, unambiguous to read aloud (no 0/O, 1/I/L), unique across the platform. */
    public static function newCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        do {
            $code = self::CODE_PREFIX;
            for ($i = 0; $i < 12; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * THE CHECKPOINTS OF A GUARD'S TOUR TODAY (M21 §1.1): the route of the shift they are on, when checkpoints hang
     * from its post — an estate with two routes gives each officer theirs — or else every active checkpoint at the
     * site, as before (an estate whose checkpoints were seeded onto a gate). One rule, so the Patrol tab, the scan's
     * count and the shift summary cannot count three different tours ("2 of 2" on one screen, "2/11" on the next).
     *
     * TWO READS, WHATEVER THE ANSWER (M22, AppApiQueryBudgetTest). The route and the site's list were two queries,
     * and a post with no route of its own asked the second after the first came back empty — three in all, which put
     * the Patrol tab over its budget. A site has tens of checkpoints, so the site's active ones are read once and the
     * route is picked from them, in the same order.
     *
     * THE ESTATE'S OWN ROUTE (M27 §4). Checkpoints that belong to no post are the estate's patrol route, the one a
     * patrol shift tours from whichever gate it starts at. A PATROL shift tours that route first; a shift standing a
     * post tours its post's own checkpoints first. Either falls back to the other, then to every checkpoint at the site.
     *
     * @return list<int>
     */
    public static function tourFor(int $guardId, string $site): array
    {
        $shift = Shift::query()->where('guard_id', $guardId)->where('tenant_id', $site)
            ->whereNotNull('actual_start')->whereNull('actual_end')
            ->orderByDesc('actual_start')->first(['post_id', 'duty']);

        $active = self::query()->where('tenant_id', $site)->where('is_active', true)
            ->orderBy('sequence')->orderBy('id')->get(['id', 'post_id']);

        return self::routeOf($active, $shift?->post_id === null ? null : (int) $shift->post_id, ($shift->duty ?? Shift::DUTY_POST) === Shift::DUTY_PATROL)
            ->map(static fn (self $c): int => (int) $c->id)->values()->all();
    }

    /**
     * The tour, picked from a site's active checkpoints (in their order): the one rule `tourFor()` and the alertness
     * board share, so the Guard App and dispatch never count two different tours.
     *
     * @param  SupportCollection<int, self>  $active
     * @return SupportCollection<int, self>
     */
    public static function routeOf(SupportCollection $active, ?int $postId, bool $patrol): SupportCollection
    {
        $own = $postId === null ? $active->take(0) : $active->filter(static fn (self $c): bool => (int) $c->post_id === $postId && $c->post_id !== null);
        $estate = $active->filter(static fn (self $c): bool => $c->post_id === null);

        foreach ($patrol ? [$estate, $own] : [$own, $estate] as $route) {
            if ($route->isNotEmpty()) {
                return $route->values();
            }
        }

        return $active->values();
    }

    /**
     * HOW LONG AFTER ONE CHECKPOINT THE NEXT IS DUE ON THIS ESTATE (M27 §4.4): the Director's setting in Sites &
     * geofences, else the platform's fifteen minutes. A guard walking a building reaches the next tag in minutes; a
     * vehicle covering five phases, stopping where it must, does not, and a cadence set for the first would show the
     * second as late all night. The alertness board, a scan's "early" rule and its next due time all read this.
     */
    public static function cadenceFor(string $estateId): int
    {
        $minutes = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)->value('patrol_interval_minutes');

        return $minutes === null ? self::CADENCE_MINUTES : max(1, (int) $minutes);
    }

    /** The radius a scan is judged against. */
    public function radius(): int
    {
        return (int) ($this->radius_m ?? self::DEFAULT_RADIUS_M);
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return HasMany<CheckpointScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(CheckpointScan::class);
    }
}
