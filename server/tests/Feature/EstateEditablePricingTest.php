<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Amenities;
use App\Services\Estate\ChargeSchedules;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The estate's own pricing, editable — M10 §3, the estate half
|--------------------------------------------------------------------------
|
| "The estate's Community Super Admin can edit the estate's own pricing: its fee
| schedules and dues rates, with the same rule that issued invoices are not
| retroactively rewritten. Both screens show clearly what a change will affect
| and from when."
|
| THE RULE UNDER TEST IS THE SECOND CLAUSE. A dues rate changes FROM A MONTH NOT
| YET BILLED, and every charge, run and journal line of a month already posted
| is read back after the change and compared with what it said before — by the
| figures themselves, not by the absence of an error. An amenity fee changes for
| the NEXT booking made, and a booking taken before the edit keeps its terms.
|
| THIS FILE'S OWN GROUND. The estate database is committed and shared by every
| file that boots the fixture, and a dues run bills real lots. So the schedule
| here is scoped to a block of this file's own — three lots in Tamarind Close,
| which no other file uses — every month it posts is reversed afterwards, and its
| schedule is taken away. The amenity is its own too, retired at the end.
|
*/

const PRICING_SCHEDULE = 'Pricing Test Maintenance Fee';
const PRICING_BLOCK = 'Tamarind Close';
const PRICING_LOTS = ['Lot 951', 'Lot 952', 'Lot 953'];
const PRICING_AMENITY = 'Pricing Test Court';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    /*
     * THE MATRIX, ON THE CELL THIS FILE NEEDS. `FacilitiesFixture::platform()`
     * re-seeds only when one of the cells it checks is missing, and a test
     * database seeded before M10 §3 has every one of those already — with the
     * Community Super Admin still on plain Full for Dues & ledger. Every
     * assertion below would then fail as a 403 that read like a broken route.
     *
     * Since M11 B3 the cell is `set_rates` on its own, not the whole Approver tag.
     */
    $current = Role::query()
        ->where('name', Role::COMMUNITY_SUPER_ADMIN)
        ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.dues_ledger.set_rates'))
        ->whereDoesntHave('permissions', static fn (Builder $query) => $query->where('name', 'estate.dues_ledger.reverse_runs'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // One fixed day, so every month named below is the month the test means.
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));

    foreach (PRICING_LOTS as $lot) {
        FacilitiesFixture::scratchUnit($lot, PRICING_BLOCK);
    }

    $this->schedules = app(ChargeSchedules::class);
    $this->csa = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    FacilitiesFixture::boot();

    // Every month this file posted, taken back out of the lots it billed — latest first, as months are reversed.
    $ids = ChargeSchedule::query()->where('description', PRICING_SCHEDULE)->pluck('id');

    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->whereNull('reversed_at')->orderByDesc('period')->get()
        ->each(fn (ChargeRun $run) => $this->schedules->reverse($run, 'Pricing test month, taken back out.', $this->csa));

    DB::connection('tenant')->table('charge_schedule_rate_changes')->whereIn('charge_schedule_id', $ids)->delete();
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();

    /*
     * AND THE AMENITY, EVEN WHEN THE TEST STOPPED HALFWAY. The test retires it
     * itself; this is for the run where an assertion failed first, which would
     * otherwise leave a fifth card on board 20 for every file after this one.
     */
    $court = Amenity::query()->where('name', PRICING_AMENITY)->first();

    if ($court !== null) {
        $court->bookings()->where('status', AmenityBooking::PENDING)->get()
            ->each(fn (AmenityBooking $booking) => app(Amenities::class)->decline($booking, 'Pricing test booking, withdrawn.'));

        $court->forceFill(['is_active' => false])->save();
    }

    DB::connection('mysql')->rollBack();
});

/** A schedule on this file's three lots, at J$6,200 a unit. */
function pricingSchedule(User $by): ChargeSchedule
{
    FacilitiesFixture::boot();

    return app(ChargeSchedules::class)->create([
        'description' => PRICING_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'phase',
        'phase' => PRICING_BLOCK,
        'due_day' => 1,
        'account_code' => '4000',
    ], $by);
}

