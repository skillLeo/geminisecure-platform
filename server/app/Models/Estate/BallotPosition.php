<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A seat being contested — board 9's position rows, board 11's result cards.
 *
 * `seat_count` IS WHAT MAKES A PERCENTAGE MEAN TWO DIFFERENT THINGS. Board 11
 * prints Chairman's two candidates at 59% and 41%, which sum to 100 because one
 * seat means one mark each. It prints Vice Chairman's at 63% and 55%, which sum
 * to 118 because three seats mean a voter marks up to three names. Both are
 * shares of BALLOTS CAST, not of votes cast, and that only reads correctly if
 * the number of seats is on the record beside the tally.
 *
 * `scope` IS NOT COSMETIC. Board 9 draws a phase-scoped seat with an amber
 * "Phase-scoped" chip INSTEAD of a seat count, and the difference underneath is
 * who is entitled to mark that part of the paper: a Phase 2 Phase Lead is voted
 * on by Phase 2 and by nobody else. A screen that treated the chip as decoration
 * would put an estate-wide count under a phase-wide seat.
 *
 * @property int $id
 * @property int $ballot_id
 * @property string $name
 * @property int $seat_count
 * @property string $scope
 * @property string|null $phase
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ballot $ballot
 * @property-read Collection<int, BallotOption> $options
 * @property-read Collection<int, Nomination> $nominations
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotPosition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotPosition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotPosition query()
 *
 * @mixin \Eloquent
 */
class BallotPosition extends Model
{
    public const ESTATE = 'estate';

    public const PHASE = 'phase';

    protected $fillable = [
        'ballot_id',
        'name',
        'seat_count',
        'scope',
        'phase',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function isPhaseScoped(): bool
    {
        return $this->scope === self::PHASE;
    }

    /**
     * How board 9 prints the seat chip: "1 seat", "3 seats".
     *
     * A phase-scoped seat has no seat chip at all — the board replaces it with
     * the scope chip — so this returns null rather than a string the screen
     * would then have to decide not to draw.
     */
    public function seatLabel(): ?string
    {
        if ($this->isPhaseScoped()) {
            return null;
        }

        return $this->seat_count.' seat'.($this->seat_count === 1 ? '' : 's');
    }

    /**
     * The name as board 9 prints it — "Phase 2 · Phase Lead".
     *
     * The phase is a prefix rather than part of the stored name, because two
     * phases electing a Phase Lead are two rows of the same seat and a stored
     * "Phase 2 · Phase Lead" could not be grouped with "Phase 3 · Phase Lead".
     */
    public function displayName(): string
    {
        return $this->isPhaseScoped() && $this->phase !== null
            ? $this->phase.' · '.$this->name
            : $this->name;
    }

    /** @return BelongsTo<Ballot, $this> */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /** @return HasMany<BallotOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(BallotOption::class)->orderBy('sort_order');
    }

    /** @return HasMany<Nomination, $this> */
    public function nominations(): HasMany
    {
        return $this->hasMany(Nomination::class);
    }
}
