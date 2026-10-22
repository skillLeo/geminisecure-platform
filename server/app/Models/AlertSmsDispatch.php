<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\ResidentApp\SmsResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ONE TEXT MESSAGE, ONE ALERT, ONE NUMBER — and what actually happened to it.
 *
 * WRITTEN AFTER THE ATTEMPT AND NEVER BEFORE, so a row here is always a fact and never
 * an intention. If the worker dies before it runs, there are no rows and
 * `duress_alerts.sms_delivered` stays null — which the apps read as "not finished",
 * exactly as they already read a null `alerted_handsets`. A `queued` row that nobody
 * ever came back to update would be a record that lies by omission.
 *
 * APPEND-ONLY, by a grant and by two triggers. This is the record of who was told that
 * somebody pressed an emergency button, and it must read the same in a year.
 *
 * THE RECIPIENT IS COPIED ONTO THE ROW, not joined. The estate may change its four
 * numbers tomorrow; who was told last night must not change with it.
 *
 * TWO AUDIENCES, ONE LEDGER (M10 §1.2 item 7). `audience` says whose text this was:
 *
 *   SITE        one of the estate's own emergency numbers (Q-M09) — an office or a
 *               role, published by the estate. `number` is what the office typed and
 *               `e164` its diallable form, both in full.
 *   HOUSEHOLD   one of the resident's own nominated emergency contacts — a PRIVATE
 *               person. `label` is the relationship the resident gave ("sister"),
 *               `number` is MASKED and `e164` is always null: the central ledger needs
 *               to say who was told, not to hold a stranger's phone number, which stays
 *               in the estate's database where the resident put it. Never a name.
 *   STAFF       one of the site's two Heads of Security (M10 §1.2 items 4 and 5) — a
 *               console user, by `user_id`. `role` and `label` say which Head of
 *               Security; `number` is MASKED and `e164` null, the household rule, because
 *               the phone is the person's own and stays on their account. Never a name.
 *
 * Every count built on this table filters by audience first, so a sister's text can
 * never be read back as one of the estate's four, or the other way round.
 *
 * `deduplicated` IS A STAFF ROW'S FIFTH WORD, and it is neither an attempt nor a
 * delivery: a Head of Security whose number is already being texted for this alert —
 * as one of the site's numbers, or as another Head of Security's — is written down and
 * NOT texted twice. `site_emergency_number_id` then names the site number that covered it.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property string $audience `site`, `household`, `staff` or `escalation`
 * @property int|null $site_emergency_number_id
 * @property int|null $household_emergency_contact_id the ESTATE database's contact id, for a household row
 * @property int|null $user_id the Head of Security texted, for a staff row
 * @property string $role
 * @property string $label
 * @property string $number
 * @property string|null $e164
 * @property string $status `sent`, `recorded`, `failed` or `unroutable` — see SmsResult — or, on a staff row, `deduplicated`
 * @property string $driver
 * @property string|null $reference
 * @property string|null $error
 * @property Carbon $attempted_at
 * @property Carbon|null $created_at
 *
 * @mixin \Eloquent
 */
class AlertSmsDispatch extends Model
{
    use CentralConnection;

    /** The estate's own emergency numbers (Q-M09). The column's default. */
    public const SITE = 'site';

    /** The resident's nominated emergency contacts (M10 §1.2 item 7). */
    public const HOUSEHOLD = 'household';

    /** The site's two Heads of Security (M10 §1.2 items 4 and 5). */
    public const STAFF = 'staff';

    /**
     * The estate's own Head of Security, texted again by the ring's escalation (round 1)
     * because nobody answered, or nobody was on duty (the guard ring §3.4). An audience of
     * its own, so the raise's `staff` totals on the alert are never moved by it, and a
     * follow-up is never read back as one of the texts sent at the raise.
     */
    public const ESCALATION = 'escalation';

    /** Not texted twice: the same number is already being texted for this alert. */
    public const DEDUPLICATED = 'deduplicated';

    /** Append-only: `created_at` is the database's own default and there is no `updated_at`. */
    public $timestamps = false;

    protected $fillable = [
        'duress_alert_id',
        'tenant_id',
        'audience',
        'site_emergency_number_id',
        'household_emergency_contact_id',
        'user_id',
        'role',
        'label',
        'number',
        'e164',
        'status',
        'driver',
        'reference',
        'error',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * True only when a provider ACCEPTED the message.
     *
     * The one method every count is built on — see `SmsResult` for why `recorded` is
     * not a delivery and must never be counted as one.
     */
    public function delivered(): bool
    {
        return $this->status === SmsResult::SENT;
    }
}
