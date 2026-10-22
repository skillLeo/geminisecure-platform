<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A meeting of the community — boards 12 and 36.
 *
 * PUBLISHING IS THE ACT THE NOTICE PERIOD GUARDS. The Build Spec, board 12:
 * "Statutory notice periods for an AGM are validated before publication, and the
 * system refuses to publish a meeting inside the required period." Drafting one
 * inside the period is fine — a secretary sketching next month's AGM has done
 * nothing wrong — and `Governance::publishMeeting()` is where the refusal lives,
 * because publication is the moment households are told and the clock starts.
 *
 * `notice_days_required` IS COPIED, NOT LOOKED UP. The estate's setting is
 * editable, and the question a member asks two years later is whether THIS
 * meeting was properly convened. That is a question about the rule in force on
 * the day it was published, so the day's rule is written onto the row.
 *
 * QUORUM IS COUNTED, NEVER STORED. Board 36 prints two different measures in one
 * column — "Quorum met · 6/7" for a committee and "Quorum met · 41%" for an
 * estate-wide meeting — and both are counts over `meeting_attendance` against
 * this row's denominator. A stored `quorum_met` boolean sitting beside a register
 * that disagreed with it would be the estate's minutes arguing with the estate's
 * own attendance sheet.
 *
 * `quorum_basis` IS WHY THERE ARE TWO MEASURES. A committee's quorum is a head
 * count of its members; a general meeting's is a proportion of the estate, and
 * "Quorum counts households, not individuals" — the Build Spec, board 36. One
 * column holding both would have to guess which it was looking at.
 *
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property Carbon $starts_at
 * @property int $duration_minutes
 * @property Carbon|null $notice_given_at
 * @property Carbon|null $postponed_at
 * @property string|null $postponed_by_name
 * @property string|null $postpone_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancelled_by_name
 * @property string|null $cancel_reason
 * @property Carbon|null $archived_at
 * @property string|null $archived_by_name
 * @property string $held_as
 * @property string|null $platform_name
 * @property string|null $venue
 * @property string|null $virtual_link
 * @property string $audience_scope
 * @property string|null $phase
 * @property int $quorum_percent
 * @property string $quorum_basis
 * @property int|null $quorum_required_total
 * @property int $eligible_households
 * @property bool $recording_enabled
 * @property bool $recording_consent_notice
 * @property string $status
 * @property int|null $notice_days_required
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MeetingAgendaItem> $agenda
 * @property-read Collection<int, MeetingAttendance> $attendance
 * @property-read MeetingMinutes|null $minutes
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting query()
 *
 * @mixin \Eloquent
 */
class Meeting extends Model
{
    public const AGM = 'agm';

    public const EGM = 'egm';

    public const COMMITTEE = 'committee';

    public const PHASE = 'phase';

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const HELD = 'held';

    public const CANCELLED = 'cancelled';

    /** Told, then moved with no new date yet (M56 §2.3). A stored status, like the three above. */
    public const POSTPONED = 'postponed';

    /** What a postponed meeting's date reads until it is given one (M56 §2.3), on the register and on its own page. */
    public const DATE_TO_BE_SET = 'New date to be set';

    /*
     * THE LIFECYCLE THE USER WROTE DOWN (M56 §2), as `stage()` reads it. Draft, Scheduled, Postponed and Cancelled are
     * stored; In progress and Held are the clock read against a scheduled meeting's start and end, as the console has
     * always read "held"; Minutes published and Archived are read off the minutes and `archived_at`.
     */
    public const STAGE_DRAFT = 'draft';

    public const STAGE_SCHEDULED = 'scheduled';

    public const STAGE_IN_PROGRESS = 'in_progress';

    public const STAGE_HELD = 'held';

    public const STAGE_MINUTES_PUBLISHED = 'minutes_published';

    public const STAGE_ARCHIVED = 'archived';

    public const STAGE_POSTPONED = 'postponed';

    public const STAGE_CANCELLED = 'cancelled';

