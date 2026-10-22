<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Services\Estate\AdoptionReport;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Support\Seeded\ReservedContacts;
use Brick\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phoenix Park's estate: 450 units, 441 households, and six months of dues.
 *
 * Runs INSIDE tenancy. Every row is written to one estate's own database.
 *
 * WHY IT IS THIS BIG. Board 2 states 450 units and 441 occupied; board 5 states
 * J$1,840,000 of arrears split four ways by age; board 2 splits the same total
 * five ways by phase; boards 5 and 6 name five specific units and what each
 * owes. Seeding four households would leave every one of those figures
 * unreachable, and the screens that exist to show them unreviewable.
 *
 * EVERY BALANCE COMES FROM POSTED JOURNAL ENTRIES. Nothing here writes a
 * balance. Dues are billed as a monthly run — one entry, one credit to income,
 * a debit line per unit — and receipts are banked in batches the same way, so
 * the arrears on every screen are the ledger read back rather than a number
 * kept beside it.
 *
 * THE FIT IS SOLVED, NOT HAND-WRITTEN. `ArrearsPlan` works out which units make
 * up the difference and proves both margins land exactly before a row is
 * written. See its docblock for the constraints.
 */
class EstateFinanceSeeder extends Seeder
{
    /** Board 2's phase table: name, units, occupied. */
    private const PHASES = [
        ['Phase 1', 92, 90],
        ['Phase 2', 104, 98],
        ['Phase 3', 88, 86],
        ['Phase 4', 96, 89],
        ['Phase 5', 70, 78],
    ];

    /**
     * A DIFFERENT ESTATE'S PHASE TABLE, when the caller has one.
     *
     * The board drew a 450-unit estate and that is what the Estate Console's own demonstration data
     * and the test fixture are built from; changing it here would change both. Phoenix Park Village 1
     * is really 2,000 lots (the client, 23 September 2026), so `PhoenixDemoSeed` sets its own table
     * before calling this and puts it back afterwards. Same shape: name, units, occupied.
     *
     * @var list<array{0: string, 1: int, 2: int}>|null
     */
    public static ?array $phases = null;

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function phases(): array
    {
        return static::$phases ?? self::PHASES;
    }

    /** Every lot the table describes. The fill runs 1 … this. */
    private function totalUnits(): int
    {
        return array_sum(array_column($this->phases(), 1));
    }

    /**
     * Each owing unit's WHOLE balance in its badge's bucket (its oldest open charge): what `ArrearsPlan` places, and
     * board 5's four figures. The seed's fingerprint since EST-S-02 (M85 A3) split the ageing totals charge by charge,
     * which no longer equal the plan and would have rebuilt the estate on every run.
     *
     * @return array<string, int>
     */
    private function byBadge(): array
    {
        $dues = app(Dues::class);
        $balances = $dues->unitBalances();
        $totals = array_fill_keys(array_keys(Dues::BUCKETS), 0);

        foreach ($dues->unitBuckets() as $unitId => $bucket) {
            $totals[$bucket] += $balances[$unitId];
        }

        return $totals;
    }

    /**
     * How many months of dues the estate has billed on this platform.
     *
     * Six, ending with the current month. Board 6's statement for Lot 47 runs
     * back to a payment on June 3, and the 90+ bucket needs a charge at least
     * ninety days overdue to sit in it — four months would leave that bucket
     * unreachable and six gives it room without inventing history nobody drew.
     */
    private const MONTHS = 6;

