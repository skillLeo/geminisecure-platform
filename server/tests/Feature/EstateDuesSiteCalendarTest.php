<?php

declare(strict_types=1);

use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Document;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Role;
use App\Services\Documents\DocumentRenderer;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The money side's "today" is the estate's — bug hunt B023, B082 and B106 (and B028's "Issued")
|--------------------------------------------------------------------------
|
| At 8:30 PM on September 30 in Kingston it is 01:30 on October 1 in UTC, and
| the dues screens used the second. "Record manual payment" was pre-filled with
| 2026-10-01 and `before_or_equal:today` accepted it, so the receipt posted as
| RCT-2026-10 and September's takings were short. Keying the right date instead
| marked the receipt "late entry", because the keying time was compared on the
| UTC calendar. A new charge schedule that evening started at October and could
| never bill September; a rate change for October applied at once; and a run
| could be posted two months ahead of the estate's own calendar.
|
| EVERY TEST HERE STANDS IN THAT EVENING, the one window in the day when the
| two calendars disagree (D-143) — and every one of them passes in the morning
| either way, which is why none of the suites around this caught it.
|
*/

const SITE_CALENDAR_SCHEDULE = 'Site Calendar Test Maintenance Fee';

const SITE_CALENDAR_PHASE = 'Site Calendar Phase';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // 8:30 PM in Kingston on the last day of September: 01:30 UTC on October 1.
    $this->travelTo(Carbon::parse('2026-09-30 20:30', 'America/Jamaica'));
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', SITE_CALENDAR_SCHEDULE)->pluck('id');
    DB::connection('tenant')->table('charge_schedule_rate_changes')->whereIn('charge_schedule_id', $ids)->delete();
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();
});

it('offers the site\'s today as the payment date, and refuses the site\'s tomorrow', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7461');

    expect(now()->utc()->toDateString())->toBe('2026-10-01');

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('today', '2026-09-30'));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash', 'amount' => '6200.00', 'received_on' => '2026-10-01',
        ])
        ->assertSessionHasErrors('received_on');

    // The service holds the same line for every caller.
    expect(fn () => app(Dues::class)->receive(unit: $unit, amount: Money::of('6200.00', 'JMD'), method: 'cash', receivedAt: '2026-10-01'))
        ->toThrow(DomainException::class, 'future');
});

it('pre-fills the payment date from the server\'s today, never the browser\'s UTC date — bug hunt B106', function () {
    // A Vue behaviour, read off the page as `CategoryBTest` reads its screens.
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/UnitLedger.vue'));

    expect($screen)->not->toContain('toISOString()')
        ->and($screen)->toMatch('/today:\s*\{\s*type:\s*String,\s*required:\s*true\s*\}/')
        ->and($screen)->toContain('received_on: props.today')
        ->and($screen)->toContain(':max="today"');
});

it('dates a receipt keyed this evening in September, and does not call it a late entry', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7462');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash', 'amount' => '6200.00', 'received_on' => '2026-09-30',
        ])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $payment = Payment::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();
    $journal = Journal::query()->where('reference', $payment->journal_ref)->firstOrFail();

    expect($journal->posted_on->toDateString())->toBe('2026-09-30')
        ->and($journal->reference)->toStartWith('RCT-2026-09-')

        // Keyed the same evening the money came in: not late paperwork.
        ->and($payment->enteredLate())->toBeFalse();

    // The whole register: a payment dated 30 September is not among the newest 200 once other tests in the same run
    // have taken payments dated today (found by M24's suite, 1 Oct). Which row it is, not where it ranks, is the point.
    $row = collect(app(Receipts::class)->register(limit: 100_000)['rows'])->firstWhere('id', $payment->id);

    expect($row['received_on'])->toBe('Sep 30, 2026')
        ->and($row['entered_on'])->toBe('Sep 30, 2026')
        ->and($row['entered_late'])->toBeFalse();
});

it('takes a moment handed to the receipt as the day it is at the estate', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 7463');

    // A gateway or a seeder hands `receive()` an instant, not a typed date.
    $payment = app(Dues::class)->receive(unit: $unit, amount: Money::of('100.00', 'JMD'), method: 'bank', receivedAt: now());

    expect($payment->received_at->toDateString())->toBe('2026-09-30')
        ->and(Journal::query()->where('reference', $payment->journal_ref)->value('reference'))->toStartWith('RCT-2026-09-')
        ->and($payment->enteredLate())->toBeFalse();
});

it('stamps a document "Issued" with the estate\'s date and time — bug hunt B028', function () {
    Queue::fake();

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7465');

    try {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/statement'))
            ->assertRedirect();

        FacilitiesFixture::boot();

        $document = Document::query()->where('kind', Document::STATEMENT)->where('subject_id', (string) $unit->id)->latest('id')->firstOrFail();

        $issued = null;
        $zone = null;

        View::composer('documents.statement', function ($view) use (&$issued, &$zone): void {
            $issued = $view->getData()['issuedAt'] ?? null;
            $zone = $view->getData()['timeZone'] ?? null;
        });

        // As RenderDocument drives it: the facts, then the page (bug hunt B027 split the render in two).
        FacilitiesFixture::platform()->run(function () use ($document): string {
            $renderer = app(DocumentRenderer::class);

            return $renderer->pdf($document, $renderer->facts($document->kind, $document->subject_id, $document->title));
        });

        // 8:30 PM on September 30 in Kingston — not 1:30 AM on October 1. And the paper says whose 8:30 (M11 §4.3), by the
        // clock's name where there is room (M19A §4.2).
        expect($issued)->toBe('September 30, 2026 at 8:30 PM Jamaica time')
            ->and($zone)->toBe('America/Jamaica');
    } finally {
        FacilitiesFixture::boot();

        // A document cannot be deleted inside its retention (13 A2); the fixture truncates, as `EstateDocumentsTest` does.
        DB::connection('mysql_owner')->statement('TRUNCATE TABLE `'.FacilitiesFixture::database().'`.documents');
    }
});

it('starts a new schedule in the site\'s month, and keeps runs and rate changes to the site\'s calendar', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $schedules = app(ChargeSchedules::class);

    FacilitiesFixture::scratchUnit('Lot 7464', SITE_CALENDAR_PHASE);

    $schedule = $schedules->create([
        'description' => SITE_CALENDAR_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'phase',
        'phase' => SITE_CALENDAR_PHASE,
        'due_day' => 1,
        'account_code' => '4000',
    ], $treasurer);

    // It is still September here: September is the month this schedule bills first.
    expect($schedules->nextPeriod($schedule)->format('Y-m'))->toBe('2026-09');

    // "This month or next" is September or October — never November.
    expect(fn () => $schedules->preview($schedule, Carbon::parse('2026-11-01')))
        ->toThrow(DomainException::class, 'more than a month ahead');

    // A change for October waits for October, rather than moving the rate in force tonight.
    $result = $schedules->amendRate($schedule, '7000.00', '2026-10', 'The AGM voted the new fee in.', $treasurer);

    $schedules->applyDueRateChanges();

    expect($result['applied'])->toBeFalse()
        ->and($schedule->fresh()->amount_minor)->toBe(6_200_00);

    // Posted this evening, and printed with this evening's date.
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));
    $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $treasurer);

    $run = collect($schedules->board()['runs'])->firstWhere('schedule', SITE_CALENDAR_SCHEDULE);

    expect($run['posted'])->toStartWith('Sep 30, 2026');
});
