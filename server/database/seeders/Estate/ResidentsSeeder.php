<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phoenix Park's phase structure, its named households and the three unit claims
 * waiting on somebody — boards 3, 4, 31 and 38.
 *
 * IT EXTENDS THE ESTATE AND NEVER REBUILDS IT. `EstateFinanceSeeder` has already
 * laid out 450 units across five phases, put 433 households in them and posted
 * six months of dues against every one — J$1,840,000 of arrears in four specific
 * ageing buckets, which is how that seeder recognises a finished estate. Nothing
 * here creates a unit, deletes one, or writes a single journal line. It renames
 * six households the boards name, adds the members the boards count, records
 * three claims and writes the five phase rows.
 *
 * WHY IT DOES NOT PLACE PEOPLE WHERE BOARD 4 PUTS THEM, EXACTLY. Board 4 gives
 * six households a phase and a lot, and three of those pairings are not
 * reproducible in this estate:
 *
 *   - Sonia Campbell is drawn at "Phase 4 · Lot 88" and Keith Walters at
 *     "Phase 2 · Lot 63". The estate's arrears fit places Lot 88 and Lot 63 in
 *     Phase 1, and the fit cannot be moved: it is what makes both the per-phase
 *     and the per-ageing totals land on the figures three boards state.
 *
 *   - Natalie Wong is drawn at "Phase 2 · Lot 12" and Dwayne Robinson at
 *     "Phase 1 · Lot 12". `units.reference` is unique, so one estate has one
 *     Lot 12 — and board 4's own note says lot numbers repeat across phases,
 *     which this schema does not model and cannot without renumbering 450 units
 *     under a ledger that has no delete.
 *
 * THE LOT WINS OVER THE PHASE, and the reason is board 19. The facilities seeder
 * has already booked the Gazebo for "Andrea Fletcher — Lot 47", the Club House
 * for "Sonia Campbell — Lot 88" and the Community Centre for "Keith Walters —
 * Lot 63", and raised ticket #1042 against Lot 47. An estate cannot have two
 * Sonia Campbells at two different addresses, so each named household is placed
 * at the LOT the boards give it and takes whichever phase this estate puts that
 * lot in. Natalie Wong, whose lot is taken, goes to the first unnamed occupied
 * unit in the phase she is drawn in. Every departure is recorded in DECISIONS.md
 * rather than resolved by moving a unit.
 *
 * AND THE BALANCES ARE THE LEDGER'S, NOT THE BOARD'S. Board 4 draws $12,400, $0,
 * $6,200, $0, $6,200 and $0 down its Balance column. Lot 47 is exactly J$12,400
 * because the arrears fit put it there; the other five are whatever their unit
 * ledgers actually say, because a residents screen showing a figure the accounts
 * disagree with is the failure this whole module is built to avoid. Nothing here
 * bills or receipts anything to make a column match.
 *
 * NOBODY IS RESTRICTED. `access_restricted` is left exactly as the estate seeder
 * left it — false on all 433 households — because nothing in this system
 * auto-restricts on arrears yet and restriction is an act with a notice period
 * behind it (D-024). `EstateResidentsTest` restricts a household of its own to
 * prove the screens withhold the figure.
 */
class ResidentsSeeder extends Seeder
{
    /**
     * Board 3's five cards.
     *
     * [name, sequence, blocks, officers, status, footnote]
     *
     * ONLY THE BLOCKS AND THE OFFICERS ARE HERE. The unit count, the occupied
     * count, the vacancies and the occupancy bar are all derived from `units` at
     * read time — a stored unit count would be free to disagree with the estate
     * it describes, and this is the screen a committee checks that arithmetic on.
     *
     * Phase 5 carries a null officer count and words instead. Board 3 draws "new
     * phase, officers pending" where the other four draw a number, and null is
     * the only value that says "nobody has been appointed yet" rather than
     * "nobody is appointed".
     *
     * @var list<array{0: string, 1: int, 2: int, 3: int|null, 4: string, 5: string|null}>
     */
    private const PHASES = [
        ['Phase 1', 1, 6, 3, Phase::ACTIVE, null],
        ['Phase 2', 2, 7, 4, Phase::ACTIVE, null],
        ['Phase 3', 3, 5, 4, Phase::ACTIVE, null],
        ['Phase 4', 4, 6, 2, Phase::ACTIVE, null],
        ['Phase 5', 5, 4, null, Phase::NEW, 'new phase, officers pending'],
    ];

