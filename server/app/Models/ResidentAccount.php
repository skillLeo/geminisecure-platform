<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A Resident App sign-in (13 D1, D3). See the `create_resident_accounts` migration.
 *
 * Like a guard, a principal that holds no role and can open no console: the
 * abilities on its token are the whole of what it can do, and a pending account's
 * token reaches only its own claim.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $destination
 * @property string|null $full_name
 * @property int|null $resident_id
 * @property int|null $unit_id
 * @property int|null $claim_id
 * @property string $status
 * @property bool $reviewer an App Store / Play reviewer's demo account (M5 §3)
 * @property string|null $reviewer_code_hash their STATIC sign-in code, hashed
 * @property Carbon|null $verified_at
 * @property Carbon|null $deleted_at when the account was deleted — a STATE it can come back from (M18H), not an erasure
 * @property string|null $deleted_reference what the person was given to quote about it
 * @property string|null $deleted_by `resident` or `office`
 * @property string|null $deleted_by_name the officer, when the office deleted it
 * @property string|null $status_before_delete what a restore puts back
 * @property Carbon|null $removed_register_at the register removal this deletion made, and when
 * @property Carbon|null $restored_at
 * @property string|null $restored_by
 * @property Carbon|null $anonymised_at the day nothing of the person was left on this row (after the window, or permanently)
 * @property string|null $anonymise_reason
 * @property Carbon|null $revoked_at the estate office ended this account's access (Q-M44)
 * @property string|null $revoked_reason
 * @property string|null $revoked_by
 * @property string|null $device_uid
 * @property string|null $device_platform
 * @property bool $notify_email "Email" on board 20: the news a push carries, by email too (M50 §4.2 #4) — off by default
 * @property list<string>|null $notify_email_muted the categories switched off, sent with that switch
 * @property Carbon|null $drill_mode_until while ahead, this account's Panic is a DRILL that wakes nobody (M53 §3A.1); set only by `panic:drill-mode`
 * @property string|null $drill_mode_reason why it was switched on, said on the phone and kept in the audit log
 * @property string|null $drill_mode_by who switched it on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 */
class ResidentAccount extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use CentralConnection;
    use HasApiTokens;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /**
     * THE ESTATE OFFICE ENDED THIS ACCOUNT'S ACCESS (Q-M44).
     *
     * Distinct from `suspended`, which is what the household's own primary resident
     * causes by removing a member. `revoked` is the OFFICE acting: the person has
     * moved out, they are no longer a resident of that unit, and at the gate they are
     * a visitor like anybody else. It is dated, it carries a reason and a name, and
     * `RevokeResidentAccess::restore()` can take it back.
     */
    public const REVOKED = 'revoked';

    /**
     * DELETED, AND STILL COMING BACK (M18H §1). Signing in, the e-pass, the passes it issued, the notifications — all
     * stopped; nothing of the person erased. The person restores it themselves in the first days, the estate office after
     * that; once the estate's window has closed `AccountDeletion::anonymise()` leaves nothing of them on the row, and the
     * status becomes `suspended` with `anonymised_at` set — an account that, to anybody signing in, never existed.
     */
    public const DELETED = 'deleted';

    /**
     * `reviewer` and `reviewer_code_hash` ARE NEVER MASS-ASSIGNABLE (M5 §3).
     *
     * That pair is the whole of reviewer mode on this side: together they let an account past the
     * static-code check, and on the Guard App's half past the geofence, the device binding and the
     * biometric check as well. Granting them is a deliberate act — `demo:seed` and the tests set
     * them with `forceFill`, where somebody had to mean it — and this list is what keeps a later
     * `fill()` of a validated form from ever granting them by accident. `guards` is safe already:
     * it uses an explicit `$fillable` that names neither.
     */
    protected $guarded = ['id', 'reviewer', 'reviewer_code_hash'];

    protected function casts(): array
    {
        // `deleted_at` was read as a string, so asking twice to delete an account threw on `->toIso8601String()`.
        return ['verified_at' => 'datetime', 'deleted_at' => 'datetime', 'revoked_at' => 'datetime', 'removed_register_at' => 'datetime', 'restored_at' => 'datetime', 'anonymised_at' => 'datetime', 'reviewer' => 'boolean', 'notify_email' => 'boolean', 'notify_email_muted' => 'array', 'drill_mode_until' => 'datetime'];
    }

    /** Deleted and not yet anonymised: it can still come back (M18H). */
    public function isDeleted(): bool
    {
        return $this->status === self::DELETED && $this->deleted_at !== null && $this->anonymised_at === null;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->resident_id !== null && $this->unit_id !== null;
    }

    /** The estate office ended this account's access, and only the office can give it back. */
    public function isRevoked(): bool
    {
        return $this->status === self::REVOKED;
    }
}
