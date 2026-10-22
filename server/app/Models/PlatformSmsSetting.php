<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The one SMS provider the whole platform texts through — M10 §1.
 *
 * CENTRAL, like `PlatformMailSetting` and for the same reason: under
 * `DatabaseTenancyBootstrapper` the default connection is the tenant's for the
 * length of a tenant request, and without this trait a save would write into
 * whichever estate happened to be open, where nothing would ever read it again.
 *
 * WHY IT EXISTS. There is no SMS gateway on this platform. M10 §1 wants both
 * Heads of Security notified "at the same time" by e-mail and text, and keeps
 * the household's emergency contacts on SMS — none of which can happen until
 * somebody buys an account. `AppServiceProvider` already binds `SmsGateway` from
 * a driver name with one match arm per provider, so the code path is built and
 * waiting on credentials. This puts those credentials where the Director can
 * enter them, which makes connecting Twilio a form rather than a release.
 *
 * @property int $id
 * @property string $driver
 * @property string|null $account_sid
 * @property string|null $token
 * @property string|null $from
 * @property int|null $updated_by
 * @property Carbon|null $verified_at
 */
class PlatformSmsSetting extends Model
{
    use CentralConnection;

    /** The driver that writes a message down and delivers it to nobody. */
    public const LOG = 'log';

    /** Twilio's Programmable Messaging — see `Services\ResidentApp\TwilioSmsGateway`. */
    public const TWILIO = 'twilio';

    /**
     * EVERY PROVIDER THE PLATFORM CAN ACTUALLY TEXT THROUGH, and the words the
     * settings screen uses for each.
     *
     * ONE LIST, BECAUSE A NAME NOT ON IT IS A SILENT LOG DRIVER. The binding in
     * `AppServiceProvider` sends any driver name it does not recognise to
     * `LogSmsGateway` — deliberately, so a typo cannot take the panic path down —
     * which means a row saying `vonage` would be written down and delivered to
     * nobody while the screen read "connected". So `SmsSettings::save()` refuses
     * a code that is not a key here, `canSend()` refuses one too, and the day a
     * second provider is built it is added here in the same commit as its match
     * arm.
     *
     * THE LOG LABEL SAYS WHAT IT DOES. "Log" is a developer's word; to the
     * Director choosing from this list it would read like a provider. It is the
     * absence of one, and the label says so in the sentence the rest of the
     * platform uses.
     *
     * @var array<string, string>
     */
    public const DRIVERS = [
        self::LOG => 'None — messages are written to the log and delivered to nobody',
        self::TWILIO => 'Twilio',
    ];

    protected $fillable = [
        'driver',
        'account_sid',
        'token',
        'from',
        'updated_by',
        'verified_at',
    ];

    /**
     * `encrypted` and not `hashed`: this one has to be read back.
     *
     * A token the platform must PRESENT to a provider cannot be a one-way hash,
     * unlike a user's own password which is only ever compared. So it is
     * encrypted at rest with APP_KEY and never leaves the server — the settings
     * payload reports only whether one is set.
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Hidden as well as omitted by the payload. Belt and braces, deliberately:
     * this is what protects a future caller who reaches for `toArray()`.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /**
     * Whether a text could actually be sent.
     *
     * THE `log` DRIVER IS NOT A PROVIDER. It writes the message into a log file
     * and delivers it to nobody, which is the honest default and exactly what
     * every screen must keep saying until this returns true. A settings row that
     * merely EXISTS proves nothing.
     *
     * A DRIVER THE PLATFORM HAS NO CLASS FOR IS NOT ONE EITHER. The binding sends
     * an unknown name to the log driver, so it is refused here for the same
     * reason `log` is. And Twilio authenticates as the ACCOUNT — its SID is in the
     * URL and is the username — so a Twilio row without one cannot send whatever
     * else it holds.
     */
    public function canSend(): bool
    {
        $driver = (string) $this->driver;

        if ($driver === self::LOG || ! array_key_exists($driver, self::DRIVERS)) {
            return false;
        }

        if ($driver === self::TWILIO && (string) $this->account_sid === '') {
            return false;
        }

        return (string) $this->token !== '' && (string) $this->from !== '';
    }

    /**
     * What is still missing before this row could send, in the screen's words.
     *
     * Empty on the log driver: "None" is not an unfinished provider, it is the
     * choice of none, and the note already says what that means.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        if ((string) $this->driver === self::LOG || ! array_key_exists((string) $this->driver, self::DRIVERS)) {
            return [];
        }

        return array_values(array_filter([
            $this->driver === self::TWILIO && (string) $this->account_sid === '' ? 'the account SID' : null,
            (string) $this->token === '' ? 'the auth token' : null,
            (string) $this->from === '' ? 'the sender number or ID' : null,
        ]));
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
