<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The periodic challenge that confirms a guard is awake and well.
 *
 * AN OUTCOME AND AN EVENT TIME, and nothing else. The guard answers with a
 * tap; no camera, image, template, landmark set or score exists anywhere in
 * this system, so none can be leaked or subpoenaed.
 *
 * `declined` is a first-class outcome. Refusing the check degrades monitoring
 * and is recorded as such — it never blocks duty, because a guard who says no
 * to check-ins is still a guard standing a post.
 *
 * @property int $id
 * @property int $guard_id
 * @property string $outcome
 * @property Carbon|null $device_time
 * @property Carbon $server_time
 * @property bool $is_simulated
 * @property-read Guard|null $officer
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertnessCheck newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertnessCheck newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AlertnessCheck query()
 *
 * @mixin \Eloquent
 */
class AlertnessCheck extends Model
{
    use CentralConnection;

    /** Outcomes that mean the guard did not answer the challenge. */
    public const UNANSWERED = ['missed', 'failed'];

    public $timestamps = false;

    protected $fillable = [
        'guard_id',
        'outcome',
        'device_time',
        'server_time',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'device_time' => 'datetime',
            'server_time' => 'datetime',
            'is_simulated' => 'boolean',
        ];
    }

    /**
     * `officer`, not `guard`: Eloquent's `Model::guard(array $guarded)` already
     * holds that name and a relation there is a fatal signature clash.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /** The board's phrasing for the "last challenge response" cell. */
    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            'pending' => 'awaiting response',
            'passed' => 'responded',
            'missed' => 'no response',
            'failed' => 'failed',
            default => 'declined',
        };
    }
}
