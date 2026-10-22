<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invite to the Resident App — board 34's, sent by the office, and a household's own, sent by its
 * primary resident. Estate database only.
 *
 * A RECORD, NOT A SIDE EFFECT. "Simone will get an SMS and email with a link to
 * download the Resident App and claim Lot 112" describes something the estate
 * has to be able to chase: a resident who never claimed their unit is the exact
 * case a property manager is asked about a fortnight later, and an estate that
 * only sent an email has nothing to answer with.
 *
 * AND IT IS SENT (`App\Services\Estate\ResidentInvites`). It used to be a row and nothing
 * else — "there is no mail driver on this path yet" — and an invited family member
 * never received anything while the screen said "Sent". `sent_at` is stamped only
 * when the mail server ACCEPTED the message; every try is counted, and the last
 * refusal is kept in words, so the screen can say what happened and offer Resend.
 *
 * @property int $id
 * @property int $resident_id
 * @property int $unit_id
 * @property string $token
 * @property string $channels
 * @property string|null $email the one address this invite is for
 * @property Carbon|null $expires_at
 * @property int|null $invited_by_resident_id the household's primary resident, when they sent it
 * @property int|null $invited_by_user_id the office, when it did (a platform user)
 * @property string|null $invited_by_name
 * @property Carbon|null $sent_at when the mail server accepted it — never when it was only asked to
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 * @property string|null $last_error the last refusal, in words; never a credential
 * @property Carbon|null $claimed_at
 * @property int|null $claim_id the unit claim its invitee's sign-in became (stage 2)
 * @property string|null $refused_reason the OFFICE-ONLY code of an address-level refusal (stage 2); never shown in the app
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resident|null $resident
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite query()
 *
 * @mixin \Eloquent
 */
class ResidentInvite extends Model
{
    /** An invite lapses: a link a household forgot about is not an open door for ever. A resend starts it again. */
    public const DAYS = 14;

    protected $fillable = [
        'resident_id',
        'unit_id',
        'token',
        'channels',
        'email',
        'expires_at',
        'invited_by_resident_id',
        'invited_by_user_id',
        'invited_by_name',
        'sent_at',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** Still standing: not claimed, not cancelled, not lapsed. */
    public function isOpen(): bool
    {
        return $this->claimed_at === null && $this->cancelled_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * WHAT A SIGN-IN MAY BE RECOGNISED BY (stage 2, W-ACCEPT): an invite that was actually tried, to an
     * address, and has neither lapsed, been claimed nor been cancelled.
     *
     * NEVER `isOpen()`, which counts a row with no `expires_at` as open for ever. Such a row is an add
     * whose send threw, or a refusal recorded on purpose with its expiry cleared, and neither is an
     * invitation anybody was sent.
     */
    public function isLive(): bool
    {
        return $this->claimed_at === null
            && $this->cancelled_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $this->email !== null
            && $this->email !== ''
            && (int) $this->attempts >= 1;
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }
}
