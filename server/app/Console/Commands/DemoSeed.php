<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Jobs\Tenancy\MigrateEstateAsOwner;
use App\Models\CheckpointScan;
use App\Models\DispatchMessage;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\PatrolCheckpoint;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\StatutoryRateVersion;
use App\Models\Tenant;
use App\Services\Estate\Dues;
use App\Services\Estate\Governance;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Services\Estate\PrimaryResidentRepair;
use App\Services\Passes\VisitorPasses;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Reviewer\ReviewerMode;
use App\Services\Tenancy\EstateProvisioner;
use App\Support\Seeded\ReservedContacts;
use App\Support\Seeded\SeedsMarkedRows;
use App\Support\SiteTime;
use Brick\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Estate\SettingsSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan demo:seed
 *   php artisan demo:seed --confirm-live      (on the platform the apps really talk to)
 *
 * THE DEMONSTRATION ESTATE, AND THE TWO REVIEWER ACCOUNTS THAT SIGN IN TO IT (M5 §3).
 *
 * WHY IT EXISTS. Both apps are login-walled with no public sign-up. An App Store or Play
 * reviewer downloads the production binary, finds no way in, and rejects it under Guideline
 * 2.1; and a reviewer who does get in and finds empty lists rejects it under 4.2 for minimum
 * functionality. So this builds an estate they can be given the keys to — invented from end
 * to end, named so that nobody could mistake it for a real community — and fills it with the
 * product actually working: a shift in progress, visitor passes in three states, a completed
 * patrol, a resolved incident, a payslip, notices, an OPEN election, and invoices both paid
 * and outstanding.
 *
 * IT IS AN ESTATE LIKE ANY OTHER, AND THAT IS THE POINT. It is provisioned by
 * `EstateProvisioner` — its own database, its own MySQL user, the same append-only grants —
 * so tenant isolation applies to it exactly as to Phoenix Park. Nothing here relaxes
 * invariant 1, and `ReviewerModeTest` walks a real estate's ids with the reviewer's token to
 * prove every one answers as an id nobody has.
 *
 * IDEMPOTENT. Run it as often as you like: it only ever adds or refreshes this estate's own
 * rows, and it never touches another estate, another guard, a ledger elsewhere or a statutory
 * figure. Each run leaves the reviewer's own shift rostered-but-not-clocked-in, so the
 * clock-in walk in `STORE_REVIEW_NOTES.md` can be done again.
 *
 * NOT LOCAL ONLY, AND DELIBERATELY SO. The reviewer signs in against the platform the
 * shipped app is built to talk to, so the demonstration estate has to exist THERE. What is
 * refused is doing it by accident: anywhere but a developer's machine the command stops
 * unless `--confirm-live` is given, and it is run once, by hand, by whoever operates the
 * platform. The credentials it prints are the ones pasted into App Store Connect.
 */
class DemoSeed extends Command
{
    // Every row it writes carries the seeded mark (M33 §5.2).
    use SeedsMarkedRows;

    protected $signature = 'demo:seed
        {--confirm-live : provision and seed the demonstration estate on a platform that is not a developer\'s machine}';

    protected $description = 'Build the demonstration estate and the two app-store reviewer accounts (M5 §3)';

    /** Where the demonstration gate is. Invented coordinates off the north coast road. */
    private const LATITUDE = 18.4762;

    private const LONGITUDE = -77.9194;

    private const GEOFENCE_M = 60;

    /** How long one watch is. Twelve hours on, twelve off, the way a manned gate is actually run. */
    private const WATCH_HOURS = 12;

    /** Fourteen days of them, so a reviewer who opens the app a week after submission still has one. */
    private const ROSTER_WATCHES = 28;

    /**
     * The handover note on the one watch the reviewer has already worked.
     *
     * It is also that row's IDENTITY: nothing else on this platform writes this sentence, so it is
     * what finds the shift again on the next run and rewrites it rather than adding a second.
     */
    private const HANDOVER = 'Quiet watch. Pedestrian gate light still out — reported. Demonstration data.';

    public function handle(EstateProvisioner $provisioner): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('confirm-live')) {
            $this->error(
                'Refused: APP_ENV is '.app()->environment().'. The demonstration estate belongs on the platform the '.
                'shipped apps talk to, but it is created once, by hand. Re-run with --confirm-live if that is what you mean.'
            );

            return self::FAILURE;
        }

        $estate = Tenant::query()->find(ReviewerMode::ESTATE);

        if (! $estate instanceof Tenant) {
            try {
                $estate = $provisioner->provision(ReviewerMode::ESTATE, ReviewerMode::ESTATE_NAME, 'active', ReviewerMode::RECEIPT_PREFIX);
                $this->info('Provisioned '.$estate->name.' ('.$estate->database()->getName().').');
            } catch (Throwable $e) {
                $estate = Tenant::query()->find(ReviewerMode::ESTATE);

                if (! $estate instanceof Tenant) {
                    $this->error('Could not provision the demonstration estate: '.$e->getMessage());

                    return self::FAILURE;
                }

                $this->warn('Provisioning stopped part-way: '.$e->getMessage());
                $this->warn('Finishing it below. An estate\'s own MySQL user is created WITHOUT update or delete and is granted them back per table only after the migrations have run (D-017) — so the first pass of a tenant migration that backfills a column cannot complete. The grants go on, and the migration runs again.');
            }
        }

        $this->finishProvisioning($estate);

        /*
         * `demo` and `active` are what the Resident App's "Explore a demo community" path and
         * the estate pick-list read. Written through the query builder rather than the model
         * so a re-run cannot trip the receipt-prefix guard on an estate that has issued one.
         */
        DB::connection('mysql')->table('tenants')->where('id', $estate->getTenantKey())->update([
            'demo' => true,
            'status' => 'active',
            'name' => ReviewerMode::ESTATE_NAME,
            'address_line' => '1 Demonstration Way',
            'parish' => 'St. Ann',
            'gate_count' => 1,
            'phases' => json_encode(['Phase 1', 'Phase 2']),
            'updated_at' => Carbon::now(),
        ]);

        $estate = Tenant::query()->findOrFail(ReviewerMode::ESTATE);

        $post = $this->post($estate);
        $guards = $this->guards($estate, $post);

        /** @var array{unit: int, resident: int, name: string} $home */
        $home = $estate->run(fn (): array => $this->insideTheEstate($estate, $guards['colleague']));

        $this->centralRecords($estate, $post, $guards);
        $account = $this->reviewerAccount($estate, $home);

        $this->report($estate, $guards['reviewer'], $account);

