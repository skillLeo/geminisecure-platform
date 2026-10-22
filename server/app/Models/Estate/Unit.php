<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Services\Estate\PhasesFromLots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * A physical address within one estate. Estate database only.
 *
 * THE UNIT IS WHAT OWES THE DUES. Households come and go; the property and its
 * arrears stay, which is why the receivable sub-ledger, `charges` and `payments`
 * are all keyed here rather than on the household. Nine of Phoenix Park's 450
 * units are vacant and every one of them still has a ledger.
 *
 * @property int $id
 * @property string $reference
 * @property string|null $block
 * @property string|null $street
 * @property string $type
 * @property string $status
 * @property-read Collection<int, Household> $households
 * @property-read int|null $households_count
 * @property-read Household|null $household
 * @property-read Phase|null $phase
 * @property-read Collection<int, Charge> $charges
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, UnitClaim> $claims
 * @property-read int|null $claims_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit query()
 *
 * @mixin \Eloquent
 */
class Unit extends Model
{
    protected $fillable = ['reference', 'block', 'street', 'type', 'status', 'house_type'];

    /** The plan's three detached two-bedroom homes (M57.5 §4.4): the key a unit's `house_type` holds, and its words. */
    public const HOUSE_TYPES = [
        'two_bed_one_bath_675' => '2 bed, 1 bath, 62.7 m² (675 sq ft)',
        'two_bed_one_bath_810' => '2 bed, 1 bath, 75.2 m² (810 sq ft)',
        'two_bed_two_bath_940' => '2 bed, 2 bath, 87.3 m² (940 sq ft)',
    ];

    /** Per estate database: whether its `block` is computed (PhasesFromLots), asked once per process. */
    private static array $derivedPhase = [];

    /**
     * ON AN ESTATE WHOSE PHASE IS WORKED OUT FROM THE LOT (M57.5 §4.2), A UNIT NEVER CARRIES A PHASE OF ITS OWN: MySQL
     * computes `block`, and refuses a value for it. A model write that still names one has it dropped here rather than
     * failing; the lot number is what decides the phase.
     */
    protected static function booted(): void
    {
        static::saving(static function (Unit $unit): void {
            if (! $unit->isDirty('block') || ! self::phaseIsDerived()) {
                return;
            }

            // A unit already on the register keeps the phase MySQL computed for it; a new one is written without one.
            $attributes = $unit->getAttributes();

            if ($unit->exists) {
                $attributes['block'] = $unit->getOriginal('block');
            } else {
                unset($attributes['block']);
            }

            $unit->setRawAttributes($attributes, false);
        });
    }

    /** Whether the estate open now computes its phases from lot numbers. */
    public static function phaseIsDerived(): bool
    {
        $database = (string) DB::connection('tenant')->getDatabaseName();

        return self::$derivedPhase[$database] ??= PhasesFromLots::derived('tenant');
    }

    /** Forget what `phaseIsDerived()` learnt (after the change is made or undone, and between tests). */
    public static function forgetPhaseDerivation(): void
    {
        self::$derivedPhase = [];
    }

