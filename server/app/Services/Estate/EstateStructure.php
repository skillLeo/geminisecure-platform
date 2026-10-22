<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phases and the addresses in them — board 3's two writes (12 §2, Wave 1).
 *
 * AN ADDRESS IS WHAT A HOUSEHOLD IS FILED AT AND DUES ARE BILLED TO, so both
 * writes here are all-or-nothing. A phase is added with its lot range decided
 * together and every lot created in one transaction; a unit list is validated
 * whole before one row of it is written, and a bad file is rejected whole. A
 * half-imported estate is a dues run that bills some households and not
 * others.
 *
 * NOTHING HERE DELETES. A unit with a ledger cannot go, and the estate has
 * never had a path that removes one.
 */
class EstateStructure
{
    /** The most rows one import may carry — enough for any estate on this platform, and a ceiling on a runaway file. */
    public const IMPORT_MAX_ROWS = 5000;

    /**
     * The longest phase name, in characters — what `units.block` holds (B046).
     *
     * A phase's name is stored twice: on its own row (64) and on every unit in
     * it (32). Only the first was checked, so a 33-to-64 character name passed
     * and then failed inside the transaction as "data too long" — a 500, after
     * the import preview had said the file was fine — and a phase added with no
     * lots got in and could never take one. The governance tables that carry a
     * phase (a position, a ballot option, a meeting) hold 32 as well, so 32 is
     * the length a phase name can actually be used at everywhere.
     */
    public const PHASE_NAME_MAX = 32;

    /** The longest unit reference, in characters — `units.reference`. */
    public const REFERENCE_MAX = 32;

    /** How many blocks of one phase the import preview names before "and N more ranges" (M27 §1.3). */
    public const SUMMARY_RUNS = 12;

    /** The longest street, in characters — `units.street`. */
    public const STREET_MAX = 120;

    public const TYPES = ['residential', 'commercial'];

    public const STATUSES = ['occupied', 'vacant'];

    public function __construct(private readonly AuditLogger $audit) {}

    /*
     * THE CONTRACT'S UNITS ARE THE CEILING (the client, 26 Sep: "this user was asking 5 phases but why is he able to
     * make a new phase in his console"; D-176). An estate is billed per unit, and the contract — the subscription's
     * `unit_count`, which Gemini sets — is what it agreed to pay for. An estate could add phases and lots past it from
     * its own console, and every lot past it was a unit nobody billed. Now the estate adds lots up to its contract and
     * is told, past it, to ask GeminiSecure to raise it; a phase with no lots still goes in, because it changes nothing
     * anybody pays. An estate with no contract figure yet (being set up) is not held to one.
     */

    /*
     * AN ESTATE BUILT FROM A SITE PLAN (M57.5 §4.1, §4.2): its phases are runs of lot numbers (`estate_phases.first_lot`,
     * `last_lot`), each unit's phase is worked out from its lot (PhasesFromLots), and the plan says how many homes were
     * BUILT. The contract's figure is a separate number that the plan never changes: where the two differ, both are said
     * ("contracted 2,000, built 1,999"), because the contract is a billing figure and only the people who signed it
     * change it. A phase or a lot outside the plan cannot be added from the console: the plan is changed first.
     */

    /** Why a plan-built estate takes no new phase and no new lot from the console. */
    public const PLAN_REFUSAL = 'Phases and lots come from the site plan. Please contact your service provider to add more.';

    /** M78 §3: what the page says when nothing is left, in Hassam's words. */
    public const FULL = 'Your estate is full. Please contact your service provider to add more.';

    /** M78 §3: the one line on the add controls when nothing is left. */
    public const NO_LOTS_LEFT = 'No lots left.';

    /**
     * The estate's plan, when it has one: the runs of lots, how many homes they hold, and whether the phase is worked
     * out from the lot. Null for an estate whose phases are not runs of lot numbers.
     *
     * @return array{ranges: list<array{name: string, first: int, last: int}>, built: int, derived: bool}|null
     */
    public function plan(): ?array
    {
        $ranges = PhasesFromLots::ranges();

        if ($ranges === []) {
            return null;
        }

        return [
            'ranges' => $ranges,
            'built' => array_sum(array_map(static fn (array $r): int => $r['last'] - $r['first'] + 1, $ranges)),
            'derived' => Unit::phaseIsDerived(),
        ];
    }

    /** @return array{contracted: int, units: int}|null the contract's units and the estate's, or null with no contract figure */
    public function contract(): ?array
    {
        $contracted = (int) (Subscription::query()->where('tenant_id', (string) tenant()?->getTenantKey())->value('unit_count') ?? 0);

        return $contracted > 0 ? ['contracted' => $contracted, 'units' => Unit::query()->count()] : null;
    }

