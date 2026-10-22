<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Rules\ReceivesMail;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\Residents;
use App\Services\Platform\OperatorEstates;
use App\Support\JamaicanNumber;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\PlatformMoney;
use App\Support\PlatformPricing;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Money\Money;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reads over the guard workforce, scoped to what the viewer may see.
 *
 * Scoping lives here rather than in the controller so the Inertia console and
 * the /api/v1 endpoints the mobile apps will call cannot drift into different
 * answers about who may see which guard.
 *
 * Central only. A guard is a Gemini Security employee posted AT an estate, so
 * every column this class reads lives in gs_platform and no method here opens
 * an estate database.
 *
 * On money: a guard's pay is Gemini's own payroll, and the invariant that a
 * guard never sees an amount owed governs what the GUARD APP shows a guard
 * about residents. It is not a rule against Gemini staff seeing their own
 * employee's payslip in the Gemini Console. The one figure shown here is read
 * from payslips in minor units and formatted by MoneyFormatter; nothing is
 * divided by 100 anywhere.
 *
 * WHICH Gemini staff is the Payroll & Accounting module's question, not this
 * one's (M10 §5): the profile shows that figure only to a viewer the caller
 * says holds it. See `profile()` and `PlatformMoney::maySeePay()`.
 */
class GuardWorkforce
{
    /** Rows per page on the directory. */
    public const PER_PAGE = 25;

    /**
     * Sortable columns: the key that appears in the URL => the column it means.
     *
     * A whitelist rather than a column name taken from the query string, which
     * is the difference between a sortable table and an injection point. The
     * keys are short because they are visible to the reader in the address bar.
     */
    private const SORTS = [
        'name' => 'guards.full_name',
        'psra' => 'guards.psra_number',
        'client' => 'tenants.name',
        'post' => 'posts.name',
        'status' => 'guards.status',
    ];

    /** @var list<string> */
    private const STATUSES = ['active', 'on_leave', 'licence_expired', 'suspended', 'inactive'];

    /** @var list<string> */
    private const EMPLOYMENT_TYPES = ['full_time', 'part_time'];

    /**
     * The audit log is a constructor dependency rather than a facade call.
     *
     * Every write in this class has to leave a trace, and a dependency the
     * container hands over is one a test can assert against. A static call
     * inside the method would be invisible from the outside, which is the one
     * property an audit trail cannot afford.
     */
    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------------------------------------------ */
    /* the compliance writes (12 §2, Wave 5) */
    /* ------------------------------------------------------------------ */

    /**
     * Record a renewed PSRA licence — boards 20 and 21's "Mark licence renewed".
     *
     * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, not a flag, and
     * the old reason said exactly that. Recording one without the date would
     * write a compliance fact nobody has seen — and the fact in question is
     * whether an officer may legally stand a post.
     *
     * THE NEW DATE MUST BE IN THE FUTURE AND LATER THAN THE OLD ONE. A renewal
     * that expires tomorrow is not a renewal, and one dated before the licence
     * it replaces is a typo that would leave the officer non-compliant while
     * the screen said otherwise.
     *
     * SUSPENSION IS NOT LIFTED BY THIS. An officer suspended for a lapsed
     * licence is suspended by a decision somebody took; the decision to put
     * them back on duty is its own, and quietly reversing it here would undo a
     * judgement nobody made again.
     */
    public function markLicenceRenewed(Guard $guard, string $expiresOn, string $licenceNumber, User $by): Guard
    {
        $new = Carbon::parse($expiresOn)->startOfDay();

        // "Today" is the officer's site's (B008): from 19:00 in Kingston the server's
        // UTC date is already tomorrow, and a licence renewed to run to tomorrow is a renewal.
        if ($new->toDateString() <= SiteTime::today((string) $guard->tenant_id)->toDateString()) {
            throw new DomainException('A renewed licence expires in the future. A date today or earlier is not a renewal, and recording it would leave this officer non-compliant while the screen said otherwise.');
        }

        if ($guard->psra_expires_on !== null && $new->lessThanOrEqualTo($guard->psra_expires_on)) {
            throw new DomainException(sprintf(
                'The licence on file already runs to %s. A renewal extends it — a date on or before that is a typo.',
                $guard->psra_expires_on->format('M j, Y'),
            ));
        }

        $number = trim($licenceNumber);

        if ($number === '') {
            throw new DomainException('The PSRA number on the renewed licence is what an inspector asks for. It is usually the same number, and it is read off the certificate rather than assumed.');
        }

        $before = [
            'psra_number' => (string) $guard->psra_number,
            'psra_expires_on' => $guard->psra_expires_on?->toDateString(),
            'status' => (string) $guard->status,
        ];

        $guard->forceFill([
            'psra_number' => $number,
            'psra_expires_on' => $new->toDateString(),

            /*
             * `licence_expired` IS THE ONE STATUS THIS CLEARS, because it is
             * the only one the licence itself caused. A suspension is a
             * decision about conduct and stays until somebody lifts it.
             */
            'status' => $guard->status === 'licence_expired' ? 'active' : $guard->status,
        ])->save();

        $this->audit->record(
            action: 'guard.licence_renewed',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            before: $before,
            after: [
                'psra_number' => $number,
                'psra_expires_on' => $new->toDateString(),
                'status' => (string) $guard->fresh()->status,
                'by' => (string) $by->name,
            ],
            tenantId: $guard->tenant_id === null ? null : (string) $guard->tenant_id,
        );

        return $guard->fresh();
    }

    /**
     * Move an officer to another client, or off one — board 20's "Reassign".
     *
     * THE POST GOES WITH THE CLIENT AND IS NEVER LEFT BEHIND. A guard posted at
     * Phoenix Park's Main Gate who is reassigned to another estate and keeps
     * `post_id` would appear on the old client's coverage board as standing a
     * gate they no longer work — which is the exact failure D-034 records for
     * suspension, in the other direction.
     *
     * FUTURE SHIFTS AT THE OLD CLIENT ARE RELEASED, not carried across. A shift
     * belongs to a post, and an officer who has moved cannot stand it; leaving
     * it assigned would hide a gap the old estate needs to fill.
     *
     * A MOVE THAT CHANGES NOTHING IS REFUSED (bug hunt B009), rather than written
     * to the audit log as a reassignment. And the answer says what actually
     * happened — how many shifts were opened, at which client, or that a move
     * between two posts at one client left the officer's shifts as they were —
     * where it used to say "any future shifts at the previous client are open"
     * whatever had happened, including after a move that opened none.
     *
     * @return string what was done, for the reader who just did it
     */
    public function reassign(Guard $guard, ?string $tenantId, ?int $postId, User $by, Roster $roster): string
    {
        if ($guard->status === 'suspended') {
            throw new DomainException($guard->full_name.' is suspended from duty. Reassigning a suspended officer would post them somewhere they may not stand — lift the suspension first, or leave them where the record shows them.');
        }

        if ((string) $guard->tenant_id === (string) $tenantId && (int) $guard->post_id === (int) $postId) {
            throw new DomainException($guard->tenant_id === null
                ? $guard->full_name.' is already off posting, with no client and no post. Choose a client to post them to.'
                : $guard->full_name.' is already posted there. Choose another client or another post to move them.');
        }

        $post = null;

        if ($postId !== null) {
            $post = Post::query()->find($postId);

            if ($post === null || (string) $post->tenant_id !== (string) $tenantId) {
                throw new DomainException('That post does not belong to the client being assigned to. A post is the client\'s, and an officer stands one of theirs or none.');
            }
        }

        if ($tenantId !== null && ! Tenant::query()->whereKey($tenantId)->exists()) {
            throw new DomainException('That client is not on this platform.');
        }

        $before = ['tenant_id' => $guard->tenant_id, 'post_id' => $guard->post_id];
        $clientChanged = (string) $guard->tenant_id !== (string) $tenantId;
        $from = $guard->tenant_id === null ? null : (string) ($guard->estate->name ?? $guard->tenant_id);
        $to = $tenantId === null ? null : (string) Tenant::query()->whereKey($tenantId)->value('name');

        $released = $clientChanged
            ? $roster->releaseFutureShifts($guard, 'Reassigned to '.($to ?? 'no client'), $by)
            : 0;

        $guard->forceFill([
            'tenant_id' => $tenantId,
            'post_id' => $post?->id,
        ])->save();

        $this->audit->record(
            action: 'guard.reassigned',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            before: $before,
            after: [
                'tenant_id' => $tenantId,
                'post_id' => $post?->id,
                'shifts_released' => $released,
                'by' => (string) $by->name,
            ],
            tenantId: $tenantId,
        );

        $name = $guard->full_name;

        if (! $clientChanged) {
            return ($post === null
                ? sprintf('%s is still posted at %s, now with no post.', $name, (string) $to)
                : sprintf('%s now stands %s at %s.', $name, $post->label(), (string) $to))
                .' They are still at the same client, so the shifts they are rostered for are unchanged.';
        }

        $where = match (true) {
            $to === null => sprintf('%s is now off posting, with no client and no post.', $name),
            $post === null => sprintf('%s is now posted at %s, with no post yet.', $name, $to),
            default => sprintf('%s is now posted at %s — %s.', $name, $to, $post->label()),
        };

        $shifts = match (true) {
            $released === 0 && $from === null => '',
            $released === 0 => sprintf(' They held no future shifts at %s, so none was opened.', $from),
            default => sprintf(
                ' %d future %s%s %s now open on the rota, with the reason on each.',
                $released,
                Str::plural('shift', $released),
                $from === null ? '' : ' at '.$from,
                $released === 1 ? 'is' : 'are',
            ),
        };

        return $where.$shifts;
    }

