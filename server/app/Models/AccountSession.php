<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One place a console account is signed in (M32 F9), for My account's "Where you are signed in".
 *
 * Written when a session is made by signing in (`KeepAccountSession`), touched at most every fifteen minutes, and
 * deleted when it is signed out. `session_key` is the session's id, encrypted: ending that one session needs it, and
 * a copy of this table must not be a way into anybody's. `session_hash` finds the row without decrypting every one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_hash
 * @property string $session_key
 * @property string $console
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountSession extends Model
{
    protected $connection = 'mysql';

    protected $fillable = ['user_id', 'session_hash', 'session_key', 'console', 'user_agent', 'ip_address', 'last_seen_at'];

    protected $hidden = ['session_key', 'session_hash'];

    protected function casts(): array
    {
        return [
            'session_key' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** How a session's id is found in this table. */
    public static function hashOf(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
