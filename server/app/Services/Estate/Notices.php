<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Document;
use App\Models\Estate\Notice;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\User;
use App\Services\Documents\Documents;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Estate notices — board 32.
 *
 * THE SEEN BAR IS COUNTED ON EVERY READ AND STORED NOWHERE. Board 32 draws 47%,
 * 71% and 88% against three notices, and that figure is the reason a committee
 * posts one and comes back to look at it. Both halves move: the numerator every
 * time somebody opens the notice, the denominator every time a household moves
 * in or out. A stored percentage is a photograph of a number that is still
 * changing, and it is the photograph a secretary would read.
 *
 * THE AUDIENCE IS A SCOPE, RESOLVED NOW. A notice addressed to "Phase 3 only"
 * means everybody who lives in Phase 3 today, not the list of people who lived
 * there when it was posted — so a household that moved in yesterday is counted
 * in the denominator of a notice about tomorrow's water. Freezing the list at
 * posting time would quietly report a notice as fully seen by an estate that
 * had grown since.
 *
 * PUBLISHING IS THE ACT, NOT WRITING. A notice with no `published_at` has told
 * nobody anything; the list draws published ones only. That separation is what
 * lets a secretary draft an announcement without a half-finished sentence
 * reaching four hundred and fifty households.
 */
class Notices
{
    /**
     * What the notices table orders by (M32 F5): its rows' own fields. Newest first by default — anything with a time.
     * "Read by" is the counted share, which is built per row and ordered here with the whole set.
     *
     * @return array<string, string>
     */
    public static function sorts(): array
    {
        return [
            'posted' => 'posted_sort',
            'title' => 'title',
            'kind' => 'kind_label',
            'audience' => 'audience',
            'seen' => 'seen_share',
        ];
    }

    /**
     * The figure the seen bar shows (GV-8; Hassam's whole rule, 10 October). Nobody: 0. Above nothing and below one
     * percent: "<1", since one reader of 456 is 0.2% and "0%" would say nobody has read it. One to 99 percent: the
     * rounded whole number. Above 99 percent and not everybody: ">99", since 455 of 456 rounds to "100%" and would say
     * everybody has. Everybody: 100.
     */
    public static function seenPercent(int $seen, int $audience): int|string
    {
        if ($seen === 0 || $audience === 0) {
            return 0;
        }

        if ($seen >= $audience) {
            return 100;
        }

        $share = $seen / $audience * 100;

        return match (true) {
            $share < 1 => '<1',
            $share > 99 => '>99',
            default => (int) round($share),
        };
    }

    /**
     * The true share behind that figure, which the bar's width and the table's order read. "<1" is words: as a width
     * it is no width at all, and the bar would draw full.
     */
    public static function seenShare(int $seen, int $audience): float
    {
        return $audience === 0 ? 0.0 : round($seen / $audience * 100, 2);
    }

