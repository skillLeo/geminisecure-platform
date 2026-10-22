<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\ReceiptPrefix;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * An estate.
 *
 * Each one owns a physically separate database, `gs_estate_<subdomain>`, with
 * its own MySQL user. A leak between two communities is therefore a connection
 * error rather than a query error, which is much harder to cause by accident
 * than a forgotten WHERE clause.
 *
 * The tenant id is the subdomain, so stancl's `prefix + id` yields the database
 * name directly with nothing to keep in sync.
 *
 * @property string $id
 * @property string $name
 * @property string|null $receipt_prefix
 * @property string $timezone the site's IANA zone, e.g. America/Jamaica (M4 §3.1)
 * @property string $status
 * @property bool $demo the demonstration estate the app stores' reviewers sign in to (M5 §3)
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed>|null $data
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Domain> $domains
 * @property-read int|null $domains_count
 * @property-read string $subdomain
 *
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> all($columns = ['*'])
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereProvisionedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected $guarded = [];

    protected $keyType = 'string';

    /**
     * The key is the subdomain, supplied explicitly at provisioning.
     *
     * This must be a method override, not the `$incrementing` property.
     * stancl's GeneratesIds trait defines:
     *
     *     public function getIncrementing()
     *     {
     *         return ! app()->bound(UniqueIdentifierGenerator::class);
     *     }
     *
     * Since `tenancy.id_generator` is deliberately null (the id is the
     * subdomain, not a UUID), that generator is unbound and the trait reports
     * `true` — silently overriding the property. Eloquent then casts
     * 'phoenixpark' to 0, and every estate collides on `gs_estate_0`.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * THE RECEIPT PREFIX IS FIXED BY THE FIRST RECEIPT (13 A1).
     *
     * Refused here rather than only in `ReceiptPrefix::change()`, because a
     * seeder, a tinker session or a future settings form all save an estate
     * through this model, and a prefix changed by any of them splits a series
     * that has already been printed.
     */
    protected static function booted(): void
    {
        static::updating(static function (Tenant $estate): void {
            if (! $estate->isDirty('receipt_prefix')) {
                return;
            }

            if (app(ReceiptPrefix::class)->issued($estate)) {
                throw new DomainException(ReceiptPrefix::lockedMessage($estate, $estate->getOriginal('receipt_prefix')));
            }
        });

        // The site's zone is read once per process (`SiteTime`); a saved estate may have moved it.
        static::saved(static fn () => SiteTime::flush());
        static::deleted(static fn () => SiteTime::flush());
    }

    /**
     * The site's IANA time zone (M4 §3.1) — `America/Jamaica` unless the estate says otherwise.
     * Storage is UTC; this is the clock the office types on and every phone displays in.
     */
    public function getTimezoneAttribute(?string $value): string
    {
        return SiteTime::valid($value);
    }

    /**
     * Columns promoted out of stancl's virtual `data` JSON column.
     *
     * Anything listed here is a real column and is queryable; anything else
     * set on the model lands in `data`.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'receipt_prefix',
            'address_line',
            'parish',
            'timezone',
            'gate_count',
            'phases',
            'status',

            // The DEMONSTRATION estate (M5 §3). A real column, not a name anybody
            // matches on, so the Resident App's demo path and the reviewer tests ask
            // the platform which estate it is rather than agreeing on a string.
            'demo',

            // Whether this estate appears in the Resident App's pre-sign-in pick list
            // (ESTATES-01). Real column so the endpoint can filter on it; defaults true.
            'listed',

            'provisioned_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
            'phases' => 'array',
            'demo' => 'boolean',
            'listed' => 'boolean',
        ];
    }

    /**
     * "Waterloo Road, St. Andrew" — where the site physically is.
     *
     * Null when neither part is known, rather than an empty string or a
     * dangling comma: a client record with no address should read as missing,
     * not as an address that happens to be blank. A part of nothing but spaces
     * is not known either (10 October: "  , St. Catherine").
     */
    public function getSiteAddressAttribute(): ?string
    {
        $parts = array_filter(array_map(static fn (?string $part): string => trim((string) $part), [$this->address_line, $this->parish]), static fn (string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * How many phases this estate is laid out in.
     *
     * Derived from the phase structure, never stored alongside it. Two places
     * holding the same count is how they come to disagree.
     */
    public function getPhaseCountAttribute(): int
    {
        return count($this->phases ?? []);
    }

    /** The subdomain is the id — they are the same fact, stored once. */
    public function getSubdomainAttribute(): string
    {
        return (string) $this->getTenantKey();
    }

    /**
     * The fields every estate listing shows.
     *
     * On the model rather than in each controller so the Gemini Console, the
     * dashboard and the future /api/v1 estate endpoint cannot describe the
     * same estate differently.
     *
     * @return array{id: string, name: string, status: string, provisioned_at: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => (string) $this->getTenantKey(),
            'name' => $this->name,
            'status' => $this->status,
            'provisioned_at' => $this->provisioned_at?->toDateString(),
        ];
    }

    /**
     * Every estate, ordered by subdomain, correctly typed.
     *
     * stancl overrides newCollection() to return its own TenantCollection,
     * which erases the element type: `Tenant::all()` is inferred as a
     * collection of plain Models, so `$estate->name` and `$estate->run()` both
     * disappear from static analysis at every call site.
     *
     * Narrowing it once here is better than repeating a cast in every seeder,
     * command and controller that iterates estates.
     *
     * @param  (callable(Builder<self>): void)|null  $shape
     * @return Collection<int, self>
     */
    public static function estates(?callable $shape = null): Collection
    {
        // self, not static: static::query() is typed Builder<static>, which is
        // not covariant with the Builder<self> the callable declares.
        $query = self::query();

        if ($shape !== null) {
            $shape($query);
        } else {
            $query->orderBy('id');
        }

        /** @var Collection<int, self> $estates */
        $estates = $query->get()->values();

        return $estates;
    }
}
