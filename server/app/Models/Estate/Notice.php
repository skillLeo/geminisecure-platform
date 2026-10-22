<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One announcement to the estate — board 32.
 *
 * WHAT IT SAYS ABOUT ITS OWN AUTHOR IS TWO FACTS, NOT ONE. `author_name` is who
 * posted it and is always recorded; `posted_as_role` is what the estate is shown
 * instead, where the notice belongs to the office rather than the person. Board
 * 32 draws both shapes — "Posted by Property Manager" on the gate closure and
 * "Posted by Delroy Samuels" on the AGM — and the difference is real: whoever is
 * managing the estate next month owns the gate closure too, while an AGM notice
 * is signed personally. `byline()` picks; the audit keeps the person either way.
 *
 * @property int $id
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string $audience_scope
 * @property string|null $audience_phase
 * @property int|null $author_id
 * @property string $author_name
 * @property string|null $posted_as_role
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, NoticeRead> $reads
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class Notice extends Model
{
    protected $table = 'notices';

    public const URGENT = 'urgent';

    public const GENERAL = 'general';

    public const ESTATE_WIDE = 'estate';

    public const ONE_PHASE = 'phase';

    protected $fillable = [
        'kind',
        'title',
        'body',
        'audience_scope',
        'audience_phase',
        'author_id',
        'author_name',
        'posted_as_role',
        'published_at',
    ];

    protected function casts(): array
    {
        /*
         * ONE TIME RULE (M4 §3.1). Stored true UTC; read on the estate's clock, because board 32
         * prints the HOUR of it — "Posted by Delroy Samuels · Sep 1, 9:00 AM". A notice posted at
         * 9 AM in Kingston read "2:00 PM" to the office that had just posted it. The moment does
         * not move, so the resident API (`SiteTime::iso`) and every comparison are unchanged.
         */
        return ['published_at' => \App\Casts\SiteDateTime::class];
    }

    /** @return HasMany<NoticeRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(NoticeRead::class);
    }

    /** "Urgent" / "General", as board 32 prints them on the pill. */
    public function kindLabel(): string
    {
        return $this->kind === self::URGENT ? 'Urgent' : 'General';
    }

    /**
     * Who the estate is told posted this.
     *
     * The role where one was recorded, the person otherwise — never both, and
     * never neither.
     */
    public function byline(): string
    {
        return $this->posted_as_role ?? $this->author_name;
    }

    /** "Phase 3 only", or "Everybody on the estate". */
    public function audienceLabel(): string
    {
        return $this->audience_scope === self::ONE_PHASE && $this->audience_phase !== null
            ? $this->audience_phase.' only'
            : 'Everybody on the estate';
    }
}