    public function run(): void
    {
        /*
         * Which estate this is, read from the connection rather than a helper.
         * The seeder runs inside `$tenant->run()`, where the `tenant` connection
         * is already pointed at exactly one estate's database — that is the
         * fact, and it cannot be out of step with the context the way a
         * container binding can.
         */
        $database = DB::connection('tenant')->getDatabaseName();

        /*
         * WHICH estate gets a dues history is the caller's decision, not this
         * seeder's. Ocean View is mid-onboarding on every board that draws it,
         * and giving it six months of billing would make the onboarding state —
         * which four Gemini screens depend on — unreachable. DemoDataSeeder
         * knows that; this class only knows how to build an estate, which is
         * also what lets the traceability suite point it at a database of its
         * own.
         */
        $this->call(ChartOfAccountsSeeder::class);

        /*
         * Leave a correctly seeded estate alone, and rebuild anything else.
         *
         * The test is the ARREARS, not the row counts. An estate whose
         * receivable already equals the figure every board states is finished,
         * and re-running would raise a second six months of dues on top of the
         * first. An estate that is half-seeded, or seeded by an older and
         * wronger version of this file, looks complete by row count and is not
         * — and there is no application path that could put it right, because
         * the ledger cannot be unposted.
         */
        $arrears = app(Ledger::class)
            ->balanceOf(Account::where('code', '1200')->firstOrFail())
            ->getMinorAmount()
            ->toInt();

        /*
         * The ageing, not just the total. An estate can hold the right total in
         * the wrong buckets — that is exactly what an earlier version of the
         * arrears fit produced — and a guard that only checked the sum would
         * declare it finished and leave three of board 5's four figures wrong.
         */
        // `>=` rather than `===`: Phoenix Park carries one lot this table cannot name, the 2,000th
        // (QUESTIONS PPV-09), added beside the fill. A half-seeded estate still has fewer and is
        // still rebuilt; an estate with that one extra lot is finished and must be left alone.
        $built = Unit::query()->count() >= $this->totalUnits()
            && $arrears === array_sum(ArrearsPlan::AGEING)
            && $this->byBadge() === ArrearsPlan::AGEING;

        if (! $built) {
            /*
             * NOTHING TO CLEAR IS NOT THE SAME AS REFUSING TO CLEAR.
             *
             * `resetLedger` truncates as the schema owner, and it refuses outside local and testing
             * because on any other environment those tables are a community's real accounts. That
             * guard is right and stays. But an estate that has never been seeded has no units and no
             * journal to protect — there is nothing for the truncate to do — so the door is simply
             * not opened, and a brand-new estate can be filled on the server it will actually live
             * on. An estate with so much as one unit or one journal line still goes through the
             * guard and is still refused in production, which is the property that matters.
             */
            $untouched = Unit::query()->count() === 0 && Journal::query()->count() === 0;

            if (! $untouched) {
                $this->resetLedger($database);
            }

            $lotsByPhase = $this->buildEstate();
            $plan = (new ArrearsPlan)->solve($lotsByPhase);

            $this->billAndCollect($plan);
        }

        // Outside the guard, and idempotent on its own terms: an estate seeded
        // before this existed still needs its corrected entry.
        $this->correctOneChargeInError();

        /*
         * The phase structure and the register — boards 3, 4, 31 and 38.
         *
         * FIRST OF THE FIVE, because it is the only one that describes the
         * estate rather than something that happened to it. It writes no journal
         * line and creates no unit: it names six households the boards name,
         * adds the members they count, records three unit claims and writes the
         * five phase rows the structure screen derives its counts against.
         *
         * Outside the guard and idempotent on its own terms — every household is
         * found by the resident already on it, so a second run places nobody a
         * second time.
         */
        $this->call(ResidentsSeeder::class);

        /*
         * Collections last, and outside the guard for the same reason.
         *
         * It reads the ledger this seeder just raised — a payment plan
         * schedules the balance the accounts say Lot 47 owes, and board 8's
         * Balance column is that same sum read back — so it cannot run before
         * the billing, and an estate seeded before boards 7 and 8 existed still
         * needs its templates and its log.
         */
        $this->call(CollectionsSeeder::class);

        /*
         * The maintenance queue and the booking diary BEFORE the payables, and
         * the order is a foreign key rather than a preference. Board 27 records
         * four of its bills against tickets #1042, #1041, #1037 and #1031 by
         * number, and `bills.ticket_id` references `maintenance_tickets.number`
         * — so a bill cannot be raised until the ticket it names exists. The
         * supplier register belongs to the payables seeder and is called out of
         * it directly, which is why running this first does not leave the
         * vendors behind.
         */
        $this->call(FacilitiesSeeder::class);

        /*
         * And the other half of the estate's books: what it owes its suppliers.
         *
         * Outside the guard for the same reason as the two above, and idempotent
         * on its own terms — it looks for each bill before recording it, because
         * approving one a second time would double a liability the ledger has no
         * way to unpost.
         */
        $this->call(PayablesSeeder::class);

        /*
         * The estate's own staff payroll, last of the money seeders.
         *
         * After the chart, because it posts against 5000, 2100 and 1000; after
         * nothing else, because it shares no table with any of them. It touches
         * account 1200 not at all, so the arrears every board states stay
         * exactly where this seeder's own guard expects to find them.
         */
        $this->call(PayrollSeeder::class);

        /*
         * The notice board, last, because its seen-bars are counted against the
         * resident roll and that roll is only complete once the households are.
         */
        $this->call(NoticesSeeder::class);

        /*
         * And then the estate says how much of itself it is using.
         *
         * LAST, BECAUSE IT COUNTS EVERYTHING ABOVE IT. Board 07 draws a bar per
         * capability per client, and the estate-owned half of those figures can
         * only be counted once the modules they measure have something in them.
         *
         * The write goes OUTWARD to `gs_platform` — the one place estate data
         * crosses the boundary on purpose, and it crosses as two integers and a
         * sentence. See `App\Services\Estate\AdoptionReport` for why the arrow
         * points this way rather than the Gemini console reading each estate.
         *
         * ONLY WHERE THERE IS AN ESTATE TO REPORT AS. This seeder also builds
         * the traceability suites' own databases, which point the `tenant`
         * connection at a schema directly without initialising tenancy — the
         * same reason the database name at the top of this method is read off
         * the connection rather than from `tenant()`. A roll-up row is a fact
         * about a CLIENT, and a fixture is not one.
         */
        $estate = tenant();

        if ($estate !== null) {
            app(AdoptionReport::class)->push((string) $estate->getTenantKey());
        }

        /*
         * The election and the meeting register LAST, and the order is a real
         * dependency rather than a preference.
         *
         * Vetting a candidate snapshots the household's arrears ageing onto the
         * nomination — board 10's rejection reads "arrears >90 days" and the
         * accounting note asks for the snapshot outright, so the check can be
         * reproduced after the candidate has paid. That snapshot is read from
         * the ledger this seeder has just raised, so a governance seed running
         * before the billing would record every candidate as owing nothing and
         * make the one figure the rejection turns on unverifiable.
         *
         * Idempotent on its own terms and in two different ways: the ballots,
         * positions, nominations, options and meetings are keyed on a business
         * key and updated in place, while the votes are append-only at the
         * database and are written once — a second run finds the poll already
         * counted and leaves it alone rather than adding a second turnout to the
         * first.
         */
        $this->call(GovernanceSeeder::class);
    }