/** The schedule's card as the page receives it. */
function pricingCard(TestCase $case, User $viewer, int $scheduleId): array
{
    $card = null;

    $case->actingAs($viewer)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$card, $scheduleId) {
            $page->component('Estate/Dues/ChargeSchedule');

            $card = collect($page->toArray()['props']['schedules'])->firstWhere('id', $scheduleId);
        });

    FacilitiesFixture::boot();

    expect($card)->not->toBeNull();

    return $card;
}

/** Post the next month exactly as the page would — against its own preview. */
function pricingPostNext(TestCase $case, User $by, int $scheduleId): ChargeRun
{
    $preview = pricingCard($case, $by, $scheduleId)['preview'];

    $case->actingAs($by)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$scheduleId.'/runs'), [
            'period' => $preview['period'],
            'expected_count' => $preview['count'],
            'expected_total_minor' => $preview['total_minor'],
        ])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    return ChargeRun::query()->where('charge_schedule_id', $scheduleId)->where('period', $preview['period'])->whereNull('reversed_at')->sole();
}

/** Everything a posted month wrote, read back from the rows themselves. */
function pricingMonthOnRecord(ChargeRun $run): array
{
    FacilitiesFixture::boot();

    $run = ChargeRun::query()->findOrFail($run->id);

    return [
        'run' => [$run->unit_count, $run->total_minor, $run->journal_ref],
        'charges' => DB::connection('tenant')->table('charges')->where('journal_ref', $run->journal_ref)->orderBy('id')->get(['id', 'unit_id', 'amount_minor', 'description'])->map(static fn ($row) => (array) $row)->all(),
        'lines' => DB::connection('tenant')->table('journal_lines')->where('entry_ref', $run->journal_ref)->orderBy('line_no')->get(['account_id', 'unit_id', 'debit_minor', 'credit_minor'])->map(static fn ($row) => (array) $row)->all(),
    ];
}

/** The message a refusal left on one key. */
function pricingError(string $key): string
{
    return (string) session('errors')?->first($key);
}

/* ------------------------------------------------------------------ */
/* dues rates */
/* ------------------------------------------------------------------ */

it('changes a dues rate from the next unposted month and never rewrites the month already posted', function () {
    $schedule = pricingSchedule($this->csa);
    $url = FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate');

    // September, posted at J$6,200 a unit.
    $september = pricingPostNext($this, $this->csa, $schedule->id);
    $postedBefore = pricingMonthOnRecord($september);

    expect($september->period)->toBe('2026-09')
        ->and($september->unit_count)->toBe(3)
        ->and(array_column($postedBefore['charges'], 'amount_minor'))->toBe([6_200_00, 6_200_00, 6_200_00]);

    // September is billed: a change cannot reach it, and the refusal names the month it can start.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '7000.00', 'effective_period' => '2026-09', 'reason' => 'Security contract renewed at a higher rate.'])
        ->assertSessionHasErrors('rate');

    expect(pricingError('rate'))->toContain('September 2026 has already been billed')
        ->and(pricingError('rate'))->toContain('October 2026 at the earliest');

    // From October: accepted, and the flash says from when, how much, and what was left alone.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '7000.00', 'effective_period' => '2026-10', 'reason' => 'Security contract renewed at a higher rate.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $flash) => str_contains($flash, MoneyFormatter::fromMinor(7_000_00).' a unit from the October 2026 charge run')
            && str_contains($flash, '3 units, '.MoneyFormatter::fromMinor(21_000_00).' a month')
            && str_contains($flash, "September 2026's charges, already posted, are not changed."));

    // THE HARD REQUIREMENT: every figure September wrote reads exactly as it did.
    expect(pricingMonthOnRecord($september))->toBe($postedBefore);

    FacilitiesFixture::boot();

    // Not in force yet — it is still September — and waiting for its month.
    expect(ChargeSchedule::query()->findOrFail($schedule->id)->amount_minor)->toBe(6_200_00)
        ->and(DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->whereNull('applied_at')->count())->toBe(1);

    // October previews at the new rate, from the same resolver the post will use.
    $card = pricingCard($this, $this->csa, $schedule->id);

    expect($card['preview']['period'])->toBe('2026-10')
        ->and($card['preview']['count'])->toBe(3)
        ->and($card['preview']['rate_minor'])->toBe(7_000_00)
        ->and($card['preview']['total_minor'])->toBe(3 * 7_000_00)
        ->and($card['amount'])->toBe(MoneyFormatter::fromMinor(6_200_00))
        ->and($card['pending_rates'])->toHaveCount(1)
        ->and($card['pending_rates'][0]['label'])->toBe('October 2026')
        ->and($card['pending_rates'][0]['to'])->toBe(MoneyFormatter::fromMinor(7_000_00));

    // October posts at J$7,000, and September still reads J$6,200.
    $october = pricingPostNext($this, $this->csa, $schedule->id);

    expect(array_column(pricingMonthOnRecord($october)['charges'], 'amount_minor'))->toBe([7_000_00, 7_000_00, 7_000_00])
        ->and($october->total_minor)->toBe(21_000_00)
        ->and(pricingMonthOnRecord($september))->toBe($postedBefore);

    // October arrives: the card's rate in force becomes the new one, once.
    $this->travelTo(Carbon::parse('2026-10-02 09:00:00'));

    $card = pricingCard($this, $this->csa, $schedule->id);

    expect($card['amount'])->toBe(MoneyFormatter::fromMinor(7_000_00))
        ->and($card['pending_rates'])->toBe([])
        ->and(ChargeSchedule::query()->findOrFail($schedule->id)->amount_minor)->toBe(7_000_00)
        ->and(DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->whereNotNull('applied_at')->count())->toBe(1)
        ->and($this->schedules->applyDueRateChanges())->toBe(0);
});

