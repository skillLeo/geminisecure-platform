<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A panic, duress, medical, fire or intrusion alert.
 *
 * Raised on a mobile device and consumed by the Gemini Console. Central,
 * because one dispatcher watches every estate at once.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> active()
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $kind
 * @property bool $from_panic_button raised with the panic or duress BUTTON — urgent whatever `kind` it was labelled (M10 §1.3, bug hunt B086)
 * @property int|null $guard_id
 * @property string|null $raised_by_name
 * @property string|null $unit_reference
 * @property string $status
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property Carbon|null $device_time
 * @property Carbon $server_time
 * @property bool $clock_skewed
 * @property bool $captured_offline
 * @property string|null $idempotency_key
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $dispatched_at when the control room SENT an officer (M10 §1.3) — the first moment "help is on the way" is true
 * @property int|null $dispatched_by the console user who sent them
 * @property int|null $responding_guard_id the officer sent, or the officer on shift who took it from their own handset when nobody was sent
 * @property Carbon|null $en_route_at when that officer was recorded as on the way
 * @property int|null $en_route_recorded_by the console user who recorded it after a radio call; NULL means the officer's own handset
 * @property Carbon|null $on_scene_at when that officer was recorded as at the scene
 * @property int|null $on_scene_recorded_by as `en_route_recorded_by`
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property string|null $resolution_classification one of App\Support\AlertClassification's keys; null for alerts resolved before 24 September 2026 and for a handset's own cancel
 * @property bool $is_simulated
 * @property bool $is_drill a real alert raised to prove the fan-out (`panic:walk`): labelled DRILL wherever it renders and left out of every response-time figure (M10 §1.3)
 * @property bool $drill_mode raised while the office had drill mode on for the resident's account (M53 §3A.1): a DRILL that wakes nobody, see wakesNobody()
 * @property int|null $alerted_handsets how many security handsets the alert was queued to (M6 §2.1); null until the fan-out finished
 * @property string|null $alerted_via `on_duty`, `rostered`, `escalation` or `none`
 * @property int|null $escalated_contacts how many people were told off-app (the site's escalation contacts, e-mailed on every panic since M10 §1)
 * @property int|null $fanout_ms how long the fan-out itself took, in milliseconds. The PLATFORM's own share — every push and mail QUEUED, contacts resolved — and never delivery to a phone (M10 §1)
 * @property int|null $sms_recipients how many of the site's emergency numbers this alert was texted to (Q-M09)
 * @property int|null $sms_delivered how many of those texts a provider ACCEPTED; null until the texting finished
 * @property int|null $raised_by_account_id the Resident App account that raised it — on the row FROM CREATION, so the panic's own fan-out can find the household (M10 §1.2 item 7)
 * @property int|null $household_sms_recipients how many of the household's nominated emergency contacts this alert is texting (at most `HouseholdContactsSms::CAP`); null on an officer's duress
 * @property int|null $household_sms_delivered how many of those a provider ACCEPTED; null until the texting finished, and on an officer's duress
 * @property int|null $household_sms_over_cap how many further contacts were on file and NOT texted because of the cap
 * @property int|null $household_email_recipients how many of the household's contacts this alert is emailing (M12 §2.5); null on an officer's duress
 * @property int|null $staff_sms_recipients how many of the site's Heads of Security this alert is texting (M10 §1.2 items 4 and 5), after the dedupe
 * @property int|null $staff_sms_delivered how many of those a provider ACCEPTED; null until the texting finished
 * @property int|null $staff_sms_no_phone Heads of Security on file with no phone to text
 * @property int|null $staff_sms_deduplicated Heads of Security not texted twice, because their number is already being texted for this alert
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Guard|null $raisedByGuard
 * @property-read Collection<int, DuressAlertDelivery> $deliveries
 * @property-read Guard|null $respondingGuard
 *
 * @method static Builder<static>|DuressAlert active()
 * @method static Builder<static>|DuressAlert panics()
 * @method static Builder<static>|DuressAlert incidents()
 * @method static Builder<static>|DuressAlert newModelQuery()
 * @method static Builder<static>|DuressAlert newQuery()
 * @method static Builder<static>|DuressAlert query()
 * @method static Builder<static>|DuressAlert whereAcknowledgedAt($value)
 * @method static Builder<static>|DuressAlert whereAcknowledgedBy($value)
 * @method static Builder<static>|DuressAlert whereCapturedOffline($value)
 * @method static Builder<static>|DuressAlert whereClockSkewed($value)
 * @method static Builder<static>|DuressAlert whereCreatedAt($value)
 * @method static Builder<static>|DuressAlert whereDeviceTime($value)
 * @method static Builder<static>|DuressAlert whereGuardId($value)
 * @method static Builder<static>|DuressAlert whereId($value)
 * @method static Builder<static>|DuressAlert whereIdempotencyKey($value)
 * @method static Builder<static>|DuressAlert whereIsSimulated($value)
 * @method static Builder<static>|DuressAlert whereKind($value)
 * @method static Builder<static>|DuressAlert whereLatitude($value)
 * @method static Builder<static>|DuressAlert whereLongitude($value)
 * @method static Builder<static>|DuressAlert whereRaisedByName($value)
 * @method static Builder<static>|DuressAlert whereResolutionNote($value)
 * @method static Builder<static>|DuressAlert whereResolvedAt($value)
 * @method static Builder<static>|DuressAlert whereServerTime($value)
 * @method static Builder<static>|DuressAlert whereStatus($value)
 * @method static Builder<static>|DuressAlert whereTenantId($value)
 * @method static Builder<static>|DuressAlert whereUnitReference($value)
 * @method static Builder<static>|DuressAlert whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class DuressAlert extends Model
{
    use CentralConnection;

    /**
     * The kinds that are a panic whoever raised them and however: somebody asking for help.
     *
     * @var list<string>
     */
    public const PANIC_KINDS = ['panic', 'duress'];

    protected $fillable = [
        'tenant_id',
        'kind',
        'from_panic_button',
        'guard_id',
        'raised_by_name',
        'raised_by_account_id',
        'unit_reference',
        'status',
        'latitude',
        'longitude',
        'device_time',
        'server_time',
        'clock_skewed',
        'captured_offline',
        'idempotency_key',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'resolution_note',
        'resolution_classification',
        'is_simulated',
        'is_drill',
        'drill_mode',
    ];

    protected function casts(): array
    {
        return [
            'device_time' => 'datetime',
            'server_time' => 'datetime',
            'acknowledged_at' => 'datetime',

            /*
             * WHO WAS SENT, AND WHEN THEY GOT THERE (M10 §1.3). Written only by
             * `Services\Dispatch\AlertProgress`, forward only, and never after the
             * alert is closed. Null is "not recorded" and never "skipped over":
             * nothing is back-filled, because a time nobody recorded is not a time.
             */
            'dispatched_at' => 'datetime',
            'en_route_at' => 'datetime',
            'on_scene_at' => 'datetime',

            'resolved_at' => 'datetime',
            'clock_skewed' => 'boolean',
            'captured_offline' => 'boolean',
            'is_simulated' => 'boolean',
            'from_panic_button' => 'boolean',

            /*
             * A DRILL IS NOT A SIMULATION (M10 §1.3). A simulated alert is sent to
             * nobody; a drill travels the resident's path exactly and reaches real
             * people, so it has to SAY what it is everywhere it lands and must never
             * move a response-time figure. `panic:walk` is what raises one.
             */
            'is_drill' => 'boolean',
            'drill_mode' => 'boolean',

            /*
             * WHAT THE ALERT ACTUALLY REACHED (M6 §2.1). Written once by
             * `Services\Push\PanicFanOut` as the fan-out finishes, and never again.
             *
             * NULL IS NOT ZERO. Null means the fan-out has not finished (or the row
             * predates the rule); 0 is the positive statement that no handset was
             * reached, which is what the resident's panic screen says out loud. The
             * API sends the null through rather than guessing — "help is coming" when
             * it is not is the worst thing this product could say.
             */
            'alerted_handsets' => 'integer',
            'escalated_contacts' => 'integer',
            'fanout_ms' => 'integer',

            /*
             * WHAT WAS TEXTED (Q-M09). `sms_recipients` is how many numbers the site
             * had on file; `sms_delivered` is how many a PROVIDER ACCEPTED — and on this
             * platform today that is 0, because no provider is configured and the default
             * driver writes the message down rather than sending it.
             *
             * The same null rule as above: null on `sms_delivered` means the texting has
             * not finished, 0 is the positive statement that no text was delivered. A text
             * is never counted as a handset, anywhere.
             */
            'sms_recipients' => 'integer',
            'sms_delivered' => 'integer',

            /*
             * AND THE HOUSEHOLD'S OWN CONTACTS (M10 §1.2 item 7), in the same shape and
             * under the same null rule — with one more meaning for null: on an officer's
             * duress no household is texted at all, and all three stay null for ever.
             * See `Services\Dispatch\HouseholdContactsSms`.
             */
            'raised_by_account_id' => 'integer',
            'household_sms_recipients' => 'integer',
            'household_sms_delivered' => 'integer',
            'household_sms_over_cap' => 'integer',
            'household_email_recipients' => 'integer',

            /*
             * AND BOTH HEADS OF SECURITY (M10 §1.2 items 4 and 5), the same shape and
             * the same null rule. See `Services\Dispatch\HeadsOfSecuritySms`.
             */
            'staff_sms_recipients' => 'integer',
            'staff_sms_delivered' => 'integer',
            'staff_sms_no_phone' => 'integer',
            'staff_sms_deduplicated' => 'integer',
        ];
    }

    /**
     * Deliberately NOT named guard().
     *
     * Eloquent already defines Model::guard(array $guarded) for mass-assignment,
     * so a relation of that name is a fatal signature clash rather than an
     * override. Same reason the foreign key stays `guard_id`: the column is
     * conventional, only the accessor has to move.
     */
    /** @return BelongsTo<Guard, $this> */
    public function raisedByGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /**
     * The officer responding: the one dispatched, or the one on shift who took it
     * from their own handset when nobody was (M10 §1.3). Never the raiser.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function respondingGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'responding_guard_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Who this alert was handed to, by which channel, and how fast (M10 §1.3).
     *
     * Written once, by `Services\Push\PanicDeliveries`, after every leg has gone.
     * Hand-off times — never arrival on a phone.
     *
     * @return HasMany<DuressAlertDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DuressAlertDelivery::class, 'duress_alert_id');
    }

    /**
     * What every screen, mail, text and push puts in front of a drill: "DRILL: Panic alert". A colon, not an em dash
     * (Law 3), and the phones' own spelling of the same title (the Guard App's digest says "DRILL: Panic alert").
     */
    public const DRILL_PREFIX = 'DRILL: ';

    /**
     * The word a screen puts in front of a drill, or an empty string.
     *
     * One place, so the queue, the response screen, the bell, the mail and the push
     * all spell it the same way — a drill that said DRILL on one screen and nothing on
     * the next would be read as real on the second.
     */
    public function drillPrefix(): string
    {
        return $this->is_drill ? self::DRILL_PREFIX : '';
    }

    /**
     * A DRILL RAISED IN DRILL MODE WAKES NOBODY (M53 §3A.1, M54 §2.3).
     *
     * `panic:walk`'s drill exists to prove who a panic reaches, so it reaches them. A press made while the office had
     * drill mode on for that resident's account exists so the panic can be tested over and over: it is recorded, it
     * is on every console labelled DRILL, the resident's own phone walks the whole path, and NO officer's phone rings,
     * nobody is mailed or texted and no siren sounds. Each channel that would tell somebody asks this.
     */
    public function wakesNobody(): bool
    {
        return (bool) $this->is_drill && (bool) $this->getAttribute('drill_mode');
    }

    /**
     * Anything not yet resolved, newest first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'false_alarm'])
            ->orderByDesc('server_time');
    }

    /**
     * Whether this is a PANIC — somebody asking for help — rather than an incident report.
     *
     * A `panic` or a `duress`, however it was raised; or anything pressed on the panic or
     * duress button, whatever the resident managed to label it (M10 §1.3: "An unlabelled
     * panic goes to everyone exactly the same", and the label is for the responder, never
     * a reason to be quieter). A press labelled "Medical" is stored with the kind
     * `medical` so the responder reads it — and every urgency rule used to ask the kind,
     * so it went out with no siren, off the Panic alerts tab, below every other panic
     * (bug hunt B086). An officer's "fire at Lot 12" reported on `POST /alerts` is an
     * incident, and stays one.
     *
     * THE ONE RULE for the siren (`AlertRaised`, `PanicWatch`), the Panic alerts tab and
     * its red row (`DispatchController`) and the order of every queue (`priority()`).
     */
    public function isPanic(): bool
    {
        return (bool) $this->getAttribute('from_panic_button') || in_array($this->kind, self::PANIC_KINDS, true);
    }

    /**
     * The panics, as `isPanic()` reads them, for a query.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePanics(Builder $query): Builder
    {
        return $query->where(static function (Builder $panics): void {
            $panics->whereIn('kind', self::PANIC_KINDS)->orWhere('from_panic_button', true);
        });
    }

    /**
     * Everything that is not a panic: incidents an officer or a device reported.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIncidents(Builder $query): Builder
    {
        return $query->whereNotIn('kind', self::PANIC_KINDS)->where('from_panic_button', false);
    }

    /**
     * How urgent this is, for ordering the queue.
     *
     * A panic outranks everything: a person is asking for help — and that is as true of
     * a press labelled "Medical" as of one with no label (`isPanic()`). This is
     * deliberately not alphabetical and not by timestamp alone.
     */
    public function priority(): int
    {
        if ($this->isPanic()) {
            return 1;
        }

        return match ($this->kind) {
            'medical' => 2,
            'fire' => 3,
            default => 4,
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'panic' => 'Panic button',
            'duress' => 'Guard duress',
            'medical' => 'Medical',
            'fire' => 'Fire',
            'intrusion' => 'Intrusion',
            default => ucfirst($this->kind),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'open' => 'overdue',
            'acknowledged', 'responding' => 'pending',
            default => 'active',
        };
    }
}
