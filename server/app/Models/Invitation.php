<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An invitation to hold a role on this platform. Always central.
 *
 * Accounts are issued and never self-created, and this is the issuing: it
 * names the inviter, the estate (null for Gemini staff), the role, and expires
 * fourteen days after it was sent (12 §2, Wave 1). The token is the whole of
 * the credential until it is accepted, so it is random, unique and never shown
 * on a screen the inviter reads.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property int $invited_by
 * @property int $role_id
 * @property string|null $tenant_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $sent_at the last send the mail server ACCEPTED; null when none has
 * @property int $attempts every try, first send and resends alike, counted as it begins; 0 only on a row written before delivery was recorded
 * @property Carbon|null $last_attempt_at when the last try began (a resend waits a minute from it)
 * @property string|null $last_error the last refusal, in words; null once a send goes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property-read Role $role
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invitation query()
 *
 * @mixin \Eloquent
 */
class Invitation extends Model
{
    use CentralConnection;

    /** How long an invitation stands, as ruled. */
    public const DAYS = 14;

    protected $fillable = [
        'email',
        'token',
        'invited_by',
        'role_id',
        'tenant_id',
        'expires_at',
        'accepted_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            // Written only by `Invitations`, with forceFill: whether an email went is the mailer's to say.
            'sent_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** Still open: not accepted, and not past its fortnight. */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
