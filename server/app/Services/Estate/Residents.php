<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Api\AppMatrix;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\SampleResidents;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The estate's structure and the people in it — boards 3, 4, 31, 34 and 38.
 *
 * ONE FUNCTION DECIDES WHAT A SCREEN MAY SAY ABOUT A HOUSEHOLD'S STANDING, and
 * it is `standingOf()`. Boards 4 and 38 both draw a balance where a household's
 * standing goes and board 31 draws an ageing bucket, so there were three places
 * a figure could have escaped and there is now one. It is a whole-object method
 * for the same reason `Household::guardVisibleStanding()` is: a serialiser
 * concern spread across three payload builders is three places to audit and
 * three places to forget.
 *
 * IT REFUSES IN TWO DIRECTIONS AND THE ORDER MATTERS.
 *
 *   1. A RESTRICTED HOUSEHOLD GETS AMBER AND THE WORD. Never the balance, never
 *      the number of days, never an ageing bucket, never a payment history —
 *      nothing a guard or a neighbour standing at a desk could read off a
 *      screen. D-025 fixed the wording and it is the same wording here as at the
 *      gate, so a committee member and a guard cannot be told different things
 *      about the same household. This is checked FIRST, before the ledger is
 *      consulted at all, so no future edit to the balance arithmetic can reach a
 *      restricted household by accident.
 *
 *   2. A VIEWER WITHOUT `estate.dues_ledger.view` GETS NO FIGURE EITHER. The
 *      Property Manager is locked out of the ledger by platform invariant rather
 *      than estate preference (D-010) — "whoever commissions work must never be
 *      able to pay for it, nor see a resident's financial position" — and a
 *      resident detail screen that printed a balance would be a side door into
 *      exactly that. The caller passes the answer in; this class does not read
 *      the gate itself, because a service that consulted the container for a
 *      permission would be a second copy of the route matrix.
 *
 * EVERY BALANCE IS THE LEDGER READ BACK. Nothing here sums `charges` less
 * `payments`: a household's balance is `Dues::unitBalances()`, which is the sum
 * of the unit's own lines on 1200 Dues Receivable. A residents screen with its
 * own arithmetic over the billing tables would agree with the arrears board by
 * coincidence, and the coincidence is what fails when somebody reconciles them.
 *
 * A CLAIM APPROVAL POSTS NOTHING. Binding a person to a household decides whose
 * guest passes they may issue and whose gate they may be admitted at; it changes
 * nothing about what the unit owes and it does not clear a restriction. Board
 * 31's own note says so, and `EstateResidentsTest` proves it by counting journal
 * entries either side of an approval.
 */
class Residents
{
    /**
     * The only thing a screen ever says about a restricted household.
     *
     * Byte-for-byte what `RestrictionPolicy` returns to a guard (D-025). Two
     * different sentences for the same fact is how a resident comes to be told
     * one thing at the gate and another at the office.
     */
    public const RESTRICTED_LABEL = 'Access restricted — contact management';

    /** What a Balance cell reads for a viewer the ledger is closed to. */
    public const WITHHELD_LABEL = 'Balance not shown';

    /**
     * Why a LIST withholds balances from a viewer who does hold the ledger (M10 §5).
     *
     * The Admin Assistant holds Dues & ledger as Entry and may read the lot in
     * front of them — board 38 and the unit ledger still show that household's
     * own balance. What they may not read is every household's balance listed
     * side by side, which is the estate's arrears picture a page at a time.
     * `standingOf()`'s own sentence says the ledger is missing, which would be
     * false about them, so the list screens pass this one instead. It names no
     * figure, for the reason the restricted wording names none.
     */
    public const LIST_POSITION_WITHHELD = 'Every household\'s standing side by side is the estate\'s own picture, and reading it on a list needs Reports access as well as Dues & ledger. Open a household to see its own balance.';

    /**
     * ASSUMPTION Q-014 — may a role locked out of the ledger be told a unit is
     * in arrears at all?
     *
     * Board 31's own persona is the Property Manager and its third card prints
     * "Tanya Simms — 90+ days arrears" in the On-record column. D-010 locks that
     * role out of a resident's financial position entirely. Both cannot hold, and
     * an ageing bucket IS a financial position — it is the label the arrears
     * ageing report is built out of.
     *
     * TRUE is the safe reading: the bucket is appended only for a viewer who
     * holds `estate.dues_ledger.view`, and the Property Manager reviewing a claim
     * sees the identity flag and nothing about money. Flip this to false only on
     * a client ruling that the flag is a risk signal rather than a ledger fact;
     * `EstateResidentsTest` asserts the safe behaviour and is the test that will
     * be rewritten when the ruling lands.
     */
    public const ARREARS_FLAG_NEEDS_LEDGER_ACCESS = true;

    /**
     * How many household members board 38's panel names before it rolls the
     * rest into a count.
     *
     * TWO, which is what the board draws: Andrea, Marlon, then "1 additional
     * member" for a household of three. The cap is a privacy affordance and not
     * a layout one — a detail screen naming every occupant of a house is a
     * roster of who lives where, and the panel exists to tell a reviewer who
     * holds the household rather than who sleeps in it.
     */
    public const MEMBERS_NAMED = 2;

    /**
     * How many households a list screen ships at once.
     *
     * Board 4 draws six rows, a search box and five phase chips, and draws no
     * pagination control at all — so there is no affordance for a second page
     * and inventing one would be inventing a screen. Four hundred and thirty
     * three rows in a page's props is not a list, it is a download, so the
     * filters narrow and this caps. The total is carried beside the rows, so a
     * page can say what it is showing out of what.
     *
     * ---------------------------------------------------------------------
     * THAT REASONING WAS HALF RIGHT AND COST 391 HOUSEHOLDS THEIR ONLY ROW.
     *
     * The cap is correct — a page's props are not a place to put 441 rows. What
     * was wrong was the conclusion drawn from the board: it draws no pager, so
     * none was built, and the register simply STOPPED at the fiftieth
     * most-recently-active household with no way to reach the others. A
     * committee member looking for a named resident found the browser's own
     * find answering "0/0" while that resident sat on a page nothing could
     * reach. The board not drawing a control is a statement about the picture,
     * not about whether four hundred households are allowed to exist.
     *
     * So the cap became a PAGE SIZE. Twenty-five, matching
     * `GuardWorkforce::PER_PAGE` on the Gemini side, so the two consoles
     * paginate at the same rhythm and a reader moving between them is not
     * learning a second set of habits.
     */
    public const PER_PAGE = 25;

    /**
     * Kept, and no longer used to truncate the register.
     *
     * Other callers still ask for "a screenful" — the dashboard's recent rows,
     * the claims queue — and they mean this rather than a page of a paginated
     * list. Removing it would change screens this work is not about.
     */
    public const LIST_LIMIT = 50;

    /**
     * The columns the register may be ordered by, and their SQL.
     *
     * ONLY REAL COLUMNS. `Balance` and `Status` are deliberately absent:
     * `standingOf()` builds the balance per row in PHP from `Dues::unitBalances()`
     * and `householdIsPending()` derives the status against the pending-claim
     * list, so neither exists to ORDER BY. Offering them would sort the
     * twenty-five rows already fetched and present it as sorting the register —
     * the reader would page through four hundred households seeing each page
     * "sorted by balance" and never reach the largest debt on the estate.
     *
     * `unit` and `phase` live on the related table, so they order by a subquery
     * rather than a join: a join would multiply rows against `residents` and
     * quietly change `withCount`. A lot in its own order: Lot 2 before Lot 10.
     * A household nobody has seen is last whichever way activity is ordered: a
     * null is not the most recent activity there has ever been.
     *
     * @return array<string, string|\Closure>
     */
    public static function householdSorts(): array
    {
        return [
            'household' => 'households.name',
            'unit' => static fn ($query, string $dir) => $query->orderByRaw('CHAR_LENGTH(unit_reference) '.$dir)->orderBy('unit_reference', $dir),
            'phase' => 'unit_block',
            'members' => 'residents_count',
            'active' => static fn ($query, string $dir) => $query->orderByRaw('households.last_active_at IS NULL')->orderBy('households.last_active_at', $dir),
        ];
    }

