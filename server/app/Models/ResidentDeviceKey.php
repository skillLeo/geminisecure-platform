<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * "Face ID / fingerprint" on one Resident App install (M49): the hash of a key the server issued to it, which signs that
 * phone back in with no emailed code. See App\Services\ResidentApp\ResidentDeviceKeys.
 *
 * @property int $id
 * @property int $resident_account_id
 * @property string $tenant_id
 * @property string $device_uid
 * @property string|null $device_hardware
 * @property string $platform
 * @property string|null $device_label
 * @property string $key_hash
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
class ResidentDeviceKey extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ResidentAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ResidentAccount::class, 'resident_account_id');
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }
}
