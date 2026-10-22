<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ONE ALERT'S RING: when it began, how far it may go, and when it was answered.
 *
 * Written by `App\Services\Ring\GuardRing` and nothing else. CENTRAL, and the only way
 * this table is touched (G5): a round queued from inside the `/api/v1/duress` request
 * runs with that estate open, and a query on the default connection would reach an
 * estate database that has no such table.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property bool $is_drill
 * @property int $max_round
 * @property int $officers_at_raise
 * @property int $handsets_at_raise
 * @property Carbon $started_at
 * @property Carbon|null $answered_at
 * @property string|null $answered_why `RingRule` — cancelled, closed, responding or acknowledged
 * @property Carbon|null $stop_sent_at
 * @property int|null $stop_handsets
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class DuressAlertRing extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_drill' => 'boolean',
            'max_round' => 'integer',
            'officers_at_raise' => 'integer',
            'handsets_at_raise' => 'integer',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'stop_sent_at' => 'datetime',
            'stop_handsets' => 'integer',
        ];
    }
}
