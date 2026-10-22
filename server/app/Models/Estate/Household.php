<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A household occupying a unit. Estate database only.
 *
 * `access_restricted` is the ONLY thing about a household's standing that a
 * guard is ever told — a boolean, never an amount, never an ageing bucket,
 * never a payment history (invariant 2).
 *
 * IT IS ALSO THE ONLY THING A CONSOLE SCREEN MAY DRAW ABOUT A RESTRICTED
 * HOUSEHOLD. Boards 4 and 38 both put a balance where a household's standing
 * goes, and where that household is restricted the figure is withheld and the
 * verdict drawn instead — amber, and the words D-025 fixed, and no amount and no
 * number of days. `Residents::standingOf()` is the single place that decides it,
 * so there is exactly one function to audit rather than one per screen.
 *
 * `last_active_at` IS STORED RATHER THAN DERIVED, and the reason is where the
 * evidence lives. What board 4's column measures — a resident opening the app,
 * booking an amenity, being admitted at a gate — is spread over three systems
 * and two databases, one of them Gemini's own. A read that fanned out over all
 * of them would put three joins behind a list screen and a cross-database one at
 * that; the writers stamp it instead.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $name
 * @property bool $access_restricted
 * @property Carbon|null $last_active_at
 * @property Carbon|null $removed_at when the office removed it (M21 §3.1); a removed household is outside the default scope
 * @property string|null $removed_by_name
 * @property string|null $removal_reference
 * @property string|null $removal_reason
 * @property array{residents?: list<int>, accounts?: list<int>}|null $removal_of what that removal did, so a restore undoes exactly that
 * @property Carbon|null $restored_at
 * @property string|null $restored_by_name
 * @property Carbon|null $anonymised_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Charge> $charges
 * @property-read int|null $charges_count
 * @property-read Collection<int, Resident> $residents
 * @property-read int|null $residents_count
 * @property-read Collection<int, UnitClaim> $claims
 * @property-read int|null $claims_count
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household query()
 *
 * @mixin \Eloquent
 */
class Household extends Model
{
    protected $fillable = ['unit_id', 'name', 'access_restricted', 'last_active_at'];

    protected function casts(): array
    {
        return [
            'access_restricted' => 'boolean',
            'last_active_at' => 'datetime',
            'removed_at' => 'datetime',
            'removal_of' => 'array',
            'restored_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }

    /**
     * A HOUSEHOLD THE OFFICE HAS REMOVED IS NO LONGER AT ITS LOT (M21 §3.1) — for the register, the gate search, the
     * lot's own page, the app — so the default scope leaves it out and the lot reads as vacant. The row is not deleted:
     * its charges, payments, gate entries and ballots still point at it, and the ledger is keyed on the unit, so what
     * the lot owes is unchanged. Removal is a scope, as it is for a removed resident; `withRemoved()` is for the few
     * reads that want the history.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('current', static function (Builder $query): void {
            $query->whereNull($query->qualifyColumn('removed_at'));
        });
    }

    /** @return Builder<static> */
    public static function withRemoved(): Builder
    {
        return static::query()->withoutGlobalScope('current');
    }

    /**
     * THE ESTATE'S HOUSEHOLDS, AS IT COUNTS THEM (M87 §1.4; Hassam, 11 October 2026: "441 is the estate's number"): a
     * household with a resident in it, on one of the estate's lots. Not a unit flagged occupied with nobody behind the
     * flag, not a household with nobody in it, not one off the site plan ("Lot 1999A", see `Unit::scopeLots()`), not a
     * removed one (the default scope). The dashboard's occupied tile, the register's Households tab, the structure page's
     * occupancy and a meeting's or ballot's eligible households all count through here, so they cannot say different
     * numbers. It counts; it changes no row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereHas('residents')->whereIn($query->qualifyColumn('unit_id'), Unit::lots()->select('units.id'));
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<Resident, $this> */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Claims made INTO this household — board 31's member claims.
     *
     * A claim on the unit itself carries no household: the claimant is
     * asserting they belong at an address, not that they belong to the people
     * already there, and the estate may not have matched them to anybody.
     *
     * @return HasMany<UnitClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(UnitClaim::class);
    }

    /**
     * The person who holds this household.
     *
     * The first primary resident, and the oldest of them if a household has
     * somehow been left with two — a list screen has to name somebody, and
     * naming whichever row came back first would make the column unstable.
     */
    public function primaryResident(): ?Resident
    {
        return $this->residents()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }

    /**
     * Everything a guard may know about this household's standing.
     *
     * Deliberately a whole-object method rather than a serializer concern, so
     * that there is exactly one place to audit and no route by which a balance
     * can reach a guard payload by accident.
     *
     * ASSUMPTION Q-005: nothing sets access_restricted automatically yet. The
     * arrears threshold that would drive it is unresolved, and restriction
     * must never follow a failed payment or a gateway outage.
     *
     * @return array{access_restricted: bool}
     */
    public function guardVisibleStanding(): array
    {
        return ['access_restricted' => $this->access_restricted];
    }
}
