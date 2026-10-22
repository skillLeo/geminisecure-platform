<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ONE EMAIL, ONE ALERT, ONE CONTACT — and what the mail server did with it (M12 §2.5).
 *
 * `AlertSmsDispatch`'s sibling, on its rules: WRITTEN AFTER THE ATTEMPT, so a row is a fact and never an intention;
 * APPEND-ONLY (a withheld grant and two triggers); the contact copied on as the relationship the resident gave and
 * the address MASKED — a private person's address stays in the estate's database. `sent` means the mail server
 * ACCEPTED it, and nothing weaker: the inbox is downstream of anything the platform can know.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property string $audience `household`
 * @property int|null $household_emergency_contact_id the ESTATE database's contact id
 * @property string $label
 * @property string $address masked
 * @property string $status `sent`, `failed` or `no_address`
 * @property string|null $error
 * @property int $attempts
 * @property Carbon $attempted_at
 * @property Carbon|null $created_at
 *
 * @mixin \Eloquent
 */
class AlertEmailDispatch extends Model
{
    use CentralConnection;

    public const HOUSEHOLD = 'household';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NO_ADDRESS = 'no_address';

    public const UPDATED_AT = null;

    protected $table = 'alert_email_dispatches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'duress_alert_id' => 'integer',
            'household_emergency_contact_id' => 'integer',
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    /** "an***@gm***.com" — enough for a dispatcher to tell two addresses apart, never enough to write to. */
    public static function mask(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');

        if ($at === false) {
            return mb_substr($address, 0, 2).'***';
        }

        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);
        $dot = strrpos($domain, '.');
        $name = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 2).'***@'.mb_substr($name, 0, 2).'***'.$tld;
    }
}