    /** The chip each stage wears, in plain words. */
    public const STAGE_LABELS = [
        self::STAGE_DRAFT => 'Draft',
        self::STAGE_SCHEDULED => 'Scheduled',
        self::STAGE_IN_PROGRESS => 'In progress',
        self::STAGE_HELD => 'Held',
        self::STAGE_MINUTES_PUBLISHED => 'Minutes published',
        self::STAGE_ARCHIVED => 'Archived',
        self::STAGE_POSTPONED => 'Postponed',
        self::STAGE_CANCELLED => 'Cancelled',
    ];

    /**
     * What may be done to a meeting at each stage of its life (M56 §2's action table): the meeting's own page and the
     * register's row menu draw these and nothing else, and the routes refuse the rest. `move_to_earliest` is offered
     * only to a draft inside its notice period, which the caller knows.
     */
    public const ACTS = [
        self::STAGE_DRAFT => ['edit', 'move_to_earliest', 'publish', 'delete'],
        self::STAGE_SCHEDULED => ['edit_place', 'reschedule', 'postpone', 'cancel'],
        // Only a new date, or calling it off altogether (M56 §2.3).
        self::STAGE_POSTPONED => ['reschedule', 'cancel'],
        self::STAGE_IN_PROGRESS => ['attendance', 'minutes'],
        self::STAGE_HELD => ['attendance', 'minutes'],
        self::STAGE_MINUTES_PUBLISHED => ['archive'],
    ];

    /** How long a meeting runs when the office does not say: two hours. */
    public const DEFAULT_DURATION_MINUTES = 120;

    public const HOUSEHOLDS = 'households';

    public const MEMBERS = 'members';

    public const WHOLE_ESTATE = 'whole_estate';

    public const PHASE_SUBSET = 'phase';

    public const COMMITTEE_ONLY = 'committee';

    /** Board 12's segmented control, in the order it draws them. */
    public const TYPES = [
        self::AGM => 'AGM',
        self::EGM => 'EGM',
        self::COMMITTEE => 'Committee',
        self::PHASE => 'Phase meeting',
    ];

    /*
     * HOW A MEETING IS HELD (M10 §8), and what it decides about the two fields
     * that have always been on this table.
     *
     * `venue` and `virtual_link` have existed since the table was created and no
     * form ever set either, so a notice could tell a household a date and never
     * a place. This is the missing third fact: a venue and a link are not
     * alternatives to be inferred from, because a meeting with both is HYBRID,
     * which is a real arrangement rather than an accident.
     *
     * Hybrid is deliberately NOT a sixth value. It is any online mode that also
     * carries a venue, so the two facts stay independent and nothing has to keep
     * a sixth option in step with the fields it governs (D-135).
     */
    public const IN_PERSON = 'in_person';

    public const ZOOM = 'zoom';

    public const GOOGLE_MEET = 'google_meet';

    public const TEAMS = 'teams';

    public const OTHER_ONLINE = 'other';

    /** The client's own list, in the order the form offers it. */
    public const HELD_AS = [
        self::IN_PERSON => 'In person',
        self::ZOOM => 'Zoom',
        self::GOOGLE_MEET => 'Google Meet',
        self::TEAMS => 'Microsoft Teams',
        self::OTHER_ONLINE => 'Other online',
    ];

    /** Everything but `in_person` needs a joining link rather than a venue. */
    public function isOnline(): bool
    {
        return $this->held_as !== self::IN_PERSON;
    }

    /** Online AND somewhere to physically turn up: both facts, so both are told. */
    public function isHybrid(): bool
    {
        return $this->isOnline() && trim((string) $this->venue) !== '';
    }

    /**
     * Where to be, in one sentence, for a notice and for the Resident App.
     *
     * Never empty: a meeting whose place nobody set says so rather than
     * rendering a blank where an address belongs, because a household reading a
     * notice with no location assumes the screen is broken and not that the
     * secretary has not decided.
     */
    public function placeLine(): string
    {
        $mode = $this->platformLabel();
        $venue = trim((string) $this->venue);

        if (! $this->isOnline()) {
            return $venue === '' ? 'In person, venue to be confirmed' : $venue;
        }

        return $venue === '' ? $mode : $mode.' · also in person at '.$venue;
    }