it('lets a change start this month while this month is unbilled, and never a month gone by', function () {
    $schedule = pricingSchedule($this->csa);
    $url = FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate');
    $reason = 'AGM of 20 September voted the new maintenance fee.';

    // Nothing posted: the next month billed is THIS month, September.
    expect($this->schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-09');

    $this->actingAs($this->csa)
        ->post($url, ['amount' => '6500.00', 'effective_period' => '2026-08', 'reason' => $reason])
        ->assertSessionHasErrors('rate');

    expect(pricingError('rate'))->toContain('August 2026 has passed')
        ->and(pricingError('rate'))->toContain('September 2026 at the earliest');

    // September itself is accepted — and, being this month, is in force at once.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '6500.00', 'effective_period' => '2026-09', 'reason' => $reason])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect(ChargeSchedule::query()->findOrFail($schedule->id)->amount_minor)->toBe(6_500_00)
        ->and($this->schedules->preview($schedule->fresh(), Carbon::parse('2026-09-01'))['total_minor'])->toBe(3 * 6_500_00);

    // A second save for the same unbilled month corrects the first rather than standing beside it.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '6600.00', 'effective_period' => '2026-09', 'reason' => $reason.' Corrected figure.'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $rows = DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->from_minor)->toBe(6_200_00)
        ->and((int) $rows[0]->to_minor)->toBe(6_600_00)
        ->and(ChargeSchedule::query()->findOrFail($schedule->id)->amount_minor)->toBe(6_600_00);

    // The same figure again is a change nobody made.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '6600.00', 'effective_period' => '2026-09', 'reason' => $reason])
        ->assertSessionHasErrors('rate');

    expect(pricingError('rate'))->toContain('already the rate for September 2026');

    // And September, posted now, bills what was decided for it.
    $september = pricingPostNext($this, $this->csa, $schedule->id);

    expect(array_column(pricingMonthOnRecord($september)['charges'], 'amount_minor'))->toBe([6_600_00, 6_600_00, 6_600_00]);
});

it('refuses a rate change without a reason and audits one with it', function () {
    $schedule = pricingSchedule($this->csa);
    $url = FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate');

    $this->actingAs($this->csa)
        ->post($url, ['amount' => '7000.00', 'effective_period' => '2026-10'])
        ->assertSessionHasErrors('reason');

    // Present but saying nothing: refused by the service, in a sentence.
    $this->actingAs($this->csa)
        ->post($url, ['amount' => '7000.00', 'effective_period' => '2026-10', 'reason' => 'higher'])
        ->assertSessionHasErrors('rate');

    expect(pricingError('rate'))->toContain('Say why the rate is changing');

    FacilitiesFixture::boot();

    $audited = fn () => AuditEntry::query()
        ->where('action', 'estate.charge_schedule_rate_changed')
        ->where('entity_type', 'ChargeSchedule')
        ->where('entity_id', (string) $schedule->id);

    expect(DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->count())->toBe(0)
        ->and($audited()->count())->toBe(0);

    $reason = 'Security contract renewed at a higher rate.';

    $this->actingAs($this->csa)
        ->post($url, ['amount' => '7000.00', 'effective_period' => '2026-10', 'reason' => $reason])
        ->assertSessionHasNoErrors();

    $entry = $audited()->sole();

    expect($entry->actor_id)->toBe($this->csa->id)
        ->and($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($entry->before['amount_minor'])->toBe(6_200_00)
        ->and($entry->after['amount_minor'])->toBe(7_000_00)
        ->and($entry->after['effective_period'])->toBe('2026-10')
        ->and($entry->after['reason'])->toBe($reason)
        ->and($entry->after['units'])->toBe(3)
        ->and($entry->after['total_minor'])->toBe(21_000_00)
        ->and($entry->after['applied'])->toBeFalse();

    FacilitiesFixture::boot();

    $row = DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->sole();

    expect($row->reason)->toBe($reason)
        ->and((int) $row->changed_by)->toBe($this->csa->id)
        ->and($row->changed_by_name)->toBe($this->csa->name);
});

it('lets the Community Super Admin change a dues rate, as every approval on Dues & ledger since M55, and refuses every role without Set dues rates', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    /*
     * THE BLOCKER, pinned. M11 B3 (PRICE-02) gave the Community Super Admin the one approval M10 §3 asked for; the
     * user's M55 ruling (8 Oct 2026, master reference §5.3) gives it all five, as the Treasurer holds them. The Admin
     * Assistant and the Property Manager (locked out by Ruling 1) hold none.
     */
    expect($this->csa->can('estate.dues_ledger.set_rates'))->toBeTrue()
        ->and($this->csa->can('estate.dues_ledger.reverse_runs'))->toBeTrue()
        ->and($this->csa->can('estate.dues_ledger.activate_plans'))->toBeTrue()
        ->and($this->csa->can('estate.dues_ledger.flag_units'))->toBeTrue()
        ->and($this->csa->can('estate.dues_ledger.adopt_dunning'))->toBeTrue()
        ->and($treasurer->can('estate.dues_ledger.set_rates'))->toBeTrue()
        ->and($treasurer->can('estate.dues_ledger.reverse_runs'))->toBeTrue()
        ->and($president->can('estate.dues_ledger.set_rates'))->toBeFalse()
        ->and($assistant->can('estate.dues_ledger.set_rates'))->toBeFalse()
        ->and($manager->can('estate.dues_ledger.set_rates'))->toBeFalse()
        ->and($manager->can('estate.dues_ledger.view'))->toBeFalse();

    // The route is the refusal; the disabled button is only the courtesy.
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route): bool => $route->getName() === 'estate.dues.schedule.rate');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('can:estate.dues_ledger.set_rates');

    $schedule = pricingSchedule($this->csa);
    $url = FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate');
    $change = ['amount' => '7000.00', 'effective_period' => '2026-10', 'reason' => 'Security contract renewed at a higher rate.'];

    foreach ([$president, $assistant, $manager] as $refused) {
        $this->actingAs($refused)->post($url, $change)->assertForbidden();
    }

    FacilitiesFixture::boot();

    expect(DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->count())->toBe(0);

    // The screen says so to the reader who cannot, and offers it to the one who
    // can — and offers the Community Super Admin the rate and, since M55, the reversal.
    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetRates', false)
            ->where('canReverse', false)
            ->where('reasons.rate', fn (string $reason) => str_contains($reason, 'Set dues rates permission')));

    $this->actingAs($this->csa)
        ->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canSetRates', true)
            ->where('canReverse', true));

    $this->actingAs($this->csa)->post($url, $change)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect((int) DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->value('to_minor'))->toBe(7_000_00);
});

