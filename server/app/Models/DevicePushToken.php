<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One phone's Expo push token, and whose phone it is NOW (M4 §3.2). See the
 * `create_device_push_tokens` migration for why this is central.
 *
 * THE TOKEN VALUE IS A DELIVERY ADDRESS, NOT A SECRET — but it is never logged,
 * never returned by the API and hidden from serialisation: anything holding it
 * can make that phone ring.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $principal_type
 * @property int $principal_id
 * @property string $app
 * @property int|null $access_token_id
 * @property string $expo_token
 * @property string $platform
 * @property list<string>|null $muted
 * @property string|null $quiet_from quiet hours on this phone, from HH:MM in the estate's clock (M50 §4.2 #5)
 * @property string|null $quiet_to until HH:MM — earlier than `quiet_from` when they run over midnight
 * @property Carbon $last_registered_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $ring_version the app's call-style ring contract, or null: an older app, iOS, or a report that could not be read — sent the ordinary alert every round
 * @property array<string, mixed>|null $ring_readiness the phone's own readiness report, as `RingReadiness::parse()` kept it
 * @property string|null $ring_state ready, partial or not_ready — decided by the server (`RingReadiness::state()`)
 * @property Carbon|null $ring_checked_at when the phone last reported its readiness
 * @property Carbon|null $ring_last_shown_at the phone's own last receipt that it put a ring on screen
 * @property Carbon|null $ring_proven_at a test ring the phone said it showed
 */
class DevicePushToken extends Model
{
    use CentralConnection;

    public const GUARD = 'guard';

    public const RESIDENT = 'resident';

    /** Why a push address stopped being used. Kept on the row; the row is never deleted. */
    public const SIGNED_OUT = 'signed_out';

    public const ACCESS_REVOKED = 'access_revoked';

    public const REPLACED = 'replaced';

    public const NOT_REGISTERED = 'device_not_registered';

    protected $guarded = ['id'];

    protected $hidden = ['expo_token'];

    protected function casts(): array
    {
        return [
            'muted' => 'array',
            'last_registered_at' => 'datetime',
            'revoked_at' => 'datetime',
            'ring_version' => 'integer',
            'ring_readiness' => 'array',
            'ring_checked_at' => 'datetime',
            'ring_last_shown_at' => 'datetime',
            'ring_proven_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * One principal's phones IN ONE ESTATE. The estate is part of the key on every
     * read, so a push raised in one estate can never find another estate's phone.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, string $tenantId, string $principalType, int $principalId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('principal_type', $principalType)->where('principal_id', $principalId);
    }

    /** Whether this phone has switched this category off. `null` is a type that cannot be switched off. */
    public function mutes(?string $category): bool
    {
        return $category !== null && in_array($category, $this->muted ?? [], true);
    }

    /**
     * Whether `$local` — the estate's own clock — falls in this phone's quiet hours. From is inside, until is not:
     * 22:00–07:00 is quiet at 22:00 and at 06:59, and not at 07:00. Hours that run over midnight wrap.
     */
    public function quietAt(Carbon $local): bool
    {
        if ($this->quiet_from === null || $this->quiet_to === null || $this->quiet_from === $this->quiet_to) {
            return false;
        }

        $now = $local->format('H:i');

        return $this->quiet_from < $this->quiet_to
            ? $now >= $this->quiet_from && $now < $this->quiet_to
            : $now >= $this->quiet_from || $now < $this->quiet_to;
    }

    /**
     * The phone's `quiet` report, read LENIENTLY — as `ring` is: a doubtful value must never refuse a registration
     * (no token stored means no push at all, a panic's included), so anything unreadable is simply no quiet hours.
     *
     * @return array{quiet_from: string|null, quiet_to: string|null}
     */
    public static function quietFrom(mixed $quiet): array
    {
        $none = ['quiet_from' => null, 'quiet_to' => null];

        if (! is_array($quiet) || ($quiet['on'] ?? true) === false) {
            return $none;
        }

        $from = is_string($quiet['from'] ?? null) ? trim($quiet['from']) : '';
        $to = is_string($quiet['to'] ?? null) ? trim($quiet['to']) : '';
        $clock = '/^([01]\d|2[0-3]):[0-5]\d$/';

        return preg_match($clock, $from) === 1 && preg_match($clock, $to) === 1 && $from !== $to
            ? ['quiet_from' => $from, 'quiet_to' => $to]
            : $none;
    }
}