    /** Why adding this many lots is refused, or null when the contract has room (or there is no contract figure). */
    public function overContract(int $adding): ?string
    {
        // A plan-built estate is bounded by its plan (each row is checked against it), not by the contract's figure.
        if ($this->plan() !== null) {
            return null;
        }

        $contract = $this->contract();

        if ($adding <= 0 || $contract === null || $contract['contracted'] >= $contract['units'] + $adding) {
            return null;
        }

        return sprintf(
            'Your estate is allowed %s units; %s are in use and %s %s left. Adding %s would make %s. Please contact your service provider to add more.',
            number_format($contract['contracted']),
            number_format($contract['units']),
            number_format(max(0, $contract['contracted'] - $contract['units'])),
            max(0, $contract['contracted'] - $contract['units']) === 1 ? 'is' : 'are',
            number_format($adding),
            number_format($contract['units'] + $adding),
        );
    }

    /**
     * WHY NO PHASE MAY BE ADDED AT ALL: THE ESTATE IS FULL (M32 F2). "2,000 of 2,000 contracted units in use", and a sixth
     * phase still went in: a phase with no lots was let through on purpose, because it changes nothing anybody pays. On a
     * full estate it is a name nothing can ever be filed under, and the screen beside it had just said there was no room,
     * so it is refused, with the three numbers. Null while there is room, or with no contract figure.
     */
    public function fullRefusal(): ?string
    {
        $contract = $this->contract();

        if ($contract === null || $contract['units'] < $contract['contracted']) {
            return null;
        }

        return sprintf(
            'Your estate is full: it is allowed %s units, %s are in use and %s are left. Please contact your service provider to add more.',
            number_format($contract['contracted']),
            number_format($contract['units']),
            number_format(max(0, $contract['contracted'] - $contract['units'])),
        );
    }

    /**
     * WHERE THE ESTATE STANDS, BEFORE ANYBODY STARTS (M32 F2): the units allowed, on the register and on the site plan,
     * what is left, and under them only what needs saying (M78 §3): that the estate is full, or what is left. The lots
     * off the site plan are listed (`outside_plan`) and never named on the page: Hassam, 12 Oct, "remove the text 'Lot
     * 1999A is not on the site plan.'" (the two figures already differ). An estate with no figure allowed (being set up)
     * is not held to one; its figures say "Not set" and "No limit", so it has no sentence.
     *
     * @return array{contracted: ?int, built: ?int, units: int, register: int, left: ?int, phases: string, full: bool, outside_plan: list<string>, sentence: string, add_refusal: ?string, plan_refusal: ?string}
     */
    public function standing(): array
    {
        $census = $this->census();

        return $this->standingOf($this->contractFor($census['count']), $census);
    }

    /**
     * The structure page's head in one pass (M32 F2, F6): the contract, where the estate stands, and its lots, from ONE
     * read of the units. Asked separately they read the contract and counted the units three times over.
     *
     * @return array{contract: array{contracted: int, units: int}|null, standing: array{contracted: ?int, built: ?int, units: int, register: int, left: ?int, phases: string, full: bool, outside_plan: list<string>, sentence: string, add_refusal: ?string, plan_refusal: ?string}, lots: array<string, mixed>}
     */
    public function head(int $blocks = 1, ?int $from = null, ?int $to = null): array
    {
        $census = $this->census();
        $contract = $this->contractFor($census['count']);
        $standing = $this->standingOf($contract, $census);

        return [
            'contract' => $contract,
            'standing' => $standing,
            'lots' => $this->lotsFrom($census, $standing['left'], $blocks, $from, $to),
        ];
    }

    /**
     * Every unit's reference and phase, and the phases they are filed under, read once each: how many units there are,
     * which phases hold them (in the estate's own order, with their blocks), and the lot numbers.
     *
     * @return array{count: int, phases: list<array{name: string, block_count: int}>, numbered: array<int, string>, others: list<string>}
     */
    private function census(): array
    {
        $count = 0;
        $blocks = [];
        $numbered = [];
        $others = [];

        foreach (Unit::query()->get(['reference', 'block']) as $unit) {
            $count++;

            if ($unit->block !== null) {
                $blocks[(string) $unit->block] = true;
            }

            if (preg_match('/^lot\s+(\d{1,9})$/i', trim((string) $unit->reference), $m) === 1) {
                $numbered[(int) $m[1]] = (string) ($unit->block ?? 'no phase');
            } else {
                $others[] = (string) $unit->reference;
            }
        }

        $phases = $blocks === [] ? [] : Phase::query()->whereIn('name', array_keys($blocks))->orderBy('sequence')->orderBy('id')->get(['name', 'block_count'])
            ->map(static fn (Phase $p): array => ['name' => (string) $p->name, 'block_count' => (int) $p->block_count])->values()->all();

        return ['count' => $count, 'phases' => $phases, 'numbered' => $numbered, 'others' => $others];
    }