    /**
     * Board 4's six rows, in the order the board draws them.
     *
     * `lot` is the lot the boards name and `phase` is the phase they name; where
     * the two disagree with this estate the LOT wins, for the reason in the class
     * docblock. `lot` may be null, which means "the boards give this household a
     * lot this estate has already given to somebody else" — Natalie Wong — and
     * the first unnamed occupied unit in her phase is used instead.
     *
     * `members` is the Members column, and it is why extra residents are created:
     * the estate seeder gives every household exactly one person, so the column
     * would otherwise read 1 six times and the board's own figures would be
     * unreachable. The additional members are NOT given invented names — no board
     * names them, board 38 rolls them up deliberately, and a made-up Jamaican
     * name on a screen a client reviews is fiction with no source behind it.
     *
     * `active` is days before today, which is what board 4's relative column
     * needs; Andrea's is today, at the time the board prints.
     *
     * @var list<array{
     *     name: string,
     *     email: string,
     *     household: string,
     *     phase: string,
     *     lot: string|null,
     *     members: int,
     *     active: int,
     *     phone: string|null,
     *     no_phone: bool,
     *     since: string|null,
     * }>
     *
     * `phone` is the number a board draws and null means "leave whatever the
     * estate seeder gave them". `no_phone` is a different thing entirely: it says
     * the register holds NO number, which board 31 asserts outright about the
     * Walters record — "Phone → Not on file" is half of what makes that card's
     * On-record column amber, and a seeded placeholder number would quietly
     * answer the question the reviewer is being asked.
     */
    private const HOUSEHOLDS = [
        [
            'name' => 'Andrea Fletcher',
            'email' => 'andrea.fletcher@example.com',
            'household' => 'Fletcher household',
            'phase' => 'Phase 2',
            'lot' => 'Lot 47',
            'members' => 3,
            'active' => 0,
            'phone' => '876 555 0112',
            'no_phone' => false,

            // Board 38's fourth hero stat, and the one date on any of these
            // screens that carries a year — so it is seeded as written rather
            // than as an offset from today.
            'since' => '2024-09-02',
        ],
        [
            'name' => 'Dwayne Robinson',
            'email' => 'd.robinson@example.com',
            'household' => 'Robinson household',
            'phase' => 'Phase 1',
            'lot' => 'Lot 12',
            'members' => 2,
            'active' => 1,
            'phone' => null,
            'no_phone' => false,
            'since' => null,
        ],
        [
            'name' => 'Sonia Campbell',
            'email' => 'sonia.c@example.com',
            'household' => 'Campbell household',
            'phase' => 'Phase 4',
            'lot' => 'Lot 88',
            'members' => 4,
            'active' => 2,
            'phone' => null,
            'no_phone' => false,
            'since' => null,
        ],
        [
            /*
             * THE REGISTER'S OWN FORM OF THE NAME, and the difference is the
             * point. Board 31 draws "K. A. Walters" in its On-record column
             * against a claimant who submitted "Keith Walters" — that variance
             * is what turns the column amber and what the reviewer is being
             * asked about. Boards 4 and 19 print the claimant's form; recorded
             * as a residual rather than flattened, because flattening it would
             * empty board 31's first card of its subject.
             */
            'name' => 'K. A. Walters',
            'email' => 'kwalters@example.com',
            'household' => 'Walters household',
            'phase' => 'Phase 2',
            'lot' => 'Lot 63',
            'members' => 1,
            'active' => 3,
            'phone' => null,

            // Board 31 draws "Phone → Not on file" against this record, and it
            // is half of what makes the card amber.
            'no_phone' => true,

            'since' => null,
        ],
        [
            'name' => 'Rachel Bennett',
            'email' => 'rbennett@example.com',
            'household' => 'Bennett household',
            'phase' => 'Phase 1',
            'lot' => 'Lot 3',
            'members' => 2,
            'active' => 5,
            'phone' => null,
            'no_phone' => false,
            'since' => null,
        ],
        [
            // Drawn at "Phase 2 · Lot 12", which Dwayne Robinson already holds
            // in Phase 1 and which one estate can only have one of.
            'name' => 'Natalie Wong',
            'email' => 'n.wong@example.com',
            'household' => 'Wong household',
            'phase' => 'Phase 2',
            'lot' => null,
            'members' => 3,
            'active' => 8,
            'phone' => null,
            'no_phone' => false,
            'since' => null,
        ],
    ];