    public static function table(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sorts(), 'posted', 'desc');
    }

    /**
     * Board 32, both columns. The left is every published notice the search admits, as a table ordered by the server
     * and paged (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function board(?TableQuery $table = null): array
    {
        $table ??= self::table();

        $notices = Notice::query()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get();

        $ids = $notices->map(static fn (Notice $notice): int => (int) $notice->id)->all();

        /*
         * One query for every audience size on the screen rather than one per
         * notice. Three notices is three counts either way; four hundred and
         * fifty residents across five phases is not, and a list screen that
         * issues a COUNT per row is the shape that is fine in review and slow
         * on a real estate.
         */
        $byPhase = $this->residentsByPhase();
        $everybody = array_sum($byPhase);

        /*
         * BOTH HALVES OF THE BAR ARE TAKEN BY THE SAME RULE — `audienceOf`, over the
         * register as it stands today. The numerator is asked for BY PHASE and resolved
         * exactly as the denominator is, which is the only way the two can be counting
         * the same people. A plain count of receipts is not: a household that has moved
         * from Phase 3 to Phase 5 keeps the receipt it left on a Phase 3 notice while
         * leaving that notice's audience, and the bar then draws past 100% — the same
         * shape as the removed member the `removed_at IS NULL` below already answers.
         * The Resident App's line is counted this way too (`householdReach`), so the
         * two screens differ in the UNIT they count and never in the figure.
         */
        $reads = $this->readsByPhase($ids);

        /*
         * WHAT WENT WITH EACH NOTICE, NAMED BACK (M4 §3.5). The composer takes a file;
         * without this the office presses Post, the list draws the notice, and nothing
         * on the screen ever says whether the contractor's letter went with it — so the
         * only way to find out would be to open a resident's phone. One query, as above.
         */
        $files = $this->attachmentsFor($ids);

        $rows = $notices->map(function (Notice $notice) use ($byPhase, $everybody, $reads, $files): array {
            $audience = self::audienceOf($notice, $byPhase, $everybody);
            $read = $reads[(int) $notice->id] ?? [];
            $seen = self::audienceOf($notice, $read, array_sum($read));

            return [
                'id' => $notice->id,
                'kind' => $notice->kind,
                'kind_label' => $notice->kindLabel(),
                'title' => $notice->title,
                'body' => $notice->body,

                // "Posted by Delroy Samuels · 1 Sep, 9:00 AM": the board's own separator, and Law 5's form (M62 A5,
                // DR-039) with no clock tag. A day alone carried "JA" after it (Security's dev-server note, 12 Oct).
                'meta' => 'Posted by '.$notice->byline().' · '.SiteTime::local($notice->published_at)->format('j M, g:i A'),
                'posted' => SiteTime::local($notice->published_at)->format('j M Y'),
                'posted_sort' => $notice->published_at?->toIso8601String(),
                'audience' => $notice->audienceLabel(),

                /*
                 * Rounded for the bar and never for the arithmetic. A
                 * notice nobody can read — an audience of zero, which is a
                 * real state for a phase with no households yet — is 0%
                 * rather than a division by zero.
                 */
                'seen_pct' => self::seenPercent($seen, $audience),
                'seen_share' => self::seenShare($seen, $audience),
                'seen_count' => $seen,
                'audience_size' => $audience,

                // The file's NAME, never its path — the console opens it by id on the documents route, the
                // same as a statement, and nothing on this screen is an address anybody could follow.
                'attachments' => array_map(
                    static fn (Document $document): array => ['id' => (int) $document->id, 'name' => $document->filename],
                    $files[(int) $notice->id] ?? [],
                ),
            ];
        })->values()->all();

        // The notices' search (M32 F5): what each says — its title and its words.
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['title'].' '.$row['body']), $needle)));
        }

        $page = $table->pageOf($rows);

        return [
            'rows' => $page->items(),
            'table' => $table->meta($page),

            'audiences' => $this->audienceOptions($byPhase, $everybody),
            'kinds' => [
                ['key' => Notice::GENERAL, 'label' => 'General'],
                ['key' => Notice::URGENT, 'label' => 'Urgent'],
            ],
        ];
    }

    /**
     * How many residents live in each phase.
     *
     * Joined through the unit rather than stored on the resident, because a
     * household's phase is a fact about its address and moving house is a change
     * of unit. See `Unit::phase()` — the relation is on the NAME, since `block`
     * has carried "Phase 2" as a string since the estate was first seeded.
     *
     * @return array<string, int>
     */
    private function residentsByPhase(): array
    {
        // `removed_at IS NULL` is the Resident model's own `current` scope, which this raw
        // query is outside of: somebody the primary resident has taken off the register is
        // not in the audience of anything, exactly as they are nowhere else in the console.
        // …and a retired sample resident or household is in nobody's audience either (M33 §5).
        $rows = DB::connection('tenant')->select('
            SELECT u.block AS phase, COUNT(r.id) AS residents
              FROM residents r
              JOIN households h ON h.id = r.household_id
              JOIN units u ON u.id = h.unit_id
             WHERE r.removed_at IS NULL'.Retirements::and('tenant', ['r' => 'residents', 'h' => 'households']).'
             GROUP BY u.block
        ');

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->phase] = (int) $row->residents;
        }

        return $counts;
    }

    /**
     * How many PEOPLE have read each of these notices, by the phase they live in TODAY —
     * board 32's numerator, in the shape `audienceOf` resolves.
     *
     * COUNTED WHERE THEY LIVE NOW, not where they lived when they read it. A receipt is a
     * fact about a person, and a person's phase is a fact about their address; bucketing
     * by the phase joined through the unit is what lets the numerator be narrowed to the
     * notice's audience by the same rule the denominator is. `removed_at IS NULL` is the
     * Resident model's own `current` scope, which this raw query is outside of.
     *
     * One query for the whole list, never one per notice (see `board()`).
     *
     * @param  list<int>  $noticeIds
     * @return array<int, array<string, int>> keyed by notice id, then by phase
     */
    private function readsByPhase(array $noticeIds): array
    {
        if ($noticeIds === []) {
            return [];
        }

        $found = [];

        foreach (DB::connection('tenant')->select('
            SELECT nr.notice_id AS notice_id, u.block AS phase, COUNT(*) AS residents
              FROM notice_reads nr
              JOIN residents r ON r.id = nr.resident_id
              JOIN households h ON h.id = r.household_id
              JOIN units u ON u.id = h.unit_id
             WHERE r.removed_at IS NULL'.Retirements::and('tenant', ['nr' => 'notice_reads', 'r' => 'residents', 'h' => 'households']).'
               AND nr.notice_id IN ('.implode(',', array_fill(0, count($noticeIds), '?')).')
             GROUP BY nr.notice_id, u.block
        ', $noticeIds) as $row) {
            $found[(int) $row->notice_id][(string) $row->phase] = (int) $row->residents;
        }

        return $found;
    }

    /**
     * WHO A NOTICE IS FOR, AS A NUMBER — the one rule both counts are taken by.
     *
     * A phase notice is for whoever is in that phase TODAY, an estate-wide one
     * for everybody: the scope is resolved now (see the head of this class).
     * Board 32's bar asks it of residents and the Resident App's line asks it of
     * households, and both ask it HERE, so the two screens can differ in the
     * unit they count and never in who they think the notice was addressed to.
     *
     * @param  array<string, int>  $byPhase
     */
    private static function audienceOf(Notice $notice, array $byPhase, int $everybody): int
    {
        return $notice->audience_scope === Notice::ONE_PHASE
            ? ($byPhase[(string) $notice->audience_phase] ?? 0)
            : $everybody;
    }

    /**
     * "Seen by 212 of 450 households" — the Resident App's line under a notice
     * (board resident-app-36, M4 §3.5).
     *
     * THE SAME COUNT AS BOARD 32'S BAR, IN HOUSEHOLDS. The bar counts receipts
     * over the roll, person by person, because a committee wants to know how
     * many PEOPLE it reached. A phone says "households" — the board's own word —
     * so the unit changes and nothing else does: the same `notice_reads` rows,
     * the same join through the unit to the phase, the same audience resolved
     * now (`audienceOf`). A household has seen a notice once ANYBODY in it has
     * opened it; a household with nobody on the register cannot open anything
     * and is not in the audience, exactly as it adds nobody to the bar's roll.
     *
     * A member the primary resident has removed is no longer of the household
     * (`Resident`'s own scope), so their receipt stops counting here — which also
     * keeps the seen figure from ever exceeding the audience it is drawn against.
     *
     * Two queries for the whole list, never one per notice (see `board()`).
     *
     * @param  iterable<Notice>  $notices
     * @return array<int, array{seen: int, audience: int}> keyed by notice id
     */
    public function householdReach(iterable $notices): array
    {
        $notices = collect($notices)->values();

        if ($notices->isEmpty()) {
            return [];
        }

        $byPhase = [];

        foreach (DB::connection('tenant')->select('
            SELECT u.block AS phase, COUNT(DISTINCT h.id) AS households
              FROM residents r
              JOIN households h ON h.id = r.household_id
              JOIN units u ON u.id = h.unit_id
             WHERE r.removed_at IS NULL'.Retirements::and('tenant', ['r' => 'residents', 'h' => 'households']).'
             GROUP BY u.block
        ') as $row) {
            $byPhase[(string) $row->phase] = (int) $row->households;
        }

        $seenByPhase = [];
        $ids = $notices->map(static fn (Notice $notice): int => (int) $notice->id)->all();

        foreach (DB::connection('tenant')->select('
            SELECT nr.notice_id AS notice_id, u.block AS phase, COUNT(DISTINCT h.id) AS households
              FROM notice_reads nr
              JOIN residents r ON r.id = nr.resident_id
              JOIN households h ON h.id = r.household_id
              JOIN units u ON u.id = h.unit_id
             WHERE r.removed_at IS NULL'.Retirements::and('tenant', ['nr' => 'notice_reads', 'r' => 'residents', 'h' => 'households']).'
               AND nr.notice_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
             GROUP BY nr.notice_id, u.block
        ', $ids) as $row) {
            $seenByPhase[(int) $row->notice_id][(string) $row->phase] = (int) $row->households;
        }

        $everybody = array_sum($byPhase);
        $reach = [];

        foreach ($notices as $notice) {
            $seen = $seenByPhase[(int) $notice->id] ?? [];

            $reach[(int) $notice->id] = [
                // A household is in ONE phase, so the per-phase figures add up without counting anybody twice.
                'seen' => self::audienceOf($notice, $seen, array_sum($seen)),
                'audience' => self::audienceOf($notice, $byPhase, $everybody),
            ];
        }

        return $reach;
    }

    /** What a notice attachment may be, and how large. A letter, a map, a photo — not an archive, not a program. */
    public const ATTACHMENT_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const ATTACHMENT_MAX_KB = 10240;

    /**
     * Put a file with a notice — through the documents path (M4 §3.5).
     *
     * KEPT, NOT UPLOADED SOMEWHERE ELSE. `Documents::keep` writes the bytes to
     * the estate's private disk, hashes them, and refuses to call the document
     * ready unless what is on disk is what was given — the same terms as a
     * statement. The same file put with the same notice twice is one document.
     *
     * THE NAME IS THE OFFICE'S, MADE SAFE. It is what a resident sees and what
     * their phone saves the file as, and it becomes part of a path on disk — so
     * it is reduced to a plain file name before either.
     *
     * EVERY RULE ABOUT WHAT MAY BE KEPT IS HELD HERE — the type, the emptiness AND
     * the size. The size used to be the composer's validator alone, one level above
     * the two rules it belongs with, so this method would have kept a file of any
     * size for any caller that did not happen to have that validator in front of it.
     * `NoticesController` still validates as well: a rule enforced twice refuses
     * earlier and in the field, which is a better refusal, not a duplicated one.
     */
    public function attach(Notice $notice, string $filename, string $contents, string $contentType, User $by): Document
    {
        if (! in_array($contentType, self::ATTACHMENT_TYPES, true)) {
            throw new DomainException('A notice can carry a PDF, a Word document, or a JPEG or PNG picture.');
        }

        if ($contents === '') {
            throw new DomainException('That file is empty, so there is nothing to put with the notice.');
        }

        if (strlen($contents) > self::ATTACHMENT_MAX_KB * 1024) {
            throw new DomainException(sprintf(
                'That file is larger than %d MB, which is the most a notice can carry.',
                intdiv(self::ATTACHMENT_MAX_KB, 1024),
            ));
        }

        return app(Documents::class)->keep(
            kind: Document::NOTICE_ATTACHMENT,
            title: $notice->title,
            filename: self::safeFilename($filename),
            contents: $contents,
            contentType: $contentType,
            by: $by,
            subjectType: self::ATTACHMENT_SUBJECT,
            subjectId: (string) $notice->id,
        );
    }

    /** What a notice's documents are filed under. */
    public const ATTACHMENT_SUBJECT = 'notice';

    /**
     * The files with each of these notices that are READY to be handed out,
     * oldest first — the order the office added them in.
     *
     * One query for the whole list. A document still being written, or one that
     * failed its hash, is not an attachment anybody can open and is not listed.
     *
     * @param  list<int>  $noticeIds
     * @return array<int, list<Document>> keyed by notice id
     */
    public function attachmentsFor(array $noticeIds): array
    {
        if ($noticeIds === []) {
            return [];
        }

        $found = [];

        Document::query()
            ->where('kind', Document::NOTICE_ATTACHMENT)
            ->where('subject_type', self::ATTACHMENT_SUBJECT)
            ->whereIn('subject_id', array_map(static fn (int $id): string => (string) $id, $noticeIds))
            ->where('status', Document::READY)
            ->whereNotNull('path')
            ->orderBy('id')
            ->get()
            ->each(static function (Document $document) use (&$found): void {
                $found[(int) $document->subject_id][] = $document;
            });

        return $found;
    }

    /** One ready attachment OF THIS NOTICE, or null — an id that belongs to another notice is not this notice's. */
    public function attachment(Notice $notice, int $id): ?Document
    {
        return Document::query()
            ->whereKey($id)
            ->where('kind', Document::NOTICE_ATTACHMENT)
            ->where('subject_type', self::ATTACHMENT_SUBJECT)
            ->where('subject_id', (string) $notice->id)
            ->where('status', Document::READY)
            ->whereNotNull('path')
            ->first();
    }

    private static function safeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', trim($filename)));
        $name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name), ' .');
        $name = (string) preg_replace('/\.{2,}/', '.', $name);

        return $name === '' ? 'attachment' : mb_substr($name, -120);
    }

    /**
     * What the composer's audience field offers.
     *
     * Estate-wide first, because board 30's own notification default calls these
     * "Estate-wide announcements from Governance" — the whole estate is the
     * ordinary case and a phase is the narrowing.
     *
     * @param  array<string, int>  $byPhase
     * @return list<array{key: string, phase: string|null, label: string, size: int}>
     */
    private function audienceOptions(array $byPhase, int $everybody): array
    {
        $options = [[
            'key' => Notice::ESTATE_WIDE,
            'phase' => null,
            'label' => 'Everybody on the estate',
            'size' => $everybody,
        ]];

        foreach (Phase::query()->orderBy('sequence')->orderBy('name')->pluck('name') as $phase) {
            $options[] = [
                'key' => Notice::ONE_PHASE,
                'phase' => (string) $phase,
                'label' => $phase.' only',
                'size' => $byPhase[(string) $phase] ?? 0,
            ];
        }

        return $options;
    }

    /**
     * Write a notice and tell the estate, in one act.
     *
     * REFUSED WITHOUT A TITLE AND A BODY, and the refusal says why rather than
     * failing validation silently: a notice with an empty body is a headline
     * four hundred and fifty households get pushed at them with nothing to read.
     *
     * THE AUTHOR IS RECORDED EVEN WHEN THE ROLE IS SHOWN. `posted_as_role`
     * changes what the estate sees; it never changes what the record says about
     * who did it.
     *
     * @param  array<string, mixed>  $input
     */
    public function post(array $input, User $by): Notice
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new DomainException(
                'A notice needs a heading and something to say. An announcement with an empty body is a '.
                'headline every household is pushed and cannot read.'
            );
        }

        $scope = ($input['audience_scope'] ?? Notice::ESTATE_WIDE) === Notice::ONE_PHASE
            ? Notice::ONE_PHASE
            : Notice::ESTATE_WIDE;

        $phase = $scope === Notice::ONE_PHASE ? trim((string) ($input['audience_phase'] ?? '')) : null;

        if ($scope === Notice::ONE_PHASE && ($phase === null || $phase === '')) {
            throw new DomainException('A notice addressed to one phase has to say which phase.');
        }

        return Notice::create([
            'kind' => ($input['kind'] ?? Notice::GENERAL) === Notice::URGENT ? Notice::URGENT : Notice::GENERAL,
            'title' => $title,
            'body' => $body,
            'audience_scope' => $scope,
            'audience_phase' => $phase,
            'author_id' => $by->getKey(),
            'author_name' => $by->name,

            /*
             * Published immediately, because board 32's composer has one button
             * and it says "Post notice". A draft state exists in the schema —
             * `published_at` is nullable — and nothing on this screen creates
             * one, which is the honest position: an estate has not asked for
             * drafts and a control nobody drew is a control nobody wants.
             */
            'published_at' => now(),
        ]);
    }

    /**
     * Record that a resident has read a notice.
     *
     * Idempotent by the table's own unique key rather than by checking first:
     * two devices opening the same notice at the same moment is a real race, and
     * a read-then-write would let both through.
     */
    public function markRead(Notice $notice, Resident $resident): void
    {
        DB::connection('tenant')->table('notice_reads')->insertOrIgnore([
            'notice_id' => $notice->id,
            'resident_id' => $resident->id,
            'read_at' => now(),
        ]);
    }
}
