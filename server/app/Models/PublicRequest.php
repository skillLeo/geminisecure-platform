<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A request sent from a PUBLIC page, with no sign-in: delete my account, or a
 * message to support. Central database. See the migration for why a deletion
 * request deletes nothing by itself.
 *
 * @property int $id
 * @property string $kind
 * @property string|null $app
 * @property string|null $estate
 * @property string $full_name
 * @property string $contact
 * @property string|null $message
 * @property string $status
 * @property string $reference
 */
class PublicRequest extends Model
{
    // CENTRAL, always: the person may not know which estate they are on, and the in-app deletion runs
    // with an estate open — without this the model would look for the table in the estate's database.
    use CentralConnection;

    public const ACCOUNT_DELETION = 'account_deletion';

    public const SUPPORT = 'support';

    public const RECEIVED = 'received';

    protected $fillable = ['kind', 'app', 'estate', 'full_name', 'contact', 'message', 'status', 'reference'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    /** "GS-7K2M9Q" — short enough to read out on the phone, random enough not to be guessed in sequence. */
    public static function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = 'GS-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::query()->where('reference', $code)->exists());

        return $code;
    }
}
