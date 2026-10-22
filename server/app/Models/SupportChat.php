<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One resident's conversation with Gemini Security on Live chat (M50 §4.3) — see the
 * `a_resident_writes_to_gemini_security_on_live_chat` migration. One per account; resolved by the control room, opened
 * again by the resident's next message.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $resident_account_id
 * @property string $status `open` or `resolved`
 * @property Carbon|null $last_message_at
 * @property Carbon|null $waiting_since the resident's first message the control room has not answered yet — null when answered
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property Carbon $created_at
 */
class SupportChat extends Model
{
    use CentralConnection;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'waiting_since' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<ResidentAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ResidentAccount::class, 'resident_account_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<SupportChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportChatMessage::class);
    }
}
