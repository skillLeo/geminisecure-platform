<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One message on an open panic, between the resident who raised it and the control room (M50 §4.2 #2). See the
 * `a_panic_carries_messages_between_the_resident_and_dispatch` migration. Never edited, never deleted.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property string $author `resident` or `dispatch`
 * @property int|null $resident_account_id
 * @property int|null $user_id the dispatcher who wrote it
 * @property string|null $author_name what the other side is shown: the dispatcher's first name, or the resident's name
 * @property string $body
 * @property string|null $client_key the phone's own id for it — one row however many times a phone sends it
 * @property Carbon|null $seen_at when the other side was shown it
 * @property Carbon $created_at
 */
class DuressAlertMessage extends Model
{
    use CentralConnection;

    public const RESIDENT = 'resident';

    public const DISPATCH = 'dispatch';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /** @return BelongsTo<DuressAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(DuressAlert::class, 'duress_alert_id');
    }
}