    /** The Households tab as its address asks for it (M32 F5): newest active first, the order board 4 draws. */
    public static function householdsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::householdSorts(), 'active', 'desc');
    }

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* the one place a household's standing is decided */
    /* ------------------------------------------------------------------ */

    /**
     * What a screen may say about this household's standing, and nothing more.
     *
     * `$balances` and `$buckets` are passed in by the list screens, which have
     * already read every unit's balance in one query — asking per row would put
     * four hundred round trips behind a table. A caller with one household in
     * front of it passes neither and this reads them.
     *
     * ON A LIST, THE RESTRICTION GOES WITH THE BALANCE (M10 §5). A household
     * is restricted because it is behind past the estate's threshold, so an
     * amber "Access restricted" on some rows of a register and not others is a
     * list of who is behind — the arrears register by the one word this
     * function lets through. `$hideRestriction` is how a list screen says its
     * viewer may not read households' standing together; the row then reads
     * exactly as an unrestricted one does, and the verdict stays where it is
     * one household in front of the reader: board 38, and the gate.
     *
     * @param  bool  $maySeeMoney  whether the VIEWER holds `estate.dues_ledger.view`
     * @param  array<int, int>|null  $balances  unit id => minor units owed
     * @param  array<int, string>|null  $buckets  unit id => ageing bucket key
     * @param  string|null  $withheldReason  what to say instead of the ledger sentence when `$maySeeMoney` is false for another reason — see `LIST_POSITION_WITHHELD`
     * @param  bool  $hideRestriction  a list, for a viewer who may not read households' standing together — see `Dues::maySeeEstatePosition()`
     * @return array{
     *     restricted: bool,
     *     tone: string,
     *     label: string,
     *     balance_minor: int|null,
     *     bucket: string|null,
     *     bucket_label: string|null,
     *     withheld_reason: string|null
     * }
     */
    public function standingOf(
        Household $household,
        bool $maySeeMoney,
        ?array $balances = null,
        ?array $buckets = null,
        ?string $withheldReason = null,
        bool $hideRestriction = false,
    ): array {
        /*
         * Money goes BEFORE the restriction check is skipped, so the restricted
         * household this lets past the first branch meets the withheld one
         * next and can never reach a balance. Skipping the verdict never shows
         * more than the verdict would have.
         */
        if ($hideRestriction) {
            $maySeeMoney = false;
        }

        /*
         * RESTRICTION IS ANSWERED FIRST, and before the ledger is touched.
         *
         * Ordering it this way is the guarantee: the restricted path returns
         * before a balance has been looked up, so no later edit to the
         * arithmetic below — a new column, a different bucket rule, a caching
         * layer — can put a figure in front of somebody who may not have one.
         * The same ordering, for the same reason, as the exemption check in
         * `RestrictionPolicy::decide()`.
         */
        if ($household->access_restricted && ! $hideRestriction) {
            return [
                'restricted' => true,
                'tone' => 'amber',
                'label' => self::RESTRICTED_LABEL,
                'balance_minor' => null,
                'bucket' => null,
                'bucket_label' => null,
                /*
                 * EVEN THIS SENTENCE CARRIES NO FIGURE AND NO MONEY WORD. It
                 * ships to the browser with the rest of the payload, so a
                 * reason that named what was being withheld would be the leak
                 * describing itself. `EstateResidentsTest` scans the whole
                 * standing for a digit and for the vocabulary of money, and
                 * this string is inside what it scans.
                 */
                'withheld_reason' => 'A restricted household shows the verdict and nothing else. '
                    .'Amber and the words, the same words a guard is given at the gate: no figure, '
                    .'no ageing, no history, nothing a person at a desk could read off the screen.',
            ];
        }

        if (! $maySeeMoney) {
            return [
                'restricted' => false,
                'tone' => 'neutral',
                'label' => self::WITHHELD_LABEL,
                'balance_minor' => null,
                'bucket' => null,
                'bucket_label' => null,
                'withheld_reason' => $withheldReason ?? 'A resident\'s financial position needs Dues & ledger view access. '
                    .'You are able to read everything else on this screen.',
            ];
        }

        $unitId = $household->unit_id;

        /*
         * THIS HOUSEHOLD'S UNIT, NOT THE ESTATE'S EVERY UNIT (M11 A2). A caller
         * with one household in front of it used to have every balance on the
         * estate read, and every charge walked for the buckets, to print one cell.
         * Its balance and its bucket from one read of the balance: `standings()`.
         */
        if ($balances === null) {
            $standing = $this->dues->standings(null, [$unitId]);
            $balances = $standing['balances'];
            $buckets ??= $standing['buckets'];
        }

        $balance = $balances[$unitId] ?? 0;

        if ($balance <= 0) {
            // Zero renders as "$0" and never as a blank or an em dash — board 4
            // is explicit, and a household that owes nothing has to be
            // distinguishable from one nobody has billed.
            return [
                'restricted' => false,
                'tone' => 'clear',
                'label' => '$0',
                'balance_minor' => 0,
                'bucket' => null,
                'bucket_label' => null,
                'withheld_reason' => null,
            ];
        }

        $buckets ??= $this->dues->unitBuckets(null, [$unitId]);
        $bucket = $buckets[$unitId] ?? 'current';

        return [
            'restricted' => false,
            'tone' => 'owing',
            'label' => $this->money($balance),
            'balance_minor' => $balance,
            'bucket' => $bucket,
            'bucket_label' => Dues::BUCKET_LABELS[$bucket] ?? $bucket,
            'withheld_reason' => null,
        ];
    }

    /**
     * A unit with nobody in it — board 38 drawn against a vacant lot.
     *
     * Not a standing of zero, and not a call to `standingOf()` with an invented
     * household: a vacant unit HAS a ledger and may well owe money (nine of
     * Phoenix Park's 450 are vacant and every one of them is billed), but there
     * is no household whose standing this could be. The arrears belong on the
     * unit ledger screen, where they are addressed to a lot rather than to a
     * family that is not there.
     *
     * @return array{
     *     restricted: bool,
     *     tone: string,
     *     label: string,
     *     balance_minor: int|null,
     *     bucket: string|null,
     *     bucket_label: string|null,
     *     withheld_reason: string|null
     * }
     */
    private function vacantStanding(): array
    {
        return [
            'restricted' => false,
            'tone' => 'neutral',
            'label' => 'Vacant unit',
            'balance_minor' => null,
            'bucket' => null,
            'bucket_label' => null,
            'withheld_reason' => 'No household occupies this unit. What the lot owes is on its own '
                .'ledger, addressed to the address rather than to a family that is not there.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 3 — estate structure */
    /* ------------------------------------------------------------------ */

    /**
     * The phase cards, with every count derived from the units themselves.
     *
     * FOUR OF THE SIX FIGURES ON A CARD ARE NOT STORED. The unit count, the
     * occupied count, the vacant count and the occupancy bar are one GROUP BY
     * over `units`; only the block count and the officer count are columns,
     * because nothing in this database can answer those. A phase row that
     * carried its own unit total would be free to say 92 about a phase somebody
     * had added a lot to.
     *
     * CONTENT RESIDUAL — board 3 gives Phase 5 seventy units, seventy-eight
     * occupied and no vacancies, which is not something one estate can be. The
     * occupancy is clamped where the estate is built (`EstateFinanceSeeder`), so
     * this derives 70 / 70 / 0 and the bar reads 100%. Recorded rather than
     * reproduced: a card that printed more households than houses would be a
     * screen contradicting itself in public.
     *
     * @return array<string, mixed>
     */
    public function structureBoard(): array
    {
        $counts = $this->unitCountsByPhase();
        $cards = [];

        foreach (Phase::query()->orderBy('sequence')->get() as $phase) {
            $count = $counts[$phase->name] ?? ['units' => 0, 'occupied' => 0];
            $units = $count['units'];
            $occupied = $count['occupied'];

            $cards[] = [
                'id' => $phase->id,
                'name' => $phase->name,
                'sequence' => $phase->sequence,
                'tag' => $units.' unit'.($units === 1 ? '' : 's'),
                'unit_count' => $units,
                'occupied' => $occupied,
                'vacant' => $units - $occupied,

                /*
                 * Rounded to a whole percent, which is what the board's inline
                 * widths are, and clamped so a phase whose occupancy somehow
                 * exceeded its units could never draw a bar past its track.
                 */
                'occupancy_pct' => $units === 0 ? 0 : min(100, (int) round($occupied / $units * 100)),
                'status' => $phase->status,
                'foot' => $phase->footLine(),
                'block_count' => $phase->block_count,
                'officers_assigned' => $phase->officers_assigned,

                // From the site plan (M57.5 §4.1, §4.3): the phase's run of lots, and its blocks by name and size.
                'lots' => $phase->lotsLabel(),
                'blocks' => array_map(static fn (array $b): array => [
                    'letter' => (string) ($b['letter'] ?? ''),
                    'units' => (int) ($b['units'] ?? 0),
                    'lots' => implode(', ', array_map(static fn (array $run): string => $run[0] === $run[1] ? 'Lot '.$run[0] : $run[0].' to '.$run[1], $b['lots'] ?? [])),
                ], $phase->blocks ?? []),
            ];
        }

        // The estate's lots (M60 §C2), so the totals are the five phase cards added up. Both counts in one read: as two,
        // the structure page ran a query over its budget whenever it was the first page in its process to count lots.
        // Occupied is a lot with one of the estate's households on it (M87 §1.4), never the flag.
        [$occupiedSql, $occupiedBindings] = self::occupiedSql();
        $totals = Unit::lots()->toBase()->selectRaw("count(*) as units, coalesce(sum({$occupiedSql}), 0) as occupied", $occupiedBindings)->first();
        $units = (int) data_get($totals, 'units', 0);
        $occupied = (int) data_get($totals, 'occupied', 0);

        return [
            'phases' => $cards,
            'totals' => [
                'units' => $units,
                'occupied' => $occupied,
                'vacant' => $units - $occupied,
            ],

            /*
             * Units standing in a block no phase row claims. Zero on a healthy
             * estate, and drawn rather than swallowed because the alternative is
             * a structure screen whose cards quietly sum to less than the estate
             * — which is exactly the arithmetic a committee checks it against.
             */
            'unplaced_units' => $units - array_sum(array_column($cards, 'unit_count')),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 4 — the residents list */
    /* ------------------------------------------------------------------ */

    /**
     * Every household the filters admit, with its standing decided once.
     *
     * A LIST IS NOT ONE HOUSEHOLD (M10 §5). Twenty-five balances a page, four
     * hundred households deep, is the arrears register by another door — so a
     * viewer who holds the ledger and may not read the estate's position
     * (`Dues::maySeeEstatePosition()`, asked by the controller) is shown this
     * list the way a viewer without the ledger is: "Balance not shown", and a
     * sentence that is true about them. Opening the household still shows its
     * own balance — `residentBoard()` is not a list and is not narrowed.
     *
     * AND THE SAME VIEWER IS NOT SHOWN WHICH ROWS ARE RESTRICTED, which is the
     * same fact by one word — see `standingOf()`'s `$hideRestriction`. That
     * covers every role that fails the rule, the three with no ledger as well
     * as the Admin Assistant; each still reads the verdict on board 38.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @param  bool  $maySeePosition  whether the viewer may read every household's standing together — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function listBoard(bool $maySeeMoney, string $phase = '', ?TableQuery $table = null, bool $maySeePosition = true): array
    {
        $table ??= self::householdsTable();
        $search = $table->search;
        $showMoney = $maySeeMoney && $maySeePosition;
        $withheldReason = $maySeeMoney && ! $maySeePosition ? self::LIST_POSITION_WITHHELD : null;

        $claimed = $this->unitsUnderClaim();

        // The estate's households (M87 §1.4), the rows the dashboard and a meeting's quorum count: one with a resident,
        // on a lot. The tab's total is the estate's number.
        $query = Household::query()
            ->counted()
            ->with(['unit', 'residents'])
            ->withCount('residents')

            /*
             * The unit's lot and phase, as sortable scalars.
             *
             * Subqueries rather than a join: `withCount('residents')` counts a
             * related table, and joining `units` alongside it multiplies the
             * rows the count is taken over. These also give the ORDER BY
             * something on the outer query to name, which `whereHas` does not.
             */
            ->addSelect([
                'unit_reference' => Unit::query()
                    ->select('reference')
                    ->whereColumn('units.id', 'households.unit_id')
                    ->limit(1),
                'unit_block' => Unit::query()
                    ->select('block')
                    ->whereColumn('units.id', 'households.unit_id')
                    ->limit(1),
            ]);

        if ($phase !== '') {
            $query->whereHas('unit', fn (Builder $unit) => $unit->where('block', $phase));
        }

        if ($search !== '') {
            /*
             * THE SAME SEARCH AS THE PEOPLE TAB (M32 F4). It used to read name, lot and phase alone, and "soft" found
             * nothing although a resident's email held it. A household is found by any of its people (name, email,
             * phone), its lot, its phase, its own name and its vehicles — and, with nobody on it yet, by its own name,
             * lot or phase still.
             */
            $people = Resident::query()
                ->join('households', 'households.id', '=', 'residents.household_id')
                ->leftJoin('units', 'units.id', '=', 'households.unit_id');
            self::matchPeople($people, $search);
            $like = '%'.addcslashes(trim($search), '%_\\').'%';

            $query->where(function (Builder $outer) use ($people, $like): void {
                $outer->whereIn('households.id', $people->select('residents.household_id'))
                    ->orWhere('households.name', 'like', $like)
                    ->orWhereHas('unit', function (Builder $unit) use ($like): void {
                        $unit->where('reference', 'like', $like)->orWhere('block', 'like', $like);
                    });
            });
        }

        /*
         * The order asked for, or newest active first — the order board 4 draws: today, yesterday, two days, three,
         * five, a week. `id` last, always (`TableQuery::order`): every column has ties — two households on the same
         * lot letter, four with the same member count, hundreds never seen — and MySQL is free to order ties
         * differently between two queries. Under pagination that is not cosmetic: a household can appear on page two
         * and again on page three while another is never shown at all.
         */
        $table->order($query, 'households.id');

        $paginator = $table->paginate($query);
        $total = $paginator->total();

        $households = $paginator->getCollection();

        /*
         * Read only where a row will print them: a scan of the receivable for a
         * viewer who may not have it is a read nobody should have made. And read
         * for THIS PAGE'S twenty-five units only (M11 A2) — the balances and
         * buckets of the households not on the page were being summed from the
         * whole ledger, and every charge on the estate walked, to print none of
         * them. A unit's balance and bucket are its own, so each row's is the
         * same either way. Both from one read of those balances: `standings()`.
         */
        $pageUnits = $households->pluck('unit_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        ['balances' => $balances, 'buckets' => $buckets] = $showMoney
            ? $this->dues->standings(null, $pageUnits)
            : ['balances' => [], 'buckets' => []];

        $rows = [];
        $latest = self::latestHouseholdByUnit($pageUnits);

        foreach ($households as $household) {
            // The person who holds the household, and whoever is there where
            // nobody has been marked — a row with no name reads as a broken
            // screen rather than as a household nobody has filled in.
            $resident = $household->residents->firstWhere('is_primary', true)
                ?? $household->residents->first();

            $unit = $household->unit;
            $pending = $this->householdIsPending($household, $claimed);

            $rows[] = [
                'id' => $household->id,
                'unit_id' => $household->unit_id,
                'unit_slug' => $unit?->slug(),
                // M32 F1: which household of the lot the row's link opens, when the lot has more than one.
                'household_param' => self::householdParam((int) $household->unit_id, (int) $household->id, $latest),
                'household' => $household->name,
                'name' => $resident->full_name ?? $household->name,
                'initials' => $resident?->initials() ?? '??',
                'email' => $resident->email ?? null,
                'unit' => $unit?->addressLabel() ?? '',
                'phase' => $unit->block ?? '',
                'members' => (int) $household->residents_count,
                'standing' => $this->standingOf($household, $showMoney, $balances, $buckets, $withheldReason, hideRestriction: ! $maySeePosition),
                'verification' => $pending ? Resident::PENDING : Resident::VERIFIED,
                'verification_label' => Resident::STATUS_LABELS[$pending ? Resident::PENDING : Resident::VERIFIED],
                'last_active' => $this->lastActiveLabel($household->last_active_at),

                // M32 F1: held by one of the demonstration's placeholders, marked rather than passed off as a person.
                'sample' => SampleResidents::marked($resident?->isSample() ?? false),
            ];
        }

        return [
            ...$this->registerFrame(),
            'filters' => ['phase' => $phase, 'search' => $search],

            /*
             * WHAT AN EMPTY FILTERED LIST SAYS, AND WHAT ITS BUTTON CLEARS (B113).
             * The page used to word it for the phase chip alone, so a search with
             * no chip read "No household is filed in " and its "All phases" kept
             * the search — reloading the same empty list. Which filter emptied it
             * is known here, so it is said here.
             */
            'empty_filtered' => $this->emptyFilteredCopy($phase, $search),
            'rows' => $rows,
            'total' => $total,
            'shown' => count($rows),

            /*
             * What the table's headings and pager read (M32 F5, `TableQuery::meta`): how it is ordered, the columns
             * the server can order by — so the page cannot invent one — and where in the register this page is.
             */
            'table' => $table->meta($paginator),

            'money_visible' => $showMoney,

            // What the Balance heading says when the column is withheld, so the
            // page does not have to guess which of the two reasons applies.
            'money_reason' => $showMoney ? null : ($withheldReason ?? 'A resident\'s financial position needs Dues & ledger view access. You are able to read everything else on this register.'),
        ];
    }

    /**
     * What the register's page carries whichever tab is open: the claims banner and the phase chips.
     *
     * @return array{pending: array{count: int, title: string, body: string}, phase_chips: list<array{value: string, label: string}>}
     */
    public function registerFrame(): array
    {
        $pendingClaims = (int) UnitClaim::query()->where('status', UnitClaim::PENDING)->count();

        return [
            /*
             * The amber banner, and its count is the same query board 31's
             * heading runs. Two counts of the same thing is how a banner comes
             * to promise three claims to a screen holding two.
             */
            'pending' => [
                'count' => $pendingClaims,
                'title' => $pendingClaims.' unit claim'.($pendingClaims === 1 ? '' : 's').' need'
                    .($pendingClaims === 1 ? 's' : '').' review',
                'body' => 'Self-claimed units that didn\'t match our records exactly',
            ],

            // "All phases" is synthetic and always first; the rest are the
            // phases the estate actually has, in their own order.
            'phase_chips' => [
                ['value' => '', 'label' => 'All phases'],
                ...Phase::query()
                    ->orderBy('sequence')
                    ->get()
                    ->map(fn (Phase $p): array => ['value' => (string) $p->name, 'label' => (string) $p->name])
                    ->all(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the register's People tab (M32 F1) */
    /* ------------------------------------------------------------------ */

    /**
     * What the People tab orders by. `lot` is the unit's natural order (Lot 2 before Lot 10).
     *
     * @return array<string, string|\Closure>
     */
    public static function peopleSorts(): array
    {
        return [
            'name' => 'residents.full_name',
            'email' => 'residents.email',
            'lot' => static fn ($query, string $dir) => $query->orderByRaw('CHAR_LENGTH(units.reference) '.$dir)->orderBy('units.reference', $dir),
            'phase' => 'units.block',
            'household' => 'households.name',
            'role' => 'residents.is_primary',
            'state' => 'residents.status',
            'added' => 'residents.created_at',
        ];
    }

    /** The People tab as its address asks for it (M32 F5): name order by default — it is a register. */
    public static function peopleTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::peopleSorts(), 'name');
    }

    /**
     * THE REGISTER SHOWS PEOPLE (M32 F1). It listed households, each under its primary's name, so a member had no row
     * of their own: hassam, added at Lot 413 under a seeded primary, was on no page the office could find. This is
     * every current resident of the estate, one row each: name, email, phase and lot, household, primary or member,
     * state (invited, pending, active) and when they were added, and whether they are one of the demonstration's
     * placeholders (`SampleResidents`), marked rather than hidden. Name order by default: it is a register.
     *
     * @return array<string, mixed>
     */
    public function peopleBoard(string $phase = '', ?TableQuery $table = null): array
    {
        $table ??= self::peopleTable();
        $search = $table->search;

        $query = Resident::query()
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->leftJoin('units', 'units.id', '=', 'households.unit_id')
            ->whereNull('households.removed_at')
            ->select([
                'residents.*',
                'households.name as household_name',
                'households.unit_id as household_unit_id',
                'units.reference as unit_reference',
                'units.block as unit_block',
            ]);

        if ($phase !== '') {
            $query->where('units.block', $phase);
        }

        if ($search !== '') {
            self::matchPeople($query, $search);
        }

        // `id` last, always: every column above has ties, and a tie ordered two ways is a row on two pages.
        $table->order($query, 'residents.id');
        $paginator = $table->paginate($query);
        $people = $paginator->getCollection();

        $units = Unit::query()->whereIn('id', $people->pluck('household_unit_id')->filter()->unique()->all())->get()->keyBy('id');
        $invites = app(ResidentInvites::class)->latestForEach($people);
        $latest = self::latestHouseholdByUnit($units->keys()->map(static fn ($id): int => (int) $id)->all());

        $rows = $people->map(function (Resident $person) use ($units, $invites, $latest): array {
            $unit = $units->get((int) $person->getAttribute('household_unit_id'));
            [$state, $label] = self::stateOf($person, $invites[(int) $person->id] ?? null);

            return [
                'id' => (int) $person->id,
                'name' => (string) $person->full_name,
                'initials' => $person->initials(),
                'email' => $person->email,
                'phase' => (string) ($person->getAttribute('unit_block') ?? ''),
                'unit' => $unit?->addressLabel() ?? (string) ($person->getAttribute('unit_reference') ?? ''),
                'unit_slug' => $unit?->slug(),
                'household_id' => (int) $person->household_id,
                // Which household of the lot the row's link opens, when the lot has more than one.
                'household_param' => self::householdParam($unit === null ? null : (int) $unit->id, (int) $person->household_id, $latest),
                'household' => (string) ($person->getAttribute('household_name') ?? ''),
                'role' => $person->is_primary ? 'primary' : 'member',
                'role_label' => $person->is_primary ? 'Primary' : 'Member',
                'state' => $state,
                'state_label' => $label,
                // On the estate's own clock: a person added late on the 3rd in Kingston was added on the 3rd. Law 5 as
                // decided (M62 A5, DR-039): "3 Oct 2026", and no zone on the estate's own console.
                'added' => $person->created_at === null ? null : SiteTime::local($person->created_at)->format('j M Y'),
                'sample' => SampleResidents::marked($person->isSample()),
            ];
        })->values()->all();

        return [
            'filters' => ['phase' => $phase, 'search' => $search],
            'rows' => $rows,
            'total' => $paginator->total(),
            'shown' => count($rows),
            'table' => $table->meta($paginator),
            'empty_filtered' => $this->emptyFilteredCopy($phase, $search, 'everybody'),
        ];
    }

    /**
     * A person's state on the register: active (identity established), invited (an invite out and not yet taken up), or
     * pending (waiting on the office, or on nothing sent at all).
     *
     * @return array{0: string, 1: string}
     */
    public static function stateOf(Resident $person, ?ResidentInvite $invite): array
    {
        if ($person->isVerified()) {
            return ['active', 'Active'];
        }

        return in_array(ResidentInvites::outcome($invite)['status'], ['sent', 'not_sent', 'expired'], true)
            ? ['invited', 'Invited']
            : ['pending', 'Pending'];
    }

    /**
     * EACH LOT'S LATEST HOUSEHOLD (M32 F1). A lot can hold more than one household now — Add resident's "a new, separate
     * household at this lot" — and the household page shows the latest when it is not told which (`Unit::household()`),
     * so a link to any other names the one it means.
     *
     * @param  list<int>  $unitIds
     * @return array<int, int> unit id => its latest current household's id
     */
    private static function latestHouseholdByUnit(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $latest = [];

        foreach (Household::query()->whereIn('unit_id', $unitIds)->groupBy('unit_id')->selectRaw('unit_id, MAX(id) AS latest')->get() as $row) {
            $latest[(int) $row->getAttribute('unit_id')] = (int) $row->getAttribute('latest');
        }

        return $latest;
    }

    /**
     * The `?household=` a link to a household's page carries (M32 F1): none for its lot's latest, which the page shows
     * anyway, so an ordinary lot's link is what it always was.
     *
     * @param  array<int, int>  $latest  `latestHouseholdByUnit()`
     */
    private static function householdParam(?int $unitId, int $householdId, array $latest): ?int
    {
        return $unitId === null || ($latest[$unitId] ?? null) === $householdId ? null : $householdId;
    }

    /**
     * THE ONE SEARCH BOX, ON EVERY FIELD A PERSON IS KNOWN BY (M32 F4). "soft" found nothing although a resident's email
     * was softdev.571@gmail.com: the search read names, lots and phases only. It matches, case-insensitively, on any part
     * of the resident's name, email or phone (however punctuated: 876 555 0271, 8765550271 and +18765550271 are one
     * number), the lot (with or without "Lot"), the phase, the household's name, and its vehicles' plates.
     *
     * For a query on `residents` joined to `households` and `units` under those names.
     *
     * @param  Builder<Resident>  $query
     */
    public static function matchPeople(Builder $query, string $search): void
    {
        $search = trim($search);
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = self::phoneDigits($search);
        // "Lot 413" also finds a unit the estate calls just "413"; the plain match below already finds "Lot 413" itself.
        $lot = preg_match('/^lot\s*(\S.*)$/i', $search, $m) === 1 ? trim($m[1]) : null;
        $plate = self::plateCondition($search);

        $query->where(function (Builder $w) use ($like, $digits, $lot, $plate): void {
            $w->where('residents.full_name', 'like', $like)
                ->orWhere('residents.email', 'like', $like)
                ->orWhere('households.name', 'like', $like)
                ->orWhere('units.block', 'like', $like)
                ->orWhere('units.reference', 'like', $like);

            if ($lot !== null) {
                $w->orWhere('units.reference', $lot);
            }

            if ($digits !== null) {
                $w->orWhereRaw("REGEXP_REPLACE(COALESCE(residents.phone, ''), '[^0-9]', '') LIKE ?", ['%'.$digits.'%']);
            }

            if ($plate !== null) {
                $w->orWhereExists(static fn ($v) => $v->select(DB::raw(1))->from('household_vehicles')
                    ->whereColumn('household_vehicles.household_id', 'households.id')
                    ->whereRaw($plate[0], $plate[1]));
            }
        });
    }

    /**
     * The person's half of `matchPeople()` (M32 F4) — name, email and phone however punctuated — for a query on
     * `residents` alone, such as inside a `whereHas('household.residents')`. Every current member, not only the primary.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  a query on the `residents` table, however the relation that reached it is typed
     */
    public static function matchResident(Builder $query, string $search): void
    {
        $search = trim($search);
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = self::phoneDigits($search);

        $query->where(static function (Builder $w) use ($like, $digits): void {
            $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like);

            if ($digits !== null) {
                $w->orWhereRaw("REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') LIKE ?", ['%'.$digits.'%']);
            }
        });
    }

    /**
     * A vehicle plate as the search reads it, spaces and dashes aside (M32 F4): "PK 7707", "pk-7707" and "PK7707" are one
     * plate. The SQL condition on a `household_vehicles` row, and its binding.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function plateCondition(string $search): ?array
    {
        $plate = strtoupper((string) preg_replace('/[\s-]+/', '', trim($search)));

        return $plate === '' ? null : ["REPLACE(REPLACE(UPPER(household_vehicles.plate), ' ', ''), '-', '') LIKE ?", ['%'.addcslashes($plate, '%_\\').'%']];
    }

    /**
     * The digits of a search that could be a phone number, or null. Four digits at least, so a lot number is not read as
     * one; a leading 1 of an eleven-digit number is the country code, which a stored number may not carry.
     */
    public static function phoneDigits(string $search): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $search);

        if (strlen($digits) < 4 || preg_match('/[a-z]/i', $search) === 1) {
            return null;
        }

        return strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;
    }

    /* ------------------------------------------------------------------ */
    /* board 31 — unit claim review */
    /* ------------------------------------------------------------------ */

    /**
     * The pending claims, each as two columns a reviewer compares.
     *
     * THE COMPARISON IS THE SCREEN. Every row is a pair — what the claimant
     * typed against what the estate holds — and which column is amber is derived
     * from the difference rather than stored, because a register that is
     * corrected between submission and review should stop being flagged.
     *
     * THE QUEUE IS A LIST TOO (M10 §5). A reviewer comparing a claimant against
     * the register needs the identity, not the household's ageing — and a
     * queue of claims each carrying "— 90+ days arrears" and a balance is the
     * arrears register for the households under review. A viewer who may not
     * read the estate's position is treated here as `listBoard()` treats them,
     * restriction included: approving a claim neither reads nor touches it
     * (`approveClaim()`), so the reviewer loses nothing the decision needs.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @param  bool  $maySeePosition  whether the viewer may read households' standing together — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function claimsBoard(bool $maySeeMoney, bool $maySeePosition = true, ?TableQuery $table = null): array
    {
        $table ??= self::claimsTable();

        // Narrowed once, here, so every read below — the balances, the
        // standing, the restriction and the arrears suffix — answers the same
        // question.
        $withheldReason = $maySeeMoney && ! $maySeePosition ? self::LIST_POSITION_WITHHELD : null;
        $maySeeMoney = $maySeeMoney && $maySeePosition;
        $hideRestriction = ! $maySeePosition;

        $query = UnitClaim::query()
            ->with(['unit.household.residents', 'household.residents'])
            ->where('status', UnitClaim::PENDING);

        if ($table->search !== '') {
            $like = '%'.addcslashes($table->search, '%_\\').'%';
            $digits = self::phoneDigits($table->search);

            $query->where(function (Builder $claim) use ($like, $digits): void {
                $claim->where('submitted_name', 'like', $like)
                    ->orWhere('submitted_lot', 'like', $like)
                    ->orWhere('submitted_phase', 'like', $like)
                    ->when($digits !== null, static fn (Builder $q) => $q->orWhereRaw("REPLACE(REPLACE(REPLACE(submitted_phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$digits.'%']));
            });
        }

        // M32 F8, F5: newest first by default, by when the claim was made — a person working the queue wants the one
        // that just arrived — and a page of the queue at a time, in whichever order its headings ask for.
        $table->order($query, 'unit_claims.id');
        $paginator = $table->paginate($query);

        $claims = $paginator->getCollection();
        $notes = ResidentInvites::claimNotes($claims);

        /*
         * Read once, and only where they can be read at all. A viewer without
         * ledger access never reaches past the second refusal in
         * `standingOf()`, so walking the whole receivable to build maps it will
         * not consult would be a scan of the estate's ledger performed for
         * somebody who is not allowed to see it.
         */
        /*
         * AND ONLY FOR THE HOUSEHOLDS IN THE QUEUE (M11 A2): a handful of claims
         * does not need the ledger of every unit on the estate read for them.
         */
        $claimUnits = $claims
            ->map(static fn (UnitClaim $claim): ?int => ($claim->household ?? $claim->unit?->household)?->unit_id)
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        ['balances' => $balances, 'buckets' => $buckets] = $maySeeMoney
            ? $this->dues->standings(null, $claimUnits)
            : ['balances' => [], 'buckets' => []];

        $cards = [];

        foreach ($claims as $claim) {
            $household = $claim->household ?? $claim->unit?->household;
            $standing = $household === null
                ? null
                : $this->standingOf($household, $maySeeMoney, $balances, $buckets, $withheldReason, $hideRestriction);

            $cards[] = [
                'id' => $claim->id,
                'title' => $claim->titleLine(),

                // The queue's own row (M32 F5): when, who, where, what kind, and what the matcher made of it.
                'made' => $claim->created_at === null ? null : SiteTime::local($claim->created_at)->format('j M Y, g:i A'),
                'claimant' => $claim->claim_type === UnitClaim::TYPE_UNVERIFIED ? 'Unknown claimant' : (string) $claim->submitted_name,
                'lot' => $claim->submittedUnitLabel(),
                'type_label' => self::CLAIM_TYPE_LABELS[$claim->claim_type] ?? $claim->claim_type,
                'match_label' => UnitClaim::MATCH_LABELS[$claim->match_result] ?? $claim->match_result,

                'claim_type' => $claim->claim_type,
                'status' => $claim->status,
                'status_label' => UnitClaim::STATUS_LABELS[$claim->status] ?? $claim->status,
                'submitted' => $this->submittedColumn($claim),
                'record' => $this->recordColumn($claim, $household, $standing, $maySeeMoney),

                /*
                 * Carried whole so a page can draw the amber verdict without
                 * deriving anything. It is the same shape every other screen
                 * gets, and for a restricted household it holds the word and no
                 * figure — which is the point of there being one function.
                 */
                'standing' => $standing,
                'actions' => $this->claimActions($claim),
                'notes' => $notes[$claim->id] ?? null,

                // M32 F1: the register's side is one of the demonstration's placeholders, marked as such.
                'record_sample' => SampleResidents::marked((bool) ($household?->residents->firstWhere('is_primary', true)?->isSample() ?? false)),
            ];
        }

        // Every pending claim, not this page's: the heading counts the queue, whichever page of it is open.
        $count = $table->search === '' ? $paginator->total() : (int) UnitClaim::query()->where('status', UnitClaim::PENDING)->count();

        return [
            'title' => 'Unit claims · '.$count.' pending',
            'pending_count' => $count,
            'claims' => $cards,
            'table' => $table->meta($paginator),
        ];
    }

    /** What a claim's kind is called in the queue. */
    private const CLAIM_TYPE_LABELS = [
        UnitClaim::TYPE_UNIT => 'The lot',
        UnitClaim::TYPE_MEMBER => 'A household',
        UnitClaim::TYPE_UNVERIFIED => 'Not matched',
    ];

    /**
     * What the claims queue orders by (M32 F5): when it was made (newest first, its default — F8), who, which lot, what
     * kind of claim and what the matcher made of it. A lot in its own order: Lot 2 before Lot 10.
     *
     * @return array<string, string|\Closure>
     */
    public static function claimSorts(): array
    {
        return [
            'made' => 'unit_claims.created_at',
            'claimant' => 'unit_claims.submitted_name',
            'lot' => static fn ($query, string $dir) => $query->orderBy('unit_claims.submitted_phase', $dir)
                ->orderByRaw('CHAR_LENGTH(unit_claims.submitted_lot) '.$dir)->orderBy('unit_claims.submitted_lot', $dir),
            'type' => 'unit_claims.claim_type',
            'match' => 'unit_claims.match_result',
        ];
    }

    public static function claimsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::claimSorts(), 'made', 'desc');
    }

    /* ------------------------------------------------------------------ */
    /* board 34 — add resident */
    /* ------------------------------------------------------------------ */

    /** How many of a phase's units the add-resident field suggests at once (M11 A2). */
    public const LOT_SUGGESTIONS = 20;

    /**
     * What the form needs to be drawn, and nothing about a person.
     *
     * `$phase` and `$lot` are what the office has chosen and typed so far — the
     * page asks again, a partial reload, as they change — and `lot` is the answer.
     * `$question` is the page's own number for that lookup, handed back inside the
     * answer as `lot.question` so the page knows which of its lookups an answer is
     * for; null for any request that is not one of them.
     *
     * @return array<string, mixed>
     */
    public function newResidentBoard(string $phase = '', string $lot = '', ?int $question = null, bool $maySeeMoney = false): array
    {
        $phases = Phase::query()
            ->orderBy('sequence')
            ->get()
            ->map(fn (Phase $p): array => ['value' => (string) $p->name, 'label' => (string) $p->name])
            ->all();

        return [
            'phases' => $phases,

            /*
             * THE UNIT AS THE REGISTER WILL READ IT, ASKED OF THE REGISTER (B041, M11 A2).
             *
             * The preview on this screen used to put "Lot " in front of whatever
             * was typed, as the service did — so at an estate whose units are
             * "OV-1A" it promised to file somebody at "Lot OV-1A", an address the
             * estate does not have. B041 fixed that by sending the page every
             * phase's units, all 2,000 at Phoenix Park, for it to resolve against.
             * Now the page asks: the unit `unitTyped()` itself resolves what was
             * typed to — the very lookup `add()` files with, not a copy of it — and
             * a few of the phase's references as the field's suggestions. References
             * only: a unit list is not a household list, and nothing here says who
             * lives where. See `lotLookup()`.
             *
             * WITH THE NUMBER OF THE PAGE'S QUESTION IT ANSWERS. The page cannot
             * tell which of its lookups an answer is for by comparing `typed` with
             * its own field: `typed` is the field after Laravel's TrimStrings, which
             * strips a zero-width space, a left-to-right mark, a soft hyphen or a
             * word joiner off the ends, and JavaScript's trim() keeps all four. A
             * lot pasted with one never matched, and the page asked again every
             * quarter of a second for as long as it was open.
             */
            'lot' => [...$this->lotLookup($phase, $lot, array_column($phases, 'value'), $maySeeMoney), 'question' => $question],

            // M32 F1: what each answer to "this lot already has a household" is called.
            'household_choices' => collect(self::HOUSEHOLD_CHOICES)->map(static fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),

            /*
             * "SEND" WAS A PROMISE NOTHING KEEPS (M10 §1). The board labels the
             * default "Send self-verification invite", and the invite it records
             * is delivered by nothing: `resident_invites.sent_at` is written null
             * because there is no SMS provider and no mail path on this act. The
             * value is unchanged; the label now says what the choice is — the
             * resident proves who they are in the app — rather than that
             * something went out.
             */
            'verification' => [
                ['value' => 'invite', 'label' => 'Resident verifies in the app'],
                ['value' => 'manual', 'label' => 'Mark verified now'],
            ],

            // The board draws the invite segment active, and it is the right
            // default: the estate asserting somebody's identity on their behalf
            // is the exception, not the ordinary path.
            'default_verification' => 'invite',

            /*
             * SHIPS OFF (D-022, Q-003). The form may collect it; nothing enrols
             * a fingerprint without it, and the refusal is in
             * `enrolBiometrics()` rather than in a settings table.
             */
            'biometric_consent' => [
                'default' => false,
                'label' => 'Resident consents to biometric enrolment',
                'note' => 'Off unless the resident says otherwise. Biometric enrolment is refused '
                    .'without it, and no estate setting grants it on anybody\'s behalf.',
            ],

            /*
             * The preview copy, with the two things it interpolates left as
             * tokens. The board's own wording is gendered (two gendered
             * pronouns) because it was drawn against one named person; a
             * template cannot know, and D-238 forbids them anyway, so this
             * reads "they". Recorded in DECISIONS.md rather than
             * guessed at per resident.
             *
             * IT PROMISES AN EMAIL ONLY AS FAR AS ONE IS SENT, AND NEVER A TEXT. The
             * board's sentence was "{first} will get an SMS and email with a link to
             * download the Resident App". For a long time nothing sent either, and this
             * said so. Now the invite IS emailed (`ResidentInvites`) when an address is
             * given — and the message after saving says whether the mail server took
             * it, with Resend on the household page. No text: there is no SMS path on
             * this act. The steps that follow — sign in, claim, approve — are the
             * claims queue's, and are named as such.
             *
             * AND THERE IS NO FORM FOR THEM TO FILL IN (stage 2): signing in with the
             * invited address is their claim, already matched and marked as the
             * office's invite, so the preview no longer asks them to "claim their unit".
             */
            'preview' => [
                'head' => 'What happens next',
                'template' => '{first} is put on the register at {lot} as "Pending". With an email address, they are '
                    .'emailed an invite to the Resident App straight away, and their household page says whether '
                    .'it went; Resend is there. No text message is sent. When they sign in with that email address, '
                    .'their claim arrives in the claims queue already matched, marked "Invited by the estate office", '
                    .'and approving it there changes their status from "Pending" to "Verified". With no email address, '
                    .'nothing is sent: tell them yourself.',
            ],
        ];
    }

    /**
     * Board 34's unit field, answered by the register (M11 A2).
     *
     *   phase        the phase this answer is about: the one asked — as `add()` takes
     *                whatever phase is posted — or, when none is, the form's first,
     *                the one its select opens on
     *   typed        what was typed, trimmed, so the page can tell whether this is the
     *                answer to what the field says now or to an earlier keystroke
     *   resolved     the reference `add()` would file at — `unitTyped()`'s own answer —
     *                or null when it names no unit in this phase
     *   suggestions  up to `LOT_SUGGESTIONS` of the phase's references that contain
     *                what was typed, the one it names first, then the shortest, then
     *                in order; with nothing typed, the phase's first
     *   more         whether the phase has more matches than were sent
     *   placeholder  the phase's first reference, so the empty field shows how this
     *                estate writes a unit; null when the phase has none
     *
     * @param  list<string>  $phases  the estate's phases, in the form's order
     * @return array{phase: string, typed: string, resolved: string|null, suggestions: list<string>, more: bool, placeholder: string|null}
     */
    public function lotLookup(string $phase, string $lot, array $phases, bool $maySeeMoney = false): array
    {
        $phase = trim($phase) !== '' ? trim($phase) : ($phases[0] ?? '');
        $typed = trim($lot);

        if ($phase === '') {
            return ['phase' => '', 'typed' => $typed, 'resolved' => null, 'suggestions' => [], 'more' => false, 'placeholder' => null];
        }

        $inPhase = static fn () => Unit::query()->where('block', $phase)->orderByRaw('CHAR_LENGTH(reference)')->orderBy('reference');

        $query = Unit::query()->where('block', $phase);

        if ($typed !== '') {
            // LOCATE rather than LIKE, so a "%" or "_" in a reference is only itself.
            $query->whereRaw('LOCATE(?, LOWER(reference)) > 0', [mb_strtolower($typed)]);

            $readings = $this->referencesTyped($typed);

            $query->orderByRaw(
                '('.implode(' OR ', array_fill(0, count($readings), 'LOWER(reference) = LOWER(?)')).') DESC',
                $readings,
            );
        }

        $found = $query
            ->orderByRaw('CHAR_LENGTH(reference)')
            ->orderBy('reference')
            ->limit(self::LOT_SUGGESTIONS + 1)
            ->pluck('reference')
            ->map(static fn ($reference): string => (string) $reference)
            ->all();

        $placeholder = $typed === '' ? ($found[0] ?? null) : $inPhase()->value('reference');
        $unit = $typed === '' ? null : $this->unitTyped($phase, $typed);

        return [
            'phase' => $phase,
            'typed' => $typed,
            'resolved' => $unit?->reference,
            'suggestions' => array_slice($found, 0, self::LOT_SUGGESTIONS),
            'more' => count($found) > self::LOT_SUGGESTIONS,
            'placeholder' => $placeholder === null ? null : (string) $placeholder,

            // M32 F1: who is already filed at the unit, so the form asks before a person is joined to them.
            'occupied' => $unit === null ? null : $this->occupiedAt($unit, $maySeeMoney),
        ];
    }

    /** What the three answers to an occupied lot are called on the form and in a refusal (M32 F1). */
    public const HOUSEHOLD_CHOICES = [
        'member' => 'A member of that household',
        'primary' => 'The household\'s primary resident: the person on record now becomes a member',
        'separate' => 'A new, separate household at this lot',
    ];

    /**
     * THE HOUSEHOLD ALREADY AT A LOT, SAID BEFORE ANYBODY IS JOINED TO IT (M32 F1). hassam was added at Lot 413 and
     * silently became a member under the seeded "Lot 413 householder", and the register showed the placeholder. Each
     * household with somebody on it: its primary (and whether that is a demonstration placeholder), how many others,
     * and the household's balance in the words this viewer may read — because a person attached to somebody else's
     * debt must see it. Null when nobody is filed at the unit: the person then holds a new household.
     *
     * @return array{unit: string, households: list<array<string, mixed>>, balance_line: string}|null
     */
    public function occupiedAt(Unit $unit, bool $maySeeMoney): ?array
    {
        $households = Household::query()->where('unit_id', $unit->id)->with('residents')->orderBy('id')->get()
            ->filter(static fn (Household $h): bool => $h->residents->isNotEmpty())->values();

        if ($households->isEmpty()) {
            return null;
        }

        $standing = $this->standingOf($households->first(), $maySeeMoney);

        // The balance is the lot's (the receivable is keyed on the unit), so a lot of two households says "this lot".
        $several = $households->count() > 1;

        return [
            'unit' => (string) $unit->reference,
            'households' => $households->map(static function (Household $h): array {
                $holder = $h->residents->firstWhere('is_primary', true) ?? $h->residents->first();

                return [
                    'id' => (int) $h->id,
                    'name' => (string) $h->name,
                    'primary' => $holder === null ? null : ['name' => (string) $holder->full_name, 'email' => $holder->email, 'sample' => SampleResidents::marked($holder->isSample())],
                    'others' => max(0, $h->residents->count() - 1),
                    'sample' => SampleResidents::marked((bool) $holder?->isSample()),
                ];
            })->all(),
            'balance_line' => match (true) {
                (bool) $standing['restricted'] => ($several ? $households->first()->name.'\'s' : 'This household\'s').' access is restricted for arrears. Adding someone does not change that.',
                $standing['balance_minor'] === null => ($several ? 'This lot\'s' : 'This household\'s').' balance is not shown to your role. Adding someone does not change it.',
                default => 'Balance on this '.($several ? 'lot' : 'household').': '.$standing['label'].'. Adding someone does not change it.',
            },
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 38 — one household in full */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 38 draws for one unit.
     *
     * THE HERO STAT IS THE STANDING, WHOLE. "Balance owed" is not a separate
     * figure from the amber word: it is one cell that reads as an amount when
     * the viewer may have one and reads as the verdict when they may not, which
     * is why it is built from `standingOf()` rather than beside it.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @return array<string, mixed>
     */
    public function residentBoard(Unit $unit, bool $maySeeMoney, ?int $householdId = null): array
    {
        /*
         * WHICH HOUSEHOLD (M32 F1). A lot can hold more than one now — Add resident's "a new, separate household at this
         * lot" — and the page shows one at a time: the one asked for, when it is at this lot, or else the latest, as it
         * always has. `households_here` names every household at the lot so the page can move between them.
         */
        $here = $unit->households()->orderBy('id')->get();
        $household = ($householdId === null ? null : $here->firstWhere('id', $householdId)) ?? $here->last();

        /*
         * A plain array rather than a collection, and the primary first. Every
         * panel below walks it in that order — who holds the household is the
         * first thing a reviewer reads — and an ordering decided once here
         * cannot be re-decided differently by the two helpers that consume it.
         */
        $members = $household === null
            ? []
            : $household->residents()->orderByDesc('is_primary')->orderBy('id')->get()->all();

        $primary = $members[0] ?? null;

        $standing = $household === null
            ? $this->vacantStanding()
            : $this->standingOf($household, $maySeeMoney);

        $links = $this->linkedActivity($unit, $standing);
        $pending = $household !== null && $this->householdIsPending($household, $this->unitsUnderClaim());
        $appAccounts = $this->appAccountsFor($members);

        // Every member's latest invite in one read, not one a member (M11 A2) — see `latestForEach()`.
        $invites = app(ResidentInvites::class)->latestForEach($members);

        return [
            'resident' => [
                'unit_id' => $unit->id,
                'unit_slug' => $unit->slug(),
                // What the office types to confirm removing the household (M21 §3.1), and the household it removes.
                'unit_reference' => (string) $unit->reference,
                'household_name' => $household?->name,
                'household_id' => $household?->id,
                'name' => $primary->full_name ?? 'No resident on record',
                'initials' => $primary?->initials() ?? '??',

                // M32 F1: the household is held by one of the demonstration's placeholders.
                'sample' => SampleResidents::marked((bool) ($primary?->isSample() ?? false)),

                // "Phase 2 · Lot 47 · Fletcher household"
                'sub' => implode(' · ', array_filter([
                    $unit->block,
                    $unit->reference,
                    $household?->name,
                ])),
                'verification' => $pending ? Resident::PENDING : Resident::VERIFIED,
                'verification_label' => Resident::STATUS_LABELS[$pending ? Resident::PENDING : Resident::VERIFIED],
            ],

            'standing' => $standing,

            'households_here' => $this->householdsHere($here, $household),

            // Who has lived at this lot, those here now first and then those that moved out (M98 census).
            'history' => $this->lotHistory($unit),

            'stats' => [
                // Plural only when it is (M53 Law 5): "1 OPEN ITEMS" was on the card.
                ['key' => 'members', 'value' => count($members), 'label' => count($members) === 1 ? 'Household member' : 'Household members'],

                /*
                 * One cell, two readings. `value` is what the tile prints in
                 * either case and `value_minor` is null whenever the figure is
                 * withheld, so a page that reached for the number instead of the
                 * label would print nothing rather than something.
                 */
                [
                    'key' => 'balance',
                    'value' => $standing['label'],
                    'value_minor' => $standing['balance_minor'],
                    'tone' => $standing['tone'],
                    'label' => 'Balance owed',
                ],

                /*
                 * Open items is a count of the cross-links that need somebody to
                 * act — an arrears balance and an unfinished ticket. A booking
                 * with a deposit held is not one: the money is where it should
                 * be and the date is in the diary, so there is nothing for a
                 * reviewer to do about it. Counted off the links this viewer can
                 * actually see, so it cannot become a way of learning that a
                 * withheld balance was non-zero.
                 */
                [
                    'key' => 'open_items',
                    'value' => $openItems = count(array_filter($links, static fn (array $l): bool => $l['is_open_item'])),
                    'label' => $openItems === 1 ? 'Open item' : 'Open items',
                ],

                [
                    'key' => 'since',
                    // Day, month, year (M53 Law 5): "2 Sep 2024", never "Sep 2, 2024".
                    'value' => $this->residentSince($members)?->format('j M Y'),
                    'label' => 'Resident since',
                ],
            ],

            'members' => $this->memberPanel($members),

            /*
             * The household's people as the edit panel edits them (12 §2, Wave
             * 2). Every member, not just the named few the panel above prints:
             * the reason to open this list is to correct somebody, and the one
             * who needs correcting is as likely to be the fourth as the first.
             *
             * VERIFICATION IS NOT HERE, and that is deliberate — see `edit()`.
             * Neither is biometric consent, which is the person's and not the
             * office's.
             */
            'people' => array_map(fn (Resident $member): array => [
                'id' => $member->id,

                // Their invite to the Resident App, as it stands — Resend is drawn from this (`ResidentInvites::outcome`).
                'is_pending' => $member->status === Resident::PENDING,
                // The office's copy: the outcome, plus the real reason a refused invite did not go (stage 2).
                'invite' => ResidentInvites::officeOutcome($invites[$member->id] ?? null),

                'full_name' => $member->full_name,
                'panel_name' => $member->panelName(),
                'email' => $member->email ?? '',
                'phone' => $member->phone ?? '',
                // M18C §3.2: "has no email — alerts go to the primary instead", ticked on the edit form.
                'alerts_to_primary' => (bool) $member->getAttribute('alerts_to_primary'),
                'relationship' => $member->relationship,
                'moved_in_on' => $member->moved_in_on === null ? '' : Carbon::parse((string) $member->moved_in_on)->toDateString(),
                'is_primary' => (bool) $member->is_primary,
                'status_label' => Resident::STATUS_LABELS[$member->status] ?? $member->status,

                // M32 F1: one of the demonstration's placeholders, marked `sample` rather than passed off as a person.
                'sample' => SampleResidents::marked($member->isSample()),

                // What they may do in the Resident App — Member, Dependent or Helper; the primary is always a Member.
                'access_level' => AccessLevels::of($member),
                'access_level_label' => AccessLevels::label(AccessLevels::of($member)),

                /*
                 * Their Resident App LOGIN — not their place on the register (Q-M44).
                 * The two are separate facts and the panel says so: ending an app
                 * account does not move anybody out, and editing a register row does
                 * not sign anybody out.
                 */
                'app_access' => $appAccounts[$member->id] ?? self::NO_APP_ACCOUNT,

                // Their e-Pass, the pass a guard admits them on, in one line (M98 census: e-Pass management).
                'epass' => $this->epassOf($member, $appAccounts[$member->id] ?? self::NO_APP_ACCOUNT),
            ], $members),

            'contact' => [
                ['label' => 'Email', 'value' => $primary->email ?? 'Not on file'],
                ['label' => 'Phone', 'value' => $primary->phone ?? 'Not on file'],
            ],

            'linked' => $links,
        ];
    }

    /**
     * WHO HAS LIVED AT THIS LOT (M98, the Residents census: ownership and tenancy history). Every household the register
     * has held here, those living here first and then those that moved out, the latest first: how its people were
     * recorded (Tenant or Owner, from their relationship to the household), when it moved in (its earliest member's
     * moved-in date, or the day it was added) and when it moved out (the office's removal), with the reason given. One
     * read of the register's own rows, removed ones included, and nothing stored for it. A household whose restore window
     * has closed names no person (HouseholdRemoval::anonymiseExpired), and its reason is gone with its people.
     *
     * @return list<array{id: int, name: string, held_by: string|null, recorded_as: string, moved_in: string|null, moved_out: string|null, reason: string|null, current: bool}>
     */
    private function lotHistory(Unit $unit): array
    {
        $rows = DB::connection('tenant')->table('households')
            ->leftJoin('residents', 'residents.household_id', '=', 'households.id')
            ->where('households.unit_id', $unit->id)
            ->orderBy('households.id')
            ->orderBy('residents.id')
            ->get([
                'households.id', 'households.name', 'households.created_at', 'households.removed_at', 'households.removal_reason',
                'households.anonymised_at', 'residents.full_name', 'residents.is_primary', 'residents.relationship',
                'residents.moved_in_on', 'residents.created_at as resident_created_at',
            ]);

        $history = [];

        foreach ($rows->groupBy('id') as $id => $people) {
            $first = $people->first();
            $current = $first->removed_at === null;
            $anonymised = $first->anonymised_at !== null;
            $named = $people->filter(static fn (object $person): bool => $person->full_name !== null);
            $primary = $named->first(static fn (object $person): bool => (bool) $person->is_primary) ?? $named->first();
            $relationships = $named->map(static fn (object $person): string => mb_strtolower(trim((string) $person->relationship)));

            // The day each person moved in, as the estate's calendar has it: a moved-in date is a date; the day a person
            // was added is a moment, read on the estate's clock.
            $days = $named
                ->map(static fn (object $person): string => $person->moved_in_on !== null
                    ? Carbon::parse((string) $person->moved_in_on)->toDateString()
                    : SiteTime::local(Carbon::parse((string) $person->resident_created_at, 'UTC'))->toDateString())
                ->sort()
                ->values();

            $movedIn = $days->first() ?? ($first->created_at === null ? null : SiteTime::local(Carbon::parse((string) $first->created_at, 'UTC'))->toDateString());

            $history[] = [
                'id' => (int) $id,
                'name' => (string) $first->name,
                'held_by' => $anonymised || $primary === null ? null : (string) $primary->full_name,
                'recorded_as' => match (true) {
                    $relationships->contains('tenant') => 'Tenant',
                    $relationships->contains('owner') => 'Owner',
                    default => 'Not recorded',
                },
                // Day, month, year (M53 Law 5).
                'moved_in' => $movedIn === null ? null : Carbon::parse($movedIn)->format('j M Y'),
                'moved_out' => $current ? null : SiteTime::local(Carbon::parse((string) $first->removed_at, 'UTC'))->format('j M Y'),
                'reason' => $current || $anonymised ? null : (trim((string) $first->removal_reason) ?: null),
                'current' => $current,
                'sort' => $current ? PHP_INT_MAX : Carbon::parse((string) $first->removed_at, 'UTC')->getTimestamp(),
            ];
        }

        usort($history, static fn (array $a, array $b): int => [$b['sort'], $b['id']] <=> [$a['sort'], $a['id']]);

        return array_map(static function (array $row): array {
            unset($row['sort']);

            return $row;
        }, $history);
    }

    /**
     * Every household at this lot, when there is more than one (M32 F1), each with who holds it, in the order they were
     * made; empty for the ordinary lot of one, which needs no switcher.
     *
     * @param  EloquentCollection<int, Household>  $here  the lot's current households, oldest first
     * @return list<array{id: int, name: string, holder: string|null, sample: bool, shown: bool, param: int|null}>
     */
    private function householdsHere(EloquentCollection $here, ?Household $shown): array
    {
        if ($here->count() < 2) {
            return [];
        }

        // Only a lot of two or more reads its people here: the ordinary page costs no query for this.
        $here->load('residents');
        $latest = (int) $here->max('id');

        return $here->map(static function (Household $h) use ($shown, $latest): array {
            $holder = $h->residents->firstWhere('is_primary', true) ?? $h->residents->first();

            return [
                'id' => (int) $h->id,
                'name' => (string) $h->name,
                'holder' => $holder?->full_name,
                'sample' => SampleResidents::marked((bool) $holder?->isSample()),
                'shown' => $shown !== null && (int) $shown->id === (int) $h->id,
                // The latest is the page's own default; any other is named in the link.
                'param' => (int) $h->id === $latest ? null : (int) $h->id,
            ];
        })->values()->all();
    }

    /**
     * A PERSON'S E-PASS, IN ONE LINE (M98, the Residents census: e-Pass management). The e-Pass is the pass a guard
     * admits a resident on, issued in the Resident App to a person whose account is active (`VisitorPasses::RESIDENT`).
     * The office manages it through the person's app access: End access cancels it at every gate at once, Give access
     * back lets it be issued again. A Helper's works on their household's days and hours, and the household may pause it.
     * Read from what the page already holds, and a Helper's window from the estate's rules, cached for the request.
     *
     * @param  array<string, mixed>  $app  the person's app access, as `appAccountsFor` reads it
     * @return array{state: string, line: string}
     */
    private function epassOf(Resident $member, array $app): array
    {
        return match ($app['state']) {
            'revoked' => ['state' => 'cancelled', 'line' => 'e-Pass cancelled: the office ended their app access'],
            'deleted' => ['state' => 'none', 'line' => 'No e-Pass: they deleted their app account'],
            'suspended' => ['state' => 'none', 'line' => 'No e-Pass: removed from the household in the app'],
            'pending' => ['state' => 'none', 'line' => 'No e-Pass yet: waiting on a unit claim'],
            'active' => $this->activeEpass($member),
            default => ['state' => 'none', 'line' => 'No e-Pass: not on the Resident App'],
        };
    }

    /** @return array{state: string, line: string} */
    private function activeEpass(Resident $member): array
    {
        if (AccessLevels::of($member) !== AppMatrix::HELPER) {
            return ['state' => 'active', 'line' => 'e-Pass in the Resident App, at any hour'];
        }

        $window = AccessRules::window($member);

        return $window['paused']
            ? ['state' => 'paused', 'line' => 'e-Pass paused by the household']
            : ['state' => 'active', 'line' => 'e-Pass works '.AccessRules::windowLine($window)];
    }

    /** A register entry with no Resident App login behind it — the ordinary case. */
    private const NO_APP_ACCOUNT = [
        'state' => 'none',
        'label' => 'No Resident App account',
        'revoked_at' => null,
        'revoked_reason' => null,
        'revoked_by' => null,
        'can_end' => false,
        'can_restore' => false,
    ];

    /**
     * EACH PERSON'S RESIDENT APP LOGIN, FOR BOARD 38'S PANEL (Q-M44).
     *
     * `resident_accounts` is CENTRAL — a Sanctum token has to resolve before any estate
     * is known — so this reads across the connection boundary and scopes by `tenant_id`.
     * A person's register entry and their app login are two different records on two
     * different databases, and the panel is careful to say which one it is talking about.
     *
     * A DELETED ACCOUNT IS NOT AN ACCOUNT. The person destroyed it themselves (M5 §4) and
     * there is nothing left for the office to end; the row is kept de-identified, and the
     * panel says so rather than offering a button that would refuse.
     *
     * @param  list<Resident>  $members
     * @return array<int, array<string, mixed>>
     */
    private function appAccountsFor(array $members): array
    {
        // Every member is a stored row, so every one has an id: nothing to filter out.
        $ids = array_map(static fn (Resident $m): int => $m->id, $members);

        if ($ids === []) {
            return [];
        }

        $estate = (string) tenant()?->getTenantKey();

        $accounts = ResidentAccount::query()
            ->where('tenant_id', $estate)
            ->whereIn('resident_id', $ids)
            ->orderBy('id')
            ->get();

        $byResident = [];

        foreach ($accounts as $account) {
            $residentId = (int) $account->resident_id;

            $state = match (true) {
                $account->deleted_at !== null => 'deleted',
                $account->isRevoked() => 'revoked',
                $account->status === ResidentAccount::SUSPENDED => 'suspended',
                $account->status === ResidentAccount::ACTIVE => 'active',
                default => 'pending',
            };

            $byResident[$residentId] = [
                'state' => $state,
                'label' => match ($state) {
                    'deleted' => 'The person deleted their own account',
                    'revoked' => 'App access ended by the estate office',
                    'suspended' => 'Suspended · removed from the household in the app',
                    'active' => 'Uses the Resident App',
                    default => 'Signed in, waiting on a unit claim',
                },

                // A stored UTC instant, read on the ESTATE'S clock by its zone's name (M4 §3.1), in Law 5's form (DR-039):
                // "11 Oct 2026, 6:21 PM", as every other date on the household page is written.
                'revoked_at' => $account->revoked_at === null ? null : SiteTime::local($account->revoked_at, $estate)->format('j M Y, g:i A'),
                'revoked_reason' => $account->revoked_reason,
                'revoked_by' => $account->revoked_by,
                'can_end' => in_array($state, ['active', 'pending', 'suspended'], true),
                'can_restore' => $state === 'revoked',
            ];
        }

        return $byResident;
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /**
     * Put a person on the register at a unit — board 34.
     *
     * THE UNIT HAS TO EXIST. An estate's units are its physical addresses and
     * this form does not create one: a lot typed in error would otherwise become
     * a house, and a household would be filed at an address the estate does not
     * have. The refusal names the phase and the lot, because that is what the
     * person can fix.
     *
     * ADDING A RESIDENT OCCUPIES THE UNIT. A lot with somebody living at it is
     * not vacant, and leaving the status alone would leave board 3's occupancy
     * counting a household that exists as an empty house.
     *
     * AN OCCUPIED LOT ASKS (M32 F1): `$householdChoice` is one of `HOUSEHOLD_CHOICES`, and on a lot holding more than
     * one household with people in it, `$householdId` says which one a member or a new primary joins.
     *
     * @param  string  $verification  'invite' — they prove who they are; 'manual' — the estate asserts it
     * @param  bool  $sendInvite  false records the invite and sends nothing (the bulk import, M98): the office sends each
     *                            from the lot's page, which says "This invite was recorded but never sent"
     */
    public function add(
        string $phase,
        string $lot,
        string $fullName,
        ?string $email = null,
        ?string $phone = null,
        string $verification = 'invite',
        bool $biometricConsent = false,
        ?User $by = null,
        ?string $householdChoice = null,
        ?int $householdId = null,
        bool $sendInvite = true,
    ): Resident {
        $unit = $this->unitTyped($phase, $lot);

        if ($unit === null) {
            // Named as it was TYPED. "No Lot OV-404" named a unit nobody had asked for.
            throw new DomainException(sprintf(
                'No unit "%s" in %s. A resident is filed at an address the estate actually has, and this '.
                'form does not create one: a unit typed in error would otherwise become a house. '.
                'Check the phase and the unit as the estate writes it, or add the unit to the estate structure first.',
                trim($lot),
                $phase,
            ));
        }

        if ($householdChoice !== null && ! array_key_exists($householdChoice, self::HOUSEHOLD_CHOICES)) {
            throw new DomainException('Choose what this person is at '.$unit->reference.': '.implode(', ', array_map('lcfirst', self::HOUSEHOLD_CHOICES)).'.');
        }

        $resident = DB::connection('tenant')->transaction(function () use (
            $unit, $fullName, $email, $phone, $verification, $biometricConsent, $by, $householdChoice, $householdId
        ): Resident {
            // One writer at a time per address: "is there a primary yet?" below is then read after the other has committed.
            Unit::query()->whereKey($unit->id)->lockForUpdate()->first();

            /*
             * AN OCCUPIED LOT ASKS, BEFORE ANYTHING IS SAVED (M32 F1). Joining whoever is already filed there silently is
             * how a real resident became a member under the demonstration's "Lot 413 householder". With somebody on any
             * household at the lot, nothing is written until the office says which of the three this person is — and,
             * where the lot holds more than one household, which household a member or a new primary joins.
             */
            $peopled = Household::query()->where('unit_id', $unit->id)->whereHas('residents')->orderBy('id')->get();
            $occupied = $peopled->isNotEmpty();

            if ($occupied && $householdChoice === null) {
                throw new HouseholdChoiceNeeded($peopled->count() === 1
                    ? sprintf(
                        '%s already has a household (%s). Choose what this person is before saving: a member of that household, its new primary resident, or a new, separate household at this lot.',
                        $unit->reference,
                        $peopled->first()->name,
                    )
                    : sprintf(
                        '%s already has %d households (%s). Choose what this person is before saving: a member of one of them, the new primary resident of one, or a new, separate household at this lot.',
                        $unit->reference,
                        $peopled->count(),
                        $peopled->pluck('name')->implode(', '),
                    ));
            }

            if ($occupied && $householdChoice !== 'separate') {
                $household = $householdId !== null
                    ? $peopled->firstWhere('id', $householdId)
                    : ($peopled->count() === 1 ? $peopled->first() : null);

                if (! $household instanceof Household) {
                    throw new HouseholdChoiceNeeded(sprintf(
                        '%s has %d households: %s. Choose which one this person joins.',
                        $unit->reference,
                        $peopled->count(),
                        $peopled->pluck('name')->implode(', '),
                    ));
                }
            } else {
                // Nobody at the lot: its household, kept from before, or a new one. "Separate": a new one, always.
                $household = $occupied ? null : $unit->household;

                $household ??= Household::create([
                    'unit_id' => $unit->id,
                    'name' => $this->householdNameFor($fullName, $unit),
                    'access_restricted' => false,
                ]);
            }

            $manual = $verification === 'manual';

            $resident = Resident::create([
                'household_id' => $household->id,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'relationship' => 'owner',

                // The first person on a household holds it. A second is added
                // beside them, and which of them is primary is a decision
                // somebody makes rather than a race between two form
                // submissions.
                'is_primary' => $household->residents()->where('is_primary', true)->doesntExist(),

                'status' => $manual ? Resident::VERIFIED : Resident::PENDING,
                'verified_at' => $manual ? now() : null,
                'verified_by' => $manual ? $by?->getKey() : null,
                'verified_by_name' => $manual ? $by?->name : null,
                'biometric_consent' => $biometricConsent,
                'moved_in_on' => SiteTime::date()->toDateString(),
            ]);

            /*
             * "The household's primary resident — the person on record now becomes a member" (M32 F1): primacy moves to
             * the new person, the one who held it stays on the household as a member, and the move is audited as
             * "Make primary" audits it. The holder lets go first, so the household never has two at once.
             */
            if ($occupied && $householdChoice === 'primary' && ! $resident->is_primary) {
                $from = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->whereKeyNot($resident->id)->first();
                $from?->forceFill(['is_primary' => false])->save();
                $resident->forceFill(['is_primary' => true])->save();

                app(AuditLogger::class)->record(
                    action: 'residents.primary_moved',
                    entityType: 'Resident',
                    entityId: (string) $resident->id,
                    before: ['primary_resident_ids' => $from === null ? [] : [$from->id]],
                    after: ['from_resident_id' => $from?->id, 'to_resident_id' => $resident->id, 'household_id' => $household->id, 'by' => $by?->name, 'via' => 'add_resident'],
                    actor: $by,
                );
            }

            if (! $manual) {
                // The record the office chases later; `ResidentInvites` sends it, below, once this has committed.
                ResidentInvite::create([
                    'resident_id' => $resident->id,
                    'unit_id' => $unit->id,
                    'token' => (string) Str::uuid(),
                    'channels' => 'email',
                    'email' => $email === null || trim($email) === '' ? null : strtolower(trim($email)),
                ]);
            }

            if ($unit->status !== 'occupied') {
                $unit->forceFill(['status' => 'occupied'])->save();
            }

            return $resident;
        });

        /*
         * AND IT IS SENT. "Resident verifies in the app" wrote the invite and sent nothing — "there is no mail
         * driver on this path yet" — so the resident never heard. It goes now, after the commit (an email is not
         * something a rolled-back transaction can take back), and says whether it went on the resident's page.
         * With no email address there is nothing to send, and the page says that instead.
         */
        if ($sendInvite && $verification !== 'manual' && $resident->email !== null && trim((string) $resident->email) !== '') {
            try {
                app(ResidentInvites::class)->send($resident, $by->name ?? 'The estate office', null, $by);
            } catch (DomainException) {
                // Said on the resident's page, from the invite's own record.
            }
        }

        return $resident;
    }

    /**
     * Edit a resident — board 38's "Edit details" (12 §2, Wave 2).
     *
     * WHAT THIS MAY AND MAY NOT TOUCH. It edits who a person IS: their name,
     * how to reach them, their relationship to the household and the date they
     * moved in. It does NOT touch whether they are AUTHORISED — the old inert
     * reason had that right, and the answer is that verification stays where
     * the decision is made. A resident is verified by the claims queue or by an
     * officer vouching for them on the add-resident screen, both of which
     * record who decided and when; a status editable from a details form would
     * let somebody verify themselves with no decision behind it.
     *
     * BIOMETRIC CONSENT IS THE PERSON'S, NOT THE OFFICE'S (D-022, Q-003). It is
     * not on this form. Withdrawal is the one direction an office may take on
     * somebody's behalf, and it is its own act below.
     *
     * MAKING SOMEBODY PRIMARY MOVES IT OFF WHOEVER HAD IT. One household, one
     * primary contact — two would mean two people receiving the notices and
     * each assuming the other answered.
     *
     * @param  array<string, mixed>  $fields
     */
    public function edit(Resident $resident, array $fields, ?User $by = null): Resident
    {
        $name = trim((string) ($fields['full_name'] ?? ''));

        if ($name === '') {
            throw new DomainException('A resident has a name. It is what the gate reads off a pass and what a notice is addressed to.');
        }

        $email = trim((string) ($fields['email'] ?? ''));
        $phone = trim((string) ($fields['phone'] ?? ''));

        if ($email === '' && $phone === '') {
            throw new DomainException('A resident needs an email address or a phone number. A household with neither cannot be sent a notice, an invitation or a dues reminder, and nobody discovers that until one is needed.');
        }

        $makePrimary = (bool) ($fields['is_primary'] ?? false);

        if (! $makePrimary && $resident->is_primary) {
            throw new DomainException('A household has one primary contact and cannot have none. Make somebody else primary instead; that moves it off this person.');
        }

        // The address the person's invite went to, as it was before this edit (G4, below).
        $emailBefore = strtolower(trim((string) $resident->email));

        /** @var list<int> $movedFrom */
        $movedFrom = [];

        $saved = DB::connection('tenant')->transaction(function () use ($resident, $name, $email, $phone, $fields, $makePrimary, &$movedFrom): Resident {
            if ($makePrimary && ! $resident->is_primary) {
                $movedFrom = Resident::query()
                    ->where('household_id', $resident->household_id)
                    ->where('id', '!=', $resident->id)
                    ->where('is_primary', true)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                Resident::query()
                    ->where('household_id', $resident->household_id)
                    ->where('id', '!=', $resident->id)
                    ->update(['is_primary' => false]);
            }

            $resident->fill([
                'full_name' => $name,
                'email' => $email === '' ? null : strtolower($email),
                'phone' => $phone === '' ? null : $phone,
                'relationship' => trim((string) ($fields['relationship'] ?? $resident->relationship)) ?: $resident->relationship,
                'moved_in_on' => ($fields['moved_in_on'] ?? null) ?: $resident->moved_in_on,
                'is_primary' => $makePrimary,
            ])->save();

            return $resident;
        });

        /*
         * A MOVE IS ON THE RECORD. "Make primary" hands somebody the right to add, invite and remove the
         * household's members, and it left no trace: two primaries on one household could not be traced
         * back to who made the second. Recorded once the move has committed, from whom and to whom.
         */
        if ($makePrimary && ($movedFrom !== [] || $saved->wasChanged('is_primary'))) {
            app(AuditLogger::class)->record(
                action: 'residents.primary_moved',
                entityType: 'Resident',
                entityId: (string) $saved->id,
                before: ['primary_resident_ids' => $movedFrom],
                after: ['from_resident_id' => $movedFrom[0] ?? null, 'to_resident_id' => $saved->id, 'household_id' => $saved->household_id, 'by' => $by?->name],
                actor: $by,
            );
        }

        /*
         * AND AN EDITED ADDRESS TAKES THE OLD INVITE DOWN (G4). An invite is for one address; left standing,
         * the household page would still read "Invite emailed to old@…" about a person the office has just
         * given a new one, and "Send invite" would not be offered for the address that is now true. The
         * same address in other capitals is the same address and cancels nothing.
         */
        if (strtolower(trim((string) $saved->email)) !== $emailBefore) {
            app(ResidentInvites::class)->cancel($saved, $by->name ?? 'The estate office', $by, 'address_changed');
        }

        return $saved;
    }

    /**
     * Enrol this resident's biometrics — REFUSED WITHOUT CONSENT.
     *
     * The flag ships off and nothing turns it on for a person but that person
     * (D-022, Q-003). This refusal lives here rather than on the screen that
     * collects the consent, because a second enrolment path — an import, an API,
     * the guard handset — would otherwise each need to remember, and the one
     * that forgets enrols somebody who never agreed.
     *
     * There is no enrolment yet. This is the gate in front of it, written now so
     * that whatever arrives behind it has to come through here.
     *
     * // ASSUMPTION Q-003 — consent ships off, and the ruling (D-022) landed on
     * // this refusal rather than in a settings table.
     * // ASSUMPTION Q-015 — whether a member of STAFF may give that consent on a
     * // resident's behalf is still open. Board 34 draws the checkbox on a form a
     * // Property Manager fills in, and the person it binds is not in the room.
     * // Nothing here can be reached without an explicit tick either way.
     */
    public function enrolBiometrics(Resident $resident): void
    {
        if (! $resident->biometric_consent) {
            throw new DomainException(sprintf(
                '%s has not consented to biometric enrolment, so nothing may be enrolled. Consent is '.
                'recorded per person and defaults to off; no estate setting grants it on a resident\'s '.
                'behalf, and no import may assume it.',
                $resident->full_name,
            ));
        }
    }

    /**
     * Approve a claim — board 31's primary action.
     *
     * `approve`, NOT `update` (D-013). This binds a person to a household, which
     * decides whose guest passes they may issue and whose gate they may be
     * admitted at, and no edit afterwards unbinds the night somebody was let
     * through. The route carries the gate; this records who decided.
     *
     * NO JOURNAL IS RAISED AND NO RESTRICTION IS TOUCHED. A unit in arrears that
     * gains an authorised occupant still owes exactly what it owed, and a
     * restricted household stays restricted — approving a claim is not a
     * decision about money and must not become one by side effect.
     */
    public function approveClaim(UnitClaim $claim, ?User $by = null, ?string $level = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s. Deciding it a second time would overwrite the record of who '.
                'decided it the first time.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        $unit = $claim->unit;

        if ($unit === null) {
            throw new DomainException(sprintf(
                'The claim by %s is not matched to a unit, so approving it would authorise a person '.
                'against nothing. Match it to a lot first: that is the decision, and it is not one '.
                'an approval button should make silently.',
                $claim->submitted_name,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($claim, $unit, $by, $level): UnitClaim {
            // One writer at a time per address: "is there a primary yet?" below is then read after the other has committed.
            Unit::query()->whereKey($unit->id)->lockForUpdate()->first();

            $household = $claim->household ?? $unit->household;

            if ($household === null) {
                $household = Household::create([
                    'unit_id' => $unit->id,
                    'name' => $this->householdNameFor($claim->submitted_name, $unit),
                    'access_restricted' => false,
                ]);
            }

            /*
             * AN INVITE'S CLAIM BINDS BY THE INVITE (stage 2, DESIGN §5.4): the register entry the invite was
             * for, never a match on the name. The office may have corrected the spelling since. It must still
             * be in THIS household; if it was removed, the approval is refused in words, and nobody is created
             * in its place.
             */
            $resident = null;

            if ($claim->invite_id !== null) {
                $invitedId = (int) (ResidentInvite::query()->whereKey($claim->invite_id)->value('resident_id') ?? 0);
                $resident = Resident::query()->whereKey($invitedId)->where('household_id', $household->id)->first();

                if ($resident === null) {
                    $gone = Resident::withRemoved()->whereKey($invitedId)->first();

                    throw new DomainException(sprintf(
                        '%s was removed from the household%s, so this invite no longer names anybody here. Reject this claim.',
                        $gone->full_name ?? $claim->submitted_name,
                        $gone?->removed_at === null ? '' : ' on '.SiteTime::local($gone->removed_at)->format('j M'),
                    ));
                }
            }

            /*
             * The person an ordinary claim resolves to. An existing member is verified
             * where one matches by name — board 31's second card is exactly
             * that, a member already added on Sep 4 asking to be recognised —
             * and a new resident is created otherwise. Matching on the name is
             * as far as this goes deliberately: a fuzzier rule would silently
             * bind a claim to the wrong person, and the whole screen exists
             * because the estate's matcher was not sure.
             */
            $resident ??= $household->residents()
                ->where('full_name', $claim->submitted_name)
                ->first();

            $resident ??= Resident::create([
                'household_id' => $household->id,
                'full_name' => $claim->submitted_name,
                'phone' => $claim->submitted_phone,
                'relationship' => $claim->submitted_relationship ?? 'owner',
                'is_primary' => $household->residents()->where('is_primary', true)->doesntExist(),
                'status' => Resident::PENDING,
                'moved_in_on' => SiteTime::date()->toDateString(),
            ]);

            /*
             * THE OFFICE CHOOSES THE LEVEL (G8, SPEC: "the primary chooses … the office can see it and change it").
             * Without it, one press on an ordinary self-claim could give a person the primary made a Dependent or
             * Helper dues, passes and voting. A primary is always a Member (INV-9), so a level is ignored for one.
             */
            if ($level !== null && ! $resident->is_primary && AccessLevels::of($resident) !== $level) {
                AccessLevels::set($resident, $level, $by->name ?? 'The estate office', $by, via: 'claim');
            }

            $resident->forceFill([
                'status' => Resident::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $by?->getKey(),
                'verified_by_name' => $by?->name,
            ])->save();

            $claim->forceFill([
                'status' => UnitClaim::APPROVED,
                'resolved_resident_id' => $resident->id,
                'household_id' => $household->id,
                'reviewed_by' => $by?->getKey(),
                'reviewed_by_name' => $by?->name,
                'reviewed_at' => now(),
            ])->save();

            // An approval binds a person to a household: it is on the record (stage 2). It never was.
            app(AuditLogger::class)->record(
                action: 'unit_claim.approved',
                entityType: 'UnitClaim',
                entityId: (string) $claim->id,
                after: [
                    'resolved_resident_id' => $resident->id,
                    'invite_id' => $claim->invite_id,
                    'by' => $by?->name,
                    // Approved with nobody pressing anything: a primary's invite, with the estate's trust on.
                    'trusted' => $by === null && $claim->invite_id !== null,
                    'access_level' => AccessLevels::of($resident->fresh() ?? $resident),
                ],
                tenantId: (string) tenant()?->getTenantKey(),
                actor: $by,
            );

            return $claim;
        });
    }

    /**
     * Refuse a claim, with the reason on the record.
     *
     * A REJECTION WITHOUT A REASON IS REFUSED. The claimant is a person who will
     * ask why, and an estate that recorded only "no" has nothing to answer with
     * — nor anything to defend the decision with if they come back.
     */
    public function rejectClaim(UnitClaim $claim, string $reason, ?User $by = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s and cannot be refused again.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException(
                'A rejected claim needs a reason. The claimant is a person who will ask why, and an '.
                'estate that recorded only "no" has nothing to answer them with.'
            );
        }

        $claim->forceFill([
            'status' => UnitClaim::REJECTED,
            'decision_reason' => mb_substr(trim($reason), 0, 190),
            'reviewed_by' => $by?->getKey(),
            'reviewed_by_name' => $by?->name,
            'reviewed_at' => now(),
        ])->save();

        /*
         * AN INVITE'S CLAIM TAKES ITS INVITE WITH IT (stage 2): the invite is cancelled, so the household's
         * primary sees "Invite cancelled" and may send a fresh one — never a claim that failed and an invite
         * that still reads "claimed".
         */
        if ($claim->invite_id !== null) {
            $invite = ResidentInvite::query()->find($claim->invite_id);

            if ($invite !== null && $invite->cancelled_at === null) {
                $invite->forceFill(['cancelled_at' => now()])->save();

                app(AuditLogger::class)->record(
                    action: 'resident.invite_cancelled',
                    entityType: 'Resident',
                    entityId: (string) $invite->resident_id,
                    after: ['invite_id' => $invite->id, 'by' => $by->name ?? 'The estate office', 'why' => 'claim_rejected', 'claim_id' => $claim->id],
                    tenantId: (string) tenant()?->getTenantKey(),
                    actor: $by,
                );
            }
        }

        return $claim;
    }

    /**
     * Ask the claimant for an identity document.
     *
     * The KIND is recorded and not just the fact. "We asked" and "we asked for a
     * passport" are different things to somebody trying to work out what to
     * send, and a claim that is chased twice for different documents is how a
     * review stalls.
     */
    public function requestDocument(UnitClaim $claim, string $kind = 'photo ID', ?User $by = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s. There is nothing left to verify.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        $claim->forceFill([
            'document_requested_at' => now(),
            'document_requested_kind' => mb_substr(trim($kind) ?: 'photo ID', 0, 64),
            'document_requested_by' => $by?->getKey(),
            'document_requested_by_name' => $by?->name,
        ])->save();

        return $claim;
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * "This unit has one of the estate's households on it", for a query over `units`: `Household::scopeCounted()` itself,
     * correlated to the outer unit, so a phase's occupancy and the totals ask the same rule the register and a meeting's
     * quorum ask (M87 §1.4) and stay one read each. Never the unit's occupied flag, which can stand with nobody behind it.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function occupiedSql(): array
    {
        $counted = Household::query()->counted()->whereColumn('households.unit_id', 'units.id')->selectRaw('1');

        return ['EXISTS ('.$counted->toSql().')', $counted->getBindings()];
    }

    /**
     * Units and occupancy per phase, in one query.
     *
     * @return array<string, array{units: int, occupied: int}>
     */
    private function unitCountsByPhase(): array
    {
        [$occupiedSql, $occupiedBindings] = self::occupiedSql();
        $rows = DB::connection('tenant')
            ->table('units')
            ->selectRaw("block, COUNT(*) as units, SUM({$occupiedSql}) as occupied", $occupiedBindings)
            ->groupBy('block')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->block] = [
                'units' => (int) $row->units,
                'occupied' => (int) $row->occupied,
            ];
        }

        return $counts;
    }

    /**
     * Units with an unanswered claim about WHO HOLDS THE HOUSEHOLD.
     *
     * A member claim is deliberately not one of these. Board 4 draws Andrea
     * Fletcher's row "Verified" while a sibling's membership claim sits in the
     * queue above it, and it is right to: nobody is disputing that Lot 47 is
     * Andrea Fletcher's. What board 4 flags amber is Keith Walters's row, where somebody the
     * register does not recognise is claiming to be the resident — and Tanya
     * Simms's, where the estate could not place the claimant at all.
     *
     * @return list<int>
     */
    private function unitsUnderClaim(): array
    {
        return UnitClaim::query()
            ->where('status', UnitClaim::PENDING)
            ->whereIn('claim_type', [UnitClaim::TYPE_UNIT, UnitClaim::TYPE_UNVERIFIED])
            ->whereNotNull('unit_id')
            ->pluck('unit_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Whether board 4 draws this household's badge amber.
     *
     * TWO WAYS INTO IT AND BOTH ARE ABOUT WHO HOLDS THE HOUSEHOLD, not about
     * everybody in it. A household is under review while a claim on its unit is
     * unanswered, and while the person the register says holds it has not been
     * verified.
     *
     * AN UNVERIFIED MEMBER DOES NOT FLAG THE HOUSEHOLD, and board 38 is what
     * settles it: Marlon Fletcher reads "Added Sep 4" in the members panel while
     * the hero pill above that row reads "Verified". A rule that flagged the whole
     * household for any unverified occupant would contradict that screen and
     * would put an amber badge on every household that had just added a teenager.
     *
     * @param  list<int>  $claimedUnitIds  from `unitsUnderClaim()`
     */
    private function householdIsPending(Household $household, array $claimedUnitIds): bool
    {
        if (in_array($household->unit_id, $claimedUnitIds, true)) {
            return true;
        }

        $primary = $household->residents->firstWhere('is_primary', true);

        return $primary !== null && ! $primary->isVerified();
    }

    /**
     * Board 4's empty filtered state, worded for the filter that emptied it (B113).
     *
     * `clears` is the query keys its one button removes — `q` for the search,
     * `phase` for the chip — so the way out always leads somewhere the list is
     * not the same empty one. Null when no filter is set: an empty list then is
     * an estate with no households, which is a different screen.
     *
     * @return array{title: string, body: string, action_label: string, clears: list<string>}|null
     */
    private function emptyFilteredCopy(string $phase, string $search, string $everyone = 'every household'): ?array
    {
        if ($phase === '' && $search === '') {
            return null;
        }

        if ($search === '') {
            return [
                'title' => 'No household is filed in '.$phase,
                'body' => 'This phase exists on the estate\'s structure and holds no household today. Clear the chip to see every phase again.',
                'action_label' => 'All phases',
                'clears' => ['phase'],
            ];
        }

        // M32 F4: what was searched, what the search looks at, and the way back — nothing that was not true.
        if ($phase === '') {
            return [
                'title' => 'Nothing matches “'.$search.'”',
                'body' => self::SEARCH_LOOKS_AT.' Clear the search to see '.$everyone.' again.',
                'action_label' => 'Clear the search',
                'clears' => ['q'],
            ];
        }

        return [
            'title' => 'Nothing in '.$phase.' matches “'.$search.'”',
            'body' => self::SEARCH_LOOKS_AT.' The phase and the search together match nothing, though either may be fine on its own. Clear both to see '.$everyone.' again.',
            'action_label' => 'Clear the search and the phase',
            'clears' => ['q', 'phase'],
        ];
    }

    /** What the register's one search box reads (M32 F4), said wherever it finds nothing. */
    public const SEARCH_LOOKS_AT = 'The search looks at names, emails, phone numbers, lots, phases and vehicles.';

    /**
     * Board 4's "Last active" column, in each of the forms it draws.
     *
     * Same day gives the time, because "Today" alone loses the only thing that
     * distinguishes nine in the morning from a minute ago. Beyond a fortnight it
     * counts weeks, which is the resolution the question is actually asked at.
     */
    private function lastActiveLabel(?Carbon $at): ?string
    {
        if ($at === null) {
            return null;
        }

        // On the estate's clock on both sides: $at's day and today (M11 §4.3). Against the UTC day, an evening
        // visit read as yesterday's from 7 PM in Kingston.
        $local = SiteTime::local($at);
        $days = (int) $local->copy()->startOfDay()->diffInDays(SiteTime::today(), absolute: true);

        return match (true) {
            // Law 5 as decided (M62 A5, DR-039): no zone on the estate's own console, and "9 Oct 2026".
            $days === 0 => 'Today, '.$local->format('g:i A'),
            $days === 1 => 'Yesterday',
            $days < 7 => $days.' days ago',
            $days < 14 => '1 week ago',
            $days < 30 => intdiv($days, 7).' weeks ago',
            default => $local->format('j M Y'),
        };
    }

    /**
     * What the claimant submitted, as board 31's left column.
     *
     * The left column is flagged only where the estate could not resolve the
     * submission at all. A claim that matched a lot is a claim whose submission
     * is at least addressable; what differs about it belongs on the right, next
     * to the record it differs from.
     *
     * @return array{label: string, mismatch: bool, rows: list<array{label: string, value: string}>}
     */
    private function submittedColumn(UnitClaim $claim): array
    {
        $rows = [['label' => 'Name', 'value' => $claim->submitted_name]];

        if ($claim->claim_type === UnitClaim::TYPE_MEMBER) {
            $rows[] = ['label' => 'Relationship', 'value' => $claim->submitted_relationship ?? 'Not given'];
            $rows[] = [
                'label' => 'Claims to',
                'value' => ($claim->household->name ?? 'a household').'\'s household',
            ];
        } else {
            $rows[] = ['label' => 'Phase / Lot', 'value' => $claim->submittedUnitLabel() ?: 'Not given'];

            if ($claim->claim_type !== UnitClaim::TYPE_UNVERIFIED) {
                $rows[] = ['label' => 'Phone', 'value' => $claim->submitted_phone ?? 'Not given'];
            }
        }

        return [
            'label' => 'Resident submitted',
            'mismatch' => $claim->claim_type === UnitClaim::TYPE_UNVERIFIED,
            'rows' => $rows,
        ];
    }

    /**
     * What the estate holds, as board 31's right column.
     *
     * THE ARREARS SUFFIX IS THE ONLY MONEY ON THIS SCREEN AND IT IS GATED TWICE.
     * A restricted household never carries it — the amber verdict travels in
     * `standing` instead — and a viewer without ledger access never carries it
     * either, which is `ARREARS_FLAG_NEEDS_LEDGER_ACCESS` and Q-014. Board 31's
     * own persona is the Property Manager, so on the drawn screen it is absent;
     * that is D-010 outranking a board, the same way the sidebar does.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}|null  $standing
     * @return array{label: string, mismatch: bool, rows: list<array{label: string, value: string}>}
     */
    private function recordColumn(UnitClaim $claim, ?Household $household, ?array $standing, bool $maySeeMoney): array
    {
        $onRecord = $household === null ? null : $this->primaryOf($household);
        $rows = [];
        $mismatch = false;

        if ($claim->claim_type === UnitClaim::TYPE_MEMBER) {
            $existing = $household?->residents
                ->firstWhere('full_name', $claim->submitted_name);

            $rows[] = ['label' => 'Primary resident', 'value' => $onRecord->full_name ?? 'None on record'];
            $rows[] = [
                'label' => 'Existing member',
                'value' => $existing === null
                    ? 'Not on file'
                    : $existing->full_name.' · added '.($existing->created_at === null ? 'unknown' : SiteTime::local($existing->created_at)->format('j M')),
            ];
            $rows[] = [
                'label' => 'Match',
                'value' => UnitClaim::MATCH_LABELS[$claim->match_result] ?? $claim->match_result,
            ];

            $mismatch = $existing === null || $claim->match_result !== 'exact';
        } elseif ($claim->claim_type === UnitClaim::TYPE_UNVERIFIED) {
            $rows[] = [
                'label' => 'Primary resident',
                'value' => $this->recordNameWithStanding($onRecord?->full_name, $standing, $maySeeMoney),
            ];
            $rows[] = [
                'label' => 'Flag',
                'value' => $claim->review_flag ?? 'Verify identity before approving',
            ];

            // An unresolved claimant is a mismatch on the record side by
            // definition: the estate is being asked to accept somebody it
            // cannot place.
            $mismatch = true;
        } else {
            $recordName = $onRecord->full_name ?? 'None on record';
            $recordPhone = $onRecord?->phone;

            $rows[] = ['label' => 'Name', 'value' => $recordName];
            $rows[] = [
                'label' => 'Phase / Lot',
                'value' => $claim->unit?->addressLabel() ?? 'No unit matched',
            ];
            $rows[] = ['label' => 'Phone', 'value' => $recordPhone ?? 'Not on file'];

            /*
             * Amber when anything the claimant gave differs from what the
             * estate holds. Derived, and never stored: a register corrected
             * between submission and review should stop being flagged, and a
             * stored flag would go on accusing a claim that was fixed.
             */
            $mismatch = $recordName !== $claim->submitted_name
                || ($claim->submitted_phone !== null && $recordPhone !== $claim->submitted_phone)
                || $recordPhone === null;
        }

        return ['label' => 'On record', 'mismatch' => $mismatch, 'rows' => $rows];
    }

    /**
     * `Household::primaryResident()`, answered from the residents the claims
     * queue already loaded (M11 A2) — the first primary, and the oldest of them,
     * in the same order that method asks MySQL for. It used to ask once per claim.
     *
     * A read path only: the queue loads its households and reads them in the same
     * request, so the loaded residents are the stored ones. Where they are not
     * loaded, the model method answers as it always has.
     */
    private function primaryOf(Household $household): ?Resident
    {
        if (! $household->relationLoaded('residents')) {
            return $household->primaryResident();
        }

        return $household->residents
            ->sortBy([['is_primary', 'desc'], ['id', 'asc']])
            ->first();
    }

    /**
     * A name on the register, with an ageing bucket only where one is allowed.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}|null  $standing
     */
    private function recordNameWithStanding(?string $name, ?array $standing, bool $maySeeMoney): string
    {
        $name ??= 'None on record';

        if ($standing === null || $standing['restricted']) {
            // A restricted household says the word or nothing. Never the days.
            return $name;
        }

        /*
         * ASSUMPTION Q-014, and `ARREARS_FLAG_NEEDS_LEDGER_ACCESS` is the flag
         * that names it. It stands at true, which is this branch: the ageing
         * bucket is a financial position and needs ledger access, so board 31's
         * own persona — the Property Manager, whom D-010 locks out — sees the
         * identity flag and nothing about money. A ruling the other way deletes
         * these three lines and the constant with them, and
         * `EstateResidentsTest` is the test that will be rewritten with it.
         */
        if (! $maySeeMoney) {
            return $name;
        }

        return $standing['bucket_label'] === null
            ? $name
            : $name.' · '.$standing['bucket_label'].' arrears';
    }

    /**
     * The two buttons a claim carries, and which of them is drawn.
     *
     * A claim the estate matched EXACTLY offers a refusal, because the only
     * question left is whether the estate agrees. Anything less offers a
     * document request, because the reviewer has something to resolve first and
     * refusing a claim they have not investigated is the wrong door. Approve is
     * always last and always primary — board 31 draws it that way on all three
     * cards, and a destructive action sitting where the confirming one usually
     * does is how the wrong button gets pressed.
     *
     * @return list<array{key: string, label: string, style: string}>
     */
    private function claimActions(UnitClaim $claim): array
    {
        $first = $claim->match_result === 'exact'
            ? ['key' => 'reject', 'label' => 'Reject', 'style' => 'outline']
            : ['key' => 'document', 'label' => 'Request ID document', 'style' => 'outline'];

        return [
            $first,
            ['key' => 'approve', 'label' => 'Approve claim', 'style' => 'primary'],
        ];
    }

    /**
     * Board 38's household panel — named members, then a rolled-up count.
     *
     * THE ROLL-UP IS A PRIVACY AFFORDANCE, NOT A LAYOUT ONE. A detail screen
     * naming every occupant of a house is a roster of who lives where; the panel
     * exists to tell a reviewer who holds the household. The rolled row reports
     * verified only when every member inside it is, because "Verified" over a
     * count that hides an unverified person is the one reading it must not have.
     *
     * @param  array<int, Resident>  $members  primary first
     * @return list<array{label: string, value: string}>
     */
    private function memberPanel(array $members): array
    {
        $rows = [];

        foreach (array_slice($members, 0, self::MEMBERS_NAMED) as $member) {
            $rows[] = [
                'label' => $member->panelName(),

                /*
                 * A union of two facts, exactly as the board draws it: a
                 * verified member says so, and one who is not says when they
                 * were added — which is what a reviewer chasing them needs.
                 *
                 * M32 F1: a demonstration placeholder's "verified" was written by the seeder, never earned, so it says that,
                 * where the screen marks samples at all (not in demonstration mode, M60 §C3).
                 */
                'value' => match (true) {
                    SampleResidents::marked($member->isSample()) => 'Sample · seeded, never verified',
                    $member->isVerified() => Resident::STATUS_LABELS[Resident::VERIFIED],
                    default => 'Added '.($member->created_at === null ? 'recently' : SiteTime::local($member->created_at)->format('j M')),
                },
                'sample' => SampleResidents::marked($member->isSample()),
            ];
        }

        $rest = array_slice($members, self::MEMBERS_NAMED);

        if ($rest !== []) {
            $allVerified = true;

            foreach ($rest as $member) {
                $allVerified = $allVerified && $member->isVerified();
            }

            $rows[] = [
                'label' => count($rest).' additional member'.(count($rest) === 1 ? '' : 's'),
                'value' => Resident::STATUS_LABELS[$allVerified ? Resident::VERIFIED : Resident::PENDING],
            ];
        }

        return $rows;
    }

    /**
     * When this household became residents.
     *
     * The OLDEST member's move-in date, because a household has been here as
     * long as its longest-standing member has — a brother added last week did
     * not make the family newer.
     *
     * @param  array<int, Resident>  $members
     */
    private function residentSince(array $members): ?Carbon
    {
        $earliest = null;

        foreach ($members as $member) {
            $date = $member->moved_in_on ?? $member->created_at;

            if ($date === null) {
                continue;
            }

            if ($earliest === null || $date->lessThan($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * Board 38's "Linked activity" — the arrears, the ticket and the booking.
     *
     * THE LEDGER ROW IS ABSENT RATHER THAN BLANKED where the balance may not be
     * shown. A row reading "arrears" with the figure struck out still says there
     * are arrears, and for a restricted household the whole point is that the
     * screen says the word and nothing else. Absent, not disabled — the same
     * rule the sidebar follows.
     *
     * `is_open_item` is what the hero count adds up, and a booking is not one: a
     * deposit that is held is money in the right place and a date that is in the
     * diary, so there is nothing for anybody to do about it.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}  $standing
     * @return list<array{type: string, title: string, sub: string, is_open_item: bool}>
     */
    private function linkedActivity(Unit $unit, array $standing): array
    {
        $links = [];

        if (($standing['balance_minor'] ?? 0) > 0) {
            $links[] = [
                'type' => 'ledger',
                // The console's separator, the middot, never an em dash (M53 Law 3).
                'title' => ($standing['bucket_label'] ?? 'Outstanding').' arrears · '.$standing['label'],
                'sub' => 'Dues & ledger',
                'is_open_item' => true,
            ];
        }

        $ticket = MaintenanceTicket::query()
            ->where('unit_id', $unit->id)
            ->whereNotIn('status', [
                MaintenanceTicket::COMPLETED,
                MaintenanceTicket::VERIFIED,
                MaintenanceTicket::CANCELLED,
            ])
            ->orderByDesc('reported_at')
            ->first();

        if ($ticket !== null) {
            $links[] = [
                'type' => 'ticket',
                'title' => 'Ticket #'.$ticket->number.' · '.$ticket->title,
                // The state it is in, with overdue beside it, never instead of it (M68 §2.3).
                // Law 5 as decided (M62 A5, DR-039): "9 Oct", as every other date on the household page is written.
                'sub' => 'Submitted '.$ticket->reported_at->format('j M').' · '.$ticket->stateLabel()
                    .($ticket->isOverdue() ? ' · overdue' : ''),
                'is_open_item' => true,
            ];
        }

        $booking = AmenityBooking::query()
            ->with('amenity')
            ->where('unit_id', $unit->id)
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->orderBy('starts_at')
            ->first();

        if ($booking !== null) {
            $deposit = $booking->depositLabel();

            $links[] = [
                'type' => 'booking',
                'title' => ($booking->amenity->name ?? 'Amenity').' booked · '
                    .$booking->starts_at->format('j M'),
                'sub' => implode(' · ', array_filter(['Facilities', $deposit])),
                'is_open_item' => false,
            ];
        }

        return $links;
    }

    /**
     * What a household with no name of its own gets called.
     *
     * The SURNAME plus "household", which is how every board names one —
     * "Fletcher household", "Brown household". Falling back to the lot where
     * there is no surname to take, because a household called " household" is
     * worse on a screen than one called after its address.
     */
    private function householdNameFor(string $personName, Unit $unit): string
    {
        $parts = preg_split('/\s+/', trim($personName)) ?: [];
        $surname = trim((string) end($parts));

        return ($surname === '' ? (string) $unit->reference : $surname).' household';
    }

    /**
     * The unit in this phase that what was typed names, or null.
     *
     * WHAT WAS TYPED FIRST, EXACTLY (B041). A reference is whatever the estate
     * calls the unit — "Lot 47" at Phoenix Park, "OV-1A" at Ocean View, and
     * "A-12" or "14B" from the unit import — so the form's value is looked for as
     * it stands before anything is added to it. This used to put "Lot " in front
     * of every value unconditionally, so a unit whose reference is not a lot
     * could never be given a resident, and the refusal told the office to add a
     * unit it already had.
     *
     * THEN AS A LOT. Somebody typing into a field labelled "Lot number" types
     * "112", and means "Lot 112"; somebody pasting "lot 112" means it too. That
     * reading is kept, second, so a unit that is literally called "112" wins over
     * "Lot 112" when the estate has both — it is what was typed.
     *
     * Case-insensitively, both times: references are unique without regard to
     * case, so "ov-1a" can only mean "OV-1A".
     */
    private function unitTyped(string $phase, string $lot): ?Unit
    {
        foreach ($this->referencesTyped($lot) as $reference) {
            $unit = Unit::query()
                ->where('block', $phase)
                ->whereRaw('LOWER(reference) = LOWER(?)', [$reference])
                ->first();

            if ($unit !== null) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * What a typed unit can mean, in the order `unitTyped()` tries it.
     *
     * @return list<string> the value as typed, then — where it is different — the lot it names
     */
    private function referencesTyped(string $lot): array
    {
        $typed = trim($lot);
        $asLot = Str::startsWith(strtolower($typed), 'lot ') ? 'Lot '.trim(substr($typed, 4)) : 'Lot '.$typed;

        return strcasecmp($typed, $asLot) === 0 ? [$typed] : [$typed, $asLot];
    }

    /**
     * Minor units as every estate board prints them: "$12,400", and "$3,400.50" when there are cents.
     *
     * Whole dollars with a thousands separator for a whole amount, as board 4's column draws it; the cents whenever there
     * are any (Hassam's money proof, 12 Oct test campaign: "every amount with cents shows its cents"). It was `intdiv`,
     * which printed a household owing $3,400.50 as "$3,400" on the register, the household's standing and Add resident's
     * line about the lot's balance. The amenity diary's own formatter, so one amount reads one way across the console;
     * integer arithmetic only. `balance_minor` beside the label is the exact figure any arithmetic uses.
     */
    private function money(int $minor): string
    {
        return AmenityBooking::amount($minor);
    }
}