        return self::SUCCESS;
    }

    /**
     * Bring the estate's database fully up, whatever state provisioning left it in.
     *
     * Every step is IDEMPOTENT and safe to repeat, which is what lets this run on every
     * `demo:seed` rather than only after a failure: the domain row is the bare subdomain
     * `InitializeTenancyBySubdomain` looks for, the migrations finish whatever the first pass
     * could not, and the append-only grants go back on afterwards. Only ever this one estate.
     *
     * THE MIGRATIONS RUN AS THE SCHEMA OWNER, and that is the point of this method.
     *
     * An estate's own MySQL user is created WITHOUT update or delete, and is granted them back
     * per table only AFTER the migrations have run (D-017) — so the migrations themselves run
     * with no update at all. Three estate migrations backfill a column, and every one of them
     * fails on a NEWLY provisioned estate with "UPDATE command denied". Worse, `payments` is an
     * append-only table, so its user never gets update back and that migration could not run as
     * the estate user at any point in the future either.
     *
     * The answer is the same one the central schema already uses (`migrate --database=
     * mysql_owner`): a schema change is the owner's act, not the application user's. The
     * connection is pointed at this estate's database with the owner's credentials for the
     * length of the migration and put back immediately, and `ApplyAppendOnlyGrants` then
     * re-establishes exactly the restricted grants the estate is meant to run under. Nothing
     * about what the API's user may do is widened — only who ran the DDL.
     */
    private function finishProvisioning(Tenant $estate): void
    {
        if (! $estate->domains()->where('domain', $estate->getTenantKey())->exists()) {
            $estate->domains()->create(['domain' => $estate->getTenantKey()]);
        }

        // The provisioning pipeline's own step since M7, so an estate is migrated one way only.
        (new MigrateEstateAsOwner($estate))->handle();

        (new ApplyAppendOnlyGrants($estate))->handle();
    }

    /* -----------------------------------------------------------------
     | The gate, and who works it
     |------------------------------------------------------------------ */

    private function post(Tenant $estate): Post
    {
        $post = Post::query()->firstOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'name' => 'Demo Main Gate'],
            ['type' => 'gate', 'is_active' => true],
        );

        /*
         * SURVEYED, and that is what makes the reviewer's geofence bypass possible at all:
         * `ReviewerMode` satisfies the check from THESE coordinates, so a post with none
         * would leave a reviewer outside a fence that has no position.
         */
        $post->forceFill([
            'latitude' => self::LATITUDE,
            'longitude' => self::LONGITUDE,
            'geofence_radius_m' => self::GEOFENCE_M,
            'is_active' => true,
        ])->save();

        return $post;
    }

    /**
     * The reviewer's own account, and a colleague who is on duty while they look.
     *
     * @return array{reviewer: Guard, colleague: Guard}
     */
    private function guards(Tenant $estate, Post $post): array
    {
        $common = [
            'psra_expires_on' => Carbon::now()->addYear()->toDateString(),
            'employment_type' => 'full_time',
            'status' => 'active',
            'hired_on' => Carbon::now()->subMonths(8)->toDateString(),
            'tenant_id' => $estate->getTenantKey(),
            'post_id' => $post->id,

            // An hourly rate, so the demonstration payslip below is arithmetic rather than
            // an invented figure. Central payroll's column; no handset can read it.
            'standard_rate_minor' => 1_050_00,
            'standard_rate_currency' => 'JMD',

            // The Guard App's leave screen reads this. Left unset it is 0, and the screen draws
            // "0 days" above an empty list — which reads as a product that has not been set up
            // rather than an officer who has taken no leave.
            'leave_entitlement_days' => 14,
        ];

        $reviewer = Guard::query()->updateOrCreate(
            ['employee_number' => ReviewerMode::GUARD_NUMBER],
            [...$common, 'full_name' => ReviewerMode::GUARD_NAME, 'psra_number' => 'PSRA-DEMO-0001', 'phone' => '876-555-0100', 'email' => 'guard.reviewer@example.com'],
        );

        /*
         * THE FLAG AND THE STATIC CODE, the two columns that make this account a reviewer's.
         * `forceFill`, because neither is mass-assignable: granting reviewer status is a
         * deliberate act, never something a fill of user input could do by accident.
         */
        $reviewer->forceFill([
            'reviewer' => true,
            'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::GUARD_CODE),
        ])->save();

        $colleague = Guard::query()->updateOrCreate(
            ['employee_number' => 'GS-DEMO-2'],
            [...$common, 'full_name' => 'Winston Blake (demonstration officer)', 'psra_number' => 'PSRA-DEMO-0002', 'phone' => '876-555-0101', 'email' => 'guard.two@example.com'],
        );

        // Never a reviewer. It is here to be somebody ELSE on the dashboard, and an account
        // that quietly carried the flag would be a second way past four gates.
        $colleague->forceFill(['reviewer' => false, 'reviewer_code_hash' => null])->save();

        return ['reviewer' => $reviewer, 'colleague' => $colleague];
    }

    /* -----------------------------------------------------------------
     | Inside the estate's own database
     |------------------------------------------------------------------ */

    /**
     * Units, households, dues paid and outstanding, passes, notices, a meeting, a ticket and
     * an OPEN election. Runs inside tenancy: every row here is this estate's own.
     *
     * @return array{unit: int, resident: int, name: string}
     */
    private function insideTheEstate(Tenant $estate, Guard $colleague): array
    {
        Artisan::call('db:seed', ['--class' => ChartOfAccountsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);

        $units = [];

        foreach ([['Lot 1', 'Phase 1'], ['Lot 2', 'Phase 1'], ['Lot 3', 'Phase 1'], ['Lot 4', 'Phase 2'], ['Lot 5', 'Phase 2'], ['Lot 6', 'Phase 2']] as $i => [$reference, $phase]) {
            $unit = Unit::query()->updateOrCreate(
                ['reference' => $reference],
                ['block' => $phase, 'street' => 'Demonstration Way', 'type' => 'residential', 'status' => 'occupied'],
            );

            $household = Household::query()->updateOrCreate(
                ['unit_id' => $unit->id],
                ['name' => self::HOUSEHOLDS[$i].' household (demonstration)', 'access_restricted' => false],
            );

            // Primary only where nobody holds the household: a re-run never hands back what the office moved (DESIGN §13).
            // An address and a number nobody has (M35 §5): `geminisecure.app` is a domain anyone could register.
            PrimaryResidentRepair::seededHolder($household, strtolower(str_replace(' ', '.', self::HOUSEHOLDS[$i])).'@example.com', [
                'full_name' => self::HOUSEHOLDS[$i].' (demonstration resident)',
                'phone' => ReservedContacts::seededPhone(200 + $i),
                'relationship' => 'owner',
            ]);

            $units[$reference] = $unit;
        }

        $this->dues($units);
        $this->correctOneChargeInError($units['Lot 6']);
        $this->passes($estate, $units['Lot 1']);
        $this->community($units['Lot 1'], $colleague);
        $this->election();
        $this->householdRecords((int) Household::query()->where('unit_id', $units['Lot 1']->id)->value('id'));

        $resident = Resident::query()->where('household_id', Household::query()->where('unit_id', $units['Lot 1']->id)->value('id'))->firstOrFail();

        return ['unit' => (int) $units['Lot 1']->id, 'resident' => (int) $resident->id, 'name' => (string) $resident->full_name];
    }

    /** The six households the demonstration estate holds. Invented names, and they say so. */
    private const HOUSEHOLDS = ['Rowe', 'Campbell', 'Nembhard', 'Sinclair', 'Ferguson', 'Bailey'];

    /**
     * ONE CHARGE RAISED IN ERROR, AND PUT RIGHT BY A REVERSAL — the same thing every other
     * demonstration estate's seed does (`DemoDataSeeder`, `EstateFinanceSeeder`).
     *
     * Without one, this estate has nothing reversed, so the reversal screen has nothing to
     * show and `gate:ledger`'s "reversals mirror what they correct" cannot run here; the gate
     * then reports INCOMPLETE, never PASSED, because an assertion that did not run is not a
     * guarantee. On Lot 6, never Lot 1: Lot 1 is the store reviewer's own household, and a
     * reviewer's statement should read as an ordinary month, not as a correction.
     *
     * Both halves stay. They net to nothing, and that is what lets somebody reading the
     * account see that a mistake was made AND that it was put right. Keyed on the memo, so a
     * re-run posts nothing new — journals are append-only.
     */
    private function correctOneChargeInError(Unit $unit): void
    {
        $memo = 'Special assessment — raised against the wrong unit (demonstration)';

        if (Journal::query()->where('memo', $memo)->exists()) {
            return;
        }

        $mistake = app(Ledger::class)->post(
            memo: $memo,
            postings: [
                Posting::debit('1200', 15_000_00, 'Special assessment', unitId: (int) $unit->id),
                Posting::credit('4000', 15_000_00, 'Special assessment'),
            ],
            on: Carbon::now()->startOfMonth()->addDays(3)->toDateString(),
            source: Ledger::SOURCE_CHARGE,
            prefix: 'CHG',
        );

        app(Ledger::class)->reverse($mistake, 'Reversal — assessment belonged to another unit');
    }

    /**
     * INVOICES PAID AND OUTSTANDING, both from the ledger and neither hand-written.
     *
     * Every charge raises a two-sided journal — Dues Receivable debited, Maintenance Fee
     * Income credited, the unit named on the receivable line — exactly as a real month's
     * billing does, so the balance the Resident App shows is the ledger read back rather than
     * a figure kept beside it. Posted once: journals are append-only, and the charge's own
     * unique reference is what stops a re-run billing the same month twice.
     *
     * @param  array<string, Unit>  $units
     */
    private function dues(array $units): void
    {
        $month = Carbon::now()->startOfMonth();
        $last = $month->copy()->subMonth();
        $dues = app(Dues::class);

        foreach ($units as $reference => $unit) {
            foreach ([$last, $month] as $period) {
                $charge = Charge::query()->updateOrCreate(
                    ['reference' => 'DEMO-'.$period->format('Ym').'-'.str_replace(' ', '', $reference)],
                    [
                        'unit_id' => $unit->id,
                        'type' => 'dues',
                        'period' => $period->format('Y-m'),
                        'description' => 'Monthly maintenance — '.$period->format('F Y'),
                        'amount_minor' => 18_500_00,
                        'currency' => 'JMD',
                        'due_on' => $period->copy()->addDays(9)->toDateString(),
                        'status' => 'outstanding',
                    ],
                );

                $posted = Journal::query()->where('source', Ledger::SOURCE_CHARGE)->where('source_id', $charge->id)->exists();

                if (! $posted) {
                    app(Ledger::class)->post(
                        memo: 'Monthly maintenance — '.$unit->reference,
                        postings: [
                            Posting::debit('1200', (int) $charge->amount_minor, 'Monthly maintenance', unitId: (int) $unit->id),
                            Posting::credit('4000', (int) $charge->amount_minor, 'Monthly maintenance'),
                        ],
                        on: (string) $charge->due_on,
                        source: Ledger::SOURCE_CHARGE,
                        sourceId: (int) $charge->id,
                        prefix: 'CHG',
                    );
                }

                // LAST month is settled, THIS month is not: a reviewer opening Dues sees both
                // a receipt they can read and a balance the app offers to pay.
                if ($period->equalTo($last) && ! Payment::query()->where('reference', 'Demo settlement '.$charge->reference)->exists()) {
                    /*
                     * Received through `Dues::receive` — the estate console's own service —
                     * so the receipt number comes out of this estate's sequence and the
                     * journal is posted the way a real receipt's is. Nothing here writes a
                     * receipt number or a balance by hand.
                     */
                    $dues->receive(
                        unit: $unit,
                        amount: Money::ofMinor(18_500_00, 'JMD'),
                        method: Payment::BANK,
                        receivedAt: $period->copy()->addDays(6),
                        reference: 'Demo settlement '.$charge->reference,
                    );

                    $charge->forceFill(['status' => 'paid'])->save();
                }
            }
        }
    }

    /**
     * VISITOR PASSES IN THREE STATES — active, used and cancelled — all properly signed.
     *
     * THE ACTIVE ONE IS RE-ISSUED WHENEVER IT HAS RUN OUT, and that is the point of this method.
     *
     * A pass's window is SIGNED INTO its token, so the window cannot be stretched afterwards without
     * re-signing — which means a pass seeded once is a pass that expires, and the day after it does,
     * the Resident App's "Expected today", the Visitors tab's Expected list and the officer's "who is
     * Lot 1 expecting" are all empty. That is precisely the state M5 §3 exists to prevent, on three
     * screens at once, and it arrives the morning after the estate is seeded — which is usually before
     * a reviewer has opened anything. (Ask this command for the pictures in `store/screenshots/` and it
     * shows up at once: the harness cannot photograph a visitor who is no longer expected.)
     *
     * So a fresh pass is issued whenever there is no active one still covering the next two hours, and
     * an expired one is simply left where it is — in History, which is where a used-up pass belongs. The
     * used and the cancelled ones are seeded once and stay: they are history by definition, and a
     * delivery that came yesterday reads correctly however long ago yesterday was.
     *
     * Nothing here writes a pass by hand. Every one goes through `VisitorPasses`, the same service the
     * Resident App calls, so each is signed with the estate's own key and can be verified at the gate
     * with no signal — which is what makes the Guard App's pass check demonstrable at all.
     */
    private function passes(Tenant $estate, Unit $unit): void
    {
        $passes = app(VisitorPasses::class);
        $resident = Resident::query()->where('household_id', Household::query()->where('unit_id', $unit->id)->value('id'))->first();
        $name = (string) ($resident->full_name ?? 'Demonstration resident');
        $site = (string) $estate->getTenantKey();
        $now = Carbon::now();

        // Two hours of head-room, so a pass does not expire between the seed and the reviewer's look.
        $stillExpected = VisitorPass::query()->where('unit_id', $unit->id)
            ->where('status', VisitorPass::ACTIVE)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>=', $now->copy()->addHours(2))
            ->exists();

        if (! $stillExpected) {
            $passes->issue($site, $unit, $resident?->id, $name, [
                'category' => 'single', 'visitor_name' => 'Marcia Grant (demonstration visitor)', 'purpose' => 'Family visit',
                'valid_from' => $now->copy()->subHour(), 'valid_to' => $now->copy()->addHours(8),
            ]);
        }

        if (! VisitorPass::query()->where('unit_id', $unit->id)->where('status', VisitorPass::USED)->exists()) {
            $used = $passes->issue($site, $unit, $resident?->id, $name, [
                'category' => 'delivery', 'visitor_name' => 'Island Courier (demonstration delivery)', 'purpose' => 'Parcel',
                'valid_from' => $now->copy()->subDay(), 'valid_to' => $now->copy()->subDay()->addHours(4),
            ]);
            $passes->consume($used, $now->copy()->subDay()->addHour());
        }

        if (! VisitorPass::query()->where('unit_id', $unit->id)->where('status', VisitorPass::CANCELLED)->exists()) {
            $cancelled = $passes->issue($site, $unit, $resident?->id, $name, [
                'category' => 'contractor', 'visitor_name' => 'Blue Mountain Plumbing (demonstration contractor)', 'purpose' => 'Kitchen tap',
                'valid_from' => $now->copy()->addDay(), 'valid_to' => $now->copy()->addDay()->addHours(6),
            ]);
            $passes->cancel($cancelled);
        }
    }

    /** Notices, a meeting and a maintenance ticket the reviewer can open and follow. */
    private function community(Unit $unit, Guard $colleague): void
    {
        foreach ([
            [Notice::URGENT, 'Water lock-off Thursday (demonstration notice)', 'The National Water Commission is working on the main line from 10 AM to 2 PM on Thursday. Nothing on this estate is real — this is demonstration data.'],
            [Notice::GENERAL, 'Gate hours over the holiday (demonstration notice)', 'The pedestrian gate closes at 9 PM from Friday. Nothing on this estate is real — this is demonstration data.'],
        ] as $i => [$kind, $title, $body]) {
            if (Notice::query()->where('title', $title)->exists()) {
                continue;
            }

            $notice = new Notice;
            $notice->forceFill([
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'audience_scope' => Notice::ESTATE_WIDE,
                'author_name' => 'Demonstration Estate Secretary',
                'published_at' => Carbon::now()->subDays($i + 1),
            ])->save();
        }

        /*
         * THE AGM, REWRITTEN ON EVERY RUN rather than written once.
         *
         * Two reasons, and they are the same two as the worked watch above. A meeting seeded once is in
         * the past within three weeks, and the Resident App's home screen and Meetings list stop showing
         * it. And a row written once keeps whatever it was written with: the one on this laptop predates
         * `Meeting::starts_at` carrying the `SiteDateTime` cast (M4 §3.1), so 6 PM had gone in as 18:00
         * UTC and the app — correctly, from what it was given — drew an annual general meeting at 1 PM.
         * Re-seeding could not correct it, because nothing re-seeded it.
         *
         * 6 PM ON THE ESTATE'S CLOCK. The wall-clock hour is built in the site's zone and the cast stores
         * the moment it means; nothing here knows an offset.
         */
        $agm = Meeting::query()->where('title', 'like', 'Annual General Meeting (demonstration%')->first() ?? new Meeting;

        $agm->forceFill([
            'type' => Meeting::AGM,
            'title' => 'Annual General Meeting (demonstration meeting)',
            'starts_at' => SiteTime::now()->addDays(21)->setTime(18, 0),
            'venue' => 'Demonstration Club House',
            'audience_scope' => Meeting::WHOLE_ESTATE,
            'status' => Meeting::SCHEDULED,
            'published_at' => Carbon::now()->subDays(2),
        ])->save();

        if (! MaintenanceTicket::query()->where('title', 'like', 'Street light out%')->exists()) {
            $next = (int) (MaintenanceTicket::query()->max('number') ?? 1000) + 1;

            MaintenanceTicket::query()->create([
                'number' => $next,
                'title' => 'Street light out by the demonstration gate',
                'location_label' => 'Demonstration Way, by the gate',
                'unit_id' => $unit->id,
                'category' => 'electrical',
                'description' => 'The light nearest the gatehouse has been out since the weekend. Demonstration data.',
                'priority' => 'low',
                'status' => 'in_progress',
                'sla_hours' => 72,
                'reported_by_name' => (string) $colleague->full_name,
                'reported_at' => Carbon::now()->subDays(3),
            ]);
        }
    }

    /**
     * AN OPEN ELECTION, opened through `Governance::open` — the same service the estate's own
     * console uses — so what the Resident App sees is a poll the estate's rules opened, with
     * the real eligibility and the real household count behind it.
     */
    private function election(): void
    {
        $open = Ballot::query()->where('title', 'like', self::ELECTION.'%')->where('stage', Ballot::VOTING_OPEN)->exists();

        if ($open) {
            return;
        }

        $ballot = Ballot::query()->create([
            'year' => (int) Carbon::now()->year,
            // Null: `code` is the "A"/"B" of a real year's ballots and is unique per year. A
            // demonstration poll must not take a letter a real one would need.
            'code' => null,
            'title' => self::ELECTION.Carbon::now()->format('Y'),
            'kind' => Ballot::ELECTION,
            'description' => 'A demonstration election so the Resident App\'s voting can be seen working. Not a real poll, and none of these people exist.',
            'stage' => Ballot::CAMPAIGN_PERIOD,
            'quorum_percent' => 1,
        ]);

        $order = 0;

        foreach ([
            'Chairperson (demonstration seat)' => ['Althea Rowe (not a real person)', 'Dwight Campbell (not a real person)'],
            'Treasurer (demonstration seat)' => ['Nadine Sinclair (not a real person)', 'Everton Bailey (not a real person)'],
        ] as $seat => $candidates) {
            $position = BallotPosition::query()->create([
                'ballot_id' => $ballot->id,
                'name' => $seat,
                'seat_count' => 1,
                'scope' => BallotPosition::ESTATE,
                'sort_order' => ++$order,
            ]);

            foreach ($candidates as $i => $label) {
                BallotOption::query()->create([
                    'ballot_id' => $ballot->id,
                    'ballot_position_id' => $position->id,
                    'label' => $label,
                    'sort_order' => $i + 1,
                ]);
            }
        }

        // Open for a fortnight: long enough that a reviewer picking the app up a week after
        // the seed still finds a poll to vote in.
        app(Governance::class)->open($ballot, Carbon::now()->addDays(14));
    }

    private const ELECTION = 'Committee election (demonstration poll) ';

    /**
     * THE HOUSEHOLD'S VEHICLES AND ITS EMERGENCY CONTACTS (M6 §3.3).
     *
     * Two of the four screens M6 found honestly empty. The Resident App's Profile tab draws both as
     * cards of their own — `vehicles.index` and `contacts.index`, and again inside `me.household` —
     * and with nothing in them the tab a reviewer taps FIFTH says "No vehicles on your household's
     * list yet" and "Nobody yet". Each empty state is true and well written, and four of them
     * together read as a product that was never finished. So the demonstration household has what a
     * real one has: the cars that come through the barrier, and somebody the office can telephone.
     *
     * WRITTEN THROUGH THE QUERY BUILDER, like the estate's own settings above, because neither table
     * has a model — `MeController` reads and writes them the same way, so this is the same shape the
     * app's own writes take.
     *
     * IDEMPOTENT ON WHAT THE SERVER ITSELF TREATS AS THE IDENTITY. A vehicle is its household and its
     * PLATE (the table's own unique key, and what `vehicles.store` matches an existing car on); a
     * contact is its household and the person's NAME. Re-running updates the row rather than making a
     * second one, so `demo:seed` before every submission leaves exactly these three vehicles and two
     * contacts however many times it is run.
     *
     * NOTHING HERE IS SHOWN TO A GUARD. An emergency contact is the household's, and the endpoint that
     * serves it is a resident capability; the plate is the one thing on this screen an officer ever
     * sees, and they see it at the barrier on a pass, never on this list.
     */
    private function householdRecords(int $householdId): void
    {
        $now = Carbon::now();

        /*
         * Invented plates that could not be mistaken for a real Jamaican registration — the format is
         * plausible, the letters say DEMO. Every other name on this estate carries "(demonstration)" in
         * words; a plate has sixteen characters and no room for a sentence, so it says it in the mark.
         */
        foreach ([
            ['DEMO 1201', 'Toyota', 'Corolla', 'Silver'],
            ['DEMO 1202', 'Honda', 'CR-V', 'White'],
            ['DEMO 1203', 'Suzuki', 'Swift', 'Dark blue'],
        ] as [$plate, $make, $model, $colour]) {
            DB::connection('tenant')->table('household_vehicles')->updateOrInsert(
                ['household_id' => $householdId, 'plate' => $plate],
                [
                    'make' => $make,
                    'model' => $model,
                    'colour' => $colour,
                    'added_by_account_id' => null,
                    // FALSE, like every other row this command writes. `is_simulated` marks a record made
                    // by a drill or a test harness, which the console filters out of its real figures.
                    // The demonstration estate is not a drill: inside it, this IS the estate's data.
                    'is_simulated' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach ([
            ['Dahlia Rowe (not a real person)', 'Sister', '876 555 0101'],
            ['Dr. Lloyd Phipps (not a real person)', 'Family doctor', '876 555 0102'],
        ] as [$name, $relationship, $phone]) {
            DB::connection('tenant')->table('household_emergency_contacts')->updateOrInsert(
                ['household_id' => $householdId, 'name' => $name],
                [
                    'relationship' => $relationship,
                    'phone' => $phone,
                    'added_by_account_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /* -----------------------------------------------------------------
     | The central half: shifts, patrol, the gate log, an incident, a payslip
     |------------------------------------------------------------------ */

    /** @param  array{reviewer: Guard, colleague: Guard}  $guards */
    private function centralRecords(Tenant $estate, Post $post, array $guards): void
    {
        $now = Carbon::now();
        $site = (string) $estate->getTenantKey();

        /*
         * A SHIFT IN PROGRESS — the colleague's, not the reviewer's.
         *
         * The reviewer has to be able to CLOCK IN, and `/shifts/me/current` answers a running
         * shift before a rostered one: seeding the reviewer on duty would hide the clock-in
         * screen the whole geofence bypass exists for. So somebody else is on duty, which is
         * also what puts a colleague on the dashboard and a manned post on the map.
         */
        $running = Shift::query()->where('guard_id', $guards['colleague']->id)->whereNotNull('actual_start')->whereNull('actual_end')->first();

        if ($running === null) {
            Shift::query()->create([
                'tenant_id' => $site,
                'guard_id' => $guards['colleague']->id,
                'post_id' => $post->id,
                'rostered_start' => $now->copy()->subHours(3),
                'rostered_end' => $now->copy()->addHours(9),
                'actual_start' => $now->copy()->subHours(3)->addMinutes(2),
                'start_method' => 'app',
                'status' => 'on_duty',
            ]);
        }

        /*
         * THE REVIEWER'S OWN SHIFT, rostered and NOT clocked in, refreshed on every run so
         * the walk in the review notes can be done again tomorrow.
         */
        Shift::query()->where('guard_id', $guards['reviewer']->id)->whereNotNull('actual_start')->whereNull('actual_end')
            ->get()->each(fn (Shift $s) => $s->forceFill(['actual_end' => $now, 'status' => 'completed'])->save());

        Shift::query()->where('guard_id', $guards['reviewer']->id)->whereNull('actual_start')->where('rostered_end', '>=', $now)->delete();

        /*
         * A FORTNIGHT OF WATCHES, NOT ONE SHIFT — and this is the difference between a review
         * that works and one that does not.
         *
         * The clock-in walk is the first thing `STORE_REVIEW_NOTES.md` asks a reviewer to do, and
         * it is the whole reason the geofence bypass exists. It needs a shift that is (a) rostered
         * to the reviewer, (b) not yet started, and (c) inside its clock-in window, which opens an
         * hour before the rostered start and closes at the rostered end. A single 12-hour shift
         * seeded at submission time satisfies all three for twelve hours and then satisfies none of
         * them: `shifts.me.current` answers null, the dashboard says there is no shift, and the
         * clock-in screen cannot be reached at all. Apple's first look is routinely a day or two
         * after submission, Google's later than that, and a re-review after a rejection later
         * still — so the one walk that matters most would be broken for nearly every reviewer who
         * ever ran it, and nobody here would see it happen.
         *
         * So the roster is CONTIGUOUS: twelve-hour watches, back to back, from the top of the
         * current hour for fourteen days. At every moment in that fortnight exactly one of them is
         * the earliest un-started shift whose end is still ahead, its window is open, and the walk
         * works. They start on the hour so the app draws "8:00 AM – 8:00 PM" rather than a ragged
         * minute, and they are deleted and rebuilt on every run, so re-seeding before a submission
         * simply moves the fortnight along.
         */
        $watchStart = SiteTime::local($now, $site)->startOfHour()->utc();

        for ($watch = 0; $watch < self::ROSTER_WATCHES; $watch++) {
            Shift::query()->create([
                'tenant_id' => $site,
                'guard_id' => $guards['reviewer']->id,
                'post_id' => $post->id,
                'rostered_start' => $watchStart->copy(),
                'rostered_end' => $watchStart->copy()->addHours(self::WATCH_HOURS),
                'status' => Shift::ROSTERED,
            ]);

            $watchStart = $watchStart->copy()->addHours(self::WATCH_HOURS);
        }

        /*
         * A SHIFT THE REVIEWER ALREADY WORKED, so My Hours is not empty — REWRITTEN on every run,
         * exactly like the fortnight of watches above, and for the two reasons that go together.
         *
         * My Hours is ONE Monday-to-Sunday week at a time. A worked shift written once, at whatever
         * moment the estate was first provisioned, walks out of the current week within days and the
         * screen goes back to being the empty list this whole command exists to prevent. And a row
         * written once keeps whatever it was written with: the one on this laptop was seeded before
         * `Shift` carried the `SiteDateTime` cast (M4 §3.1), had been holding 06:00 UTC ever since,
         * and My Hours honestly drew it as a watch from 1 AM to 1 PM. Nothing re-seeded it, so nothing
         * could correct it.
         *
         * WHICH ROW IS THIS COMMAND'S. The reviewer's own completed watches at the demonstration post
         * that ended BEFORE the site's today — which is every one this command wrote on an earlier run,
         * and never a watch the reviewer clocked in and out of while looking at the app, because that
         * one ends today. The first is rewritten and any others are dropped: two overlapping watches on
         * one day is not a demonstration of anything. Idempotent — one worked watch, however many times
         * this is run, and it moves with the calendar.
         *
         * YESTERDAY'S 6 AM – 6 PM, ON THE ESTATE'S CLOCK. The wall-clock hours are built in the site's
         * zone; the cast stores the moment they mean, in UTC, and the app draws them back in the site's
         * zone. Nothing here knows a number of hours' offset.
         */
        $seeded = Shift::query()
            ->where('guard_id', $guards['reviewer']->id)
            ->where('post_id', $post->id)
            ->where('status', 'completed')
            ->whereNotNull('actual_end')
            ->where('actual_end', '<', SiteTime::today($site)->utc())
            ->orderBy('id')
            ->get();

        $worked = $seeded->first() ?? new Shift;
        $seeded->skip(1)->each(fn (Shift $s) => $s->delete());

        $worked->forceFill([
            'tenant_id' => $site,
            'guard_id' => $guards['reviewer']->id,
            'post_id' => $post->id,
            'rostered_start' => SiteTime::today($site)->subDay()->setTime(6, 0),
            'rostered_end' => SiteTime::today($site)->subDay()->setTime(18, 0),
            'actual_start' => SiteTime::today($site)->subDay()->setTime(5, 58),
            'actual_end' => SiteTime::today($site)->subDay()->setTime(18, 4),
            'start_method' => 'app',
            'status' => 'completed',
            'handover_note' => self::HANDOVER,
        ])->save();

        /*
         * AN OPEN SHIFT NOBODY IS ROSTERED TO. Without one, "Open shifts" — a whole screen the
         * Guard App's More menu offers — is an empty list on the demonstration estate, and an
         * empty list with no explanation is what Guideline 4.2 is about. One is enough: it is the
         * screen working, and claiming it is a write the reviewer can actually carry through.
         */
        $open = Shift::query()->whereNull('guard_id')->where('post_id', $post->id)->where('rostered_start', '>=', $now)->exists();

        if (! $open) {
            Shift::query()->create([
                'tenant_id' => $site,
                'guard_id' => null,
                'post_id' => $post->id,
                'rostered_start' => SiteTime::today($site)->addDay()->setTime(18, 0),
                'rostered_end' => SiteTime::today($site)->addDays(2)->setTime(6, 0),
                'status' => Shift::OPEN,
            ]);
        }

        $this->patrol($site, $post, $guards['reviewer']);
        $this->gateLog($site, $post, $guards['colleague'], $guards['reviewer']);
        $this->incident(
            $site,
            $guards['colleague'],
            'Gate barrier arm struck by a reversing van. No injuries. Demonstration data.',
            'Barrier arm replaced by the contractor on the following morning. Closed.',
        );

        // The reviewer's OWN, so "My reports" is not an empty list on the account they sign in as.
        $this->incident(
            $site,
            $guards['reviewer'],
            'Perimeter light out at the rear fence, reported to the estate office. Demonstration data.',
            'Bulb replaced by the estate\'s electrician the next day. Closed.',
        );
        $this->payslip($guards['reviewer']);
        $this->standingOrders($site, $post);
        $this->dispatchMessages($site);
        $this->requests($site, $guards['reviewer']);
    }

    /**
     * WHAT THE OFFICER HAS ASKED THE OFFICE FOR — equipment, and leave (M6 §3.3).
     *
     * The other two screens M6 found honestly empty, and they are BOTH fed by `requests.index`, which
     * answers the CALLER's own requests and nobody else's — so a request filed against the colleague
     * would leave both of the reviewer's lists exactly as empty as they were. These are the reviewer's.
     *
     *   /equipment   board guard-app-09 · "Recent requests" under the form
     *   /leave       board guard-app-10 · the list under the leave form, AND the balance above it
     *
     * EVERY DECISION A DIFFERENT ONE, because a list where every chip says the same word demonstrates
     * one state, not a screen: approved with the storekeeper's note, still pending, and one refused
     * with the reason a real supervisor would give. The refusal matters most — it is the only place in
     * either app where a reviewer sees the product tell somebody no, and say why.
     *
     * THE BALANCE IS ARITHMETIC, NOT A FIGURE. `requests.index` counts the guard's APPROVED leave days
     * whose `starts_on` falls in the current year and subtracts them from the entitlement seeded on the
     * account, so the four approved days below are what make the leave screen read "14 · 4 · 10" rather
     * than an entitlement above an empty list. That is also why `withinYear()` exists: seeded relative
     * to today, a run in the first weeks of January would place last month's leave in LAST year, the
     * count would be nil and the screen would go back to looking unused.
     *
     * DATES, NOT INSTANTS. `starts_on` and `ends_on` are calendar days — the day a person would say —
     * so they are taken from the SITE's today (M4 §3.1), never from the server's UTC date. `decided_at`
     * IS an instant and is stored in UTC like every other one.
     */
    private function requests(string $site, Guard $guard): void
    {
        $today = SiteTime::today($site);

        foreach ([
            [
                'Torch', 1, 'approved', 26,
                'The torch issued to me no longer holds charge for a full watch.',
                'Approved. Collect the replacement from stores at the start of your next watch.', 24,
            ],
            [
                'Raincoat', 1, 'pending', 5,
                'The one I was issued is torn at the shoulder and the wet season has started.',
                null, null,
            ],
            [
                'Radio', 2, 'denied', 12,
                'A second handset for the pedestrian gate when two officers are on.',
                'Two handsets are already signed out to this post. Ask your supervisor to check the spare before a third is issued.', 10,
            ],
        ] as [$item, $quantity, $status, $askedDaysAgo, $reason, $note, $decidedDaysAgo]) {
            $this->request($site, $guard, [
                'kind' => GuardRequest::EQUIPMENT,
                'subject' => $item,
                'quantity' => $quantity,
                'starts_on' => null,
                'ends_on' => null,
                'reason' => $reason,
                'certificate_attached' => false,
                'status' => $status,
                'note' => $note,
                'asked' => $askedDaysAgo,
                'decided' => $decidedDaysAgo,
            ]);
        }

        foreach ([
            [
                'vacation', $this->withinYear($today->copy()->subDays(38), $today, 20), 3, 'approved', 60,
                'Family coming in from Portland for the weekend.',
                'Approved. Officer Blake covers the watch.', 55, false,
            ],
            [
                'sick', $this->withinYear($today->copy()->subDays(12), $today, 40), 1, 'approved', 13,
                'Stomach bug. Certificate handed in at the office.',
                'Approved. Certificate seen at the office.', 11, true,
            ],
            [
                'vacation', $today->copy()->addDays(24), 5, 'pending', 3,
                'Taking my mother to Mandeville for the week.',
                null, null, false,
            ],
        ] as [$type, $from, $days, $status, $askedDaysAgo, $reason, $note, $decidedDaysAgo, $certificate]) {
            $this->request($site, $guard, [
                'kind' => GuardRequest::LEAVE,
                'subject' => $type,
                'quantity' => null,
                'starts_on' => $from->toDateString(),
                'ends_on' => $from->copy()->addDays($days - 1)->toDateString(),
                'reason' => $reason,
                'certificate_attached' => $certificate,
                'status' => $status,
                'note' => $note,
                'asked' => $askedDaysAgo,
                'decided' => $decidedDaysAgo,
            ]);
        }
    }

    /**
     * One request, written so a re-run refreshes it rather than adding a second.
     *
     * The identity is the guard, the kind, the subject and the first day — which is what tells two
     * vacation requests apart and is null on an equipment one (Laravel reads a null there as IS NULL,
     * not `= NULL`, so the match works). `created_at` and `decided_at` are re-stamped on every run and
     * that is deliberate: STORE_REVIEW_NOTES.md says to re-run this before every submission so the
     * data is fresh, and "Requested 26 days ago" is only true if it moves with the calendar.
     *
     * `forceFill`, because `is_simulated` is not mass-assignable and neither is the timestamp pair.
     *
     * @param  array<string, mixed>  $spec
     */
    private function request(string $site, Guard $guard, array $spec): void
    {
        $row = GuardRequest::query()->firstOrNew([
            'guard_id' => $guard->id,
            'kind' => $spec['kind'],
            'subject' => $spec['subject'],
            'starts_on' => $spec['starts_on'],
        ]);

        $asked = Carbon::now()->subDays((int) $spec['asked']);

        $row->forceFill([
            'tenant_id' => $site,
            'guard_id' => $guard->id,
            'kind' => $spec['kind'],
            'subject' => $spec['subject'],
            'quantity' => $spec['quantity'],
            'starts_on' => $spec['starts_on'],
            'ends_on' => $spec['ends_on'],
            'reason' => $spec['reason'],
            'certificate_attached' => (bool) $spec['certificate_attached'],
            'status' => $spec['status'],
            'decided_by' => null,
            'decided_at' => $spec['decided'] === null ? null : Carbon::now()->subDays((int) $spec['decided']),
            'decision_note' => $spec['note'],
            'is_simulated' => false,
            'created_at' => $asked,
            'updated_at' => Carbon::now(),
        ])->save();
    }

    /**
     * A day that certainly falls in the site's CURRENT year.
     *
     * `requests.index` counts approved leave BY YEAR. A day seeded relative to today drops into last
     * year for as long as the seed is run near New Year, and the leave balance would quietly read nil
     * with the list still full — which is worse than an empty screen, because it looks decided.
     */
    private function withinYear(Carbon $day, Carbon $today, int $dayOfYear): Carbon
    {
        return $day->year === $today->year ? $day : $today->copy()->startOfYear()->addDays($dayOfYear);
    }

    /**
     * STANDING ORDERS FOR THE DEMONSTRATION GATE.
     *
     * Two screens are empty without them — the orders screen itself, and the "orders to
     * acknowledge" row on the Guard App's home dashboard, which reads zero and so draws nothing
     * at all. Acknowledging it is a write a reviewer can carry through end to end, which is worth
     * more than another read-only list.
     *
     * Post-specific rather than company-wide: a company-wide set carries no estate and would be
     * put in front of every real guard on the platform. Nothing this command writes may leave the
     * demonstration estate.
     */
    private function standingOrders(string $site, Post $post): void
    {
        DB::connection('mysql')->table('standing_order_sets')->updateOrInsert(
            ['tenant_id' => $site, 'post_id' => $post->id, 'title' => 'Demonstration gate orders'],
            [
                'category' => 'post_specific',
                'summary' => 'How this gate is worked — demonstration data, for a gate that does not exist.',
                'version' => 1,
                'body' => "1. The gate is manned at all times. Do not leave the post unattended.\n".
                    "2. Every visitor is checked against a pass or the household is called. No pass and no answer means no entry.\n".
                    "3. Log every entry and every refusal as it happens, not at the end of the shift.\n".
                    "4. The barrier is raised by hand only when the control room says so.\n".
                    '5. This is demonstration data for an estate that does not exist, and nobody works to these orders.',
                'effective_on' => Carbon::now()->subMonths(2)->toDateString(),
                'reviewed_on' => Carbon::now()->subWeeks(3)->toDateString(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }

    /**
     * TWO BROADCASTS FROM THE CONTROL ROOM, so the Messages TAB is not empty.
     *
     * Messages is one of the Guard App's five tabs. A reviewer taps all five, and a tab that
     * opens on nothing is the "minimum functionality" rejection in its plainest form. Broadcasts
     * rather than a thread: `DispatchMessage` has two directions only — a broadcast to an estate,
     * and a guard writing back — so the control room's side of a conversation IS a broadcast, and
     * replying to it is a write the reviewer can carry through.
     */
    private function dispatchMessages(string $site): void
    {
        foreach ([
            ['Shift change at 6 PM. Hand over the gate log and the barrier key to the officer relieving you. (Demonstration message.)', 95],
            ['Contractor van expected at the main gate this afternoon — Blue Mountain Plumbing, Lot 1. Check the pass before you raise the barrier. (Demonstration message.)', 40],
        ] as [$body, $minutesAgo]) {
            DispatchMessage::query()->firstOrCreate(
                ['tenant_id' => $site, 'direction' => DispatchMessage::BROADCAST, 'body' => $body],
                ['guard_id' => null, 'sent_by' => null, 'sent_at' => Carbon::now()->subMinutes($minutesAgo)],
            );
        }
    }

    /** A COMPLETED PATROL: four checkpoints on the tour, each scanned in sequence last night. */
    private function patrol(string $site, Post $post, Guard $guard): void
    {
        $labels = ['Gatehouse', 'Pump house', 'Phase 2 playground', 'Rear perimeter'];
        $checkpoints = [];

        foreach ($labels as $i => $label) {
            $checkpoints[] = PatrolCheckpoint::query()->updateOrCreate(
                ['tenant_id' => $site, 'code' => 'DEMO-CP-'.($i + 1)],
                ['post_id' => $post->id, 'label' => $label.' (demonstration checkpoint)', 'sequence' => $i + 1, 'is_active' => true],
            );
        }

        /*
         * 5 AM THIS MORNING AT THE ESTATE — the last round of the night watch, before handover.
         *
         * TODAY, and that is the change that makes the screen show anything. The Patrol tab counts THIS
         * DAY's tour ("0 of 4 checkpoints · No scans yet today"): a round seeded for last night leaves
         * four named checkpoints and no round at all, on the screen whose whole subject is a round being
         * walked. Because a scan is found again by its idempotency key and rewritten, every re-seed simply
         * moves the round to that morning.
         *
         * Re-seeded before 5 AM the round would be in the FUTURE, which no scan may be, so it falls back
         * to yesterday morning — the same round, one day older, and still true.
         *
         * M4 §3.1: a scan's own columns are plain instants, so the wall-clock hour is built in the site's
         * zone and moved to UTC to be stored. Nothing here knows an offset.
         */
        $round = SiteTime::today($site)->setTime(5, 0);

        if ($round->greaterThan(SiteTime::now($site))) {
            $round = $round->subDay();
        }

        $at = $round->utc();

        foreach ($checkpoints as $i => $checkpoint) {
            $when = $at->copy()->addMinutes($i * PatrolCheckpoint::CADENCE_MINUTES);

            CheckpointScan::query()->updateOrCreate(
                ['patrol_checkpoint_id' => $checkpoint->id, 'guard_id' => $guard->id, 'idempotency_key' => 'demo-patrol-'.$checkpoint->code],
                ['device_time' => $when, 'server_time' => $when, 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false],
            );
        }
    }

    /**
     * A DAY AT THE GATE: admitted, admitted, and one turned away — plus one the REVIEWER recorded.
     *
     * Append-only, and that is the whole shape of this method. A gate event is never updated and never
     * deleted (invariant: it is the security record of who was let into a private community), so there
     * is no way to move yesterday's log forward. It is therefore keyed BY THE SITE'S DAY: re-running on
     * the same day writes nothing, and re-running on a later day writes that day's watch.
     *
     * That matters because the screen is called "TODAY'S ACTIVITY" and counts today. Seeded once, at
     * provisioning, the whole gate log reads zero from the following morning — and `STORE_REVIEW_NOTES.md`
     * tells whoever operates the platform to re-run this command before every submission precisely so the
     * demonstration is fresh. Keyed by the day, that instruction now does what it says.
     *
     * ONE OF THE FOUR IS THE REVIEWER'S OWN. `gate.activity` opens on "YOUR ENTRIES TODAY" — what THIS
     * officer recorded — with everybody else's behind a control. Three events by the colleague leave the
     * screen a reviewer actually lands on empty, under an estate total that says three things happened.
     * The same reasoning as `incident()`: a screen showing an officer's own work has to have some.
     */
    private function gateLog(string $site, Post $post, Guard $colleague, Guard $reviewer): void
    {
        $day = SiteTime::today($site)->toDateString();

        foreach ([
            [$colleague, GateEvent::ADMIT, 'guest', 'Marcia Grant (demonstration visitor)', 'Pass verified', 95],
            [$colleague, GateEvent::ADMIT, 'delivery', 'Island Courier (demonstration delivery)', 'Pass verified', 60],
            [$colleague, GateEvent::DENY, 'guest', 'Unknown caller (demonstration)', 'No pass and the household did not answer', 30],
            [$reviewer, GateEvent::ADMIT, 'contractor', 'Blue Mountain Plumbing (demonstration contractor)', 'Pass verified · Lot 1 called and confirmed', 20],
        ] as $i => [$officer, $verdict, $category, $subject, $basis, $minutesAgo]) {
            $key = 'demo-gate-'.$day.'-'.$i;

            if (GateEvent::query()->where('idempotency_key', $key)->exists()) {
                continue;
            }

            GateEvent::query()->create([
                'tenant_id' => $site,
                'guard_id' => $officer->id,
                'guard_name' => $officer->full_name,
                'post_id' => $post->id,
                'post_name' => $post->name,
                'verdict' => $verdict,
                'category' => $category,
                'subject' => $subject,
                'basis' => $basis,
                'occurred_at' => Carbon::now()->subMinutes($minutesAgo),
                'idempotency_key' => $key,
                'is_simulated' => false,
            ]);
        }
    }

    /**
     * A RESOLVED INCIDENT, so the incident screens have a closed one to read.
     *
     * ONE FOR EACH OFFICER, AND THE REVIEWER'S IS THE POINT. `incidents.me` answers the CALLER's
     * own reports and nobody else's, so an incident filed by the colleague leaves the Guard App's
     * "My reports" screen empty for the reviewer — the empty list M5 §3 exists to prevent, on the
     * screen that shows what an officer's own work looks like.
     */
    private function incident(string $site, Guard $guard, string $detail, string $resolution): void
    {
        if (SecurityIncident::query()->where('tenant_id', $site)->where('detail', $detail)->exists()) {
            return;
        }

        SecurityIncident::query()->create([
            'tenant_id' => $site,
            'guard_id' => $guard->id,
            'guard_name' => $guard->full_name,
            'kind' => 'property_damage',
            'detail' => $detail,
            'severity' => 'low',
            'status' => SecurityIncident::RESOLVED,
            'occurred_at' => Carbon::now()->subDays(4),
            'resolution' => $resolution,
            'closed_at' => Carbon::now()->subDays(3),
            'logged_by_name' => 'Demonstration Control Room',
        ]);
    }

    /**
     * ONE APPROVED PAYSLIP for the reviewer's guard account.
     *
     * The figures come from the REAL `PayrollCalculator` against the current statutory rate
     * version — the demonstration estate is not a place to invent a net pay, and a slip whose
     * deductions did not add up would be the first thing a reviewer noticed.
     */
    private function payslip(Guard $guard): void
    {
        $end = Carbon::now()->subWeek()->endOfWeek();
        $start = $end->copy()->subDays(13);

        /*
         * THE CARD IN FORCE ON THE PAY DATE (PAY-B-08, M85 A1), never the newest card. The newest was TAJ 2027/28,
         * which carries an annual threshold only, so a 2026 fortnight was taxed above 2,003,496.00 / 26 = 77,057.53
         * instead of TAJ's published 73,234.90: PAYE 1,105.62 where 2,061.28 was owed.
         */
        $rates = StatutoryRateVersion::inForceOn($end->toDateString());

        if ($rates === null) {
            Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
            $rates = StatutoryRateVersion::inForceOn($end->toDateString());
        }

        if ($rates === null) {
            $this->warn('No statutory rate version is in force on the pay date, so the demonstration payslip was not written.');

            return;
        }

        $run = PayrollRun::query()->firstOrNew(['reference' => 'DEMO-PR-'.$end->format('Ymd')]);

        // Never rewrite a settled run: invariant 4, and the database trigger refuses it anyway.
        if ($run->exists && in_array($run->status, ['approved', 'paid'], true)) {
            return;
        }

        $run->fill([
            'period_label' => $start->format('j M').' – '.$end->format('j M Y').' (demonstration)',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'periods_per_year' => 26,
            'statutory_rate_version_id' => $rates->id,
            'status' => 'calculated',
            'currency' => 'JMD',
        ])->save();

        $gross = 80 * (int) ($guard->standard_rate_minor ?? 1_050_00);
        $figures = app(PayrollCalculator::class)->payslip($gross, $rates, 26, (int) $guard->approved_pension_minor);
        $employer = app(PayrollCalculator::class)->employerCost($gross, $rates, 26, true, (int) $guard->approved_pension_minor);

        Payslip::query()->updateOrCreate(
            ['payroll_run_id' => $run->id, 'guard_id' => $guard->id],
            [
                'tenant_id' => $guard->tenant_id,
                'currency' => 'JMD',
                ...$figures,
                'employer_nis_minor' => $employer['nis_minor'],
                'employer_nht_minor' => $employer['nht_minor'],
                'employer_education_tax_minor' => $employer['education_tax_minor'],
                'employer_heart_minor' => $employer['heart_minor'],
            ],
        );

        // APPROVED, because `payslips.me` answers approved and paid runs only — a draft is a
        // calculation in progress, and a reviewer shown one would see an empty list instead.
        $run->forceFill([
            'gross_minor' => $figures['gross_minor'],
            'net_minor' => $figures['net_minor'],
            'status' => 'approved',
            'approved_at' => Carbon::now()->subDays(2),
        ])->save();
    }

    /* -----------------------------------------------------------------
     | The reviewer's Resident App account
     |------------------------------------------------------------------ */

    /** @param  array{unit: int, resident: int, name: string}  $home */
    private function reviewerAccount(Tenant $estate, array $home): ResidentAccount
    {
        $account = ResidentAccount::query()->updateOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL],
            [
                'full_name' => ReviewerMode::RESIDENT_NAME,
                'status' => ResidentAccount::ACTIVE,
                'resident_id' => $home['resident'],
                'unit_id' => $home['unit'],
                'verified_at' => Carbon::now(),
            ],
        );

        // The flag and the static code — `forceFill` for the same reason the guard's are.
        $account->forceFill([
            'reviewer' => true,
            'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::RESIDENT_CODE),
            'deleted_at' => null,
            'deleted_reference' => null,
        ])->save();

        return $account;
    }

    private function report(Tenant $estate, Guard $guard, ResidentAccount $account): void
    {
        $this->newLine();
        $this->info('The demonstration estate is ready. These are the credentials STORE_REVIEW_NOTES.md quotes.');
        $this->newLine();
        $this->table(['App', 'Signs in with', 'Code'], [
            ['Guard App', $guard->employee_number.' · '.$guard->full_name, ReviewerMode::GUARD_CODE.'  (static, never expires)'],
            ['Resident App', $account->destination.' · '.$estate->name, ReviewerMode::RESIDENT_CODE.'  (static, never expires)'],
            ['Resident App', '"Explore a demo community" — no account at all', 'read-only session'],
        ]);
        $this->line('  estate id      '.$estate->getTenantKey());
        $this->line('  database       '.$estate->database()->getName());
        $this->line('  every bypass   written to the audit log under `reviewer.*` each time it is used');
        $this->newLine();
    }
}
