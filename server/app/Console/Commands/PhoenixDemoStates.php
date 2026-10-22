<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Account;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\Vendor;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Amenities;
use App\Services\Estate\Governance;
use App\Services\Estate\Ledger;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Payables;
use App\Services\Estate\Payroll;
use App\Services\Estate\Posting;
use App\Services\Push\PushAnnouncer;
use App\Support\SampleData;
use App\Support\SampleResidents;
use App\Support\Seeded\SeedsMarkedRows;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan phoenix:demo-states             what it would make (writes nothing)
 *   php artisan phoenix:demo-states --confirm   make it
 *   … --only=tickets,bookings,meetings,elections,bills,payroll,claims,statement,invoices   some kinds only
 *
 * A RECORD IN EVERY STATE THE CONSOLE HAS A NEXT ACTION FOR (M53 §2; Hassam, 9 October 2026: "the demo data has no
 * record where the next action is possible", and the M58 run's pass 3 found 21 of 51 states). One record per missing
 * state, each made by the service that owns it wherever a service can reach the state, so the record is what the
 * console would have made: tickets acknowledged, assigned, reopened and cancelled; bookings requested, declined,
 * expired and cancelled; meetings postponed, cancelled, held and archived; September's pay run calculated and waiting
 * for approval; unit claims pending, approved and rejected; and a BANK STATEMENT WHOSE FIVE LINES MATCH (M53 §2's
 * third S2: the seeded statement's lines were invented, no posted entry matched any of them, and every Match was
 * disabled), so a reconciliation can be worked to a nil difference and completed. An approved bill only when asked
 * for by name (`--only=bills`): approving a bill posts it to the ledger, and M58 §2.1 keeps that act Hassam's.
 *
 * WHAT A COMMITTEE READS IS REAL WORDS (M60 §C1). No title, name, note or reference says sample, test or demo: the
 * names are the estate's own named households', the dates are within weeks of today, the amounts read like a real
 * estate's books.
 *
 * THE MARK IS A COLUMN, NEVER A WORD (Hassam, 9 October 2026: "The mark never lives in a field a human reads"). Every
 * row carries `seeded_at` and `seeded_by` (`SeedsMarkedRows`), the mark every other row of the Phoenix Park
 * demonstration already carries, and falls in the id ranges recorded under the Phoenix tag, so `phoenix:demo-purge`
 * and the launch-day retirement take it out with the rest. A second run finds its own rows by that mark (`MARK`) and
 * makes nothing twice.
 *
 * NOBODY IS TOLD. Pushes, mail and texts are switched off for this command's own process; every record sits on a
 * household the demonstration named (never Lot 47, never a household with an app account, never a placeholder
 * "Lot N householder", never one held in the client's own first name, M53 §2).
 *
 * WHAT IT DOES NOT MAKE, AND SAYS SO:
 *   - ELECTIONS (Hassam's call, 9 October 2026: none dated 2020, at most three in the past and all within three
 *     years, at least one open for voting, no governance history Omar cannot answer for). A year holds one election and
 *     the Elections tab opens the latest year, so a stage still in progress has no year to take; a finished one needs
 *     votes, and a seed never writes a vote (it could never be taken out again), so a certified past year would read
 *     "Turnout 0" and "no seat filled". It reports the election open for voting instead, and who can still vote.
 *   - AN APPROVED PAY RUN: approving posts the month's wages to the ledger, and September stays waiting for approval
 *     for the Sunday rehearsal's payroll step.
 *   - GEMINI'S INVOICES TO THE ESTATE: every one is Omar's own company's; a drafted or overdue one made up here would be
 *     history he cannot answer for, and none is ever part paid.
 *
 * ON THE LIVE SERVER IT WRITES TO PHOENIX PARK VILLAGE 1 ONLY (M60 §C1), and nowhere unless the platform's
 * demonstration mode is on.
 */
class PhoenixDemoStates extends Command
{
    use SeedsMarkedRows;

    protected $signature = 'phoenix:demo-states
        {--estate=phoenixparkvillage1 : the estate; on the live server Phoenix Park Village 1 only}
        {--only= : some kinds only: tickets, bookings, meetings, elections, motion, bills, payroll, claims, statement, invoices}
        {--confirm : make them; without it nothing is written}';

    protected $description = 'Give Phoenix Park Village 1 a record in every state the console acts on (M53 §2)';

    private const KINDS = ['tickets', 'bookings', 'meetings', 'elections', 'motion', 'bills', 'payroll', 'claims', 'statement', 'invoices'];

    /** What a run without `--only` takes: everything but the bill, whose approval M58 §2.1 keeps for Hassam. */
    private const DEFAULT_KINDS = ['tickets', 'bookings', 'meetings', 'elections', 'motion', 'payroll', 'claims', 'statement', 'invoices'];

    /** The committee's motion (M62 A9, Hassam's answers 10 October 03:20; M73 §1, step 7). */
    public const MOTION_TITLE = 'Committee Ballot · 2027 Budget Approval';

    private const MOTION_QUESTION = 'Do you approve the 2027 budget as the Treasurer presented it to the committee?';

    /** The one estate it writes to on the live server (M60 §C1). */
    private const ESTATE = 'phoenixparkvillage1';

    /** Who wrote a row, in its `seeded_by` column: how a second run finds this command's rows, never by a word. */
    public const MARK = 'phoenix:demo-states';

    /** The bank's service charges that make a short month up to five lines, in minor units: $450, $375, $520, $610, $295. */
    private const BANK_CHARGES = [450_00, 375_00, 520_00, 610_00, 295_00];

    /** @var list<array{0: string, 1: string, 2: string}> kind, state, what */
    private array $lines = [];

    public function handle(): int
    {
        if (! (bool) config('demo.enabled')) {
            $this->error('The platform\'s demonstration mode is off, so this is not a demonstration server. Nothing was changed.');

            return self::INVALID;
        }

        $key = (string) $this->option('estate');

        if ($key !== self::ESTATE && app()->environment('production')) {
            $this->error('On the live server this writes to Phoenix Park Village 1 ('.self::ESTATE.') only. Nothing was changed.');

            return self::INVALID;
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $unknown = array_diff($only, self::KINDS);

        if ($unknown !== []) {
            $this->error('Unknown kind: '.implode(', ', $unknown).'. Use '.implode(', ', self::KINDS).'. Nothing was changed.');

            return self::INVALID;
        }

        $kinds = $only === [] ? self::DEFAULT_KINDS : $only;
        $apply = (bool) $this->option('confirm');

        // Nobody is told (see the class docblock): this process only.
        config(['push.enabled' => false, 'mail.default' => 'array']);

        $actor = $this->actor($key);

        if (! $actor instanceof User) {
            $this->error('Nobody is assigned to '.$estate->name.' as its Community Super Admin, so there is nobody to act as. Nothing was changed.');

            return self::INVALID;
        }

        $beforeCentral = SampleData::highWater('mysql');
        $beforeTenant = $estate->run(fn (): array => SampleData::highWater('tenant'));

        $estate->run(function () use ($kinds, $apply, $actor, $key): void {
            $units = $this->sampleUnits($key);

            if (! in_array('bills', $kinds, true)) {
                $this->say('bill', 'approved', 'not made unless asked for by name (--only=bills): approving a bill posts it to the ledger, and M58 §2.1 keeps that act yours');
            }

            foreach ($kinds as $kind) {
                try {
                    match ($kind) {
                        'tickets' => $this->tickets($apply, $actor, $units),
                        'bookings' => $this->bookings($apply, $actor, $units),
                        'meetings' => $this->meetings($apply, $actor),
                        'elections' => $this->elections(),
                        'motion' => $this->motion($apply, $units),
                        'bills' => $this->bills($apply, $actor),
                        'payroll' => $this->payroll($apply, $actor),
                        'claims' => $this->claims($apply, $actor, $units),
                        'statement' => $this->statement($apply),
                        'invoices' => $this->invoices(),
                    };
                } catch (Throwable $failure) {
                    $this->lines[] = [$kind, 'stopped', $failure->getMessage()];
                }
            }
        });

        if ($apply) {
            SampleData::record(SampleData::PHOENIX_TAG, $key, 'mysql', $beforeCentral);
            $estate->run(fn () => SampleData::record(SampleData::PHOENIX_TAG, $key, 'tenant', $beforeTenant));
        }

        $this->info(($apply ? 'Made at ' : 'What --confirm would make at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));
        $this->table(['Kind', 'State', 'What'], $this->lines);
        $this->line('Every row carries the seeded mark in its own columns, never in a word, so the purge and the launch-day retirement take it out. Pushes, mail and texts were off.');

        return collect($this->lines)->contains(static fn (array $line): bool => $line[1] === 'stopped') ? self::FAILURE : self::SUCCESS;
    }

    /* ---- who acts, and on which households ---------------------------------------------------------------------- */

    /** The estate's Community Super Admin: the services that need a person record this one. */
    private function actor(string $key): ?User
    {
        $role = Role::query()->where('name', Role::COMMUNITY_SUPER_ADMIN)->value('id');
        $id = EstateAssignment::query()->where('tenant_id', $key)->where('role_id', $role)->where('is_active', true)->orderBy('id')->value('user_id');

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Households the demonstration named, ten, oldest first, so a second run picks the same ones: the person who holds
     * the household has a real name (never a placeholder "Lot N householder", never one in the client's own first name,
     * M53 §2), nobody on it is signed in to the app, and it is never Lot 47 (the user's own test household).
     *
     * @return Collection<int, Unit>
     */
    private function sampleUnits(string $key): Collection
    {
        $withApp = DB::connection('mysql')->table('resident_accounts')->where('tenant_id', $key)->whereNotNull('unit_id')->pluck('unit_id')->map(intval(...))->all();

        return Unit::query()
            ->with('household.residents')
            ->whereHas('household')
            ->where('reference', '!=', 'Lot 47')
            ->whereNotIn('id', $withApp === [] ? [0] : $withApp)
            ->orderBy('id')
            ->get()
            ->filter(static function (Unit $unit): bool {
                $name = self::nameOn($unit);

                return $name !== null && ! SampleResidents::isSample($name, null) && ! str_starts_with(strtolower($name), 'omar ');
            })
            ->take(10)
            ->values();
    }

    /** @param  Collection<int, Unit>  $units */
    private function unit(Collection $units, int $index): Unit
    {
        if ($units->isEmpty()) {
            throw new \DomainException('The estate has no household with a named resident to put it on.');
        }

        return $units[$index % $units->count()];
    }

    /** The name of the person who holds the household, or null when it has nobody named. */
    private static function nameOn(Unit $unit): ?string
    {
        $residents = $unit->household?->residents;
        $primary = $residents?->firstWhere('is_primary', true) ?? $residents?->first();
        $name = trim((string) ($primary->full_name ?? ''));

        return $name === '' ? null : $name;
    }

    /** The named person on a household `sampleUnits()` chose: never null there. */
    private static function who(Unit $unit): string
    {
        return self::nameOn($unit) ?? throw new \DomainException($unit->reference.' has nobody named on it.');
    }

    /** A vendor by its name, or the estate's first one when it has no such vendor. */
    private static function vendor(string $name): ?Vendor
    {
        return Vendor::query()->where('name', $name)->first() ?? Vendor::query()->orderBy('id')->first();
    }

    private function say(string $kind, string $state, string $what): void
    {
        $this->lines[] = [$kind, $state, $what];
    }

    /* ---- tickets ------------------------------------------------------------------------------------------------- */

    /** @param  Collection<int, Unit>  $units */
    private function tickets(bool $apply, User $by, Collection $units): void
    {
        $maintenance = app(Maintenance::class);

        // state => [title, category, what the household wrote, the vendor it goes to]
        $made = [
            'acknowledged' => ['Intercom crackles at the gate', 'Access control', 'The intercom at the Phase 1 entrance crackles and drops the call when a visitor rings the house.', null],
            'assigned' => ['Street light out by the playground', 'Electrical', 'The light at the corner by the playground has been out three nights running. The path is dark after six.', 'Island Electric Services'],
            'reopened' => ['Blocked drain at the kerb', 'Plumbing', 'Water stands at the kerb after rain. It was cleared last week and has blocked again.', 'AquaTech Pool Services'],
            'cancelled' => ['Low water pressure in the mornings', 'Plumbing', 'The pressure drops to a trickle between six and eight most mornings.', null],
        ];

        foreach (array_keys($made) as $index => $state) {
            [$title, $category, $description, $vendorName] = $made[$state];
            $unit = $this->unit($units, $index);
            $there = MaintenanceTicket::query()->where('title', $title)->where('seeded_by', self::MARK)->first();

            if ($there !== null) {
                $this->say('ticket', $state, '#'.$there->number.' is there already');

                continue;
            }

            $vendor = $vendorName === null ? null : self::vendor($vendorName);

            if ($vendorName !== null && $vendor === null) {
                $this->say('ticket', $state, 'not made: the estate has no vendor to assign');

                continue;
            }

            if (! $apply) {
                $this->say('ticket', $state, '"'.$title.'" on '.$unit->addressLabel());

                continue;
            }

            $ticket = $maintenance->report(title: $title, locationLabel: $unit->addressLabel(), unit: $unit, category: $category, description: $description, reportedByName: self::who($unit), by: $by);

            if ($state === 'cancelled') {
                // No console act cancels a ticket (the household withdraws it in the app); the state is written as that leaves it.
                $ticket->forceFill(['status' => MaintenanceTicket::CANCELLED])->save();
            } else {
                $maintenance->acknowledge($ticket, $by);
            }

            if ($vendor !== null) {
                $maintenance->assign($ticket->fresh(), $vendor, by: $by);
            }

            if ($state === 'reopened') {
                $maintenance->start($ticket->fresh(), by: $by);
                $maintenance->resolve($ticket->fresh(), 'Cleared the drain and flushed the line.', $by);
                $maintenance->reopen($ticket->fresh(), 'The drain blocked again after two days of rain.', $by);
            }

            $this->say('ticket', $state, '#'.$ticket->number.' "'.$title.'" on '.$unit->addressLabel());
        }
    }

    /* ---- bookings ------------------------------------------------------------------------------------------------ */

    /** @param  Collection<int, Unit>  $units */
    private function bookings(bool $apply, User $by, Collection $units): void
    {
        $amenities = app(Amenities::class);
        $place = Amenity::query()->where('is_active', true)->where('is_bookable', true)->orderBy('sort_order')->first();

        if ($place === null) {
            $this->say('booking', 'all', 'not made: the estate has no amenity open for booking');

            return;
        }

        // state => [what the household wrote, its day from today]: ahead for the three still to come, behind for the one that expired.
        $made = [
            'requested' => ['Birthday party for about twenty children', 12],
            'rejected' => ['Family reunion lunch', 16],
            'cancelled' => ['Church youth group fellowship', 20],
            'expired' => ['Baby shower', -3],
        ];

        foreach ($made as $state => [$note, $days]) {
            $there = AmenityBooking::query()->where('notes', $note)->where('seeded_by', self::MARK)->first();

            if ($there !== null) {
                $this->say('booking', $state, $there->reference.' is there already');

                continue;
            }

            if (! $apply) {
                $this->say('booking', $state, 'a '.$place->name.' booking, "'.$note.'", about '.abs($days).' days '.($days < 0 ? 'ago' : 'ahead'));

                continue;
            }

            $booking = $this->bookOne($amenities, $place, $units, $days, $note);

            match ($state) {
                'rejected' => $amenities->decline($booking, 'The '.$place->name.' is closed for painting that week.', $by),
                'cancelled' => $amenities->cancel($booking),
                'expired' => $amenities->expire($booking),
                default => null,
            };

            $this->say('booking', $state, $booking->reference.', '.$place->name.', '.SiteTime::fmt($booking->starts_at, 'M j').', '.$booking->unit->reference);
        }
    }

    /**
     * One booking on the first chosen household that may book, on the first free two hours from the day asked for.
     *
     * @param  Collection<int, Unit>  $units
     */
    private function bookOne(Amenities $amenities, Amenity $place, Collection $units, int $days, string $note): AmenityBooking
    {
        $opens = substr((string) $place->opens_at, 0, 5);
        $last = null;

        foreach ($units as $unit) {
            if (! $amenities->mayBook($unit)) {
                continue;
            }

            for ($shift = 0; $shift < 14; $shift++) {
                $day = SiteTime::today()->addDays($days + ($days < 0 ? -$shift : $shift))->format('Y-m-d');
                $from = Carbon::parse($day.' '.$opens)->addHour();

                try {
                    return $amenities->book($place, $unit, self::who($unit), $from->format('Y-m-d H:i'), $from->copy()->addHours(2)->format('Y-m-d H:i'), notes: $note);
                } catch (\DomainException $refused) {
                    $last = $refused->getMessage();
                }
            }
        }

        throw new \DomainException('No chosen household could book the '.$place->name.($last === null ? '.' : ': '.$last));
    }

    /* ---- meetings ------------------------------------------------------------------------------------------------ */

    private function meetings(bool $apply, User $by): void
    {
        $governance = app(Governance::class);

        // state => [title, what it is for, its day from today]
        $made = [
            'postponed' => ['Gate contract renewal', 'To compare the two quotes for the gate and barrier contract and agree the terms of the renewal.', 40],
            'cancelled' => ['Pool opening hours', 'To settle the pool\'s opening hours for the Christmas season.', 45],
            'held' => ['Road repairs in Phase 2', 'To agree the order of the pothole repairs on the Phase 2 roads and how to pay for them.', -12],
            'archived' => ['Landscaping tender', 'To open the three landscaping tenders and choose a contractor for the common areas.', -60],
        ];

        foreach ($made as $state => [$title, $purpose, $days]) {
            $there = Meeting::query()->where('title', $title)->where('seeded_by', self::MARK)->first();

            if ($there !== null) {
                $this->say('meeting', $state, '#'.$there->id.' is there already');

                continue;
            }

            $when = SiteTime::today()->addDays($days)->setTime(18, 30);

            if (! $apply) {
                $this->say('meeting', $state, '"'.$title.'", '.$when->format('M j'));

                continue;
            }

            $meeting = $governance->scheduleMeeting([
                'type' => Meeting::COMMITTEE,
                'title' => $title,
                'description' => $purpose,
                'starts_at' => $when,
                'duration_minutes' => 90,
                'held_as' => Meeting::IN_PERSON,
                'venue' => 'Community Centre',
                'audience_scope' => Meeting::COMMITTEE_ONLY,
            ], [], $by);

            if ($days > 0) {
                $governance->publishMeeting($meeting, $by);

                if ($state === 'postponed') {
                    $governance->postpone($meeting->fresh(), 'The Community Centre is booked for a wedding that evening.', $by);
                } else {
                    $governance->cancelMeeting($meeting->fresh(), 'The committee will take it at the special general meeting.', $title, $by);
                }
            } else {
                // A meeting already past cannot be given notice now; it is written as a held meeting leaves it.
                $meeting->forceFill([
                    'status' => Meeting::HELD,
                    'published_at' => $when->copy()->subDays(14),
                    'notice_given_at' => $when->copy()->subDays(14),
                    ...($state === 'archived' ? ['archived_at' => $when->copy()->addDays(40), 'archived_by_name' => $by->name] : []),
                ])->save();
            }

            $this->say('meeting', $state, '#'.$meeting->id.' "'.$title.'", '.$when->format('M j'));
        }
    }

    /* ---- elections: none made, and the one open for voting reported -------------------------------------------- */

    private function elections(): void
    {
        $governance = app(Governance::class);
        $open = Ballot::query()->where('stage', Ballot::VOTING_OPEN)->orderByDesc('year')->get();

        if ($open->isEmpty()) {
            $this->say('election', 'voting open', 'none is open for voting: open one from the console\'s Elections page (it tells every household)');
        }

        $lot47 = Unit::query()->where('reference', 'Lot 47')->first();

        foreach ($open as $ballot) {
            $turnout = $governance->turnout($ballot);
            $left = max(0, $turnout['eligible'] - $turnout['cast']);

            $this->say('election', 'voting open', $ballot->year.' "'.$ballot->title.'": '.$turnout['label'].' have voted, '.$left.' can still vote'
                .($ballot->closes_at === null ? '' : ', until '.SiteTime::fmt($ballot->closes_at, 'M j'))
                .($lot47 === null ? '' : '; Lot 47 '.($governance->hasVoted($ballot, $lot47) ? 'has voted' : 'has not voted')));
        }

        $this->say('election', 'nominations closed, candidate vetting, campaign period, voting closed, tally', 'not made: a year holds one election, this year and the next hold the estate\'s own, and a later year would become the first page the Elections tab opens');
        $this->say('election', 'certified, archived, cancelled', 'not made: a finished election needs votes, which are never written by this (they could never be taken out again), so a past year would read "Turnout 0", and it would be history nobody on the committee can answer for');
    }

    /* ---- the committee's motion, in draft, on its roll of seven ------------------------------------------------ */

    /**
     * "Committee Ballot · 2027 Budget Approval", a resolution in draft, put to a recorded roll of seven households (M62
     * A9, Hassam's answers: Lot 47 and six; M73 §1, step 7: the card is opened and closed on Sunday). On the year of the
     * estate's latest election, so its card sits on the page the Elections tab opens; never a year of its own, which
     * would become that page. The roll: Lot 47's household first; then committee members' own households
     * (`PushAnnouncer::committeeHouseholds`, an app account under a member's own email); then named households with no
     * app account (`sampleUnits()`), until there are seven. Nobody is told while it is a draft; opening voting tells the
     * roll's phones, and the dry run says how many.
     *
     * @param  Collection<int, Unit>  $units
     */
    private function motion(bool $apply, Collection $units): void
    {
        $there = Ballot::query()->where('title', self::MOTION_TITLE)->first();

        if ($there !== null) {
            $this->say('motion', $there->stageLabel(), '#'.$there->id.' "'.self::MOTION_TITLE.'" is there already');

            return;
        }

        $lot47 = Unit::query()->where('reference', 'Lot 47')->with('household')->first();

        if ($lot47?->household === null) {
            $this->say('motion', 'draft', 'not made: Lot 47 has no household to put on its roll');

            return;
        }

        $key = (string) tenant()?->getTenantKey();
        $why = 'named by the estate office for this motion';
        $roll = [(int) $lot47->household->id => $why];

        foreach (Household::query()->whereIn('unit_id', PushAnnouncer::committeeHouseholds($key))->orderBy('unit_id')->get() as $household) {
            if (count($roll) < 7) {
                $roll[(int) $household->id] ??= 'a committee member\'s household';
            }
        }

        foreach ($units as $unit) {
            if (count($roll) < 7 && $unit->household !== null) {
                $roll[(int) $unit->household->id] ??= $why;
            }
        }

        if (count($roll) < 7) {
            $this->say('motion', 'draft', 'not made: only '.count($roll).' households could be named for its roll of seven');

            return;
        }

        $households = Household::query()->with('unit')->whereIn('id', array_keys($roll))->get()->keyBy('id');
        $unitIds = $households->map(static fn (Household $h): int => (int) $h->unit_id)->values()->all();
        $accounts = DB::connection('mysql')->table('resident_accounts')->where('tenant_id', $key)->where('status', 'active')->whereIn('unit_id', $unitIds)->pluck('id');
        $phones = $accounts->isEmpty() ? 0 : DB::connection('mysql')->table('device_push_tokens')->where('tenant_id', $key)
            ->where('principal_type', 'resident')->whereNull('revoked_at')->whereIn('principal_id', $accounts->all())->count();

        $year = (int) (Ballot::query()->where('kind', Ballot::ELECTION)->max('year') ?? SiteTime::today()->year);
        $list = implode('; ', array_map(static fn (int $id): string => ($households[$id]->unit->reference ?? 'household '.$id).' ('.$roll[$id].')', array_keys($roll)));
        $tells = 'Nobody is told while it is a draft; opening voting tells '.$phones.' phone'.($phones === 1 ? '' : 's').' on those households';

        if (! $apply) {
            $this->say('motion', 'draft', '"'.self::MOTION_TITLE.'" ('.$year.'), on a roll of seven: '.$list.'. '.$tells);

            return;
        }

        $motion = app(Governance::class)->createCommitteeBallot($year, self::MOTION_TITLE, self::MOTION_QUESTION, $roll);

        $this->say('motion', 'draft', '#'.$motion->id.' "'.self::MOTION_TITLE.'" ('.$year.'), on a roll of seven: '.$list.'. '.$tells);
    }

    /* ---- bills (only when asked for by name) -------------------------------------------------------------------- */

    private function bills(bool $apply, User $by): void
    {
        $description = 'Guard house lighting rewired, Main Gate';
        $amount = '18742.50';
        $there = Bill::query()->where('description', $description)->where('seeded_by', self::MARK)->first();

        if ($there !== null) {
            $this->say('bill', 'approved', $there->reference.' is there already');

            return;
        }

        $vendor = self::vendor('Island Electric Services');

        if ($vendor === null) {
            $this->say('bill', 'approved', 'not made: the estate has no vendor to bill from');

            return;
        }

        if (! $apply) {
            $this->say('bill', 'approved', '$18,742.50 from '.$vendor->name.', approved: its entry is posted to the ledger');

            return;
        }

        $payables = app(Payables::class);
        $bill = $payables->recordBill($vendor, Money::of($amount, 'JMD'), $description, SiteTime::today()->addDays(20));
        $payables->approve($bill, $by);

        $this->say('bill', 'approved', $bill->reference.', $18,742.50 from '.$vendor->name);
    }

    /* ---- payroll ------------------------------------------------------------------------------------------------- */

    private function payroll(bool $apply, User $by): void
    {
        $run = PayrollRun::query()->whereIn('status', [PayrollRun::DRAFT, PayrollRun::EXCEPTIONS])->orderByDesc('period_start')->first();

        $this->say('pay run', 'approved', 'not made: approving posts the month\'s wages to the ledger, and the open month stays waiting for approval for the rehearsal\'s payroll step');

        if ($run === null) {
            $calculated = PayrollRun::query()->where('status', PayrollRun::CALCULATED)->whereNull('approved_at')->orderByDesc('period_start')->first();
            $this->say('pay run', 'calculated, awaiting approval', $calculated === null ? 'not made: no pay run is open to calculate' : $calculated->period_label.' is calculated and waiting for approval already');

            return;
        }

        $open = PayrollException::query()->where('payroll_run_id', $run->id)->whereNotIn('status', [PayrollException::RESOLVED, PayrollException::EXCLUDED])->get();

        if (! $apply) {
            $this->say('pay run', 'calculated, awaiting approval', $run->period_label.': '.($open->isEmpty() ? '' : $open->count().' exception'.($open->count() === 1 ? '' : 's').' resolved into the run as the console resolves one, then ').'calculated, then waiting for approval');

            return;
        }

        $payroll = app(Payroll::class);

        // The run's open exceptions, resolved into it as the console's "Resolve" does, with the reason on each.
        foreach ($open as $exception) {
            $payroll->resolveException($exception, PayrollException::RESOLVED, 'Hours confirmed from the paper timesheet.', $by);
        }

        $payroll->calculate($run->fresh(), $by);

        $this->say('pay run', 'calculated, awaiting approval', $run->period_label.', '.PayrollRun::query()->whereKey($run->id)->value('status').($open->isEmpty() ? '' : ', '.$open->count().' exception'.($open->count() === 1 ? '' : 's').' resolved first'));
    }

    /* ---- unit claims --------------------------------------------------------------------------------------------- */

    /** @param  Collection<int, Unit>  $units */
    private function claims(bool $apply, User $by, Collection $units): void
    {
        // state => [the claimant, the number they gave (a reserved one, which reaches nobody)]. The approved claim is the
        // household's own named resident's: an approved claim resolves to that person.
        $made = ['pending' => ['Marlon Ellis', '+1 876 555 0170'], 'approved' => [null, '+1 876 555 0171'], 'rejected' => ['Devon Richards', '+1 876 555 0172']];

        foreach (array_keys($made) as $index => $state) {
            [$name, $phone] = $made[$state];
            $there = UnitClaim::query()->where('submitted_phone', $phone)->where('seeded_by', self::MARK)->first();

            if ($there !== null) {
                $this->say('unit claim', $state, 'claim #'.$there->id.' is there already');

                continue;
            }

            $unit = $this->unit($units, $index + 4);
            $name ??= self::who($unit);

            if (! $apply) {
                $this->say('unit claim', $state, '"'.$name.'" claiming '.$unit->addressLabel());

                continue;
            }

            $claim = new UnitClaim;
            $claim->forceFill([
                'unit_id' => $unit->id,
                'household_id' => $unit->household?->id,
                'claim_type' => UnitClaim::TYPE_UNIT,
                'submitted_name' => $name,
                'submitted_phase' => (string) ($unit->block ?? ''),
                'submitted_lot' => preg_replace('/^Lot\s+/i', '', (string) $unit->reference),
                'submitted_phone' => $phone,
                'submitted_relationship' => 'owner',
                'match_result' => $state === 'approved' ? 'exact' : 'partial',
                'status' => $state,
                ...($state === 'pending' ? [] : ['reviewed_by' => $by->id, 'reviewed_by_name' => $by->name, 'reviewed_at' => Carbon::now()->subDays(2)]),
                ...($state === 'approved' ? ['resolved_resident_id' => $unit->household?->residents->firstWhere('is_primary', true)?->id] : []),
                ...($state === 'rejected' ? ['decision_reason' => 'The name does not match the title deed on file.'] : []),
            ])->save();

            $this->say('unit claim', $state, 'claim #'.$claim->id.', "'.$name.'" claiming '.$unit->addressLabel());
        }
    }

    /* ---- a bank statement whose five lines match ----------------------------------------------------------------- */

    /**
     * The newest past month with no statement for the operating account: a statement of five of its bank movements
     * no statement has claimed, each line the entry's own day and signed amount, so each is proposed for its entry and,
     * all five matched, the difference is nil. Its opening is the ledger's bank balance at the month's start.
     *
     * THE DEMONSTRATION'S BANK ACCOUNT MOVES ONE TO FOUR TIMES A MONTH, so a month short of five is made up the way a
     * real statement is: with the bank's own service charges, which the estate posts when the statement arrives (Dr 5910
     * Bank & Card Charges, Cr the bank, a manual entry dated in the month). Each line is then a posted entry.
     * Made only where it will be the statement the console opens (the newest open one).
     */
    private function statement(bool $apply): void
    {
        $bank = Account::query()->where('code', Payables::BANK)->first();

        if ($bank === null) {
            $this->say('statement', 'five lines that match', 'not made: the estate has no operating bank account');

            return;
        }

        $mine = BankReconciliation::query()->where('seeded_by', self::MARK)->first();

        if ($mine !== null) {
            $this->say('statement', 'five lines that match', 'the '.$mine->periodLabel().' statement is there already');

            return;
        }

        $taken = BankReconciliation::query()->where('account_id', $bank->id)->get()->map(static fn (BankReconciliation $r): string => $r->statement_date->format('Y-m'))->all();
        $claimed = BankStatementLine::query()->whereNotNull('matched_entry_ref')->pluck('matched_entry_ref')->all();
        $newestOpen = BankReconciliation::query()->where('account_id', $bank->id)->where('status', BankReconciliation::OPEN)->orderByDesc('statement_date')->first();

        $month = null;

        for ($back = 1; $back <= 12 && $month === null; $back++) {
            $candidate = SiteTime::today()->startOfMonth()->subMonths($back);
            $month = in_array($candidate->format('Y-m'), $taken, true) ? null : $candidate;
        }

        if ($month === null) {
            $this->say('statement', 'five lines that match', 'not made: every month of the last year already has a statement');

            return;
        }

        if ($newestOpen !== null && $newestOpen->statement_date->greaterThan($month->copy()->endOfMonth())) {
            $this->say('statement', 'five lines that match', 'not made: the open '.$newestOpen->periodLabel().' statement is newer, so a '.$month->format('F').' one would not be the statement the console opens');

            return;
        }

        $entries = $this->bankMovements($bank->id, $month, $claimed);
        $charges = max(0, 5 - $entries->count());

        if (! $apply) {
            $this->say('statement', 'five lines that match', 'a '.$month->format('F Y').' statement of '.$bank->code.' '.$bank->name.': '.$entries->count().' of its posted bank entries'.($charges === 0 ? '' : ' and '.$charges.' bank service charge'.($charges === 1 ? '' : 's').' posted for it (Dr 5910, Cr '.$bank->code.')').', each line proposed for its own entry');

            return;
        }

        DB::connection('tenant')->transaction(function () use ($bank, $month, $claimed, $charges): void {
            $ledger = app(Ledger::class);

            // The bank's own charges for the month, posted as the estate posts them when the statement arrives.
            foreach (array_slice(self::BANK_CHARGES, 0, $charges) as $n => $minor) {
                $memo = 'Bank service charge, '.$month->format('F Y');
                $ledger->post(
                    memo: $memo,
                    postings: [Posting::debit('5910', $minor, $memo), Posting::credit($bank->code, $minor, $memo)],
                    on: $month->copy()->endOfMonth()->subDays($n)->toDateString(),
                );
            }

            $entries = $this->bankMovements($bank->id, $month, $claimed);
            $movement = (int) $entries->sum('movement');
            $opening = (int) DB::connection('tenant')->table('journals')
                ->join('journal_lines', 'journal_lines.entry_ref', '=', 'journals.reference')
                ->where('journal_lines.account_id', $bank->id)
                ->where('journals.posted_on', '<', $month->toDateString())
                ->sum(DB::raw('journal_lines.debit_minor - journal_lines.credit_minor'));

            $reconciliation = BankReconciliation::query()->create([
                'account_id' => $bank->id,
                'statement_date' => $month->copy()->endOfMonth()->toDateString(),
                'opening_minor' => $opening,
                'closing_minor' => $opening + $movement,
                'currency' => 'JMD',
                'status' => BankReconciliation::OPEN,
            ]);

            foreach ($entries as $entry) {
                $on = Carbon::parse((string) $entry->posted_on);
                $charge = str_starts_with((string) $entry->memo, 'Bank service charge');

                BankStatementLine::query()->create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'value_date' => $on->toDateString(),
                    'description' => (int) $entry->movement > 0 ? 'Deposit' : ($charge ? 'Service charge' : 'Payment'),
                    'amount_minor' => (int) $entry->movement,
                    'currency' => 'JMD',
                    'bank_reference' => self::bankReference((string) $entry->reference, $on, $charge),
                ]);
            }
        });

        $this->say('statement', 'five lines that match', $month->format('F Y').' statement of '.$bank->code.', five lines, each one posted entry'.($charges === 0 ? '' : ', '.$charges.' of them bank service charges posted for it'));
    }

    /**
     * The bank's own reference on a statement line, as a Jamaican bank prints one: "FT" (a transfer) or "SC" (a service
     * charge), the value date and four figures, the same every time for the same entry.
     */
    private static function bankReference(string $entryRef, Carbon $on, bool $charge): string
    {
        return ($charge ? 'SC' : 'FT').$on->format('ymd').str_pad((string) (crc32($entryRef) % 10000), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Five of a month's movements on the bank no statement has claimed, oldest first: rows of `reference`, `memo`,
     * `posted_on` and `movement` (the signed sum of its lines on the bank, in minor units).
     *
     * @param  list<string>  $claimed
     * @return Collection<int, \stdClass>
     */
    private function bankMovements(int $bankId, Carbon $month, array $claimed): Collection
    {
        return DB::connection('tenant')->table('journals')
            ->join('journal_lines', 'journal_lines.entry_ref', '=', 'journals.reference')
            ->where('journal_lines.account_id', $bankId)
            ->whereBetween('journals.posted_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->whereNotIn('journals.reference', $claimed === [] ? [''] : $claimed)
            ->selectRaw('journals.reference as reference, journals.memo as memo, journals.posted_on as posted_on, SUM(journal_lines.debit_minor - journal_lines.credit_minor) as movement')
            ->groupBy('journals.reference', 'journals.memo', 'journals.posted_on')
            ->havingRaw('SUM(journal_lines.debit_minor - journal_lines.credit_minor) <> 0')
            ->orderBy('journals.posted_on')
            ->orderBy('journals.reference')
            ->limit(5)
            ->get();
    }

    /* ---- Gemini's invoices to the estate: none made ------------------------------------------------------------- */

    private function invoices(): void
    {
        $this->say('invoice', 'draft, part paid, overdue', 'not made: every invoice to the estate is Gemini\'s own; a made-up draft or overdue one is billing Gemini never sent, and none is ever part paid');
    }
}