    /**
     * Everything the directory URL is allowed to say, and nothing else.
     *
     * The screen reads its own state back out of this array, so an unknown
     * status or a made-up sort key does not merely fail to filter: it is gone
     * by the time either the query or the page sees it.
     *
     * The order and the page are the shared table's (`rosterTable()`, M32 F5), read by its one rule.
     *
     * @param  array<string, mixed>  $input
     * @return array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null}
     */
    public function normaliseFilters(array $input): array
    {
        return [
            'q' => $this->searchTerm($input['q'] ?? null),
            'client' => $this->text($input['client'] ?? null),
            'status' => $this->oneOf($input['status'] ?? null, self::STATUSES),
            'employment' => $this->oneOf($input['employment'] ?? null, self::EMPLOYMENT_TYPES),
            'licence' => $this->oneOf($input['licence'] ?? null, ['expired', 'expiring']),
        ];
    }

    /**
     * What the directory orders by (M32 F5). `joined`, the default, is the roster's own order: the order guards joined
     * the company, which is the order the board lists them in.
     *
     * @return array<string, string>
     */
    public static function rosterSorts(): array
    {
        return ['joined' => 'guards.id', ...self::SORTS];
    }

    /** The directory as its address asks for it (M32 F5). */
    public static function rosterTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::rosterSorts(), 'joined', 'asc', self::PER_PAGE);
    }

    /**
     * One page of the directory, filtered, sorted and presented.
     *
     * @param  array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null}  $filters
     * @return array{rows: array<int, array<string, mixed>>, table: array<string, mixed>}
     */
    public function roster(User $viewer, array $filters, ?TableQuery $table = null): array
    {
        $table ??= self::rosterTable();
        $query = $this->filtered($viewer, $filters)->with(['post', 'estate']);

        /*
         * `id` last, always (`TableQuery::order`): it makes paging deterministic, which sorting on a column where every
         * row repeats — every guard is "Full-time" — would not be on its own.
         */
        $table->order($query, 'guards.id');
        $page = $table->paginate($query);

        return [
            'rows' => $page->getCollection()
                ->map(fn (Guard $guard): array => $this->present($guard))
                ->values()
                ->all(),
            'table' => $table->meta($page),
        ];
    }

    /**
     * The four figures the board draws above the directory.
     *
     * Deliberately NOT narrowed by the current filter. They are the counts the
     * filters drill into, so a set that shrank as you filtered would leave
     * nothing to come back to.
     *
     * @return array{total: int, active: int, on_leave: int, licence_expired: int}
     */
    public function summary(User $viewer): array
    {
        return [
            'total' => $this->scoped($viewer)->count(),
            'active' => $this->scoped($viewer)->where('guards.status', 'active')->count(),
            'on_leave' => $this->scoped($viewer)->where('guards.status', 'on_leave')->count(),
            'licence_expired' => $this->expiredLicence($this->scoped($viewer))->count(),
        ];
    }

    /**
     * The estates that actually have a guard posted, for the filter chips.
     *
     * The query builder rather than Eloquent: the columns wanted belong to
     * `tenants`, and asking Eloquent for them hands back Guard models carrying
     * attributes the class does not have.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function clients(User $viewer): array
    {
        $query = DB::connection('mysql')
            ->table('guards')
            ->join('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->distinct()
            // Onboarding order, which is the order the board lists them in and
            // the order the people who run this platform think in.
            ->orderBy('tenants.created_at')
            ->orderBy('tenants.name')
            ->select('tenants.id as id', 'tenants.name as name', 'tenants.created_at as created_at');

        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            $query->whereIn('guards.tenant_id', $restricted);
        }

        /*
         * THE SAME EXCLUSION EVERY OTHER READ ON THIS SCREEN APPLIES, AND THIS
         * ONE DID NOT.
         *
         * `scoped()` drops the demonstration estate's invented officers (M5 §3)
         * and `postings()` keeps it out of the picker an operator posts a real
         * guard through — but this method builds the directory's client chips
         * off a bare join and so offered "Seaview Demo Estate (demonstration
         * data)" as a filter beside the paying clients. Two faults at once: a
         * demonstration account is listed as a customer on a console shown to
         * real ones, and the chip cannot return a single row, because the rows
         * it would filter were already excluded by `scoped()`. Pressing it gave
         * an empty table with no explanation.
         *
         * Through `OperatorEstates` rather than a name match, which is how the
         * rule is expressed everywhere else it is applied.
         */
        OperatorEstates::exclude($query, 'guards.tenant_id');

        return $query->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * The PSRA licence register: every guard, soonest expiry first.
     *
     * Not only the guards inside `needingCompliance()`. This screen is the
     * register a compliance officer reads down, and a licence renewing in four
     * months is a fact they plan around. A table holding only today's failures
     * would answer "is anything wrong now" rather than "when does the next
     * thing go wrong". `needingCompliance()` still decides which rows are
     * actionable, so the flag and the badge cannot disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compliance(User $viewer): array
    {
        $query = $this->joined($this->scoped($viewer))->with(['post', 'estate']);
        self::complianceSorts()['expiry']($query, 'asc');

        return $this->complianceRows($viewer, $query->orderBy('guards.full_name')->get());
    }

    /**
     * What the licence register orders by (M32 F5). `expiry`, the default, is its own order: soonest first, undated
     * licences last either way — an unrecorded expiry is a gap in the record, not the most urgent thing on the register,
     * and MySQL would otherwise sort NULL to the very top.
     *
     * @return array<string, string|Closure>
     */
    public static function complianceSorts(): array
    {
        return [
            'expiry' => static fn ($query, string $dir) => $query->orderByRaw('guards.psra_expires_on IS NULL')->orderBy('guards.psra_expires_on', $dir),
            'name' => 'guards.full_name',
            'psra' => 'guards.psra_number',
            'client' => 'tenants.name',
        ];
    }

    public static function complianceTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::complianceSorts(), 'expiry', 'asc', self::PER_PAGE);
    }

    /**
     * The register's screen (M32 F5): a page of it, in the order the table asks for. `compliance()` is the whole of
     * it, as the export takes it.
     *
     * @return array{rows: array<int, array<string, mixed>>, table: array<string, mixed>}
     */
    public function compliancePage(User $viewer, TableQuery $table): array
    {
        $query = $this->joined($this->scoped($viewer))->with(['post', 'estate']);
        $table->order($query, 'guards.id');
        $page = $table->paginate($query);

        return ['rows' => $this->complianceRows($viewer, $page->getCollection()), 'table' => $table->meta($page)];
    }

    /**
     * @param  Collection<int, Guard>  $guards
     * @return array<int, array<string, mixed>>
     */
    private function complianceRows(User $viewer, $guards): array
    {
        /** @var list<int> $flagged */
        $flagged = $this->scoped($viewer)->needingCompliance()->pluck('guards.id')->all();

        return $guards
            ->map(fn (Guard $guard): array => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'initials' => $this->initials($guard->full_name),
                'psra_number' => $guard->psra_number,
                'estate' => $guard->estate->name ?? 'Unassigned',
                'expires_on' => $this->longDate($guard->psra_expires_on),
                'licence_badge' => $this->licenceBadge($guard),
                'licence_label' => $this->licenceCountdown($guard),
                'action' => in_array($guard->id, $flagged, true) ? 'Take action' : 'View',

                /*
                 * "Take action" goes to the action screen, "View" to the
                 * record. The two words on this board are different promises
                 * and were reaching the same page, which made one of them a
                 * lie: a reader who clicked "Take action" arrived somewhere
                 * with nothing to act on.
                 */
                'href' => in_array($guard->id, $flagged, true)
                    ? '/guards/'.$guard->id.'/compliance'
                    : '/guards/'.$guard->id,
            ])
            ->values()
            ->all();
    }

    /**
     * One guard, as the profile screen draws them.
     *
     * The board's hero shows a standard hourly rate and the record carries no
     * such column, so that slot holds the figure that does exist: the gross on
     * the guard's most recent payslip. Inventing a rate to match the drawing
     * would put a number on screen that no table could ever confirm.
     *
     * Boards 19 and 23 are the SAME screen drawn on two guards, and the second
     * one is not a styling variant. Devon Palmer's PSRA licence has lapsed, so
     * his record is in two states a compliant guard's is not — UN-ROSTERABLE,
     * and carrying an OPEN COMPLIANCE CASE — and both are answered here rather
     * than in the page, so the profile, the register and the compliance action
     * screen cannot come to different conclusions about the same guard.
     *
     * A PAYSLIP AMOUNT IS PAYROLL'S (M10 §5). `$maySeePay` is the caller's answer from
     * `PlatformMoney::maySeePay()`; without it the "Last gross pay" stat is absent and the payslip
     * table is never read. Defaults to true so the callers that have never been asked — the
     * compliance suite reads this record directly — keep the board's four stats.
     *
     * @return array<string, mixed>
     */
    public function profile(Guard $guard, bool $maySeePay = true): array
    {
        $estate = $guard->estate->name ?? 'Unassigned';
        $post = $guard->post?->label();
        $case = $this->complianceCase($guard);
        $rosterable = $this->isRosterable($guard);

        return [
            'id' => $guard->id,
            'name' => $guard->full_name,

            /*
             * WHAT THE TWO FORMS ON THIS SCREEN START FROM (bug hunt B009). The Reassign
             * form opens on the officer's current client and post, and the renewal form on
             * the licence number on file. Neither was sent, so Reassign always opened on
             * "No client — off posting" with an empty post list — and one press as it stood
             * took the officer off their client and opened every future shift they held —
             * while the renewal's number, meant to be prefilled, was blank.
             */
            'tenant_id' => $guard->tenant_id,
            'post_id' => $guard->post_id,
            'psra_number' => $guard->psra_number,

            'subtitle' => implode(' · ', array_filter(['Security Officer', $estate, $post])),
            'status_label' => $guard->statusLabel(),
            'status_badge' => $guard->statusBadge(),
            'contact_href' => $this->contactHref($guard),

            /*
             * The un-rosterable state, made explicit rather than left to be
             * inferred from the status pill. The Build Spec is unambiguous:
             * "Expired licence sets un-rosterable — a hard block, not a
             * warning." A hard block is why the profile of such a guard offers
             * no reassignment control at all, rather than one that is offered
             * and then refused.
             */
            'rosterable' => $rosterable,
            'hero_class' => $rosterable ? null : 'suspended',
            'blocked_reason' => $rosterable ? null : $this->blockedReason($guard),

            'compliance_case' => $case,
            'compliance_href' => $case === null ? null : '/guards/'.$guard->id.'/compliance',

            // An open case turns the deployment timeline into the case file:
            // the history and the compliance record are one sequence, and the
            // heading says so rather than filing compliance events under a
            // title that does not admit they are there.
            'history_head' => $case === null ? 'Deployment history' : 'Deployment & compliance history',

            'stats' => [
                ['label' => 'Licence number', 'value' => $guard->psra_number],
                ['label' => $this->licenceStatLabel($guard), 'value' => $this->longDate($guard->psra_expires_on)],
                /*
                 * THE ONE FIGURE ON THIS RECORD, AND IT IS PAYROLL & ACCOUNTING'S (M10 §5). The
                 * board wants a standard hourly rate here and the record's hero has always held the
                 * gross on the latest payslip instead — a payslip amount, on a screen gated by
                 * Guard workforce, which the Admin Assistant, the Dispatcher and the Head of
                 * Security all hold and none of them holds Payroll. Absent rather than masked:
                 * the hero reflows onto three stats, and nothing on the wire says a figure was
                 * taken out.
                 */
                ...($maySeePay
                    ? [['label' => 'Last gross pay', 'value' => $this->latestGross($guard) ?? 'No payslip yet']]
                    : []),
                ['label' => 'Employed since', 'value' => $guard->hired_on?->format('M Y') ?? 'Not recorded'],
            ],
            'employment' => [
                ['label' => 'Employment type', 'value' => $this->employmentLabel($guard->employment_type)],
                ['label' => 'Employee number', 'value' => $guard->employee_number],
                ['label' => 'Email', 'value' => $guard->email ?? 'Not recorded'],
                ['label' => 'Phone', 'value' => $guard->phone ?? 'Not recorded'],
            ],
            'assignment' => [
                ['label' => 'Client', 'value' => $estate],
                ['label' => 'Post', 'value' => $post ?? 'No post assigned'],
                ['label' => 'Post type', 'value' => $this->postType($guard)],
            ],
        ];
    }

    /**
     * The deployment timeline, built only from events that actually happened.
     *
     * Where the guard stands now, whatever the audit log recorded about them,
     * and the two dated facts on the record — the licence and the hire.
     * Nothing here is a placeholder with a date attached.
     *
     * ORDER CARRIES MEANING. On a compliant guard the posting leads, because
     * where they stand is the thing being read. On a guard with an OPEN
     * COMPLIANCE CASE the case leads, because somebody opening this profile has
     * to know the officer cannot legally be on post before they read which post
     * that is. Same rows, same component, one question asked of the record.
     *
     * @return array<int, array{title: string, meta: string, danger: bool}>
     */
    public function deploymentHistory(Guard $guard): array
    {
        $posting = $guard->tenant_id === null
            ? ['title' => 'No current posting', 'meta' => 'Awaiting assignment', 'danger' => false]
            : [
                'title' => 'Assigned to '.($guard->estate->name ?? 'an estate').' — '.($guard->post?->label() ?? 'no post'),
                'meta' => 'Current posting',
                'danger' => false,
            ];

        $hired = [
            'title' => 'Hired by Gemini Security',
            'meta' => $guard->hired_on === null ? 'Date not recorded' : $this->longDate($guard->hired_on),
            'danger' => false,
        ];

        $compliance = [...$this->auditEvents($guard), $this->licenceEvent($guard)];

        if ($this->complianceCase($guard) !== null) {
            $compliance[] = $this->payrollEvent($guard);
        }

        return $this->complianceCase($guard) === null
            ? [$posting, ...$compliance, $hired]
            : [...$compliance, $posting, $hired];
    }

    /**
     * The compliance action screen — board 21, one guard, one open matter.
     *
     * There is no compliance_cases table and the Build Spec's entity list does
     * not name one, so nothing here is stored: the CASE is the licence state,
     * and the RECORD of what was done about it is the append-only audit log.
     * A status column would be a second home for the same fact, and the two
     * would disagree the first night a licence lapsed.
     *
     * @return array<string, mixed>
     */
    public function complianceAction(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'first_name' => Str::before($guard->full_name, ' '),
            'psra_number' => $guard->psra_number,
            'estate' => $guard->estate->name ?? 'Unassigned',
            'post' => $guard->post?->label(),
            'status_label' => $guard->statusLabel(),
            'suspended' => $guard->status === 'suspended',
            'contact_href' => $this->contactHref($guard),
            'case' => $this->complianceCase($guard),
            'impacts' => $this->complianceImpacts($guard),

            /*
             * Why the one live control on this board cannot be pressed, or
             * null when it can. Decided here rather than in the page, so the
             * button the reader sees and the rule `suspendFromDuty()` enforces
             * are the same sentence — a screen that offered a suspension the
             * service then refused would be worse than one that never offered.
             */
            'suspend_blocked_reason' => $this->suspensionBlockedReason($guard),
        ];
    }

    /**
     * Suspend a guard from active duty. The one write board 21 performs.
     *
     * Two things happen and both are the point: the guard's status changes,
     * and the audit log gains a row that cannot later be edited or removed. A
     * suspension is a decision taken about a person's livelihood, so the record
     * of who took it, when, and what the record looked like beforehand is not
     * optional book-keeping — it is the reason the control exists at all.
     *
     * WHAT DOES NOT HAPPEN IS AS IMPORTANT (D-034). The guard keeps their
     * tenant_id and their post_id. Deployed means POSTED AT THE ESTATE, not
     * compliant, and Phoenix Park Village 1 must go on seeing that the officer
     * assigned to their Service Gate is the one who cannot legally stand it.
     * Quietly unposting him here would clear the client's screen of the problem
     * while the problem was still standing at their gate.
     *
     * @return string what was recorded, for the reader who just did it
     *
     * @throws ValidationException when there is nothing to suspend for
     */
    public function suspendFromDuty(Guard $guard): string
    {
        $refusal = $this->suspensionBlockedReason($guard);

        if ($refusal !== null) {
            /*
             * The same sentence the disabled button carries. A refusal that
             * reached this far arrived from a stale page or a hand-made POST,
             * and either way the caller deserves the reason rather than a 500.
             */
            throw ValidationException::withMessages(['guard' => $refusal]);
        }

        $before = ['status' => $guard->status, 'post_id' => $guard->post_id, 'tenant_id' => $guard->tenant_id];

        DB::connection('mysql')->transaction(function () use ($guard, $before): void {
            $guard->forceFill(['status' => 'suspended'])->save();

            $this->audit->record(
                action: 'guard.suspended',
                entityType: 'Guard',
                // The row id, as every entry about a guard is keyed (bug hunt B017); the number is in the reason.
                entityId: (string) $guard->id,
                before: $before,
                after: [
                    'status' => 'suspended',
                    'post_id' => $guard->post_id,
                    'tenant_id' => $guard->tenant_id,
                    'reason' => $this->complianceCase($guard)['headline'] ?? 'PSRA licence cannot be shown to be current',
                ],
                tenantId: $guard->tenant_id,
            );
        });

        $where = $guard->post === null
            ? ' They hold no post.'
            : ' '.($guard->estate->name ?? 'The client').' still sees them assigned to '.$guard->post->label().'.';

        return $guard->full_name.' is suspended from active duty, and the suspension is on the audit log.'.$where;
    }

    /**
     * The estates a new guard can be posted to, with their posts.
     *
     * Scoped like every other read here: a Head of Security confined to their
     * assigned sites cannot post a new employee to an estate they cannot see.
     *
     * Each estate also carries what one more guard COSTS that client. Board 22
     * states the consequence in words — "this adds a 3rd guard to Emerald
     * Heights, taking their Security Provider add-on from $9,000/mo to
     * $13,500/mo" — and it is a real figure rather than a flourish: the
     * per-guard add-on is a row in `platform_rates`, so the two amounts are read
     * from the rate card and the client's own guard count, never assembled in
     * the browser. A client with no rate on file carries nulls and the screen
     * says nothing about billing rather than guessing at it.
     *
     * THE COUNT THE INVOICE BILLS, AND ONLY TO A ROLE THAT MAY READ IT (bug hunt
     * B011). The count was every guard row at the estate — suspended and on leave
     * included — which is neither the contracted number nor the one billed:
     * `InvoiceRun::draft()` bills each per-guard rate on the guards deployed at the
     * client (not on leave, not suspended) on the day the invoice is raised. It is
     * now that number, so the figure is the invoice's and the form can say the new
     * officer is on the next one. And what a client is charged is Billing &
     * subscriptions' (M10 §5): the Dispatcher and the Head of Security reach this
     * form through Guard workforce and hold no Billing, so for them the figures are
     * not sent at all — nor for a client with no billing subscription, which the
     * invoice run does not invoice.
     *
     * @return array<int, array{id: string, name: string, posts: array<int, array{id: int, name: string}>, guards_now: int, next_ordinal: string, addon_label?: string, addon_now?: string, addon_next?: string}>
     */
    public function postings(User $viewer): array
    {
        // The picker an operator posts a REAL officer through. The demonstration
        // estate must not be one of the choices (M5 §3): a guard posted there
        // would be paid by Gemini to stand a gate that does not exist, and would
        // vanish from the client's own coverage board.
        $estates = OperatorEstates::estates();
        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            $estates = $estates
                ->filter(fn (Tenant $estate): bool => in_array((string) $estate->getTenantKey(), $restricted, true))
                ->values();
        }

        $posts = Post::query()->where('is_active', true)->orderBy('name')->get();
        $counts = $this->deployedGuardsByEstate();
        $rate = PlatformMoney::maySeeRevenue($viewer) ? $this->addOnPerGuard() : null;
        $invoiced = $rate === null ? [] : $this->invoicedEstates();

        return $estates
            ->map(function (Tenant $estate) use ($posts, $counts, $rate, $invoiced): array {
                $id = (string) $estate->getTenantKey();
                $now = $counts[$id] ?? 0;

                $posting = [
                    'id' => $id,
                    'name' => $estate->name,
                    'posts' => $posts
                        ->where('tenant_id', $id)
                        ->map(fn (Post $post): array => ['id' => $post->id, 'name' => $post->label()])
                        ->values()
                        ->all(),
                    'guards_now' => $now,
                    'next_ordinal' => $this->ordinal($now + 1),
                ];

                // Absent rather than null or masked: nothing on the wire says a figure was taken out.
                if ($rate !== null && in_array($id, $invoiced, true)) {
                    $posting['addon_label'] = $rate['label'];
                    $posting['addon_now'] = MoneyFormatter::whole($now * $rate['minor'], $rate['currency']);
                    $posting['addon_next'] = MoneyFormatter::whole(($now + 1) * $rate['minor'], $rate['currency']);
                }

                return $posting;
            })
            ->all();
    }

    /**
     * The two employment types, as the board writes them.
     *
     * Read off the same constant the directory filters by, so the picker on the
     * Add Guard form can never offer a type the roster cannot then filter for.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function employmentTypes(): array
    {
        return array_map(
            fn (string $type): array => ['value' => $type, 'label' => $this->employmentLabel($type)],
            self::EMPLOYMENT_TYPES,
        );
    }

    /**
     * Create a guard employee record — board screen super-admin-22.
     *
     * WHAT THIS METHOD REFUSES IS THE POINT OF IT.
     *
     * A PSRA number and its expiry are compliance data, not two more strings on
     * a form. An expired licence sets un-rosterable — the Build Spec's words,
     * and a hard block rather than a warning — so a guard saved with a lapsed
     * date would be created into a state they can never work from: on the
     * payroll, on the compliance register, and refused by every roster and
     * assignment picker on the platform from the moment they exist. Accepting
     * that silently and letting the compliance screen discover it tomorrow is
     * the failure this refusal exists to prevent. The message says so in words
     * rather than reporting "invalid date".
     *
     * WHAT IS NOT ASKED FOR IS ALSO DELIBERATE. The board draws no employee
     * number field, and the number is not the operator's to invent: it is
     * Gemini Security's own sequence, allocated here, so two people onboarding
     * two guards on the same afternoon cannot both type GS-1072.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the record would be created into a state
     *                             it could not legally be worked from
     */
    public function add(User $viewer, array $input): Guard
    {
        /** @var array<string, mixed> $data */
        $data = $this->validateNewGuard($viewer, $input)->validate();

        $rateMinor = Money::of((string) $data['standard_rate'], MoneyFormatter::DEFAULT_CURRENCY)
            ->getMinorAmount()
            ->toInt();

        return DB::connection('mysql')->transaction(function () use ($data, $rateMinor): Guard {
            $guard = Guard::create([
                'full_name' => (string) $data['full_name'],
                'employee_number' => $this->nextEmployeeNumber(),
                'psra_number' => (string) $data['psra_number'],
                'psra_expires_on' => (string) $data['psra_expires_on'],
                'employment_type' => (string) $data['employment_type'],
                'standard_rate_minor' => $rateMinor,
                'standard_rate_currency' => MoneyFormatter::DEFAULT_CURRENCY,

                /*
                 * Active, and un-enrolled. The spec's rule is that "a guard
                 * cannot be rostered until the licence is recorded and the
                 * device is bound", and the second half of that is already
                 * modelled: `device_id` is null until a handset is bound to
                 * them, which is what `deviceIsBound()` reads. Inventing a
                 * sixth status to mean the same thing would give the platform
                 * two answers to one question.
                 */
                'status' => 'active',

                'phone' => (string) JamaicanNumber::normalise((string) $data['phone']),
                'email' => (string) $data['email'],

                // The day the record is made is the day the employment starts,
                // unless somebody says otherwise — and this board draws no
                // field where they could. A null hire date would read as "not
                // recorded" on a profile created ten seconds ago.
                'hired_on' => PlatformCalendar::today()->toDateString(),

                'tenant_id' => $this->nullableText($data['tenant_id'] ?? null),
                'post_id' => $this->nullableId($data['post_id'] ?? null),
            ]);

            /*
             * Creating an employee is an audited write, and the entry is
             * written inside the same transaction as the row it describes.
             * Outside it, a failure between the two would leave either a guard
             * nobody can account for or a log entry for a guard who does not
             * exist, and an audit trail that can be wrong in either direction
             * is not one.
             */
            $this->audit->record(
                action: 'guard.created',
                entityType: 'Guard',
                // The row id, as every entry about a guard is keyed (bug hunt B017); the number is in `after`.
                entityId: (string) $guard->id,
                before: null,
                after: [
                    'employee_number' => $guard->employee_number,
                    'full_name' => $guard->full_name,
                    'psra_number' => $guard->psra_number,
                    'psra_expires_on' => $guard->psra_expires_on?->toDateString(),
                    'employment_type' => $guard->employment_type,
                    'standard_rate_minor' => $guard->standard_rate_minor,
                    'standard_rate_currency' => $guard->standard_rate_currency,
                    'tenant_id' => $guard->tenant_id,
                    'post_id' => $guard->post_id,
                ],
                tenantId: $guard->tenant_id,
            );

            return $guard;
        });
    }

    /**
     * The next employee number in Gemini Security's own sequence.
     *
     * Read from the numbers already issued rather than from a counter, so it
     * stays correct after a restore, a reseed or a manually inserted record.
     * Only `GS-<digits>` is considered: the test suite creates guards with
     * numbers of its own shape, and one of those must never be able to push the
     * company's sequence somewhere it cannot come back from.
     *
     * The unique index on `employee_number` is the real guarantee. This is
     * called inside `add()`'s transaction, so two simultaneous onboardings
     * cannot both commit the same number — one of them fails on the index
     * rather than quietly issuing a duplicate.
     */
    public function nextEmployeeNumber(): string
    {
        // Past every number the unique index holds, a retired sample officer's too (M33 §5).
        $highest = Retirements::seeingEverything(static fn (): mixed => DB::connection('mysql')
            ->table('guards')
            ->whereRaw("employee_number regexp '^GS-[0-9]+$'")
            ->selectRaw('max(cast(substring(employee_number, 4) as unsigned)) as highest')
            ->value('highest'));

        return 'GS-'.max((int) $highest + 1, 1001);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Everything the Add Guard form must be true before a record is made.
     *
     * A validator rather than a FormRequest, and here rather than in the
     * controller, for the reason every other rule in this class lives here: the
     * console and the /api/v1 endpoints the mobile apps will call must not be
     * able to disagree about what a valid guard is. A rule enforced in an HTTP
     * request class is enforced on one route.
     *
     * @param  array<string, mixed>  $input
     * @return \Illuminate\Validation\Validator
     */
    private function validateNewGuard(User $viewer, array $input): \Illuminate\Contracts\Validation\Validator
    {
        $estates = $this->postings($viewer);

        /** @var array<int, string> $estateIds */
        $estateIds = array_column($estates, 'id');

        /** @var array<int, string> $postEstate  post id => the estate it stands at */
        $postEstate = [];

        foreach ($estates as $estate) {
            foreach ($estate['posts'] as $post) {
                $postEstate[$post['id']] = $estate['id'];
            }
        }

        /*
         * TODAY AT THE SITE THE OFFICER IS BEING POSTED TO (B008), or on the default
         * clock when they are not posted yet. Laravel's `today` is the server's UTC
         * date, which from 19:00 in Kingston is already tomorrow: a licence good
         * through today was refused as "already expired".
         */
        $chosen = $this->nullableText($input['tenant_id'] ?? null);
        $siteToday = SiteTime::today($chosen !== null && in_array($chosen, $estateIds, true) ? $chosen : null)->toDateString();

        $validator = Validator::make($input, [
            'full_name' => ['required', 'string', 'max:160'],

            // Unique among every officer on file, a retired sample officer too: the database's index holds them all (M33 §5).
            'psra_number' => ['required', 'string', 'max:32', static function (string $attribute, mixed $value, Closure $fail): void {
                if (Retirements::seeingEverything(static fn (): bool => DB::connection('mysql')->table('guards')->where('psra_number', $value)->exists())) {
                    $fail('That PSRA licence number already belongs to another guard on the platform.');
                }
            }],

            /*
             * `date` first, then `after_or_equal` the site's today. The two
             * failures are different facts about the world — "that is not a
             * date" and "that date has passed" — and the second one is the one
             * this screen exists to catch.
             */
            'psra_expires_on' => ['required', 'date', 'after_or_equal:'.$siteToday],

            // Required, because the office has to be able to reach the officer —
            // the enrolment code is read to them, not texted (M10 §1: there is no
            // SMS provider, and nothing sends one on guard creation). A guard
            // record with no way to reach the guard cannot start the sequence
            // the panel beside the form describes.
            // M32 F7: the example in its message is the country of the estate the officer is posted to.
            'phone' => ['required', 'string', 'max:40', new PhoneNumber($chosen !== null && in_array($chosen, $estateIds, true) ? $chosen : null)],

            // And an email (M18C §2: guard and staff records — name, phone, email). Nothing is optional.
            'email' => ['required', 'email', 'max:190', new ReceivesMail],

            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],

            // Nullable, and that is a domain fact rather than leniency: a guard
            // hired between postings is a real state the roster already draws.
            'tenant_id' => ['nullable', Rule::in($estateIds)],
            'post_id' => ['nullable', 'integer', Rule::in(array_keys($postEstate))],

            // Digits and at most two decimal places, so the string that reaches
            // Money::of() is one that currency can represent exactly. A float
            // never touches it.
            'standard_rate' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [
            'full_name.required' => 'A guard needs a name on their record.',
            'psra_number.required' => 'A PSRA licence number is what makes this officer licensable. It cannot be added later.',
            'psra_number.unique' => 'That PSRA licence number already belongs to another guard on the platform.',
            'psra_expires_on.required' => 'A licence with no expiry on file cannot be shown to be current, which is the same to a regulator as one that has lapsed.',
            'psra_expires_on.date' => 'That is not a real date.',
            'psra_expires_on.after_or_equal' => 'That licence has already expired. An expired PSRA licence is a hard block on rostering, so this guard would be un-rosterable from the moment the record exists — record the renewed licence and its new expiry instead.',
            'phone.required' => 'A phone number is how the office reaches this officer — to read them their Guard App enrolment code, among other things. Nothing is texted to it.',
            'email.required' => 'An email is how the office writes to this officer — their payslips and notices. Enter one.',
            'email.email' => 'That does not look like an email address. Example: andre.campbell@gmail.com.',
            'employment_type.required' => 'Choose whether this is a full-time or part-time position.',
            'tenant_id.in' => 'That client is not one you may post a guard to.',
            'post_id.in' => 'That post is not one you may post a guard to.',
            'standard_rate.required' => 'A standard hourly rate is an employment term, agreed at hire.',
            'standard_rate.regex' => 'Enter the hourly rate as an amount — 425 or 425.00, without a currency symbol.',
        ]);

        $validator->after(function ($validator) use ($input, $postEstate): void {
            $postId = $this->nullableId($input['post_id'] ?? null);

            if ($postId === null || ! array_key_exists($postId, $postEstate)) {
                return;
            }

            $tenantId = $this->nullableText($input['tenant_id'] ?? null);

            /*
             * A post belongs to exactly one estate, so a post chosen against
             * the wrong client is not a preference to reconcile — it would
             * create a guard standing a gate at an estate their record says
             * they do not work at, and the cross-client roster would draw them
             * under both.
             */
            if ($tenantId === null) {
                $validator->errors()->add('post_id', 'Choose the client first — a post belongs to one client.');

                return;
            }

            if ($postEstate[$postId] !== $tenantId) {
                $validator->errors()->add('post_id', 'That post is not at the client you chose.');
            }
        });

        return $validator;
    }

    /**
     * How many guards each estate has deployed right now — the number its invoice bills.
     *
     * `InvoiceRun::draft()`'s own definition, which the billing preview and the
     * client directory share: posted there, and neither on leave nor suspended
     * (bug hunt B011). Counting every row at the estate put suspended and on-leave
     * officers into a figure the invoice never bills.
     *
     * @return array<string, int>
     */
    private function deployedGuardsByEstate(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, count(*) as total')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * The estates the invoice run raises invoices for — a subscription in one of
     * its billing states (`InvoiceRun::draft()`: active or dunning). A client with
     * none is not invoiced, so the form quotes no invoice for it.
     *
     * @return list<string>
     */
    private function invoicedEstates(): array
    {
        return DB::connection('mysql')
            ->table('subscriptions')
            ->whereIn('status', ['active', 'dunning'])
            ->pluck('tenant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The per-guard Security Provider add-on from the platform rate card.
     *
     * Null when no such rate is on file, and the screen then says nothing about
     * billing at all. A default of any kind here would put a made-up monthly
     * figure in front of somebody about to hire.
     *
     * Only as a rate the invoice run bills per guard (`basis` guard, active), and
     * at the amount in force today — which the form says.
     *
     * @return array{label: string, minor: int, currency: string}|null
     */
    private function addOnPerGuard(): ?array
    {
        $row = DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', PlatformPricing::GUARD_RATE_KEY)
            ->where('basis', 'guard')
            ->where('is_active', true)
            ->select('label', 'amount_minor', 'currency')
            ->first();

        return $row === null
            ? null
            : ['label' => (string) $row->label, 'minor' => (int) $row->amount_minor, 'currency' => (string) $row->currency];
    }

    /** "1st", "2nd", "3rd", "11th" — the board's own phrasing for the next hire. */
    private function ordinal(int $number): string
    {
        $suffix = match (true) {
            $number % 100 >= 11 && $number % 100 <= 13 => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix;
    }

    /** A submitted value that means "nothing chosen", turned into a real null. */
    private function nullableText(mixed $value): ?string
    {
        return $this->text($value);
    }

    /** The same, for an id: an empty select posts '', which is not a post. */
    private function nullableId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $text = $this->text($value);

        return $text === null || ! ctype_digit($text) ? null : (int) $text;
    }

    /* ------------------------------------------------------------------ */

    /**
     * The open compliance matter against this guard, or null when there is none.
     *
     * Open when the licence cannot be shown to be current — lapsed, or with no
     * expiry on file at all. Both mean the same thing to a regulator asking
     * Gemini to produce a valid licence for the officer at the gate, so both
     * open a case.
     *
     * @return array{headline: string, detail: string}|null
     */
    private function complianceCase(Guard $guard): ?array
    {
        $state = $guard->licenceState();

        if ($state !== 'expired' && $state !== 'unknown') {
            return null;
        }

        $headline = $state === 'expired'
            ? $guard->psra_number.' expired '.$this->longDate($guard->psra_expires_on).' — '.$this->lapsedFor($guard)
            : $guard->psra_number.' has no expiry date on file';

        $where = $guard->post === null
            ? ' They hold no post at present.'
            : ' They are currently posted at '.($guard->estate->name ?? 'an unassigned estate').'’s '.$guard->post->label().'.';

        return [
            'headline' => $headline,
            'detail' => $guard->full_name.' cannot legally be on active duty until this is renewed.'.$where,
        ];
    }

    /**
     * Why this guard cannot be suspended right now, or null when they can.
     *
     * Two refusals, and neither is a technicality. Suspending a guard whose
     * licence is in order would be a disciplinary act dressed as a compliance
     * one, and this screen is not where that is decided. Suspending a guard who
     * is already suspended would write a second audit row saying nothing
     * changed, which is exactly the kind of noise that makes a log unreadable.
     */
    private function suspensionBlockedReason(Guard $guard): ?string
    {
        if ($guard->status === 'suspended') {
            return $guard->full_name.' is already suspended from active duty.';
        }

        if ($this->complianceCase($guard) === null) {
            return 'There is no open compliance matter against '.$guard->full_name
                .'. A licence in good standing is not grounds for a suspension.';
        }

        return null;
    }

    /**
     * Whether this guard may be put on a post at all.
     *
     * A licence that is merely expiring soon still licenses the officer today,
     * so it does not block: it is a date to plan around, and the register next
     * door is where it is planned around. Suspended and inactive block for a
     * different reason and are the same answer.
     *
     * `Guard::isRosterable()`, so this profile, the roster's picker and assign
     * and the client roster's pool and save give one answer (bug hunt B071).
     */
    private function isRosterable(Guard $guard): bool
    {
        return $guard->isRosterable();
    }

    /** Why this guard cannot be rostered, in one sentence, for a control's title. */
    private function blockedReason(Guard $guard): string
    {
        return match (true) {
            $guard->status === 'suspended' => $guard->full_name.' is suspended and cannot be rostered',
            $guard->status === 'inactive' => $guard->full_name.' is no longer an active employee',
            $guard->licenceState() === 'unknown' => $guard->psra_number.' has no expiry date on file, so this guard cannot be shown to be licensed',
            // Marked unlicensed by the office, on a date that has not yet lapsed.
            $guard->licenceState() !== 'expired' => $guard->full_name.'\'s record is marked licence expired, so this guard cannot be rostered until a renewal is recorded',
            default => $guard->psra_number.' lapsed on '.$this->longDate($guard->psra_expires_on)
                .', so this guard cannot legally be on post',
        };
    }

    /** "Licence expires" reads as a future promise on a licence that already lapsed. */
    private function licenceStatLabel(Guard $guard): string
    {
        return match ($guard->licenceState()) {
            'expired' => 'Licence expired',
            'unknown' => 'Licence expiry',
            default => 'Licence expires',
        };
    }

    /**
     * "14 days ago" — how long a lapsed licence has been lapsed, counted on the
     * site's calendar like `licenceDaysLeft()`, not from the server's UTC today.
     */
    private function lapsedFor(Guard $guard): string
    {
        $days = $guard->licenceDaysLeft();

        if ($days === null) {
            return 'date not recorded';
        }

        $ago = max(0, -$days);

        return $ago === 0 ? 'today' : $ago.' '.Str::plural('day', $ago).' ago';
    }

    /**
     * What the audit log recorded about this guard.
     *
     * BY THE GUARD'S ROW ID, which is how every entry about a guard is keyed
     * (bug hunt B017). This looked the log up by PSRA number, on the reasoning
     * that the log should carry the identifier a regulator recognises — but only
     * two writers keyed a guard that way (created, suspended), and every other
     * one by the row id: a licence renewal, a reassignment, released shifts, an
     * enrolment code, a handset rebind, a dispatch message. None of those ever
     * appeared here. And a PSRA number is not even stable: a renewal can replace
     * it, and the lookup by the current number then lost "Created" and
     * "Suspended" too. The row id never changes, and the number an entry was
     * about is in its own before/after.
     *
     * The entries written before that — keyed by a PSRA number this officer held,
     * then or since — are still theirs, and read by every number their renewals
     * record. Only a number that cannot be mistaken for a row id is read that way:
     * a licence number that is all digits is indistinguishable from another
     * officer's id, and a timeline that showed a stranger's reassignment would be
     * worse than one missing an entry the audit log itself still holds.
     *
     * @return array<int, array{title: string, meta: string, danger: bool}>
     */
    private function auditEvents(Guard $guard): array
    {
        $id = (string) $guard->id;

        /** @var list<string> $numbers every PSRA number this officer has held that is not also a possible row id */
        $numbers = AuditEntry::query()
            ->where('entity_type', 'Guard')
            ->where('entity_id', $id)
            ->where('action', 'guard.licence_renewed')
            ->get()
            ->flatMap(static fn (AuditEntry $renewal): array => [
                $renewal->before['psra_number'] ?? null,
                $renewal->after['psra_number'] ?? null,
            ])
            ->push($guard->psra_number)
            ->filter(static fn (mixed $number): bool => is_string($number) && $number !== '' && ! ctype_digit($number))
            ->unique()
            ->values()
            ->all();

        return AuditEntry::query()
            ->where('entity_type', 'Guard')
            ->where(static function (Builder $whose) use ($id, $numbers): void {
                $whose->where('entity_id', $id);

                if ($numbers !== []) {
                    $whose->orWhereIn('entity_id', $numbers);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'title' => Str::of($entry->action)->after('.')->replace('_', ' ')->ucfirst()->value(),
                'meta' => ($entry->actor_name ?? 'System').' · '.$this->longDate($entry->created_at),
                'danger' => Str::contains($entry->action, ['flag', 'suspend', 'revoke']),
            ])
            ->all();
    }

    /**
     * What the block costs the guard in pay, taken from the pay run itself.
     *
     * Read rather than asserted. A guard who cannot be rostered has no shifts
     * to be paid for, and the run's own payslips are the proof — so this row
     * says whether this guard is on the latest run, not what the rule is.
     *
     * @return array{title: string, meta: string, danger: bool}
     */
    private function payrollEvent(Guard $guard): array
    {
        $run = DB::connection('mysql')
            ->table('payroll_runs')
            ->orderByDesc('period_end')
            ->select('id', 'period_label')
            ->first();

        if ($run === null) {
            return [
                'title' => 'Not yet reached a pay run',
                'meta' => 'No payroll run has been calculated',
                'danger' => false,
            ];
        }

        $paid = DB::connection('mysql')
            ->table('payslips')
            ->where('payroll_run_id', $run->id)
            ->where('guard_id', $guard->id)
            ->exists();

        return $paid
            ? [
                'title' => 'Still on the '.$run->period_label.' pay run',
                'meta' => 'Calculated before the licence lapsed',
                'danger' => false,
            ]
            : [
                'title' => 'Left off the '.$run->period_label.' pay run',
                'meta' => 'A guard who cannot be rostered has no shifts to be paid for',
                'danger' => false,
            ];
    }

    /**
     * What an open compliance matter actually affects, board 21's middle panel.
     *
     * Three consequences, each read from a table rather than asserted: the post
     * standing uncovered, the pay run, and the client who can see this officer
     * listed under their own deployed guards (D-034).
     *
     * @return array<int, array{icon: string, title: string, meta: string}>
     */
    private function complianceImpacts(Guard $guard): array
    {
        $estate = $guard->estate->name ?? 'Unassigned';
        $payroll = $this->payrollEvent($guard);

        return [
            $guard->post === null
                ? [
                    'icon' => 'shifts',
                    'title' => 'No post held at present',
                    'meta' => 'Nothing at a client site is uncovered by this block',
                ]
                : [
                    'icon' => 'shifts',
                    'title' => $guard->post->label().' at '.$estate,
                    'meta' => 'That post is held by an officer who cannot legally stand it',
                ],
            [
                'icon' => 'billing',
                'title' => $payroll['title'],
                'meta' => $payroll['meta'],
            ],
            [
                'icon' => 'shield',
                'title' => 'Contract compliance risk for '.$estate,
                'meta' => 'The client sees this officer under the guards deployed at their estate',
            ],
        ];
    }

    /** Trims and caps a search term; null when there is nothing to search for. */
    private function searchTerm(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : mb_substr($text, 0, 120);
    }

    /**
     * The directory query: scope, then filters. Its order is the table's (M32 F5).
     *
     * @param  array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null}  $filters
     * @return Builder<Guard>
     */
    private function filtered(User $viewer, array $filters): Builder
    {
        $query = $this->joined($this->scoped($viewer));

        $this->applySearch($query, $filters['q']);

        if ($filters['client'] !== null) {
            $query->where('guards.tenant_id', $filters['client']);
        }

        if ($filters['status'] !== null) {
            $query->where('guards.status', $filters['status']);
        }

        if ($filters['employment'] !== null) {
            $query->where('guards.employment_type', $filters['employment']);
        }

        if ($filters['licence'] === 'expired') {
            $this->expiredLicence($query);
        }

        // `expiring` exactly as licenceState() means it: 0 to 30 days left on the site's calendar.
        if ($filters['licence'] === 'expiring') {
            $query->whereLicenceDaysLeft('>=', 0)->whereLicenceDaysLeft('<=', Guard::LICENCE_WARNING_DAYS);
        }

        // The order is the caller's: the directory's table (`roster()`), or the order its own caller gives.
        return $query;
    }

    /**
     * Joins the two tables the list displays and sorts by.
     *
     * Left joins, because a guard between postings is a real state rather than
     * a broken row, and an inner join would quietly drop them from the roster.
     *
     * @param  Builder<Guard>  $query
     * @return Builder<Guard>
     */
    private function joined(Builder $query): Builder
    {
        return $query
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->select('guards.*');
    }

    /**
     * @param  Builder<Guard>  $query
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }

        // The wildcards belong to the query, not to the person typing: without
        // this, a search for "50%" matches everybody.
        $like = '%'.addcslashes($term, '%_\\').'%';

        // "Full-time" is what the screen shows; full_time is what is stored.
        $employment = '%'.addcslashes(str_replace(['-', ' '], '_', mb_strtolower($term)), '%\\').'%';

        // M32 F4: a guard's phone however punctuated, and their email, beside everything the directory already read.
        $digits = Residents::phoneDigits($term);

        $query->where(function ($inner) use ($like, $employment, $digits): void {
            $inner->where('guards.full_name', 'like', $like)
                ->orWhere('guards.psra_number', 'like', $like)
                ->orWhere('guards.employee_number', 'like', $like)
                ->orWhere('guards.employment_type', 'like', $employment)
                ->orWhere('guards.email', 'like', $like)
                ->orWhere('tenants.name', 'like', $like)
                ->orWhere('posts.name', 'like', $like)
                // "Gate 5" finds the Back Gate's officers (M26).
                ->orWhere('posts.also_called', 'like', $like);

            if ($digits !== null) {
                $inner->orWhereRaw("REGEXP_REPLACE(COALESCE(guards.phone, ''), '[^0-9]', '') LIKE ?", ['%'.$digits.'%']);
            }
        });
    }

    /**
     * A lapsed licence, asked the same way `licenceState()` decides it.
     *
     * One predicate behind both the count and the filter, so the KPI card can
     * never disagree with the list it drills into.
     *
     * AND THE SAME WAY, WHICH IT WAS NOT (bug hunt B008). This compared the DATE
     * column with UTC `now()`, a date-time, so a licence expiring on the 24th read
     * as lapsed from 00:00 UTC on the 24th — 19:00 the evening before in Kingston —
     * while `licenceState()` rightly calls it good through the end of the 24th on
     * the site's clock. The card counted an officer the row beside it called
     * Active. Fewer than zero days left, on each officer's own estate's calendar.
     *
     * @param  Builder<Guard>  $query
     * @return Builder<Guard>
     */
    private function expiredLicence(Builder $query): Builder
    {
        return $query->whereLicenceDaysLeft('<', 0);
    }

    /**
     * Narrows the query to the estates this viewer may see.
     *
     * Head of Security is the one Gemini role scoped to assigned sites. The
     * scope is read from the ROLE, never inferred from whether assignment rows
     * happen to exist: inferring it would silently promote a newly created
     * Head of Security with no assignments yet into seeing every guard on the
     * platform.
     *
     * @return Builder<Guard>
     */
    private function scoped(User $viewer): Builder
    {
        $query = Guard::query();

        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            /*
             * NULL SURVIVES THE SCOPE, exactly as it survives the exclusion
             * below — and a bare `whereIn` did not let it.
             *
             * An officer taken on and not yet posted carries a null tenant_id:
             * `add()` writes it, the validator marks `tenant_id` nullable, and
             * the Add Guard form offers "Not posted yet" as its own first
             * choice. In SQL `NULL IN (…)` is NULL rather than true, so
             * `whereIn('guards.tenant_id', […])` deleted every one of them for a
             * site-scoped viewer. A Head of Security could add an officer,
             * leave the client on the form's default, and never see that
             * officer again on any screen this method feeds — the directory,
             * all four counters and the compliance register — while the PSRA
             * number and the employee number were both consumed. Their only
             * evidence the record existed was the duplicate-licence refusal when
             * they tried to enter the same person a second time.
             *
             * `OperatorEstates::exclude()` on the line below documents this
             * precise hazard in its own docblock and handles it; the comment
             * immediately above it — "written through `OperatorEstates` so that
             * a null tenant_id survives" — was true of the exclusion and not of
             * the scope four lines earlier.
             *
             * Grouped, so the OR cannot escape and widen the exclusion that
             * follows. Qualified, because `posts` also has a tenant_id and the
             * joined query would otherwise be ambiguous.
             *
             * A viewer with NO assignments now sees unposted officers rather
             * than nothing. That is the stated rule — the scope is read from the
             * role and an unposted officer belongs on every one of these
             * screens — and it is a deliberate consequence rather than a side
             * effect.
             */
            $query->where(static function (Builder $group) use ($restricted): void {
                $group->whereNull('guards.tenant_id')
                    ->orWhereIn('guards.tenant_id', $restricted);
            });
        }

        /*
         * The demonstration estate's two invented officers are not workforce
         * (M5 §3). They carry invented PSRA numbers with invented expiry dates,
         * and the compliance register — which is exported as a CSV and used to
         * chase real licences — listed them beside people who actually hold one.
         *
         * Qualified for the same reason the scope above is, and written through
         * `OperatorEstates` so that a null tenant_id survives: an officer taken
         * on and not yet posted belongs on every one of these screens.
         */
        OperatorEstates::exclude($query, 'guards.tenant_id');

        return $query;
    }

    /**
     * The estate ids this viewer is confined to, or null for the whole platform.
     *
     * @return list<string>|null
     */
    private function restrictedTo(User $viewer): ?array
    {
        return $viewer->widestScope() === AccessScope::AssignedSites
            ? $viewer->accessibleEstateIds()
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'initials' => $this->initials($guard->full_name),
            'employment_type' => $this->employmentLabel($guard->employment_type),
            'employment_key' => $guard->employment_type,
            'psra_number' => $guard->psra_number,
            'estate' => $guard->estate->name ?? 'Unassigned',
            'post' => $guard->post?->label() ?? '—',
            'status' => $guard->status,
            'status_label' => $guard->statusLabel(),
            'status_badge' => $guard->statusBadge(),

            // The board says "Review" on the row that needs one and "View" on
            // the rest, which is a real distinction rather than decoration.
            'action' => $guard->licenceState() === 'expired' ? 'Review' : 'View',
        ];
    }

    /** The badge class the board defines for each licence state. */
    private function licenceBadge(Guard $guard): string
    {
        return match ($guard->licenceState()) {
            'expired' => 'expired',
            'expiring' => 'soon',
            default => 'ok',
        };
    }

    /**
     * "Expired 14 days ago", "Expires today", "Renews in 87 days" — the board's phrasing.
     *
     * FROM `licenceDaysLeft()`, the number `licenceState()` and so the badge beside
     * these words are read from (bug hunt B008). This asked `isPast()` of the date,
     * against the server's UTC clock, so on a licence's last valid day the register
     * and its CSV said "Expired today" next to a "soon" badge — and "Expires today"
     * could never be printed at all. A licence is good through its expiry date on
     * the site's calendar: 0 days left is "Expires today", -1 is "Expired 1 day ago".
     */
    private function licenceCountdown(Guard $guard): string
    {
        $days = $guard->licenceDaysLeft();

        if ($days === null) {
            return 'No expiry recorded';
        }

        if ($days < 0) {
            return 'Expired '.abs($days).' '.Str::plural('day', abs($days)).' ago';
        }

        return $days === 0 ? 'Expires today' : 'Renews in '.$days.' '.Str::plural('day', $days);
    }

    /**
     * @return array{title: string, meta: string, danger: bool}
     */
    private function licenceEvent(Guard $guard): array
    {
        $number = $guard->psra_number;
        $expires = $this->longDate($guard->psra_expires_on);

        return match ($guard->licenceState()) {
            'expired' => [
                'title' => 'PSRA licence expired',
                'meta' => $number.' · lapsed '.$expires,
                'danger' => true,
            ],
            'expiring' => [
                'title' => 'PSRA licence expires within '.Guard::LICENCE_WARNING_DAYS.' days',
                'meta' => $number.' · '.$expires,
                'danger' => true,
            ],
            'valid' => [
                'title' => 'PSRA licence verified',
                'meta' => $number.' · valid to '.$expires,
                'danger' => false,
            ],
            default => [
                'title' => 'PSRA licence expiry not recorded',
                'meta' => $number.' · compliance cannot be confirmed',
                'danger' => true,
            ],
        };
    }

    /**
     * The gross on this guard's most recent payslip.
     *
     * Minor units in, a formatted string out, and nothing divided by hand on
     * the way. Ordered by the run's period rather than by row id, so a run
     * entered late does not read as the latest one.
     */
    private function latestGross(Guard $guard): ?string
    {
        $row = DB::connection('mysql')
            ->table('payslips')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.guard_id', $guard->id)
            ->orderByDesc('payroll_runs.period_end')
            ->select('payslips.gross_minor', 'payslips.currency')
            ->first();

        return $row === null
            ? null
            : MoneyFormatter::fromMinor((int) $row->gross_minor, (string) $row->currency);
    }

    /** A tel: link where there is a number, a mailto: where there is only mail. */
    private function contactHref(Guard $guard): ?string
    {
        if ($guard->phone !== null) {
            $dialable = preg_replace('/[^0-9+]/', '', $guard->phone) ?? '';

            if ($dialable !== '') {
                return 'tel:'.$dialable;
            }
        }

        return $guard->email === null ? null : 'mailto:'.$guard->email;
    }

    private function postType(Guard $guard): string
    {
        return $guard->post === null
            ? '—'
            : Str::of($guard->post->type)->replace('_', ' ')->ucfirst()->value();
    }

    private function employmentLabel(string $type): string
    {
        return Str::of($type)->replace('_', '-')->ucfirst()->value();
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** "Nov 30, 2026" — the board's date format. */
    private function longDate(?Carbon $date): string
    {
        return $date?->format('M j, Y') ?? 'Not recorded';
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        $text = $this->text($value);

        return $text !== null && in_array($text, $allowed, true) ? $text : null;
    }
}
