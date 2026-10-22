<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person asserting they belong to a unit — boards 4 and 31.
 *
 * WHAT WAS SUBMITTED IS STORED VERBATIM AND NEVER NORMALISED. Board 31 draws two
 * columns side by side and the whole value of the screen is the difference
 * between them: "Keith Walters" against a register that reads "K. A. Walters",
 * a phone the claimant gave against a phone the estate does not hold. Tidying
 * the submission into the register's shape before storing it would erase the
 * question the reviewer is being asked.
 *
 * `unit_id` IS NULLABLE. Board 31's third card is titled "Unknown claimant"
 * because the estate could not resolve what the person typed to anything it
 * holds. That claim still has to be answered, and refusing to record it because
 * it did not match would lose exactly the submissions that need a human.
 *
 * APPROVING ONE IS `approve`, NOT `update` (D-013). It binds a person to a
 * household — which decides whose guest passes they may issue and whose gate
 * they may be admitted at — and no edit afterwards unbinds the night somebody
 * was let through. The gate is on the route; this record carries who decided and
 * when, so the decision survives the committee that made it.
 *
 * NO JOURNAL IS EVER RAISED BY ANYTHING HERE. A claim approval changes who is
 * authorised against a unit and changes nothing about what the unit owes. Board
 * 31's own note says so, and `EstateResidentsTest` proves it by counting entries
 * either side of an approval.
 *
 * @property int $id
 * @property int|null $unit_id
 * @property int|null $household_id
 * @property int|null $invite_id the invite this claim was made from, when its invitee signed in with the invited address (stage 2); written with forceFill only
 * @property string $claim_type
 * @property string $submitted_name
 * @property string|null $submitted_phase
 * @property string|null $submitted_lot
 * @property string|null $submitted_phone
 * @property string|null $submitted_relationship
 * @property string $match_result
 * @property string|null $review_flag
 * @property string $status
 * @property int|null $resolved_resident_id
 * @property int|null $reviewed_by
 * @property string|null $reviewed_by_name
 * @property Carbon|null $reviewed_at
 * @property string|null $decision_reason
 * @property Carbon|null $document_requested_at
 * @property string|null $document_requested_kind
 * @property int|null $document_requested_by
 * @property string|null $document_requested_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $unit
 * @property-read Household|null $household
 * @property-read Resident|null $resolvedResident
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitClaim newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitClaim newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitClaim query()
 *
 * @mixin \Eloquent
 */
class UnitClaim extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** A claim on the unit itself — "I live at Lot 63". */
    public const TYPE_UNIT = 'unit';

    /** A claim into an existing household — "I am Andrea Fletcher's brother". */
    public const TYPE_MEMBER = 'household_member';

    /** A claim the estate could not resolve to anybody it holds. */
    public const TYPE_UNVERIFIED = 'unverified';

    /** What each derived state is called on screen. */
    public const STATUS_LABELS = [
        self::PENDING => 'Pending review',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
    ];

    /** What the estate's own matcher concluded at submission. */
    public const MATCH_LABELS = [
        'exact' => 'Exact',
        'partial' => 'Partial',
        'none' => 'No match',
    ];

    protected $fillable = [
        'unit_id',
        'household_id',
        'claim_type',
        'submitted_name',
        'submitted_phase',
        'submitted_lot',
        'submitted_phone',
        'submitted_relationship',
        'match_result',
        'review_flag',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'document_requested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * The invite this claim was made from (stage 2): its invitee signed in with the invited address.
     *
     * @return BelongsTo<ResidentInvite, $this>
     */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(ResidentInvite::class, 'invite_id');
    }

    /** @return BelongsTo<Resident, $this> */
    public function resolvedResident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resolved_resident_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * The unit as the claimant addressed it — "Phase 2 · Lot 63".
     *
     * Built from what was SUBMITTED and not from the unit it matched, because a
     * claim naming a lot in the wrong phase must go on reading the way it was
     * typed. Where the claimant gave only one half, that half stands alone
     * rather than being padded with a middot on one side.
     */
    public function submittedUnitLabel(): string
    {
        return implode(' · ', array_filter([$this->submitted_phase, $this->submitted_lot]));
    }

    /**
     * Board 31's card title.
     *
     * "Keith Walters, claiming Phase 2 · Lot 63", and "Unknown claimant" where
     * the estate could not resolve the submission to a unit at all. The
     * claimant's own name is still carried in the comparison below the title;
     * the title says what the reviewer is deciding, which for an unresolved
     * claim is a lot rather than a person. No em dash (Law 3; seen on the
     * claims page, M98 final pass): a comma, and the middot beside a place.
     */
    public function titleLine(): string
    {
        $where = $this->submittedUnitLabel();

        if ($this->claim_type === self::TYPE_UNVERIFIED) {
            return $where === '' ? 'Unknown claimant' : 'Unknown claimant · '.$where;
        }

        return $where === ''
            ? $this->submitted_name
            : $this->submitted_name.', claiming '.$where;
    }
}
