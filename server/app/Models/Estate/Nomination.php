<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A candidate put forward, and the vetting decision on them — board 10.
 *
 * THREE DECISIONS, NOT TWO. The Build Spec: "Accept · reject with a reason ·
 * request more information." A candidate waiting on paperwork has not been
 * refused, and folding that into `rejected` would put a rejection on a member's
 * permanent record for a missing form. `more_info` is a state of its own and can
 * still become either of the other two.
 *
 * A REJECTION WITHOUT A REASON IS NOT A REJECTION. Board 10 prints the reason
 * inside the badge — "Rejected — arrears >90 days" — and `Governance::reject()`
 * refuses to write the row without one. The reason is a stored string rather
 * than a code, because the estate's rules are the estate's own and the next one
 * will not be about arrears.
 *
 * THE ELIGIBILITY CHECK IS SNAPSHOTTED, AND THAT IS THE WHOLE POINT OF THE FOUR
 * `_at_check` COLUMNS. Board 10's accounting note asks for it outright: the
 * check "needs a snapshot date so the ageing that justified the rejection can be
 * reproduced later". A candidate who clears their arrears the following week
 * would otherwise be able to open this screen and find the ledger saying they
 * had never been in arrears at all — which is true today and was not true on the
 * day the returning officer decided.
 *
 * @property int $id
 * @property int $ballot_id
 * @property int $ballot_position_id
 * @property int|null $unit_id
 * @property string $candidate_name
 * @property int|null $nominator_unit_id
 * @property string $nominator_name
 * @property int|null $seconder_unit_id
 * @property string $seconder_name
 * @property string $status
 * @property string|null $decision_reason
 * @property Carbon|null $decided_at
 * @property int|null $decided_by
 * @property string|null $decided_by_name
 * @property Carbon|null $eligibility_checked_on
 * @property string|null $arrears_bucket_at_check
 * @property int|null $arrears_minor_at_check
 * @property int|null $tenure_months_at_check
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ballot $ballot
 * @property-read BallotPosition $position
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Nomination newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Nomination newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Nomination query()
 *
 * @mixin \Eloquent
 */
class Nomination extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const MORE_INFO = 'more_info';

    protected $fillable = [
        'ballot_id',
        'ballot_position_id',
        'unit_id',
        'candidate_name',

        /*
         * The candidate's own words (M10 §7). Nullable and staying nullable: an
         * estate that runs an election where nobody writes anything is not doing
         * it wrong, and a required statement turns "I want to stand" into a
         * writing exercise.
         */
        'statement',

        'nominator_unit_id',
        'nominator_name',
        'seconder_unit_id',
        'seconder_name',
        'status',
        'decision_reason',
        'decided_at',
        'decided_by',
        'decided_by_name',
        'eligibility_checked_on',
        'arrears_bucket_at_check',
        'arrears_minor_at_check',
        'tenure_months_at_check',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'eligibility_checked_on' => 'date',
            'arrears_minor_at_check' => 'integer',
            'tenure_months_at_check' => 'integer',
        ];
    }

    /** Whether the returning officer has yet ruled on this one either way. */
    public function isDecided(): bool
    {
        return in_array($this->status, [self::APPROVED, self::REJECTED], true);
    }

    /**
     * The badge board 10 prints, reason and all.
     *
     * The reason is appended to the rejection label rather than drawn separately
     * because that is how the board reads it — one pill saying "Rejected —
     * arrears >90 days" — and a candidate reading a bare "Rejected" would have
     * to ask somebody why.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected'.($this->decision_reason === null ? '' : ': '.$this->decision_reason),
            self::MORE_INFO => 'More information requested',
            default => 'Pending review',
        };
    }

    /**
     * The two letters board 10 draws in the row avatar.
     *
     * First and last initial, so "Dwayne Robinson" reads DR and a single-word
     * name still returns something rather than an empty circle.
     */
    public function initials(): string
    {
        $parts = array_values(array_filter(explode(' ', trim($this->candidate_name))));

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));

        return count($parts) === 1
            ? $first
            : $first.mb_strtoupper(mb_substr((string) end($parts), 0, 1));
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