    /** How long ago board 38's second household member was added. */
    private const MARLON_ADDED_DAYS_AGO = 6;

    /**
     * The command running this seeder, when one is — `db:seed` sets it; the test suite runs the seeder bare. The
     * framework's own `$command` is declared never-null and is simply unset then, so this is the one to ask.
     */
    private ?Command $console = null;

    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    public function run(): void
    {
        $this->seedPhases();

        $placed = $this->seedHouseholds();

        $this->seedFletcherHousehold($placed['Andrea Fletcher'] ?? null);
        $this->seedClaims($placed);
    }

    /**
     * The five phase rows.
     *
     * `updateOrCreate` on the name, because the name is the join to `units.block`
     * and is the one field that must never drift. Everything else on the row is
     * a fact about the estate's layout that a committee can correct.
     */
    private function seedPhases(): void
    {
        foreach (self::PHASES as [$name, $sequence, $blocks, $officers, $status, $footnote]) {
            Phase::updateOrCreate(
                ['name' => $name],
                [
                    'sequence' => $sequence,
                    'block_count' => $blocks,
                    'officers_assigned' => $officers,
                    'status' => $status,
                    'footnote' => $footnote,
                ],
            );
        }
    }

    /**
     * Rename six households after the people the boards name, and fill them out.
     *
     * @return array<string, Unit> resident name => the unit they were placed at
     */
    private function seedHouseholds(): array
    {
        $placed = [];
        $taken = [];

        foreach (self::HOUSEHOLDS as $row) {
            $unit = $this->unitFor($row['name'], $row['phase'], $row['lot'], $taken);

            if ($unit === null) {
                continue;
            }

            $taken[] = $unit->id;
            $household = $unit->household;

            if ($household === null) {
                continue;
            }

            $household->forceFill([
                'name' => $row['household'],

                /*
                 * Board 4's relative column, seeded as an offset from today so
                 * it still reads "2 days ago" whenever somebody opens the
                 * screen. Andrea's is 9:15 this morning, which is the one the
                 * board prints with a time on it.
                 */
                'last_active_at' => Carbon::today()->subDays($row['active'])->setTime(9, 15),
            ])->save();

            $primary = $household->residents()->orderByDesc('is_primary')->orderBy('id')->first();

            if ($primary === null) {
                continue;
            }

            /*
             * PRIMACY THE OFFICE MOVED IS LEFT WHERE IT WAS PUT (DESIGN §13). This used to rename whoever held
             * the household to the board's person — so after "Make primary" handed it to somebody else, a
             * routine `db:seed` renamed THAT person "Andrea Fletcher", with her email. The row this seeder
             * owns is the board's person, found by name or email, or the estate seeder's own first row on the
             * household (the one it names on the first run). Anybody else holding it was put there by a
             * person, and the household is left alone.
             */
            // Removed rows count: if the board's person was taken off the household, the NEXT row is somebody the
            // office or the household put there, and must never be renamed to the board's person.
            $firstRow = (int) Resident::withRemoved()->where('household_id', $household->id)->orderBy('id')->value('id');
            $boardsOwn = strcasecmp((string) $primary->email, $row['email']) === 0 || $primary->full_name === $row['name'];

            if ($primary->is_primary && ! $boardsOwn && $primary->id !== $firstRow) {
                $this->console?->warn(sprintf('%s: primacy was moved on this estate; not re-seeding it.', $row['household']));

                continue;
            }

            $primary->forceFill([
                'full_name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['no_phone'] ? null : ($row['phone'] ?? $primary->phone),
                'is_primary' => true,
                'status' => Resident::VERIFIED,
                'verified_at' => $primary->verified_at ?? Carbon::today()->subMonths(2),
                'moved_in_on' => $row['since'] ?? $primary->moved_in_on ?? Carbon::today()->subYear()->toDateString(),
            ])->save();

            $this->fillMembers($household, $row['members']);

            $placed[$row['name']] = $unit;
        }

        return $placed;
    }

