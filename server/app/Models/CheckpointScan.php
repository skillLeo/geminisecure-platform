<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Proof that a guard physically reached a checkpoint.
 *
 * Device time is kept beside server time and flagged when they disagree, never
 * rewritten to look tidy — a scan recorded from a handset with a wrong clock is
 * still evidence, and rewriting it destroys the only trace that it happened.
 *
 * @property int $id
 * @property int $patrol_checkpoint_id
 * @property int $guard_id
 * @property int|null $shift_id
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $accuracy_m
 * @property int|null $distance_m
 * @property bool $early
 * @property Carbon|null $device_time
 * @property Carbon $server_time
 * @property bool $clock_skewed
 * @property bool $captured_offline
 * @property string|null $idempotency_key
 * @property bool $is_simulated
 * @property-read PatrolCheckpoint|null $checkpoint
 * @property-read Guard|null $officer
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan query()
 *
 * @mixin \Eloquent
 */
class CheckpointScan extends Model
{
    use CentralConnection;

    /** The table carries an event time of its own; created_at would duplicate it. */
    public $timestamps = false;

    /**
     * WHEN THE CHECKPOINT WAS REACHED, as SQL over this table (D-192). A scan taken with no signal reaches the server
     * when signal returns: its `server_time` is the SYNC, and the guard's tour showed a round walked at 1:49 as
     * "Scanned 2:02" (the M11 §1.7 airplane test, 26 Sep). So a scan captured offline on a clock the server trusts
     * (`clock_skewed` false: a device clock is only suspect when it runs AHEAD) is placed at its `device_time`; every
     * other scan at `server_time`, as before. Both columns are kept as sent; this only chooses which one to show.
     */
    public const REACHED_AT_SQL = 'CASE WHEN captured_offline = 1 AND clock_skewed = 0 AND device_time IS NOT NULL THEN device_time ELSE server_time END';

    protected $fillable = [
        'patrol_checkpoint_id',
        'guard_id',
        'shift_id',
        'latitude',
        'longitude',
        'accuracy_m',
        'distance_m',
        'early',
        'device_time',
        'server_time',
        'clock_skewed',
        'captured_offline',
        'idempotency_key',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'device_time' => 'datetime',
            'server_time' => 'datetime',
            'clock_skewed' => 'boolean',
            'captured_offline' => 'boolean',
            'is_simulated' => 'boolean',
            'early' => 'boolean',
            'accuracy_m' => 'integer',
            'distance_m' => 'integer',
        ];
    }

    /** When the checkpoint was reached — REACHED_AT_SQL's rule, for a row already loaded. */
    public function reachedAt(): Carbon
    {
        return $this->captured_offline && ! $this->clock_skewed && $this->device_time !== null ? $this->device_time : $this->server_time;
    }

    /** @return BelongsTo<PatrolCheckpoint, $this> */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(PatrolCheckpoint::class, 'patrol_checkpoint_id');
    }

    /**
     * `officer`, not `guard`: Eloquent's own `Model::guard(array $guarded)`
     * already holds that name, and a relation there is a fatal clash.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }
}
