<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a mark can be cast for: a candidate, or For / Against / Abstain.
 *
 * THE ONLY THING `ballot_marks` POINTS AT, and the reason a mark needs no other
 * column. A mark is an option id and a random key, and that is the entire row.
 *
 * SEPARATE FROM `nominations`, AND BOARD 11 IS THE PROOF. Andre Thompson wins a
 * Vice Chairman seat on the results screen and appears in no nomination row on
 * board 10. A ballot paper is settled once vetting is done (`Governance::open()`
 * writes it from the approved nominations as the poll opens, where no paper was
 * seeded), and from then it stops depending on the vetting record that produced
 * it — otherwise a nomination re-decided afterwards would silently rewrite a
 * paper people had already voted on, and there is no way to un-cast the votes
 * that were made against the old one.
 *
 * `unit_id` HERE IS THE CANDIDATE'S OWN HOUSEHOLD, NOT A VOTER'S. It is the one
 * household on a ballot paper that is meant to be identifiable: board 11 prints
 * "Phase 1" under a winner's name, and an eligibility check needs a unit to run
 * the arrears ageing against. Nothing joins it to `ballot_receipts`, and nothing
 * may — a candidate is also a voter, and the fact that Dwayne Robinson stands
 * for Chairman says nothing whatever about how Dwayne Robinson voted.
 *
 * @property int $id
 * @property int $ballot_id
 * @property int|null $ballot_position_id
 * @property string $label
 * @property int|null $unit_id
 * @property string|null $phase
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ballot $ballot
 * @property-read BallotPosition|null $position
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotOption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotOption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotOption query()
 *
 * @mixin \Eloquent
 */
class BallotOption extends Model
{
    /** The three a resolution ballot always carries, in the order they read. */
    public const FOR = 'For';

    public const AGAINST = 'Against';

    public const ABSTAIN = 'Abstain';

    protected $fillable = [
        'ballot_id',
        'ballot_position_id',
        'label',
        'unit_id',
        'phase',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The two letters board 10 and board 11 draw in an avatar.
     *
     * First and last initial. "For" and "Against" have one word and get one
     * letter, which is right — a resolution ballot draws no avatars at all, and
     * a two-letter "FO" would be a person's initials for something that is not a
     * person.
     */
    public function initials(): string
    {
        $parts = array_values(array_filter(explode(' ', trim($this->label))));

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));

        if (count($parts) === 1) {
            return $first;
        }

        return $first.mb_strtoupper(mb_substr((string) end($parts), 0, 1));
    }

    /** @return BelongsTo<Ballot, $this> */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /** @return BelongsTo<BallotPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(BallotPosition::class, 'ballot_position_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