    /**
     * Bring a household up to the number of people board 4 counts.
     *
     * THE EXTRA MEMBERS ARE UNNAMED ON PURPOSE. No board names them — board 38
     * rolls the Fletchers' third member into "1 additional member" rather than
     * printing who they are — so inventing a name would put a person on a screen
     * a client reviews with nothing behind them. They are recorded as members of
     * the household they belong to, which is the only fact the boards assert.
     *
     * Idempotent by COUNT rather than by name: a second run finds the household
     * already at its size and adds nobody.
     */
    private function fillMembers(Household $household, int $members): void
    {
        $existing = (int) $household->residents()->count();

        for ($i = $existing; $i < $members; $i++) {
            Resident::create([
                'household_id' => $household->id,
                'full_name' => $household->name.' member',
                'relationship' => 'household member',
                'is_primary' => false,
                'status' => Resident::VERIFIED,
                'verified_at' => now(),
                'moved_in_on' => Carbon::today()->subMonths(2)->toDateString(),
            ]);
        }
    }

    /**
     * Board 38's subject, in the detail that board draws.
     *
     * Marlon is the second member and he is NOT verified: board 38 gives him
     * "Added Sep 4" where his sister reads "Verified", which is the union the
     * panel draws — a verified member says so and one who is not says when they
     * arrived. He is also board 31's second claim, asking to be recognised as
     * the member he already appears as.
     */
    private function seedFletcherHousehold(?Unit $unit): void
    {
        $household = $unit?->household;

        if ($household === null) {
            return;
        }

        $marlon = $household->residents()->where('full_name', 'Marlon Fletcher')->first();

        if ($marlon !== null) {
            return;
        }

        /*
         * He replaces one of the unnamed members rather than being added beside
         * them, because board 38 counts three people in this household and
         * naming a fourth would put the hero stat one over the board.
         *
         * The FIRST of them, so that the panel — which orders the primary and
         * then by id — names Andrea, then Marlon, and rolls the remaining one
         * up. Taking the last would name a member the board does not name and
         * roll up the brother it does.
         */
        $placeholder = $household->residents()
            ->where('is_primary', false)
            ->orderBy('id')
            ->first();

        $added = Carbon::today()->subDays(self::MARLON_ADDED_DAYS_AGO)->setTime(10, 0);

        if ($placeholder === null) {
            return;
        }

        $placeholder->forceFill([
            'full_name' => 'Marlon Fletcher',
            'relationship' => 'brother',
            'status' => Resident::PENDING,
            'verified_at' => null,
            'moved_in_on' => $added->toDateString(),
            'created_at' => $added,
        ])->save();
    }

    /**
     * Board 31's three claims, each a different shape of the same question.
     *
     * THE SUBMITTED PHASE AND LOT ARE THE UNIT'S OWN. Board 31 draws the claimant
     * writing "Phase 2 · Lot 63" and the register agreeing, and the amber on that
     * card comes from the NAME and the missing phone rather than from the
     * address. Seeding the board's literal phase against a unit this estate puts
     * in Phase 1 would invent a second mismatch the board does not draw and make
     * the card read as a different claim.
     *
     * @param  array<string, Unit>  $placed
     */
    private function seedClaims(array $placed): void
    {
        $walters = $placed['K. A. Walters'] ?? null;
        $fletcher = $placed['Andrea Fletcher'] ?? null;
        $simms = Unit::query()->where('reference', 'Lot 21')->first();

        if ($walters !== null) {
            /*
             * A claim on the unit itself. The claimant writes his name in full
             * and the register holds initials; he gives a phone the estate does
             * not hold at all. Two differences, one amber column, and neither is
             * enough to refuse him — which is why the outline action on this card
             * asks for a document rather than rejecting.
             */
            $this->claim([
                'unit_id' => $walters->id,
                'claim_type' => UnitClaim::TYPE_UNIT,
                'submitted_name' => 'Keith Walters',
                'submitted_phase' => $walters->block,
                'submitted_lot' => $walters->reference,
                'submitted_phone' => '876 555 0171',
                'match_result' => 'partial',
            ]);
        }

        if ($fletcher !== null) {
            /*
             * A member claim, and the only one of the three the estate matched
             * exactly: Marlon Fletcher is already on the register as a member of
             * this household, added six days ago. Nothing about it is amber, and
             * its outline action is a refusal — because the only question left is
             * whether the estate agrees, and there is nothing further to verify.
             */
            $this->claim([
                'unit_id' => $fletcher->id,
                'household_id' => $fletcher->household?->id,
                'claim_type' => UnitClaim::TYPE_MEMBER,
                'submitted_name' => 'Marlon Fletcher',
                'submitted_phase' => $fletcher->block,
                'submitted_lot' => $fletcher->reference,
                'submitted_relationship' => 'Household member',
                'match_result' => 'exact',
            ]);
        }

        if ($simms !== null) {
            /*
             * A claimant the estate cannot place. The name matches the register
             * at this lot and nothing else does, which is precisely the case an
             * identity check exists for — and Lot 21 is one of the five units the
             * arrears fit puts in the 90-plus bucket, so it is a lot somebody has
             * a reason to want authorisation against.
             *
             * The ageing is NOT written here and is not a column on this row. It
             * is read from the ledger at request time and shown only where it may
             * be — never for a restricted household, and never to a viewer
             * without ledger access (Q-008).
             */
            $this->claim([
                'unit_id' => $simms->id,
                'claim_type' => UnitClaim::TYPE_UNVERIFIED,
                'submitted_name' => 'Tanya Simms',
                'submitted_phase' => $simms->block,
                'submitted_lot' => $simms->reference,
                'match_result' => 'none',
                'review_flag' => 'Verify identity before approving',
            ]);
        }
    }