    /**
     * The mode by name: "Zoom", "Google Meet", "Microsoft Teams" — and for "Other online" the platform the secretary
     * typed ("Webex"), or "Other online" when they typed none (26 Sep).
     */
    public function platformLabel(): string
    {
        $named = trim((string) $this->platform_name);

        if ($this->held_as === self::OTHER_ONLINE && $named !== '') {
            return $named;
        }

        return self::HELD_AS[$this->held_as] ?? 'In person';
    }

    /** The uppercase form board 12's preview eyebrow prints. */
    public const TYPE_EYEBROWS = [
        self::AGM => 'ANNUAL GENERAL MEETING',
        self::EGM => 'EXTRAORDINARY GENERAL MEETING',
        self::COMMITTEE => 'COMMITTEE MEETING',
        self::PHASE => 'PHASE MEETING',
    ];

    /**
     * How far ahead a meeting may be dated, in months, on the estate's calendar (GV-10): far enough to book next year's
     * AGM early, not 2030.
     */
    public const SCHEDULED_WITHIN_MONTHS = 18;

    /** The latest day a meeting may be dated, on the estate's calendar (GV-10). */
    public static function latestDay(): Carbon
    {
        return SiteTime::date()->addMonths(self::SCHEDULED_WITHIN_MONTHS);
    }

    /** What the scheduler and the server both say about it, in one sentence. */
    public static function latestLine(): string
    {
        return 'A meeting is scheduled up to '.self::SCHEDULED_WITHIN_MONTHS.' months ahead. The latest day is '.self::latestDay()->format('j M Y').'.';
    }

    /** The title a new meeting of each type is given until somebody edits it (GV-2): it follows the type chosen. */
    public const TYPE_TITLES = [
        self::AGM => 'Annual General Meeting',
        self::EGM => 'Extraordinary General Meeting',
        self::COMMITTEE => 'Committee meeting',
        self::PHASE => 'Phase meeting',
    ];

    protected $fillable = [
        'type',
        'title',
        'description',
        'starts_at',
        'duration_minutes',
        'held_as',
        'platform_name',
        'venue',
        'virtual_link',
        'audience_scope',
        'phase',
        'quorum_percent',
        'quorum_basis',
        'quorum_required_total',
        'eligible_households',
        'recording_enabled',
        'recording_consent_notice',
        'status',
        'notice_days_required',
        'published_at',
        'notice_given_at',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        // ONE TIME RULE (M4 §3.1): stored true UTC, read on the estate's clock — see `SiteDateTime`.
        return [
            'starts_at' => SiteDateTime::class,
            'duration_minutes' => 'integer',
            'quorum_percent' => 'integer',
            'quorum_required_total' => 'integer',
            'eligible_households' => 'integer',
            'recording_enabled' => 'boolean',
            'recording_consent_notice' => 'boolean',
            'notice_days_required' => 'integer',
            'published_at' => SiteDateTime::class,
            'notice_given_at' => SiteDateTime::class,
            'postponed_at' => SiteDateTime::class,
            'cancelled_at' => SiteDateTime::class,
            'archived_at' => SiteDateTime::class,
        ];
    }