    /**
     * `contract()` on a unit count already taken.
     *
     * @return array{contracted: int, units: int}|null
     */
    private function contractFor(int $units): ?array
    {
        $contracted = (int) (Subscription::query()->where('tenant_id', (string) tenant()?->getTenantKey())->value('unit_count') ?? 0);

        return $contracted > 0 ? ['contracted' => $contracted, 'units' => $units] : null;
    }

    /**
     * `standing()` on figures already read.
     *
     * @param  array{contracted: int, units: int}|null  $contract
     * @param  array{count: int, phases: list<array{name: string, block_count: int}>, numbered: array<int, string>, others: list<string>}  $census
     * @return array{contracted: ?int, built: ?int, units: int, register: int, left: ?int, phases: string, full: bool, outside_plan: list<string>, sentence: string, add_refusal: ?string, plan_refusal: ?string}
     */
    private function standingOf(?array $contract, array $census): array
    {
        $units = $census['count'];

        // The phases that hold the units in use, in the estate's own order: "Phase 1 to Phase 5".
        $names = array_column($census['phases'], 'name');
        $phases = match (count($names)) {
            0 => $units === 0 ? 'no units yet' : 'not filed under a phase',
            1 => $names[0],
            default => $names[0].' to '.$names[count($names) - 1],
        };

        /*
         * BUILT FROM A SITE PLAN (M57.5 §4.1): "units" is the plan's lots the estate has, "left" is what the plan has
         * that the register does not, and the figure allowed is said beside the plan's, never in place of it. M78 §3:
         * the sentence that compared the two is gone; the figures sit side by side.
         */
        $plan = $this->plan();

        if ($plan !== null) {
            $inPlan = 0;
            $outside = $census['others'];

            foreach (array_keys($census['numbered']) as $lot) {
                if (PhasesFromLots::phaseOf((int) $lot, $plan['ranges']) !== null) {
                    $inPlan++;
                } else {
                    $outside[] = 'Lot '.$lot;
                }
            }

            $left = max(0, $plan['built'] - $inPlan);

            return [
                'contracted' => $contract['contracted'] ?? null,
                'built' => $plan['built'],
                'units' => $inPlan,
                'register' => $units,
                'left' => $left,
                'phases' => $phases,
                'full' => $left === 0,
                'outside_plan' => $outside,
                'sentence' => $left === 0
                    ? self::FULL
                    : number_format($left).' lot'.($left === 1 ? '' : 's').' on the site plan '.($left === 1 ? 'is' : 'are').' not on the register yet.',
                'add_refusal' => $left === 0 ? self::NO_LOTS_LEFT : 'Lots come from the site plan.',
                'plan_refusal' => self::PLAN_REFUSAL,
            ];
        }

        if ($contract === null) {
            return [
                'contracted' => null,
                'built' => null,
                'units' => $units,
                'register' => $units,
                'left' => null,
                'phases' => $phases,
                'full' => false,
                'outside_plan' => [],
                // The figures say it ("Not set", "No limit"): a number needs no sentence (M78 §2).
                'sentence' => '',
                'add_refusal' => null,
                'plan_refusal' => null,
            ];
        }

        $left = max(0, $contract['contracted'] - $units);

        return [
            'contracted' => $contract['contracted'],
            'built' => null,
            'units' => $units,
            'register' => $units,
            'left' => $left,
            'phases' => $phases,
            'full' => $left === 0,
            'outside_plan' => [],
            'sentence' => $left === 0
                ? self::FULL
                : 'You can add up to '.number_format($left).' more unit'.($left === 1 ? '' : 's').'.',
            'add_refusal' => $left === 0 ? self::NO_LOTS_LEFT : null,
            'plan_refusal' => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* which lot numbers are free (M32 F6) */
    /* ------------------------------------------------------------------ */

    /** How many ranges a list names before it says "and N more". */
    private const RANGES_SHOWN = 3;

    /**
     * WHICH LOT NUMBERS THE ESTATE HAS, AND WHICH ARE FREE, BEFORE A NUMBER IS TYPED (M32 F6). "Lots from 90 to 100"
     * used to be answered only after submitting: "11 of those lots already exist". This reads the units once.
     *
     * A lot is NUMBERED when its reference is "Lot" and a whole number ("Lot 413"); anything else ("Lot 1999A", "PP-14B")
     * holds no number and is named apart. Free is every gap below the highest number, and everything above it.
     *
     * SUGGESTED is the next free block after the highest number, of the size the Blocks field implies: the estate's own
     * lots per block, from the phases it has, times the blocks typed — and never more than the contract has room for.
     * `$from`/`$to`, when both are given, are checked as the form types them: free, taken (by which phase), or past the
     * contract, in one line.
     *
     * @return array<string, mixed>
     */
    public function lotPanel(int $blocks = 1, ?int $from = null, ?int $to = null): array
    {
        $census = $this->census();
        $standing = $this->standingOf($this->contractFor($census['count']), $census);

        return $this->lotsFrom($census, $standing['left'], $blocks, $from, $to);
    }

    /**
     * `lotPanel()` on a census already taken.
     *
     * @param  array{count: int, phases: list<array{name: string, block_count: int}>, numbered: array<int, string>, others: list<string>}  $census
     * @return array<string, mixed>
     */
    private function lotsFrom(array $census, ?int $room, int $blocks, ?int $from, ?int $to): array
    {
        $numbered = $census['numbered'];

        // The estate's own lots per block, from the phases that have lots: the size a new phase of N blocks implies.
        $withLots = array_flip(array_unique(array_values($numbered)));
        $blocksHeld = array_sum(array_map(static fn (array $p): int => isset($withLots[$p['name']]) ? $p['block_count'] : 0, $census['phases']));
        $perBlock = $blocksHeld > 0 ? count($numbered) / $blocksHeld : null;

        return self::panel($numbered, $census['others'], $perBlock, $room, $blocks, $from, $to);
    }

    /**
     * `lotPanel()` on numbers already read: the arithmetic alone, so it can be held to exact answers.
     *
     * @param  array<int, string>  $numbered  lot number => the phase it stands in
     * @param  list<string>  $others  references that hold no plain number
     * @param  ?int  $room  units the contract has left, or null with no contract figure
     * @return array<string, mixed>
     */
    public static function panel(array $numbered, array $others, ?float $perBlock, ?int $room, int $blocks = 1, ?int $from = null, ?int $to = null): array
    {
        ksort($numbered);
        $used = self::ranges(array_keys($numbered));
        $highest = $numbered === [] ? 0 : (int) array_key_last($numbered);
        $gaps = self::gaps(array_keys($numbered));

        $size = $perBlock === null ? max(1, $blocks) : max(1, (int) round($perBlock * max(1, $blocks)));
        $size = $room === null ? $size : min($size, $room);

        $suggested = $room === 0 ? null : ['from' => $highest + 1, 'to' => $highest + $size];

        return [
            // What this answer was asked about, so a page shows it only while those are the numbers on its screen.
            'blocks' => max(1, $blocks),
            'used_label' => $used === [] ? 'No numbered lots yet' : self::rangesLabel($used),
            'free_label' => self::freeLabel($gaps, $highest + 1),
            'others' => count($others) === 0 ? null : count($others).' lot'.(count($others) === 1 ? '' : 's').' without a plain number: '.implode(', ', array_slice($others, 0, 5)).(count($others) > 5 ? ', …' : ''),
            'suggested' => $suggested,
            'suggested_label' => $suggested === null
                ? 'Nothing to suggest: no lots left.'
                : self::rangeText($suggested['from'], $suggested['to']).($perBlock === null ? '' : ', about '.number_format($perBlock, 0).' a block, as the estate\'s phases have'),
            'check' => $from === null || $to === null ? null : ['from' => $from, 'to' => $to, ...self::rangeVerdict($from, $to, $numbered, $room)],
        ];
    }

    /**
     * One line for a typed range: free, taken (and by which phase), or past the contract.
     *
     * @param  array<int, string>  $numbered  lot number => the phase it stands in
     * @return array{ok: bool, line: string}
     */
    private static function rangeVerdict(int $from, int $to, array $numbered, ?int $room): array
    {
        if ($from < 1 || $to < $from || $to - $from >= self::IMPORT_MAX_ROWS) {
            return ['ok' => false, 'line' => 'A lot range runs upward from 1, with both ends filled in, and no phase has more than five thousand lots.'];
        }

        $count = $to - $from + 1;
        $taken = [];

        foreach ($numbered as $lot => $phase) {
            if ($lot >= $from && $lot <= $to) {
                $taken[$lot] = $phase;
            }
        }

        if ($taken !== []) {
            $first = (int) array_key_first($taken);

            return ['ok' => false, 'line' => 'Lot '.$first.' is already in '.$taken[$first].'.'
                .(count($taken) > 1 ? ' '.(count($taken) - 1).' more of these lots are in use too.' : '')];
        }

        if ($room !== null && $count > $room) {
            return ['ok' => false, 'line' => $count.' lot'.($count === 1 ? '' : 's').', '.self::rangeText($from, $to).', but only '.number_format($room).' more '.($room === 1 ? 'is' : 'are').' allowed.'];
        }

        return ['ok' => true, 'line' => $count.' lot'.($count === 1 ? '' : 's').', '.self::rangeText($from, $to).'. All free.'];
    }

    /**
     * Sorted whole numbers as runs: [1, 2, 3, 5] → [[1, 3], [5, 5]].
     *
     * @param  list<int>  $numbers  ascending
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(array $numbers): array
    {
        $runs = [];

        foreach ($numbers as $n) {
            if ($runs !== [] && $runs[count($runs) - 1][1] === $n - 1) {
                $runs[count($runs) - 1][1] = $n;
            } else {
                $runs[] = [$n, $n];
            }
        }

        return $runs;
    }

    /**
     * The gaps below the highest number: [[1, 3], [5, 5]] has the gap [4, 4].
     *
     * @param  list<int>  $numbers  ascending
     * @return list<array{0: int, 1: int}>
     */
    private static function gaps(array $numbers): array
    {
        $gaps = [];
        $next = 1;

        foreach ($numbers as $n) {
            if ($n > $next) {
                $gaps[] = [$next, $n - 1];
            }

            $next = $n + 1;
        }

        return $gaps;
    }

    private static function rangeText(int $from, int $to): string
    {
        return $from === $to ? 'Lot '.$from : 'Lot '.$from.' to Lot '.$to;
    }

    /**
     * "Lot 1 to Lot 1998, and Lot 2000"; past three runs, "…, and 4 more".
     *
     * @param  list<array{0: int, 1: int}>  $runs
     */
    private static function rangesLabel(array $runs): string
    {
        $shown = array_map(static fn (array $r): string => self::rangeText($r[0], $r[1]), array_slice($runs, 0, self::RANGES_SHOWN));
        $more = count($runs) - count($shown);

        return $more > 0 ? implode(', ', $shown).', and '.$more.' more' : self::joinAnd($shown);
    }

    /**
     * "Lot 1999, and anything from Lot 2001 upward"; past three gaps, "…, 4 more gaps, and anything …".
     *
     * @param  list<array{0: int, 1: int}>  $gaps
     */
    private static function freeLabel(array $gaps, int $above): string
    {
        $shown = array_map(static fn (array $r): string => self::rangeText($r[0], $r[1]), array_slice($gaps, 0, self::RANGES_SHOWN));
        $more = count($gaps) - count($shown);

        return ucfirst(self::joinAnd([...$shown, ...($more > 0 ? [$more.' more gap'.($more === 1 ? '' : 's')] : []), 'anything from Lot '.$above.' upward']));
    }

    /** @param list<string> $parts */
    private static function joinAnd(array $parts): string
    {
        return count($parts) <= 1 ? ($parts[0] ?? '') : implode(', ', array_slice($parts, 0, -1)).', and '.$parts[count($parts) - 1];
    }

    /* ------------------------------------------------------------------ */
    /* add phase */
    /* ------------------------------------------------------------------ */

    /**
     * Add a phase, and the lots in it.
     *
     * The blocks and the lot range are decided together, as the reason on the
     * old inert control said they had to be. A phase may be added with no
     * lots — an estate taking on land before the addresses exist — and take
     * them from an import later. Every lot reference is checked against the
     * estate before any is written, so a range that overlaps an existing lot
     * creates nothing.
     *
     * @return array{phase: Phase, units: int}
     */
    public function addPhase(
        string $name,
        int $blockCount,
        ?int $lotFrom,
        ?int $lotTo,
        ?string $street,
        User $by,
    ): array {
        $name = trim($name);

        // An estate built from a site plan takes its phases from the plan (M57.5 §4.1), never from this form.
        if ($this->plan() !== null) {
            throw new DomainException(self::PLAN_REFUSAL);
        }

        // A full estate takes no new phase at all, lots or none (M32 F2): the screen already says so beside the button.
        if (($full = $this->fullRefusal()) !== null) {
            throw new DomainException($full);
        }

        if ($name === '') {
            throw new DomainException('A phase has a name — Phase 6, or Hillside — and every lot in it is filed under that name.');
        }

        if (mb_strlen($name) > self::PHASE_NAME_MAX) {
            throw new DomainException(sprintf(
                'A phase name runs to %d characters, because every lot in it carries the name, and %s is %d.',
                self::PHASE_NAME_MAX,
                $name,
                mb_strlen($name),
            ));
        }

        if (Phase::query()->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            throw new DomainException($name.' already exists. Two phases with one name would file two sets of lots under it.');
        }

        if (Unit::query()->where('block', $name)->exists()) {
            throw new DomainException(sprintf(
                'Units already stand in a block called %s. Adding it as a phase would claim them; import them under it instead.',
                $name,
            ));
        }

        if ($blockCount < 0 || $blockCount > 200) {
            throw new DomainException('A phase has between none and two hundred blocks.');
        }

        if (($lotFrom === null) !== ($lotTo === null)) {
            throw new DomainException('A lot range has both ends, or neither.');
        }

        $references = [];

        if ($lotFrom !== null && $lotTo !== null) {
            if ($lotFrom < 1 || $lotTo < $lotFrom || $lotTo - $lotFrom >= self::IMPORT_MAX_ROWS) {
                throw new DomainException('A lot range runs upward from 1, and no phase has more than five thousand lots.');
            }

            for ($lot = $lotFrom; $lot <= $lotTo; $lot++) {
                $references[] = 'Lot '.$lot;
            }

            $taken = Unit::query()->whereIn('reference', $references)->orderBy('reference')->pluck('reference');

            if ($taken->isNotEmpty()) {
                throw new DomainException(sprintf(
                    '%d of those lots already exist — %s%s. A range that overlaps the estate creates nothing; choose lots the estate does not have.',
                    $taken->count(),
                    $taken->take(5)->implode(', '),
                    $taken->count() > 5 ? ', …' : '',
                ));
            }
        }

        if (($refusal = $this->overContract(count($references))) !== null) {
            throw new DomainException($refusal);
        }

        return DB::connection('tenant')->transaction(function () use ($name, $blockCount, $street, $references): array {
            $phase = Phase::create([
                'name' => $name,
                'sequence' => ((int) Phase::query()->max('sequence')) + 1,
                'block_count' => $blockCount,
                'officers_assigned' => null,
                'status' => Phase::NEW,
                'footnote' => 'officers pending',
            ]);

            $now = now();

            $rows = array_map(static fn (string $reference): array => [
                'reference' => $reference,
                'block' => $name,
                'street' => $street === null || trim($street) === '' ? null : trim($street),
                'type' => 'residential',
                'status' => 'vacant',
                'created_at' => $now,
                'updated_at' => $now,
            ], $references);

            foreach (array_chunk($rows, 500) as $chunk) {
                Unit::query()->insert($chunk);
            }

            $this->audit->record(
                action: 'estate.phase_added',
                entityType: 'Phase',
                entityId: (string) $phase->id,
                after: ['name' => $name, 'blocks' => $blockCount, 'units' => count($references)],
            );

            return ['phase' => $phase, 'units' => count($references)];
        });
    }

    /* ------------------------------------------------------------------ */
    /* import CSV */
    /* ------------------------------------------------------------------ */

    /**
     * Parse and validate a unit list. Nothing is written.
     *
     * COLUMNS: reference, phase, street, type, status — a header row names
     * them in any order; only reference and phase are required. Every row is
     * checked, every problem is reported against its line, and the file is
     * valid only when no row has one. A phase named in the file that does not
     * exist is created on commit and reported here as new.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, new_phases: list<string>, valid: bool}
     */
    public function parseUnits(string $contents): array
    {
        $lines = preg_split('/\R/', trim(self::asUtf8($contents))) ?: [];

        if (count($lines) < 2) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file has no rows under its header.']], 'new_phases' => [], 'valid' => false];
        }

        $header = array_map(static fn (string $h): string => strtolower(trim($h, " \t\"'")), str_getcsv(array_shift($lines)));
        $required = ['reference', 'phase'];
        $errors = [];

        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                $errors[] = ['line' => 1, 'message' => 'The header has no "'.$column.'" column. Expected: reference, phase, street, type, status.'];
            }
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors, 'new_phases' => [], 'valid' => false];
        }

        if (count($lines) > self::IMPORT_MAX_ROWS) {
            return ['rows' => [], 'errors' => [['line' => 1, 'message' => 'The file has more than '.number_format(self::IMPORT_MAX_ROWS).' rows. Split it.']], 'new_phases' => [], 'valid' => false];
        }

        $known = Phase::query()->pluck('name')->map(static fn (string $n): string => strtolower($n))->flip();
        $existing = Unit::query()->pluck('reference')->map(static fn (string $r): string => strtolower($r))->flip();

        // Built from a site plan (M57.5 §4.2): a lot's phase is the plan's, so a row is checked against it, not trusted.
        $plan = $this->plan();

        $rows = [];
        $seen = [];
        $newPhases = [];

        foreach ($lines as $index => $line) {
            $lineNo = $index + 2;

            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $row = [];

            foreach ($header as $i => $column) {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }

            $reference = $row['reference'] ?? '';
            $phase = $row['phase'] ?? '';
            $type = strtolower($row['type'] ?? '') ?: 'residential';
            $status = strtolower($row['status'] ?? '') ?: 'vacant';

            // CHARACTERS, as the column counts them — not bytes, which called a
            // 32-character reference with an accent in it too long.
            if ($reference === '' || mb_strlen($reference) > self::REFERENCE_MAX) {
                $errors[] = ['line' => $lineNo, 'message' => 'A reference is required and runs to '.self::REFERENCE_MAX.' characters.'];
            } elseif (isset($existing[strtolower($reference)])) {
                $errors[] = ['line' => $lineNo, 'message' => $reference.' already exists in this estate.'];
            } elseif (isset($seen[strtolower($reference)])) {
                $errors[] = ['line' => $lineNo, 'message' => $reference.' appears twice in the file (first on line '.$seen[strtolower($reference)].').'];
            }

            $seen[strtolower($reference)] = $lineNo;

            // 32 and not 64: the phase is written onto the unit, and `units.block`
            // holds 32 (B046). Checked for a phase that already exists too — one
            // added before this limit was held can take no lot either.
            if ($plan !== null) {
                $lot = PhasesFromLots::lotNumberOf($reference);
                $planned = PhasesFromLots::phaseOf($lot, $plan['ranges']);

                if ($lot === null) {
                    $errors[] = ['line' => $lineNo, 'message' => 'This estate\'s lots are numbered on its site plan, so a reference is "Lot" and a number, not "'.$reference.'".'];
                } elseif ($planned === null) {
                    $errors[] = ['line' => $lineNo, 'message' => 'Lot '.$lot.' is not on the site plan.'];
                } elseif (strcasecmp($planned, $phase) !== 0) {
                    $errors[] = ['line' => $lineNo, 'message' => 'Lot '.$lot.' is in '.$planned.' on the site plan, not '.($phase === '' ? 'no phase' : $phase).'.'];
                }
            } elseif ($phase === '' || mb_strlen($phase) > self::PHASE_NAME_MAX) {
                $errors[] = ['line' => $lineNo, 'message' => 'A phase is required and runs to '.self::PHASE_NAME_MAX.' characters, because every unit in it carries the name.'];
            } elseif (! isset($known[strtolower($phase)])
                && ! in_array(strtolower($phase), array_map('strtolower', $newPhases), true)) {
                // First spelling wins: "Phase 7" on line 2 and "phase 7" on
                // line 3 are one new phase, named the way line 2 wrote it.
                $newPhases[] = $phase;
            }

            if (! in_array($type, self::TYPES, true)) {
                $errors[] = ['line' => $lineNo, 'message' => 'Type is residential or commercial, not "'.$type.'".'];
            }

            if (! in_array($status, self::STATUSES, true)) {
                $errors[] = ['line' => $lineNo, 'message' => 'Status is occupied or vacant, not "'.$status.'".'];
            }

            $rows[] = [
                'line' => $lineNo,
                'reference' => $reference,
                'phase' => $phase,
                // Cut at a CHARACTER. A byte cut can land inside one and hand MySQL
                // a string that is not UTF-8 at all.
                'street' => ($row['street'] ?? '') === '' ? null : mb_substr($row['street'], 0, self::STREET_MAX),
                'type' => $type,
                'status' => $status,
            ];
        }

        if ($rows === []) {
            $errors[] = ['line' => 2, 'message' => 'The file has no rows under its header.'];
        }

        // Said in the preview, before anybody presses Import: a file past the contract is refused whole.
        if ($errors === [] && ($refusal = $this->overContract(count($rows))) !== null) {
            $errors[] = ['line' => 1, 'message' => $refusal];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'new_phases' => $newPhases,
            'valid' => $errors === [],
        ];
    }

    /**
     * What a previewed list will do, phase by phase (M25 §2.3): how many lots go into each phase, which lots, and
     * whether the phase is new. The preview table shows the first 200 rows; a whole estate is 2,000, so this is what
     * lets a person see every boundary before pressing Import. Lots named "Lot <number>" are told as runs of numbers;
     * anything else is counted and named.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $newPhases
     * @return list<array{phase: string, count: int, lots: string, new: bool}>
     */
    public static function summarise(array $rows, array $newPhases): array
    {
        $byPhase = [];

        foreach ($rows as $row) {
            $phase = (string) ($row['phase'] ?? '');
            $byPhase[$phase] ??= ['numbers' => [], 'other' => []];

            if (preg_match('/^Lot\s+(\d+)$/i', (string) ($row['reference'] ?? ''), $m) === 1) {
                $byPhase[$phase]['numbers'][] = (int) $m[1];
            } else {
                $byPhase[$phase]['other'][] = (string) ($row['reference'] ?? '');
            }
        }

        $new = array_map('mb_strtolower', $newPhases);
        $summary = [];

        foreach ($byPhase as $phase => $refs) {
            sort($refs['numbers']);
            $runs = [];

            foreach ($refs['numbers'] as $n) {
                $last = array_key_last($runs);

                if ($last !== null && $runs[$last][1] === $n - 1) {
                    $runs[$last][1] = $n;
                } elseif ($last === null || $runs[$last][1] !== $n) {
                    $runs[] = [$n, $n];
                }
            }

            // Every block a phase has (M27 §1.3: "phase one has two sides of it"), up to a dozen, so it reads against the map.
            $told = array_map(static fn (array $run): string => $run[0] === $run[1] ? 'Lot '.$run[0] : 'Lot '.$run[0].' to Lot '.$run[1], array_slice($runs, 0, self::SUMMARY_RUNS));

            if (count($runs) > self::SUMMARY_RUNS) {
                $told[] = 'and '.(count($runs) - self::SUMMARY_RUNS).' more range'.(count($runs) - self::SUMMARY_RUNS === 1 ? '' : 's');
            }

            if ($refs['other'] !== []) {
                $told[] = implode(', ', array_slice($refs['other'], 0, 3)).(count($refs['other']) > 3 ? ' and '.(count($refs['other']) - 3).' more' : '');
            }

            $summary[] = [
                'phase' => (string) $phase,
                'count' => count($refs['numbers']) + count($refs['other']),
                'lots' => implode(', ', $told),
                'new' => in_array(mb_strtolower((string) $phase), $new, true),
            ];
        }

        return $summary;
    }

    /**
     * A unit list as UTF-8 text, whichever of Excel's two CSVs it was saved as (B051).
     *
     * "CSV UTF-8 (Comma delimited)" — Excel's UTF-8 CSV, and what the platform's
     * own `Exporter` writes on purpose — starts with a byte-order mark. Left in,
     * it is part of the first header cell, which then reads "\xEF\xBB\xBFreference",
     * and the file was refused for having no "reference" column when it plainly
     * had one. The mark is dropped.
     *
     * Plain "CSV (Comma delimited)" is not UTF-8 at all: it is the Windows code
     * page, where "é" is the one byte E9 that UTF-8 does not allow, and a street
     * spelt with one could be neither stored nor drawn. A file that is not valid
     * UTF-8 is read as Windows-1252, what Excel on an English Windows writes. A
     * file that IS valid UTF-8 is left exactly as it is — never converted twice.
     */
    private static function asUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }

    /**
     * Commit a validated unit list — all of it, or none of it.
     *
     * Re-validated here, not trusted from the preview: the estate may have
     * changed between the two presses, and a reference that was free a minute
     * ago may not be now. The whole file is refused if a single row fails.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{units: int, phases: int}
     */
    public function importUnits(array $rows, User $by): array
    {
        $contents = "reference,phase,street,type,status\n";

        foreach ($rows as $row) {
            $contents .= implode(',', array_map(
                static fn ($v): string => '"'.str_replace('"', '""', (string) ($v ?? '')).'"',
                [$row['reference'], $row['phase'], $row['street'] ?? '', $row['type'], $row['status']],
            ))."\n";
        }

        $parsed = $this->parseUnits($contents);

        if (! $parsed['valid']) {
            throw new DomainException(
                'The list no longer passes: '.($parsed['errors'][0]['message'] ?? 'a row failed').' Nothing was imported — preview it again.'
            );
        }

        if (($refusal = $this->overContract(count($parsed['rows']))) !== null) {
            throw new DomainException($refusal.' Nothing was imported.');
        }

        return DB::connection('tenant')->transaction(function () use ($parsed): array {
            $sequence = (int) Phase::query()->max('sequence');

            foreach ($parsed['new_phases'] as $name) {
                Phase::create([
                    'name' => $name,
                    'sequence' => ++$sequence,
                    'block_count' => 0,
                    'officers_assigned' => null,
                    'status' => Phase::NEW,
                    'footnote' => 'officers pending',
                ]);
            }

            // The phase name as the estate spells it, so "phase 2" in a file
            // files under the existing "Phase 2" rather than beside it — and
            // under the phase this import just created, spelt the way its
            // first row spelt it.
            $canonical = Phase::query()->pluck('name')->keyBy(static fn (string $n): string => strtolower($n));

            // Where MySQL works the phase out from the lot (M57.5 §4.2) the row carries none: it would be refused.
            $derived = Unit::phaseIsDerived();

            $now = now();
            $units = array_map(static fn (array $row): array => [
                'reference' => $row['reference'],
                ...($derived ? [] : ['block' => (string) ($canonical[strtolower($row['phase'])] ?? $row['phase'])]),
                'street' => $row['street'],
                'type' => $row['type'],
                'status' => $row['status'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $parsed['rows']);

            foreach (array_chunk($units, 500) as $chunk) {
                Unit::query()->insert($chunk);
            }

            $this->audit->record(
                action: 'estate.units_imported',
                entityType: 'Unit',
                entityId: null,
                after: ['units' => count($units), 'phases_created' => count($parsed['new_phases'])],
            );

            return ['units' => count($units), 'phases' => count($parsed['new_phases'])];
        });
    }
}