    /**
     * THE ESTATE'S LOTS (M60 §C2: 1,999, not 2,000). On an estate whose phase is worked out from the lot number, the
     * units a phase's run of lots holds: a unit the site plan does not have ("Lot 1999A", Phoenix Park's 2,000th home
     * from before the plan was read) stays on the register, filed under no phase, and is not one of the estate's lots.
     * On any other estate, every unit. What "Total units", the residents' structure totals, a ballot's eligible
     * households, a meeting's quorum and "This tells N households" all count.
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeLots(Builder $query): Builder
    {
        return self::phaseIsDerived() ? $query->whereNotNull('block') : $query;
    }

    /** @return HasMany<Household, $this> */
    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    /**
     * The household living here now, if any.
     *
     * A unit has a history of households and at most one current occupant, so
     * `households` is the record and this is the one the screens ask for. Null
     * on a vacant unit, which is a real and common state: the dues still fall
     * due and there is simply nobody to call about them.
     *
     * @return HasOne<Household, $this>
     */
    public function household(): HasOne
    {
        return $this->hasOne(Household::class)->latestOfMany();
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The phase this unit stands in.
     *
     * Related on the NAME, because `block` has held "Phase 2" as a string since
     * Phase 1 and re-keying 450 units under a ledger that cannot be unposted
     * buys nothing a unique phase name does not already give. See `Phase`.
     *
     * @return BelongsTo<Phase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class, 'block', 'name');
    }

    /** @return HasMany<UnitClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(UnitClaim::class);
    }

    /**
     * The residents screens registered as literals ahead of `residents/{unit}`.
     *
     * A unit whose reference encoded to one of these would be shadowed by the
     * screen of the same name, so `slugFor()` escapes its first letter instead.
     */
    private const RESERVED_SLUGS = ['claims', 'new'];

    /** The bytes a slug carries as themselves. Spelled out: `ctype_*` answers by the process locale. */
    private const SLUG_LITERALS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * The URL segment board 38 addresses this unit by — "lot-47".
     *
     * THE ROUTE KEYS ON THE LOT AND NOT ON THE PERSON, which is the board's own
     * URL and is the correct choice on its own terms: the unit outlives every
     * household in it, so a link a committee member bookmarks still resolves
     * after the family at Lot 47 has moved out.
     */
    public function slug(): string
    {
        return self::slugFor((string) $this->reference);
    }

    /**
     * A reference as a URL segment, in a form that can be read back exactly.
     *
     * "Lot 47" is "lot-47", as it always was. But a reference is whatever the
     * estate calls the unit — Ocean View's are "OV-1A", and the unit import takes
     * "A-12", "14B" or "Block 3.1" — so the segment cannot simply be the
     * reference with its spaces turned into hyphens and turned back again: that
     * sent "OV-1A" to a lookup for "ov 1a", and every household on every estate
     * but Phoenix Park answered 404 (B040).
     *
     * So every character has exactly one spelling, and no spelling is shared:
     *
     *   a–z 0–9   as themselves (a capital is lowered; references are unique
     *             without regard to case, so no two units differ only by one)
     *   space     "-"   — which is what keeps "lot-47" the URL it has always been
     *   "-"       "_"
     *   any other byte, "_" and "~" included, "~" and two hex digits
     *
     * The result is only ever [a-z0-9_~-], all of which a URL carries as they
     * are, and `referenceFromSlug()` undoes it character for character.
     */
    public static function slugFor(string $reference): string
    {
        $slug = '';

        for ($i = 0, $length = strlen($reference); $i < $length; $i++) {
            $byte = strtolower($reference[$i]);

            $slug .= match (true) {
                str_contains(self::SLUG_LITERALS, $byte) => $byte,
                $byte === ' ' => '-',
                $byte === '-' => '_',
                default => '~'.bin2hex($byte),
            };
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            $slug = '~'.bin2hex($slug[0]).substr($slug, 1);
        }

        return $slug;
    }

    /**
     * The reference a segment spells, lower-cased — or null when it spells none.
     *
     * Null for anything `slugFor()` could not have written: a character outside
     * its alphabet, a "~" without two hex digits after it, bytes that are not
     * UTF-8. A caller answers that with a 404, the same answer as a unit the
     * estate does not have.
     */
    public static function referenceFromSlug(string $slug): ?string
    {
        $reference = '';

        for ($i = 0, $length = strlen($slug); $i < $length; $i++) {
            $character = $slug[$i];

            if ($character === '~') {
                $hex = substr($slug, $i + 1, 2);

                // Two LOWER-CASE hex digits, the only spelling `slugFor()` writes.
                if (preg_match('/^[0-9a-f]{2}$/', $hex) !== 1) {
                    return null;
                }

                $reference .= chr((int) hexdec($hex));
                $i += 2;

                continue;
            }

            $decoded = match (true) {
                $character === '-' => ' ',
                $character === '_' => '-',
                str_contains(self::SLUG_LITERALS, $character) => $character,
                default => null,
            };

            if ($decoded === null) {
                return null;
            }

            $reference .= $decoded;
        }

        return $reference === '' || ! mb_check_encoding($reference, 'UTF-8') ? null : $reference;
    }

    /** "Phase 2 · Lot 47", as every estate screen addresses a unit. */
    public function addressLabel(): string
    {
        return implode(' · ', array_filter([$this->block, $this->reference]));
    }
}