    /**
     * One claim, written once.
     *
     * Keyed on the unit and the submitted name, which is what identifies a claim
     * before it has an id — and re-running must not queue the same person twice
     * against the same lot, because a reviewer would then approve one and leave
     * its twin standing.
     *
     * A claim that has already been DECIDED is left alone: `firstOrCreate` finds
     * it whatever its status, so a seeded estate somebody has reviewed does not
     * silently get its decisions reopened.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function claim(array $attributes): void
    {
        UnitClaim::firstOrCreate(
            [
                'unit_id' => $attributes['unit_id'],
                'submitted_name' => $attributes['submitted_name'],
            ],
            [...$attributes, 'status' => UnitClaim::PENDING],
        );
    }

    /**
     * The unit a named household goes on.
     *
     * THE NAME IS ASKED FOR FIRST, and that is what makes this seeder safe to run
     * twice. A household placed by an earlier run already has its person on it;
     * looking for a free unit again would find the NEXT one and rename a second
     * household after the same resident — an estate with two Natalie Wongs at two
     * addresses, added silently by a routine `db:seed`. It also picks up Andrea
     * Fletcher, whom the arrears fit named before this seeder existed.
     *
     * Otherwise: the lot the boards give it where this estate has that lot, and
     * failing that the first occupied unit in the drawn phase that the estate
     * seeder has not already named. That last case is Natalie Wong, drawn at a
     * Lot 12 that Dwayne Robinson holds, getting an address of her own without
     * anybody being moved.
     *
     * @param  list<int>  $taken
     */
    private function unitFor(string $residentName, string $phase, ?string $lot, array $taken): ?Unit
    {
        $already = Resident::query()->where('full_name', $residentName)->first();
        $unit = $already?->household?->unit;

        if ($unit !== null) {
            return $unit;
        }

        if ($lot !== null) {
            $unit = Unit::query()->where('reference', $lot)->first();

            if ($unit !== null && ! in_array($unit->id, $taken, true)) {
                return $unit;
            }
        }

        /*
         * "Not already named" means the estate seeder's own placeholder — it
         * calls every unnamed householder "Lot N householder" — so this can
         * never land a board's household on top of Andrea Fletcher or Tanya
         * Simms, whose names the arrears fit already placed.
         *
         * Asked of `households` rather than of `units`, because `Unit::household`
         * is a one-of-many relation and `whereHas` over one of those builds a
         * windowed subquery to answer a question that has a plain join.
         */
        $household = Household::query()
            ->whereNotIn('unit_id', $taken)
            ->whereHas(
                'unit',
                fn (Builder $u) => $u->where('block', $phase)->where('status', 'occupied'),
            )
            ->whereHas(
                'residents',
                fn (Builder $r) => $r->where('is_primary', true)->where('full_name', 'like', '%householder'),
            )
            ->orderBy('unit_id')
            ->first();

        return $household?->unit;
    }
}