it('says before the save what a rate change reaches and from which month', function () {
    $schedule = pricingSchedule($this->csa);
    $rate = MoneyFormatter::fromMinor(6_200_00);

    // Nothing posted yet: from this month, and nothing already raised.
    $card = pricingCard($this, $this->csa, $schedule->id);

    expect($card['units'])->toBe(3)
        ->and($card['rate_input'])->toBe('6200.00')
        ->and($card['rate_options'])->toHaveCount(ChargeSchedules::RATE_MONTHS_AHEAD)
        ->and($card['rate_options'][0]['period'])->toBe('2026-09')
        ->and($card['rate_options'][0]['effect'])->toBe('Applies from the September 2026 charge run. 3 units on this schedule. Nothing has been posted from this schedule yet, so no charge already raised is changed.');

    pricingPostNext($this, $this->csa, $schedule->id);

    // September posted: the client's own sentence, with this estate's figures in it.
    $card = pricingCard($this, $this->csa, $schedule->id);
    $options = collect($card['rate_options'])->keyBy('period');

    expect($card['rate_options'][0]['period'])->toBe('2026-10')
        ->and($options['2026-10']['effect'])->toBe("Applies from the October 2026 charge run. 3 units on this schedule. September 2026's charges, already posted, are not changed.")
        ->and($options['2026-10']['current'])->toBe($rate)
        ->and($options['2026-11']['effect'])->toBe("Applies from the November 2026 charge run. 3 units on this schedule. October 2026 is still billed at {$rate} a unit. September 2026's charges, already posted, are not changed.")
        ->and($options['2027-01']['effect'])->toContain("October 2026 to December 2026 are still billed at {$rate} a unit.");

    // The same sentence the service gives anyone who asks, from the same resolver.
    FacilitiesFixture::boot();

    expect($this->schedules->rateChangeEffect($schedule->fresh(), Carbon::parse('2026-10-01')))->toBe($options['2026-10']['effect']);

    // A change already set for a later month is named, not hidden, when an earlier one is considered.
    $this->actingAs($this->csa)
        ->post(FacilitiesFixture::url('/finance/charge-schedule/'.$schedule->id.'/rate'), [
            'amount' => '7500.00',
            'effective_period' => '2026-12',
            'reason' => 'Second step of the rise the AGM voted.',
        ])
        ->assertSessionHasNoErrors();

    $options = collect(pricingCard($this, $this->csa, $schedule->id)['rate_options'])->keyBy('period');

    expect($options['2026-10']['effect'])->toContain('The change already set for December 2026 ('.MoneyFormatter::fromMinor(7_500_00).' a unit) still takes over from then.')
        ->and($options['2027-01']['current'])->toBe(MoneyFormatter::fromMinor(7_500_00));
});

