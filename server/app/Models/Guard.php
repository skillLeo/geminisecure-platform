<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SiteTime;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A security officer employed by Gemini Security Limited.
 *
 * Central, never per estate: a guard is posted at an estate and can be
 * reassigned, and the cross-client roster has to see all of them at once.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> needingCompliance()
 *
 * @property int $id
 * @property string $full_name
 * @property string $employee_number
 * @property string $psra_number
 * @property Carbon|null $psra_expires_on
 * @property string $employment_type
 * @property int|null $standard_rate_minor
 * @property string $standard_rate_currency
 * @property string $status
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $hired_on
 * @property string|null $tenant_id
 * @property int|null $post_id
 * @property int|null $user_id
 * @property string|null $device_id
 * @property string|null $device_label
 * @property int $leave_entitlement_days
 * @property int $approved_pension_minor
 * @property bool $reviewer an App Store / Play reviewer's demo handset account (M5 §3)
 * @property string|null $reviewer_code_hash their STATIC enrolment code, hashed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Post|null $post
 * @property-read User|null $user
 *
 * @method static Builder<static>|Guard needingCompliance()
 * @method static Builder<static>|Guard whereLicenceDaysLeft(string $operator, int $days)
 * @method static Builder<static>|Guard newModelQuery()
 * @method static Builder<static>|Guard newQuery()
 * @method static Builder<static>|Guard query()
 * @method static Builder<static>|Guard whereCreatedAt($value)
 * @method static Builder<static>|Guard whereEmail($value)
 * @method static Builder<static>|Guard whereEmployeeNumber($value)
 * @method static Builder<static>|Guard whereEmploymentType($value)
 * @method static Builder<static>|Guard whereFullName($value)
 * @method static Builder<static>|Guard whereHiredOn($value)
 * @method static Builder<static>|Guard whereId($value)
 * @method static Builder<static>|Guard wherePhone($value)
 * @method static Builder<static>|Guard wherePostId($value)
 * @method static Builder<static>|Guard wherePsraExpiresOn($value)
 * @method static Builder<static>|Guard wherePsraNumber($value)
 * @method static Builder<static>|Guard whereStatus($value)
 * @method static Builder<static>|Guard whereTenantId($value)
 * @method static Builder<static>|Guard whereUpdatedAt($value)
 * @method static Builder<static>|Guard whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Guard extends Model implements AuthenticatableContract
{
    /**
     * A guard is a PRINCIPAL, not just a record about one.
     *
     * Sanctum resolves a handset's token to its tokenable and hands it to
     * `Auth::user()`, so whatever holds the token is what the application sees
     * as the actor. Declaring that explicitly is what lets the audit log, the
     * ability middleware and anything else that asks "who is doing this" get a
     * truthful answer instead of a model that merely happens to be there.
     *
     * IT CANNOT SIGN INTO A CONSOLE. No auth provider is configured for guards,
     * there is no password column, and the trait's session methods are never
     * reached — a handset token is the only way this principal ever appears.
     * That is the point: a credential that can raise an alert and clock on, and
     * cannot open a single screen.
     */
    use AuthenticatableTrait;

    use CentralConnection;

    /**
     * A HANDSET'S TOKEN BELONGS TO THE GUARD, NOT TO A CONSOLE USER.
     *
     * The /api/v1 endpoints are authenticated by a Sanctum token, and the
     * obvious way to issue one is to give every guard a `users` row and mint it
     * there. That would be wrong: a token minted on a console account is a
     * credential that can, in principle, reach a console, and the whole point of
     * a handset token is that it can raise an alert, adjudicate a scan and clock
     * on and off — and nothing else, ever.
     *
     * Making the guard the tokenable closes that by construction. A handset
     * token resolves to a `Guard`, which holds no role, no permission and no
     * console; `EnsureEstateAccess` and every `can:` gate in the web routes
     * would refuse it because there is nothing there to grant. The abilities on
     * the token are the whole of what it can do.
     */
    use HasApiTokens;

    /** Days before expiry at which a licence is flagged as expiring soon. */
    public const LICENCE_WARNING_DAYS = 30;

    /**
     * Statuses that keep an officer off every post, whatever their licence says.
     *
     * ONE LIST, read by every door onto a post (bug hunt B071, B006): the roster's
     * picker and its assign, the client roster's pool and its save, and the
     * profile's "rosterable". The picker used to leave out two of these while the
     * assign behind it refused one, so a hand-made POST put an officer who had
     * left on a gate. `licence_expired` is here because the pill on the record
     * says so: an officer the office has marked unlicensed is not offered for a
     * post on the strength of a date that disagrees with it.
     *
     * @var list<string>
     */
    public const OFF_POST_STATUSES = ['suspended', 'inactive', 'licence_expired'];

    protected $fillable = [
        'full_name',
        'employee_number',
        'psra_number',
        'psra_expires_on',
        'employment_type',

        // The hourly rate agreed at hire, in minor units, with the currency it
        // was agreed in. Never a float, and never divided by hand on the way
        // back out — see App\Support\MoneyFormatter.
        'standard_rate_minor',
        'standard_rate_currency',

        'status',
        'phone',
        'email',
        'hired_on',
        'tenant_id',
        'post_id',
        'user_id',
        'device_id',
        'device_label',
        'leave_entitlement_days',
    ];

    /**
     * Is this guard's handset bound to them, and only to them?
     *
     * The binding is what stops one phone starting shifts for several people,
     * so an unbound guard is a monitoring gap rather than a missing field.
     */
    public function deviceIsBound(): bool
    {
        return $this->device_id !== null;
    }

    protected function casts(): array
    {
        return [
            'psra_expires_on' => 'date',
            'hired_on' => 'date',
            'reviewer' => 'boolean',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Licence state, derived rather than stored.
     *
     * Storing it would mean a guard whose licence lapsed overnight still read
     * as valid until something happened to rewrite the row. Deriving it from
     * the date means the answer is correct the moment it is asked.
     */
    public function licenceState(): string
    {
        $daysLeft = $this->licenceDaysLeft();

        return match (true) {
            $daysLeft === null => 'unknown',
            $daysLeft < 0 => 'expired',
            $daysLeft <= self::LICENCE_WARNING_DAYS => 'expiring',
            default => 'valid',
        };
    }

    /**
     * Whether this officer may be put on a post today.
     *
     * A status that allows it, and a licence that is current. One merely expiring
     * soon still licenses the officer today, so it does not block — it is a date
     * to plan around. A licence with no expiry on file cannot be shown to be
     * current, which to a regulator is the same as one that has lapsed.
     */
    public function isRosterable(): bool
    {
        return ! in_array($this->status, self::OFF_POST_STATUSES, true)
            && in_array($this->licenceState(), ['valid', 'expiring'], true);
    }

    /**
     * Calendar days from the SITE'S TODAY to the licence's expiry date. Negative
     * once it has run out: 0 is "expires today and is still good today", -1 is
     * yesterday's. Null when the office has recorded no expiry.
     *
     * ON THE SITE'S CLOCK, BY ITS IANA NAME, NEVER AN OFFSET (M4 §3.1).
     * This used to ask `$this->psra_expires_on->isPast()`, which compares a date
     * against the SERVER's now — and the server stores and runs in UTC. Kingston
     * is five hours behind it, so from 19:00 local a licence expiring "today"
     * already read as EXPIRED: a guard was told they could not stand a post for
     * the last five hours of a day their licence was still valid, and the console
     * stopped offering them to a roster. An expiry is a CALENDAR DATE, and which
     * day it is depends on where you are standing. The next territory keeps
     * daylight saving, so the gap is not even a constant.
     *
     * TODAY FIRST, EXPIRY SECOND. Carbon 3 returns a SIGNED difference. Asked the
     * other way round a licence good for another two years reads as -730, which is
     * <= 30 — and every valid licence on the platform renders as expiring. The
     * order of the two dates is the whole behaviour of this method.
     *
     * The guard's own phone is told the same number, worked out the same way
     * (`Api\V1\Guard\ProfileController::licence()`), so a guard and the office
     * reading the same licence on the same afternoon never disagree about it.
     */
    public function licenceDaysLeft(): ?int
    {
        if ($this->psra_expires_on === null) {
            return null;
        }

        /*
         * TWO CALENDAR DATES, COUNTED AS DATES. Both are read as midnight UTC only so the
         * difference is a whole number of days: two midnights in a zone that keeps daylight
         * saving can be 23 hours apart, and Carbon 3's float difference then truncates to a
         * day short. Which date "today" is was already decided, on the site's clock, above.
         */
        $today = SiteTime::today((string) $this->tenant_id)->toDateString();

        return (int) Carbon::parse($today, 'UTC')->diffInDays(
            Carbon::parse($this->psra_expires_on->toDateString(), 'UTC'),
            false,
        );
    }

    /**
     * Narrow a query on `guards` to licences with `$operator $days` days left —
     * `licenceDaysLeft()` asked in SQL, so a count, a filter and the badge on the
     * row beside them give one answer (bug hunt B008).
     *
     * EACH OFFICER ON THEIR OWN ESTATE'S CALENDAR. One UTC "now" cannot stand in
     * for `licenceDaysLeft()`: from 19:00 in Kingston the server's date is already
     * tomorrow, which read a licence as lapsed for the last five hours before its
     * final day and all through it, and an estate across the date line is a day
     * ahead of Kingston all day. So "today plus `$days`" is worked out per estate,
     * in PHP, by its IANA zone and never an offset (M4 §3.1), and estates on the
     * same date share one clause. On a platform whose estates keep one zone the
     * whole of it is `psra_expires_on < '2026-09-24'`.
     *
     * The same fall-backs as `licenceDaysLeft()`: an officer with no estate is read
     * on `SiteTime::zone(null)`, and one whose estate is not on this platform on
     * the default zone. A licence with no expiry on file matches nothing, as it has
     * no days left to count.
     *
     * A base query builder, so it serves Eloquent (`->whereLicenceDaysLeft()`) and
     * the `DB::table('guards')` readers alike.
     */
    public static function applyLicenceDaysLeft(QueryBuilder $query, string $operator, int $days, string $table = 'guards'): QueryBuilder
    {
        if (! in_array($operator, ['<', '<=', '=', '>=', '>'], true)) {
            throw new InvalidArgumentException('A licence is compared by date: <, <=, =, >= or >.');
        }

        $expires = $table.'.psra_expires_on';
        $estate = $table.'.tenant_id';
        $on = static fn (string $zone): string => Carbon::today($zone)->addDays($days)->toDateString();

        /** @var array<string, string> $zones estate id => its zone */
        $zones = DB::connection('mysql')->table('tenants')->pluck('timezone', 'id')
            ->mapWithKeys(static fn (mixed $zone, mixed $id): array => [(string) $id => SiteTime::valid($zone)])
            ->all();

        /** @var array<string, array{estates?: list<string>, unposted?: bool, unknown?: bool}> $dates the date that is today + $days there => whose it is */
        $dates = [];

        foreach ($zones as $id => $zone) {
            $dates[$on($zone)]['estates'][] = $id;
        }

        $dates[$on(SiteTime::zone(null))]['unposted'] = true;
        $dates[$on(SiteTime::platformZone())]['unknown'] = true;

        $query->whereNotNull($expires);

        if (count($dates) === 1) {
            return $query->where($expires, $operator, (string) array_key_first($dates));
        }

        return $query->where(static function (QueryBuilder $any) use ($dates, $zones, $expires, $estate, $operator): void {
            foreach ($dates as $date => $who) {
                $any->orWhere(static function (QueryBuilder $here) use ($date, $who, $zones, $expires, $estate, $operator): void {
                    $here->where($expires, $operator, $date)->where(static function (QueryBuilder $whose) use ($who, $zones, $estate): void {
                        if (($who['estates'] ?? []) !== []) {
                            $whose->orWhereIn($estate, $who['estates']);
                        }

                        if ($who['unposted'] ?? false) {
                            $whose->orWhereNull($estate);
                        }

                        if ($who['unknown'] ?? false) {
                            $whose->orWhere(static fn (QueryBuilder $stray) => $stray->whereNotNull($estate)->whereNotIn($estate, array_keys($zones)));
                        }
                    });
                });
            }
        });
    }

    /**
     * `applyLicenceDaysLeft()` on this model's own query.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereLicenceDaysLeft(Builder $query, string $operator, int $days): Builder
    {
        self::applyLicenceDaysLeft($query->getQuery(), $operator, $days, $this->getTable());

        return $query;
    }

    /** The status pill as drawn in the wireframe. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'on_leave' => 'On leave',
            'licence_expired' => 'Licence expired',
            'suspended' => 'Suspended',
            default => 'Inactive',
        };
    }

    /** Maps status onto the badge classes the wireframe defines. */
    public function statusBadge(): string
    {
        return match ($this->status) {
            'active' => 'active',
            'on_leave' => 'leave',
            'licence_expired', 'suspended' => 'expired',
            default => 'ok',
        };
    }

    /**
     * Guards whose licence needs attention, for the compliance screen: lapsed, or
     * `expiring` as `licenceState()` means it — on each officer's own site calendar,
     * so the register's "Take action" and the badge beside it agree.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingCompliance(Builder $query): Builder
    {
        return $this->scopeWhereLicenceDaysLeft($query, '<=', self::LICENCE_WARNING_DAYS)
            ->orderBy('psra_expires_on');
    }
}
