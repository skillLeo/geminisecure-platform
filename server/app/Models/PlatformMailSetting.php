<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The one relay the whole platform sends from.
 *
 * CENTRAL, like every other platform-wide record. Under `DatabaseTenancyBootstrapper` the
 * default connection is swapped to the tenant database for the length of a tenant request, and
 * without this trait an estate console saving mail settings would write them into that estate's
 * own database — where nothing would ever read them again. The same reasoning as `Role`.
 *
 * @property int $id
 * @property string $host
 * @property int $port
 * @property string|null $scheme
 * @property string|null $username
 * @property string|null $password
 * @property string $from_address
 * @property string $from_name
 * @property int|null $updated_by
 * @property Carbon|null $verified_at
 */
class PlatformMailSetting extends Model
{
    use CentralConnection;

    protected $fillable = [
        'host',
        'port',
        'scheme',
        'username',
        'password',
        'from_address',
        'from_name',
        'updated_by',
        'verified_at',
    ];

    /**
     * `encrypted` and not `hashed`: this one has to be read back.
     *
     * A password the platform must PRESENT to a relay cannot be a one-way hash — unlike a
     * user's own password, which is only ever compared. So it is encrypted at rest with APP_KEY
     * and never leaves the server: `MailSettings::shape()` omits it and reports only whether
     * one is set, and no interface returns it.
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Hidden as well as omitted by the shape.
     *
     * Belt and braces, deliberately: `shape()` is the one place that is meant to build the
     * payload, and this is what protects a future caller who reaches for `toArray()` instead.
     *
     * @var list<string>
     */
    protected $hidden = ['password'];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