    /** When it ends: its start and its length. */
    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes(max(1, (int) ($this->duration_minutes ?: self::DEFAULT_DURATION_MINUTES)));
    }

    /**
     * WHERE THIS MEETING IS IN ITS LIFE (M56 §2): one of the STAGE_ keys. Every screen and every refusal asks this,
     * so "may it be edited now?" has one answer everywhere.
     */
    public function stage(?Carbon $asAt = null): string
    {
        $now = $asAt?->copy() ?? Carbon::now();

        return match (true) {
            $this->status === self::DRAFT => self::STAGE_DRAFT,
            $this->status === self::CANCELLED => self::STAGE_CANCELLED,
            $this->status === self::POSTPONED => self::STAGE_POSTPONED,
            $this->archived_at !== null => self::STAGE_ARCHIVED,
            $this->relationLoaded('minutes') ? $this->minutes?->published_at !== null : $this->minutes()->whereNotNull('published_at')->exists() => self::STAGE_MINUTES_PUBLISHED,
            $this->status === self::HELD || $now->greaterThanOrEqualTo($this->endsAt()) => self::STAGE_HELD,
            $now->greaterThanOrEqualTo($this->starts_at) => self::STAGE_IN_PROGRESS,
            default => self::STAGE_SCHEDULED,
        };
    }

    public function stageLabel(?Carbon $asAt = null): string
    {
        return self::STAGE_LABELS[$this->stage($asAt)];
    }

    /**
     * WHO A MEETING OF THIS TYPE IS FOR (M56 §2.5): "AGM and EGM are always whole estate. A Phase meeting is always one
     * phase." A committee meeting is its members. The form no longer lets the two disagree, and this is the one rule.
     */
    public static function audienceFor(string $type): string
    {
        return match ($type) {
            self::PHASE => self::PHASE_SUBSET,
            self::COMMITTEE => self::COMMITTEE_ONLY,
            default => self::WHOLE_ESTATE,
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Whether the meeting is still ahead — board 36 keys two things off this. */
    public function isUpcoming(?Carbon $asAt = null): bool
    {
        return $this->starts_at->greaterThan($asAt?->copy() ?? Carbon::now());
    }

    /**
     * Board 36's Date column, which has two formats and a rule between them.
     *
     * An upcoming meeting shows the weekday and the time, because that is what a
     * resident needs in order to turn up. A meeting already held shows the date
     * alone — the time it started stopped mattering the moment it ended.
     *
     * In Law 5's form (M62 A5, DR-039), on the estate's calendar and with no clock
     * tag: "Mon 12 Oct 2026, 6:00 PM", "5 Oct 2026". (It read the UTC date, so an
     * evening meeting was listed under the next day.)
     */
    public function dateLabel(?Carbon $asAt = null): string
    {
        // A postponed meeting has no date until it is given one (M56 §2.3): the day it was called for is not its date.
        if ($this->status === self::POSTPONED) {
            return self::DATE_TO_BE_SET;
        }

        $local = SiteTime::local($this->starts_at);

        return $this->isUpcoming($asAt) ? $local->format('D j M Y, g:i A') : $local->format('j M Y');
    }

    /** Board 36's Audience column: "Whole estate", "Phase 2 only", "Committee members". */
    public function audienceLabel(): string
    {
        return match ($this->audience_scope) {
            self::PHASE_SUBSET => ($this->phase ?? 'Phase').' only',
            self::COMMITTEE_ONLY => 'Committee members',
            default => 'Whole estate',
        };
    }

    /**
     * How many households or members the quorum rule needs.
     *
     * Rounded UP. A quorum of "25% of 450" is 112.5 households, and 112 is below
     * a quarter — a meeting that declared itself quorate on the strength of a
     * rounding-down is one whose decisions can be challenged.
     *
     * ONE RULE, TWO DENOMINATORS. `quorum_percent` is the rule in both cases,
     * which is why there is one of it; `quorum_basis` says only what it is a
     * percentage OF — this committee's seven members, or the estate's 450
     * households. This used to return `quorum_required_total` unchanged for a
     * committee, which made the requirement equal to the committee's whole
     * membership: board 36's "Quorum met · 6/7" was then unreachable, because
     * six of seven present would be one short of a quorum of seven and the same
     * column was being read as both the numerator's denominator and the bar to
     * clear. See D-052.
     */
    public function quorumRequired(): int
    {
        $basis = $this->quorum_basis === self::MEMBERS
            ? ($this->quorum_required_total ?? 0)
            : $this->eligible_households;

        return (int) ceil($basis * $this->quorum_percent / 100);
    }

    /** @return HasMany<MeetingAgendaItem, $this> */
    public function agenda(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<MeetingAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    /** @return HasOne<MeetingMinutes, $this> */
    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinutes::class);
    }
}
