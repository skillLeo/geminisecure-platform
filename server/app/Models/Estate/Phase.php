<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One phase of the estate — board 3's cards. Estate database only.
 *
 * FOUR OF THE SIX FIGURES ON A PHASE CARD ARE NOT HERE. The unit count, the
 * occupied count, the vacant count and the occupancy bar are `units` grouped by
 * `block`, which is where a unit already records the phase it stands in.
 * Storing them beside the phase would create a second answer to "how many units
 * are in Phase 2", and the copy is the one a committee reads while the estate
 * quietly adds a lot.
 *
 * THE JOIN IS THE NAME, NOT AN ID, and that is a deliberate cost. `units.block`
 * has held "Phase 2" as a string since Phase 1, across 450 rows with six months
 * of posted dues behind them. A foreign key would mean renumbering every unit in
 * a ledger that cannot be unposted, to buy a referential guarantee that a unique
 * name already gives — the name is unique here, and a phase nobody spelled the
 * same way twice shows up immediately as a card with no units on it.
 *
 * `officers_assigned` IS NULLABLE AND NULL IS NOT ZERO. Board 3 gives four
 * phases a number and gives the fifth the words "new phase, officers pending".
 * A phase with nobody appointed and a phase where nobody has yet been appointed
 * are different states of the world, and only the second can be null.
 *
 * @property int $id
 * @property string $name
 * @property int $sequence
 * @property int $block_count
 * @property int|null $first_lot
 * @property int|null $last_lot
 * @property list<array{letter?: string, units?: int, lots?: list<array{0: int, 1: int}>}>|null $blocks the site plan's blocks in this phase (M57.5 §4.3)
 * @property int|null $officers_assigned
 * @property string $status
 * @property string|null $footnote
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Unit> $units
 * @property-read int|null $units_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phase query()
 *
 * @mixin \Eloquent
 */
class Phase extends Model
{
    /** A phase the estate has been running. */
    public const ACTIVE = 'active';

    /** A phase only just taken on — board 3's Phase 5. */
    public const NEW = 'new';

    protected $table = 'estate_phases';

    protected $fillable = [
        'name',
        'sequence',
        'first_lot',
        'last_lot',
        'block_count',
        'blocks',
        'officers_assigned',
        'status',
        'footnote',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'first_lot' => 'integer',
            'last_lot' => 'integer',
            'block_count' => 'integer',
            // The site plan's blocks in this phase (M57.5 §4.3): [{letter, units, lots: [[from, to], …]}, …].
            'blocks' => 'array',
            'officers_assigned' => 'integer',
        ];
    }

    /** Whether this phase is a run of lot numbers from the estate's site plan (M57.5 §4.1). */
    public function isRunOfLots(): bool
    {
        return $this->first_lot !== null && $this->last_lot !== null;
    }

    /** "Lots 1 to 356", or null for a phase that is not a run of lots. */
    public function lotsLabel(): ?string
    {
        return $this->isRunOfLots() ? 'Lots '.number_format((int) $this->first_lot).' to '.number_format((int) $this->last_lot) : null;
    }

    /**
     * The plan's block names in this phase, in the plan's order: "A, B, C, … R" (letters repeat across phases, so a
     * block is always named with its phase).
     *
     * @return list<string>
     */
    public function blockLetters(): array
    {
        return array_map(static fn (array $b): string => (string) ($b['letter'] ?? ''), $this->blocks ?? []);
    }

    /**
     * The units standing in this phase.
     *
     * Keyed on the name for the reason in the class docblock. Eloquent is
     * perfectly willing to relate on a non-key column; what it cannot do is
     * cascade, which is correct here — deleting a phase must not delete the
     * lots in it, and the estate has never had a path that deletes either.
     *
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'block', 'name');
    }

    /**
     * The line board 3 prints under the occupancy bar, in Hassam's words (M78 §3).
     *
     * "19 blocks · 3 officers", "No blocks drawn · 2 officers", or "No blocks
     * drawn · officers pending". The seeded footnote ("new phase, officers
     * pending") is not printed: the card says only what is pending. Composed
     * here rather than in the page because it is one sentence with two shapes,
     * and a template deciding between them is a template holding a rule.
     */
    public function footLine(): string
    {
        // From the site plan (M57.5 §4.3): the blocks it draws, or that it draws none, never an invented count.
        $count = $this->isRunOfLots() ? count($this->blockLetters()) : (int) $this->block_count;
        $blocks = $this->isRunOfLots() && $count === 0 ? 'No blocks drawn' : $count.' block'.($count === 1 ? '' : 's');

        if ($this->officers_assigned === null) {
            return $blocks.' · officers pending';
        }

        return $blocks.' · '.$this->officers_assigned.' officer'.($this->officers_assigned === 1 ? '' : 's');
    }
}