    /**
     * Clear the demonstration estate so it can be rebuilt from nothing.
     *
     * NEEDED BECAUSE THE LEDGER CANNOT BE UNDONE, which is the point of it. A
     * seed that fails half way through — as this one did, after writing 450
     * units and before billing them — leaves an estate that is neither empty
     * nor correct, and no application path can put it right: journals are
     * append-only, and posting six more months on top would double the arrears.
     *
     * TRUNCATE, not DELETE, and by the schema owner. It is DDL, so it bypasses
     * the append-only triggers, and the estate's own MySQL user could not issue
     * it. That is a deliberately narrow door and it is why this refuses to open
     * outside local development: on any other environment these rows are a
     * community's actual accounts.
     */
    private function resetLedger(string $database): void
    {
        /*
         * Local and testing only. The traceability suite builds its own estate
         * with this seeder — that is the point of it, since what is being proven
         * is that this arithmetic and the ledger's agree — so `testing` belongs
         * here beside `local`. Anywhere else these tables hold a community's
         * real accounts and this must never open.
         */
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'EstateFinanceSeeder rebuilds an estate from empty and runs only in local and testing. '.
                'On any other environment these tables hold a community\'s real accounts.'
            );
        }

        $owner = DB::connection('mysql_owner');

        $owner->statement('SET FOREIGN_KEY_CHECKS = 0');

        /*
         * Collections goes first, and it has to. Payment plans and dunning
         * notices are keyed on `units`, so an estate rebuilt with new unit ids
         * would leave a plan pointing at whatever lot happened to inherit its
         * number — a live gate shield over the wrong household. Templates are
         * cleared with them because a template with notices behind it is only
         * half of a record.
         */
        /*
         * Payables goes with them, and for a harder reason. A bill's link to its
         * entry is `journal_ref`, a string — no foreign key can hold it, because
         * the ledger writes its lines before its header. Truncating `journals`
         * and leaving `bills` standing would leave every bill pointing at a
         * reference that no longer exists, and the seeder's own idempotency check
         * would then find the bills present and decline to raise them again: an
         * estate whose payables screen shows J$121,690 owed and whose accounts
         * show nothing at all.
         */
        /*
         * The maintenance queue and the booking diary go too, and for the same
         * reason as the payment plans: both are keyed on `units`. A ticket left
         * standing over a rebuilt estate would report a leaking pipe at whatever
         * lot inherited its id, and a booking would hold a deposit against a
         * household that never made it. Tickets are also what the bills below
         * point at by number, so clearing one without the other would leave the
         * foreign key with nothing on the far side of it.
         */
        /*
         * The governance module goes FIRST in this list and it has to, twice
         * over. Its receipts, its ballot options, its nominations and its
         * attendance register are all keyed on `units`, so an estate rebuilt
         * with new unit ids would leave a vote recorded against whatever lot
         * inherited its number — and a turnout figure attached to the wrong
         * households is worse than no turnout at all.
         *
         * `ballot_marks` and `ballot_receipts` are also the two tables the
         * estate's own MySQL user cannot touch (D-017, and invariant 3 rather
         * than invariant 4). TRUNCATE by the schema owner is the only clearance
         * that reaches them, and it is the only shape of clearance that may:
         * deleting marks one at a time identifies the survivors by elimination,
         * so the crowd goes whole or not at all.
         *
         * Marks before options before positions before ballots, and receipts
         * before ballots, so every child is gone before its parent — the same
         * ordering the rest of this list keeps.
         */
        /*
         * The register's own tables go at the top, ahead of `residents`,
         * `households` and `units` which every one of them points at. A claim is
         * keyed on a unit and a household and an invite on a resident and a
         * unit, so a claim left standing over a rebuilt estate would be a
         * stranger asking for authorisation against whichever lot inherited its
         * id, and an invite would be a live token pointing at somebody else's
         * house.
         *
         * `estate_phases` goes with them although no foreign key constrains it,
         * and that is exactly why it has to be listed. It joins to `units.block`
         * by NAME, so an orphan is invisible to the database and surfaces only as
         * a structure screen whose cards sum to less than the estate.
         */
        /*
         * Payroll goes with them, and for the bills' own reason. Its runs post
         * to the ledger, so a run left standing over a truncated `journals`
         * would point at a reference that no longer exists — and its own
         * idempotency guard would then find the runs present and decline to
         * post them again, leaving an estate whose payroll screen shows three
         * paid months and whose accounts show none.
         */
        /*
         * AND THE KEPT DOCUMENTS, FIRST, because every one of them names a record
         * this list is about to take (M10, baseline). A document is filed against its
         * subject by id — a notice attachment by the notice, a statement by the unit,
         * a receipt by the payment, a bank file by the pay run — as a string, so no
         * foreign key holds it and nothing refused the truncation below. TRUNCATE
         * also resets AUTO_INCREMENT, so the rebuilt estate hands those very ids out
         * again: the first notice posted after a reset inherited a stranger's
         * attachment, and a new unit would have inherited somebody else's statement.
         * `ResidentCommunityHistoryTest` found it as a brand-new notice that already
         * carried a file. TRUNCATE steps past the retention trigger exactly as it
         * steps past the ledger's, under the same local-and-testing guard; the files
         * those rows named are left on disk, where nothing can reach them.
         */
        foreach (self::RESET_TABLES as $table) {
            $owner->statement("TRUNCATE TABLE `{$database}`.`{$table}`");
        }

        $owner->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * What `resetLedger` clears, children before parents. The reasons are `resetLedger`'s.
     *
     * EVERY TABLE KEYED ON ONE OF THESE IS ONE OF THESE. TRUNCATE runs with the foreign keys switched off and
     * resets AUTO_INCREMENT, so a child left standing is not refused: it keeps its parent's old id, and the rebuilt
     * estate hands that id to a different lot. The collection flags were the one the suite found (M13): a hardship
     * flag outlived its lot and sat on whichever lot inherited the number, keeping the dunning run away from a
     * household nobody had flagged, and holding a foreign key that refused the new lot's deletion. The flags, the
     * gate approvals, the visitor passes, the RSVPs, the household's contacts, vehicles and delinquency flags, the
     * ticket photos and the dunning drafts had all been added after this list was written — and so, on 8 October
     * 2026, were a household's pets, its saved cards and its AutoPay.
     * `EstateFinanceSeederResetTest` reads the estate's foreign keys and fails when a new table is missed.
     *
     * @var list<string>
     */
    public const RESET_TABLES = [
        // The live-update log FIRST, and it has to be here. It records events by an entity name and the entity's
        // id as a string, with no foreign key. Every entity table below is TRUNCATEd, and TRUNCATE resets
        // AUTO_INCREMENT — so the rebuilt estate hands out ids 1, 2, 3 again, and a stale event still keyed to the
        // OLD id 1 would be read as belonging to the NEW entity 1. `GateLiveTest` found it (M15): after a finance
        // seed reset the estate, a fresh walk-up got approval id 1 and read TWO `approval.requested` events — its
        // own and a truncated approval's leftover. A rebuilt-from-empty estate has no events; clear them with it.
        'live_events',

        'documents',

        // Receipts before notices: a read is a fact about a resident who is
        // about to be truncated too, and the bar it feeds means nothing
        // without the roll behind it.
        'notice_reads',
        'notices',
        'statutory_filings',
        'payroll_run_exceptions',
        'payroll_run_lines',
        'payroll_runs',
        'employees',
        'resident_invites',
        'unit_claims',
        'estate_phases',
        'meeting_minutes',
        'meeting_attendance',
        'meeting_rsvps',
        'meeting_agenda_items',
        'meetings',
        'nominations',
        'ballot_marks',
        'ballot_receipts',
        'ballot_options',
        'ballot_positions',
        'ballots',
        'amenity_bookings',
        'amenity_slots',
        'amenities',
        'ticket_media',
        'maintenance_ticket_activity',
        'bank_statement_lines',
        'bank_reconciliations',
        'bill_payments',
        'bills',
        'maintenance_tickets',
        'vendors',
        'dunning_notices',
        'dunning_template_drafts',
        'dunning_templates',
        'unit_collection_flags',
        'payment_plan_instalments',
        'payment_plans',
        // A card payment's intent points at its unit and its receipt (M16 §2.2); its events point at nothing.
        'payment_gateway_events',
        'payment_intents',
        'journal_lines',
        'journals',
        'payments',
        'charges',

        // A household's AutoPay before the saved card it charges (both keyed on the lot, M50 §6.6).
        'autopay_arrangements',
        'household_cards',

        // The gate's and the household's own rows, each keyed on the lot or the household.
        'gate_approval_requests',
        'visitor_passes',
        'household_emergency_contacts',
        'household_vehicles',
        'household_pets',
        'delinquency_flags',
        'residents',
        'households',
        'units',
    ];

    /**
     * The physical estate: phases, units, households and their residents.
     *
     * @return array<string, list<int>> phase name => lot numbers
     */
    private function buildEstate(): array
    {
        $named = ArrearsPlan::NAMED;
        $lotsByPhase = [];
        $remaining = [];

        foreach ($this->phases() as [$phase, $units]) {
            $lotsByPhase[$phase] = [];
            $remaining[$phase] = $units;
        }

        // The five the boards name sit where the boards put them, whatever the
        // sequential fill would otherwise have done with those lot numbers.
        foreach ($named as $lot => $row) {
            $lotsByPhase[$row['phase']][] = $lot;
            $remaining[$row['phase']]--;
        }

        $phases = array_keys($remaining);
        $cursor = 0;

        for ($lot = 1, $last = $this->totalUnits(); $lot <= $last; $lot++) {
            if (isset($named[$lot])) {
                continue;
            }

            while ($remaining[$phases[$cursor]] === 0) {
                $cursor++;
            }

            $lotsByPhase[$phases[$cursor]][] = $lot;
            $remaining[$phases[$cursor]]--;
        }

        foreach ($lotsByPhase as $phase => $lots) {
            sort($lots);
            $lotsByPhase[$phase] = $lots;
        }

        $this->writeUnits($lotsByPhase);

        return $lotsByPhase;
    }

    /**
     * @param  array<string, list<int>>  $lotsByPhase
     */
    private function writeUnits(array $lotsByPhase): void
    {
        $occupancy = [];

        foreach ($this->phases() as [$phase, $units, $occupied]) {
            // Board 5 lists Phase 5 with more occupied units than its unit
            // count, which cannot be true of one estate. Clamped, and the
            // discrepancy recorded rather than silently propagated.
            $occupancy[$phase] = min($occupied, $units);
        }

        $unitRows = [];

        foreach ($lotsByPhase as $phase => $lots) {
            $vacantFrom = $occupancy[$phase];

            foreach ($lots as $i => $lot) {
                $unitRows[] = [
                    'reference' => 'Lot '.$lot,
                    'block' => $phase,
                    'street' => $phase.' Drive',
                    'type' => 'residential',
                    'status' => $i < $vacantFrom ? 'occupied' : 'vacant',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($unitRows, 200) as $chunk) {
            DB::connection('tenant')->table('units')->insert($chunk);
        }

        $units = Unit::query()->get()->keyBy('reference');
        $householdRows = [];

        foreach ($units as $reference => $unit) {
            if ($unit->status !== 'occupied') {
                continue;
            }

            $lot = (int) substr((string) $reference, 4);
            $named = ArrearsPlan::NAMED[$lot] ?? null;

            $householdRows[] = [
                'unit_id' => $unit->id,
                'name' => $named['household'] ?? 'Lot '.$lot.' household',
                'access_restricted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($householdRows, 200) as $chunk) {
            DB::connection('tenant')->table('households')->insert($chunk);
        }

        /*
         * Residents are named only where a board names them. Four hundred and
         * forty invented Jamaican names would be four hundred and forty pieces
         * of fiction on a screen a client reviews, and the five that matter are
         * the five the boards draw.
         */
        $residentRows = [];

        foreach (Household::query()->with('unit')->get() as $household) {
            $lot = (int) substr((string) $household->unit->reference, 4);
            $named = ArrearsPlan::NAMED[$lot] ?? null;

            $residentRows[] = [
                'household_id' => $household->id,
                'full_name' => $named['resident'] ?? 'Lot '.$lot.' householder',
                'email' => 'lot'.$lot.'@phoenixpark.test',
                // The fictional block, never a number a stranger can be rung on (M35 §5): lot 47 is 876-555-0147.
                'phone' => ReservedContacts::seededPhone($lot),
                'relationship' => 'owner',
                'is_primary' => true,

                /*
                 * VERIFIED, and said rather than defaulted. `residents.status`
                 * defaults to `pending`, which is the right answer for a row
                 * nobody vouched for — but every person here is on the estate's
                 * own roll, put there by the estate, which IS an establishment
                 * of identity. Leaving them to the default would put 433
                 * households on board 4 under a "Pending review" badge and bury
                 * the three claims that actually need one.
                 */
                'status' => 'verified',

                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($residentRows, 200) as $chunk) {
            DB::connection('tenant')->table('residents')->insert($chunk);
        }
    }

    /**
     * Six monthly dues runs, and the receipts that settle all but the arrears.
     *
     * A unit's payments are worked backwards from what it should still owe: it
     * has paid everything except its target balance, and FIFO puts what remains
     * on the oldest months. That is the only construction under which the
     * seeded ageing and the seeded balances agree with each other.
     *
     * @param  array<int, array{balance: int, bucket: string}>  $plan
     */
    private function billAndCollect(array $plan): void
    {
        $dues = app(Dues::class);
        $units = Unit::query()->get()->keyBy('reference');
        $firstOfThisMonth = Carbon::today()->startOfMonth();

        $months = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $months[] = $firstOfThisMonth->copy()->subMonths($back);
        }

        /*
         * A unit owing more than the whole months its bucket accounts for has a
         * special levy on top, DATED IN THE SAME MONTH AS ITS OLDEST OPEN
         * CHARGE. That placement is what keeps the unit in its bucket: the levy
         * sits behind the same oldest charge, so it enlarges the balance without
         * ageing it.
         *
         * This is what boards 5 and 35 show — Lot 21 owes J$44,900, which is not
         * a multiple of the monthly fee and never could be, and board 35 posts a
         * "Perimeter wall repair — Phase 2 special levy" for exactly this
         * reason.
         *
         * @var array<int, array{amount: int, month: int}>
         */
        $levies = [];

        foreach ($plan as $lot => $target) {
            $monthsOpen = ArrearsPlan::MONTHS_OPEN[$target['bucket']];
            $excess = $target['balance'] - ($monthsOpen * ArrearsPlan::MONTHLY_DUES);

            if ($excess > 0) {
                $levies[$lot] = ['amount' => $excess, 'month' => self::MONTHS - $monthsOpen];
            }
        }

        foreach ($months as $index => $month) {
            $charges = [];

            foreach ($units as $unit) {
                $charges[] = [
                    'unit' => $unit,
                    'amount' => Money::ofMinor(ArrearsPlan::MONTHLY_DUES, 'JMD'),
                    'description' => 'Maintenance fee — '.$month->format('F'),
                ];
            }

            $dues->chargeRun(
                rows: $charges,
                dueOn: $month,
                memo: 'Maintenance fee — '.$month->format('F Y'),
            );

            $thisMonth = [];

            foreach ($levies as $lot => $levy) {
                if ($levy['month'] === $index) {
                    $thisMonth[] = [
                        'unit' => $units['Lot '.$lot],
                        'amount' => Money::ofMinor($levy['amount'], 'JMD'),
                        'description' => 'Perimeter wall repair — special levy',
                        'type' => 'special_assessment',
                    ];
                }
            }

            if ($thisMonth !== []) {
                $dues->chargeRun(
                    rows: $thisMonth,
                    dueOn: $month,
                    memo: 'Perimeter wall repair — special levy',
                );
            }
        }

        $this->collect($dues, $units, $plan, $months, $levies);

    }

    /**
     * One levy raised against the wrong unit, and the entry that corrects it.
     *
     * DELIBERATELY IMPERFECT, for the same reason the dispatch seed leaves a
     * gate uncovered. A correction is the one thing an append-only ledger has to
     * be able to express, and an estate where nothing was ever posted in error
     * leaves the reversing path — and the pair of statement rows a unit ledger
     * must show for it — permanently unreachable, which is to say untested.
     *
     * It disturbs no total. A reversed pair nets to nothing on every account it
     * touched, so the arrears remain exactly what the boards state.
     */
    private function correctOneChargeInError(): void
    {
        $memo = 'Perimeter wall repair — Phase 2 special levy';

        if (Journal::where('memo', $memo)->exists()) {
            return;
        }

        $wrongUnit = Unit::where('reference', 'Lot 112')->first() ?? Unit::query()->firstOrFail();

        $mistake = app(Dues::class)->charge(
            unit: $wrongUnit,
            amount: Money::ofMinor(15_000_00, 'JMD'),
            description: $memo,
            dueOn: Carbon::today()->startOfMonth()->addDays(2),
            type: 'special_assessment',
        );

        $entry = Journal::query()
            ->where('source', Ledger::SOURCE_CHARGE)
            ->where('source_id', $mistake->id)
            ->firstOrFail();

        app(Ledger::class)->reverse($entry, 'Reversal — levy belonged to a Phase 2 unit');

        $mistake->forceFill(['status' => 'reversed'])->save();
    }

    /**
     * What each unit has paid: everything charged, less what it should owe.
     *
     * Banked in one batch per month so the estate's cash book reads the way a
     * bank statement does — a deposit a month, each carrying the receipts that
     * make it up.
     *
     * @param  Collection<string, Unit>  $units
     * @param  array<int, array{balance: int, bucket: string}>  $plan
     * @param  list<Carbon>  $months
     * @param  array<int, array{amount: int, month: int}>  $levies
     */
    private function collect(Dues $dues, $units, array $plan, array $months, array $levies): void
    {
        $owed = [];

        foreach ($units as $reference => $unit) {
            $lot = (int) substr((string) $reference, 4);
            $charged = self::MONTHS * ArrearsPlan::MONTHLY_DUES + ($levies[$lot]['amount'] ?? 0);

            $owed[$lot] = $charged - ($plan[$lot]['balance'] ?? 0);
        }

        /*
         * Paid month by month, oldest first, a full month's dues at a time.
         * The last payment a unit makes may be a part month — which is exactly
         * how a unit ends up owing an amount that is not a whole number of
         * months, and how board 5's odd balances arise.
         */
        foreach ($months as $index => $month) {
            $receipts = [];

            foreach ($units as $reference => $unit) {
                $lot = (int) substr((string) $reference, 4);

                if ($owed[$lot] <= 0) {
                    continue;
                }

                /*
                 * A month's payment covers that month's charges and no more —
                 * the dues, plus a levy if one fell due the same month. Paying
                 * ahead would settle a charge that has not been raised, and
                 * FIFO would then age the unit into the wrong bucket.
                 */
                $dueThisMonth = ArrearsPlan::MONTHLY_DUES
                    + (($levies[$lot]['month'] ?? null) === $index ? $levies[$lot]['amount'] : 0);

                $amount = min($owed[$lot], $dueThisMonth);
                $owed[$lot] -= $amount;

                $receipts[] = [
                    'unit' => $unit,
                    'amount' => Money::ofMinor($amount, 'JMD'),
                    'method' => 'bank',

                    // Paid a few days after the due date, which is what a
                    // standing order looks like against a first-of-month bill.
                    'received_at' => $month->copy()->addDays(2),
                ];
            }

            $dues->paymentRun(
                rows: $receipts,
                bankedOn: $month->copy()->addDays(2),
                memo: 'Dues receipts banked — '.$month->format('F Y'),
            );
        }
    }
}
