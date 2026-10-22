<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One message on Live chat, between a resident and Gemini Security's control room (M50 §4.3). Never edited, never
 * deleted.
 *
 * @property int $id
 * @property int $support_chat_id
 * @property string $author `resident` or `staff`
 * @property int|null $user_id the member of staff who wrote it
 * @property string|null $author_name what the other side is shown: the staff member's first name, or the resident's name
 * @property string $body
 * @property string|null $client_key the phone's own id for it — one row however many times a phone sends it
 * @property Carbon|null $seen_at when the other side was shown it
 * @property Carbon $created_at
 */
class SupportChatMessage extends Model
{
    use CentralConnection;

    public const RESIDENT = 'resident';

    public const STAFF = 'staff';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /** @return BelongsTo<SupportChat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(SupportChat::class, 'support_chat_id');
    }
}