/* ------------------------------------------------------------------ */
/* amenity fees */
/* ------------------------------------------------------------------ */

it('audits every amenity fee change and leaves a booking already made on the terms it was made under', function () {
    $amenities = app(Amenities::class);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $settings = FacilitiesFixture::url('/facilities/amenities/settings');

    $terms = [
        'name' => PRICING_AMENITY,
        'icon_key' => 'pavilion',
        'capacity' => 8,
        'fee' => '3000.00',
        'deposit' => '5000.00',
        'opens_at' => '08:00',
        'closes_at' => '20:00',
        'booking_window_days' => 60,
        'cancellation_hours' => 24,
        'is_bookable' => true,
    ];

    // Added, and on the record: who set the price, and what it was set to.
    $this->actingAs($this->csa)->post(FacilitiesFixture::url('/facilities/amenities'), $terms)->assertRedirect($settings);

    FacilitiesFixture::boot();

    $court = Amenity::query()->where('name', PRICING_AMENITY)->sole();
    $added = AuditEntry::query()->where('action', 'estate.amenity_added')->where('entity_id', (string) $court->id)->sole();

    expect($added->actor_id)->toBe($this->csa->id)
        ->and($added->after['booking_fee_minor'])->toBe(3_000_00)
        ->and($added->after['deposit_minor'])->toBe(5_000_00)
        ->and($added->after['cancellation_hours'])->toBe(24);

    // A booking taken under those terms.
    $taken = $amenities->book(
        amenity: $court,
        unit: FacilitiesFixture::scratchUnit('Lot 955'),
        residentName: 'Pricing Test Household',
        startsAt: SiteTime::today()->addDays(40)->addHours(10),
        endsAt: SiteTime::today()->addDays(40)->addHours(12),
    );
    $takenTerms = [$taken->fee_minor, $taken->deposit_minor, $taken->cancellation_hours];

    expect($takenTerms)->toBe([3_000_00, 5_000_00, 24]);

    // BEFORE the edit, the panel already says what an edit would reach and what it would not.
    $this->actingAs($this->csa)
        ->get($settings)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'rows',
            fn ($rows) => collect($rows)->firstWhere('id', $court->id)['terms_effect']
                === 'Applies to bookings made from now on. The 1 upcoming booking already made keeps the fee, deposit and cancellation rule it was made under.',
        ));

    // A role that reads the card does not change it.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$court->id), [...$terms, 'fee' => '9000.00'])
        ->assertForbidden();

    // THE EDIT: fee, deposit and notice all move.
    $this->actingAs($this->csa)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$court->id), [...$terms, 'fee' => '3500.00', 'deposit' => '6000.00', 'cancellation_hours' => 48])
        ->assertRedirect($settings)
        ->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'Booking fee $3,000 → $3,500; deposit $5,000 → $6,000; cancellation notice 24h → 48h.')
            && str_contains($flash, 'Applies to bookings made from now on.')
            && str_contains($flash, 'The 1 upcoming booking already made keeps'));

    FacilitiesFixture::boot();

    $changed = AuditEntry::query()->where('action', 'estate.amenity_terms_changed')->where('entity_id', (string) $court->id)->sole();

    expect($changed->actor_id)->toBe($this->csa->id)
        ->and($changed->before['booking_fee_minor'])->toBe(3_000_00)
        ->and($changed->after['booking_fee_minor'])->toBe(3_500_00)
        ->and($changed->before['deposit_minor'])->toBe(5_000_00)
        ->and($changed->after['deposit_minor'])->toBe(6_000_00)
        ->and($changed->before['cancellation_hours'])->toBe(24)
        ->and($changed->after['cancellation_hours'])->toBe(48)
        ->and($changed->before['opens_at'])->toBe($changed->after['opens_at'])
        ->and($changed->after['upcoming_bookings_keeping_terms'])->toBe(1);

    // The booking made before the edit has not moved; the next one copies the new card.
    $taken = AmenityBooking::query()->findOrFail($taken->id);

    expect([$taken->fee_minor, $taken->deposit_minor, $taken->cancellation_hours])->toBe($takenTerms);

    $next = $amenities->book(
        amenity: Amenity::query()->findOrFail($court->id),
        unit: FacilitiesFixture::scratchUnit('Lot 955'),
        residentName: 'Pricing Test Household',
        startsAt: SiteTime::today()->addDays(41)->addHours(10),
        endsAt: SiteTime::today()->addDays(41)->addHours(12),
    );

    expect([$next->fee_minor, $next->deposit_minor, $next->cancellation_hours])->toBe([3_500_00, 6_000_00, 48]);

    // Saving the card unchanged is not a change, and writes no entry claiming one.
    $this->actingAs($this->csa)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$court->id), [...$terms, 'fee' => '3500.00', 'deposit' => '6000.00', 'cancellation_hours' => 48])
        ->assertRedirect($settings);

    FacilitiesFixture::boot();

    expect(AuditEntry::query()->where('action', 'estate.amenity_terms_changed')->where('entity_id', (string) $court->id)->count())->toBe(1);

    // Put away: both bookings declined, the court retired — and the retirement is on the record too.
    foreach ([$taken, $next] as $booking) {
        $amenities->decline(AmenityBooking::query()->findOrFail($booking->id), 'Pricing test booking, withdrawn.');
    }

    $this->actingAs($this->csa)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$court->id), [...$terms, 'retire' => true])
        ->assertRedirect($settings);

    FacilitiesFixture::boot();

    expect(Amenity::query()->findOrFail($court->id)->is_active)->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'estate.amenity_retired')->where('entity_id', (string) $court->id)->count())->toBe(1);
});
