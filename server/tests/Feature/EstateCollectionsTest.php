<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Restriction\RestrictionPolicy;
use Brick\Money\Money;
use Database\Seeders\Estate\CollectionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\CollectionsFixture;

/*
|--------------------------------------------------------------------------
| Collections — boards 7 and 8
|--------------------------------------------------------------------------
|
| TWO GUARANTEES ARE UNDER TEST HERE AND EVERYTHING ELSE IS SUPPORT.
|
| The first is that a payment plan is a live thing a GATE decision depends on.
| While an agreed plan is being met the arrears restriction is lifted; the
| moment an instalment is ruled missed it is back. That is not a note on a file
| and it is not a screen state — it reaches a guard's handset, so it is asserted
| through `RestrictionPolicy` itself rather than by reading a column.
|
| The second is that a dunning notice is FROZEN AT SEND. The template can be
| reworded the next day and the log must still say, word for word, what the
| resident was actually told — because the only reason the log exists is to
| settle a dispute about exactly that.
|
| The arithmetic assertions exist to stop those two resting on a schedule that
| does not add up. An instalment plan whose parts do not sum to the debt is an
| estate quietly writing off a cent, or quietly demanding one.
|
*/

/**
 * THE SEEDED LADDER, LOG AND PLAN ARE THE SEEDER'S IN EVERY TEST (M4 §4, defect 3).
 *
 * The fixture is one committed database built once per process, and half this file writes to it —
 * a plan is agreed, a notice is sent, a flag is lifted. The assertions about what `CollectionsSeeder`
 * MADE ("Lot 47's plan is active, J$12,400 over four instalments", "the seeded dunning log") used
 * to be true only because they were declared above the writing ones. `phpunit.xml` now runs the
 * suite in random order, so every test runs inside a transaction on the estate connection and none
 * of it is kept. There is no HTTP request and no `$tenant->run()` in this file.
 *
 * THE TRANSACTION IS THE WHOLE DEFENCE, and exactly one thing destroys it: a `DB::purge('tenant')`
 * (M6 §3.1). A purge disconnects, and a disconnect abandons an open transaction silently — the
 * writes are then COMMITTED to an estate database shared with `RestrictionPolicyTest`, and the
 * treasurer's test sentence in the last test below would replace the seeded Reminder 1 wording that
 * the seeder assertion reads. That is why `CollectionsFixture::boot()` returns without purging once
 * it is already pointed here, why nothing in this file makes an HTTP request (tenancy re-points the
 * connection), and why every service it calls writes on `tenant` — `Dues`, `Ledger` and
 * `Collections` all open their own transactions on that same connection, which nest as savepoints.
 * Add an HTTP request to this file and the rollback stops working; put the write in its own suite.
 */
beforeEach(function () {
    CollectionsFixture::boot();

    DB::connection('tenant')->beginTransaction();

    $this->collections = app(Collections::class);
});

afterEach(function () {
    DB::connection('tenant')->rollBack();
});

/** The receivable balance of one unit, summed independently in raw SQL. */
function unitReceivable(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS balance
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->balance;
}

/* ------------------------------------------------------------------ */
/* the schedule adds up */
/* ------------------------------------------------------------------ */

it('schedules the balance the ledger says the unit owes', function () {
    $unit = CollectionsFixture::unit('Lot 47');

    $schedule = $this->collections->schedule($unit, 4);

    // The board's own figures: J$12,400.00 over four instalments of J$3,100.00.
    expect($schedule['total_minor'])->toBe(unitReceivable($unit->id))
        ->and($schedule['total_minor'])->toBe(12_400_00)
        ->and($schedule['instalment_minor'])->toBe(3_100_00)
        ->and($schedule['is_even'])->toBeTrue()
        ->and($schedule['rows'])->toHaveCount(4);
});

it('sums every instalment to the total, to the cent', function (int $instalments) {
    // J$7,777.77 divides into nothing. Every board figure divides by four
    // cleanly, so a test that only used those would pass whatever the
    // remainder did with the cents.
    $unit = CollectionsFixture::unit('Lot 100');

    $schedule = $this->collections->schedule($unit, $instalments);

    $summed = array_sum(array_column($schedule['rows'], 'amount_minor'));

    expect($summed)->toBe($schedule['total_minor'])
        ->and($summed)->toBe(unitReceivable($unit->id))
        ->and($summed)->toBe(7_777_77);
})->with([1, 2, 3, 4, 5, 7, 12, 13, 36]);

it('puts the rounding remainder on the first instalment, never the last', function () {
    $unit = CollectionsFixture::unit('Lot 100');

    $rows = $this->collections->schedule($unit, 4)['rows'];

    // 777,777 over four is 194,444 with 1 left over. The odd cent is paid
    // first, so a plan abandoned half way leaves the estate no worse off than
    // the arithmetic promised — and the closing instalment is exactly the
    // figure the household agreed to.
    expect($rows[0]['amount_minor'])->toBe(194_445)
        ->and($rows[1]['amount_minor'])->toBe(194_444)
        ->and($rows[2]['amount_minor'])->toBe(194_444)
        ->and($rows[3]['amount_minor'])->toBe(194_444)
        ->and($rows[3]['amount_minor'])->toBeLessThanOrEqual($rows[0]['amount_minor']);
});

it('writes a draft whose instalments sum to the agreed total', function () {
    $unit = CollectionsFixture::unit('Lot 21');

    $plan = $this->collections->draft($unit, 6);

    $summed = (int) $plan->schedule()->sum('amount_minor');

    expect($plan->status)->toBe(PaymentPlan::DRAFT)
        ->and($plan->schedule)->toHaveCount(6)
        ->and($summed)->toBe($plan->total_minor)
        ->and($summed)->toBe(unitReceivable($unit->id));
});

it('refuses to schedule a debt that has not been billed', function () {
    $unit = Unit::create([
        'reference' => 'Lot 800',
        'block' => 'Phase 1',
        'street' => 'Phase 1 Drive',
        'type' => 'residential',
        'status' => 'vacant',
    ]);

    // A plan clears an existing balance. Drawing one against a unit that owes
    // nothing would be the estate inventing a debt and then scheduling it.
    expect(fn () => $this->collections->schedule($unit, 4))
        ->toThrow(DomainException::class, 'nothing to schedule');
});

/* ------------------------------------------------------------------ */
/* a plan the household never agreed to */
/* ------------------------------------------------------------------ */

it('refuses to activate a plan the household has not agreed to', function () {
    $unit = CollectionsFixture::unit('Lot 31');
    $plan = $this->collections->draft($unit, 4);

    expect($plan->isAgreed())->toBeFalse();

    // An unagreed plan is a demand. Activating one would lift a gate
    // restriction on terms nobody in the household ever accepted.
    expect(fn () => $this->collections->activate($plan, 'Omar Brown', new User(['name' => 'Treasurer'])))
        ->toThrow(DomainException::class, 'has not agreed');

    expect($plan->fresh()->status)->toBe(PaymentPlan::DRAFT)
        ->and($this->collections->isProtected($unit))->toBeFalse();
});

it('refuses to activate an agreement in somebody else name', function () {
    $unit = CollectionsFixture::unit('Lot 9');
    $plan = $this->collections->draft($unit, 3);

    $this->collections->agree($plan, 'Ricardo Hall');

    // Activating "Andrea Fletcher's agreement" against a plan that records
    // Ricardo Hall's is approving something other than what is on the screen.
    expect(fn () => $this->collections->activate($plan, 'Andrea Fletcher', new User(['name' => 'Treasurer'])))
        ->toThrow(DomainException::class, 'records an agreement by');
});

it('records an agreement as a fact with a time and a name on it', function () {
    $unit = CollectionsFixture::unit('Lot 63');
    $plan = $this->collections->draft($unit, 2);

    $this->collections->agree($plan, '  Devon Clarke  ');

    expect($plan->agreed_at)->not->toBeNull()
        ->and($plan->agreed_by_name)->toBe('Devon Clarke')
        ->and($plan->isAgreed())->toBeTrue();

    // A nameless agreement cannot be shown to the household it binds.
    expect(fn () => $this->collections->agree($plan, '   '))
        ->toThrow(DomainException::class, 'Record who agreed');
});

it('does not rewrite the agreement an approval relied on — bug hunt B038', function () {
    $unit = CollectionsFixture::unit('Lot 31');
    $plan = $this->collections->draft($unit, 4);

    // A draft's agreement can still be corrected before anybody has approved it.
    $this->collections->agree($plan, 'Omar Brwn');
    $this->collections->agree($plan, 'Omar Brown');
    $this->collections->activate($plan, 'Omar Brown', new User(['name' => 'Treasurer']));

    $agreedAt = $plan->fresh()->agreed_at;

    // Once approved on Omar Brown's word, it stays Omar Brown's word.
    expect(fn () => $this->collections->agree($plan->fresh(), 'John Brown'))
        ->toThrow(DomainException::class, 'approved');

    expect($plan->fresh()->agreed_by_name)->toBe('Omar Brown')
        ->and($plan->fresh()->agreed_at?->toIso8601String())->toBe($agreedAt?->toIso8601String())
        ->and($plan->fresh()->status)->toBe(PaymentPlan::ACTIVE);
});

/* ------------------------------------------------------------------ */
/* what the gate is told */
/* ------------------------------------------------------------------ */

it('lifts the arrears restriction while a plan is being met', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 901');
    $household = $unit->household;
    $policy = new RestrictionPolicy;

    // Restricted before anything is agreed.
    expect($policy->decide($household, 'guest')['admitted'])->toBeFalse();

    $plan = $this->collections->draft($unit, 4);
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));

    expect($this->collections->isProtected($unit))->toBeTrue()
        ->and($policy->decide($household, 'guest')['admitted'])->toBeTrue()

        // AND THE FLAG IS STILL SET. The arrears did not go away; they were
        // given a schedule. Clearing it would leave nothing to reinstate.
        ->and($household->fresh()->access_restricted)->toBeTrue();
});

it('reinstates the restriction the moment an instalment is missed', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 902');
    $household = $unit->household;
    $policy = new RestrictionPolicy;

    // Its first instalment fell due two months ago: only a date that has passed can be ruled missed.
    $plan = $this->collections->draft($unit, 4, now()->subMonths(2)->toDateString());
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));

    expect($policy->decide($household, 'guest')['admitted'])->toBeTrue();

    $this->collections->markMissed($plan->schedule()->firstOrFail());

    expect($plan->fresh()->status)->toBe(PaymentPlan::DEFAULTED)
        ->and($this->collections->isProtected($unit))->toBeFalse()
        ->and($policy->decide($household, 'guest')['admitted'])->toBeFalse();
});

it('tells a guard nothing about a payment plan', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 903');
    $household = $unit->household;

    $plan = $this->collections->draft($unit, 4);
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));

    $shielded = (new RestrictionPolicy)->decide($household, 'guest');
    $clear = (new RestrictionPolicy)->decide(
        CollectionsFixture::unit('Lot 47')->household,
        'guest'
    );

    /*
     * Byte for byte the ordinary admit. A plan is a fact about money, and a
     * verdict that distinguished a household on a plan from one with no arrears
     * would tell a guard something invariant 2 forbids — by implication rather
     * than by figure, which is exactly how that guarantee gets lost.
     */
    expect($shielded)->toBe($clear);
});

it('does not reactivate a plan the household has already broken', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 904');

    $plan = $this->collections->draft($unit, 4, now()->subMonths(2)->toDateString());
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));
    $this->collections->markMissed($plan->schedule()->firstOrFail());

    // A second chance is a second plan, so that the first still says what
    // went wrong.
    expect(fn () => $this->collections->activate($plan->fresh(), 'Test Householder', new User(['name' => 'Treasurer'])))
        ->toThrow(DomainException::class, 'cannot be activated');
});

it('ends the restriction outright when the last instalment is met and the ledger is clear', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 905', 9_000_00);
    $household = $unit->household;

    $plan = $this->collections->draft($unit, 3);
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));

    // The money moves where every other receipt does, through the ledger.
    // Marking an instalment met posts nothing and must not.
    app(Dues::class)->receive(
        unit: $unit,
        amount: Money::ofMinor(9_000_00, 'JMD'),
        method: 'bank',
        receivedAt: now(),
    );

    foreach ($plan->schedule as $instalment) {
        $this->collections->recordInstalmentMet($instalment);
    }

    expect($plan->fresh()->status)->toBe(PaymentPlan::COMPLETED)

        // The shield is gone because the plan is finished — so if the flag
        // survived, a household that had just paid in full would be restricted.
        ->and($this->collections->isProtected($unit))->toBeFalse()
        ->and($household->fresh()->access_restricted)->toBeFalse()
        ->and(unitReceivable($unit->id))->toBe(0);
});

it('leaves a restriction standing when a plan completes over fresh arrears', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 906', 9_000_00);
    $household = $unit->household;

    $plan = $this->collections->draft($unit, 3);
    $this->collections->agree($plan, 'Test Householder');
    $this->collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));

    foreach ($plan->schedule as $instalment) {
        $this->collections->recordInstalmentMet($instalment);
    }

    // Every instalment accepted, nothing actually received. Fresh arrears get
    // their own eligibility and their own notice period; an unrelated
    // agreement finishing does not clear them.
    expect($plan->fresh()->status)->toBe(PaymentPlan::COMPLETED)
        ->and(unitReceivable($unit->id))->toBe(9_000_00)
        ->and($household->fresh()->access_restricted)->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* the notice as it was sent */
/* ------------------------------------------------------------------ */

it('keeps a sent notice word for word when the template is edited afterwards', function () {
    $unit = CollectionsFixture::unit('Lot 47');

    $template = DunningTemplate::create([
        'key' => 'frozen-test',
        'label' => 'Reminder 1',
        'stage' => 1,
        'channel' => 'push+email',
        'subject' => 'Maintenance fee overdue — {unit_label}',
        'body' => 'Hi {resident_first_name}, your maintenance fee of {amount_due} for {unit_label} is now '.
            'overdue. Please settle it at your earliest convenience to avoid further reminders.',
        'days_overdue' => 30,
        'is_active' => true,
    ]);

    $notice = $this->collections->send($unit, $template);

    $sentSubject = $notice->subject;
    $sentBody = $notice->body;

    // Rendered, not referenced: the resident read a name and a figure, not a
    // token.
    expect($sentBody)->toContain('Andrea')
        ->and($sentBody)->toContain('$12,400.00')
        ->and($sentBody)->toContain('Lot 47')
        ->and($sentBody)->not->toContain('{')
        ->and($sentSubject)->toBe('Maintenance fee overdue — Lot 47');

    $template->forceFill([
        'subject' => 'Completely different subject',
        'body' => 'We have rewritten this template and it says something else entirely now.',
    ])->save();

    // THE ONE ASSERTION THIS TABLE EXISTS FOR. A log that re-rendered from
    // today's wording would show a resident a message they were never sent.
    $reloaded = DunningNotice::findOrFail($notice->id);

    expect($reloaded->body)->toBe($sentBody)
        ->and($reloaded->subject)->toBe($sentSubject)
        ->and($reloaded->template->body)->not->toBe($sentBody);
});

it('copies the step, stage and channel onto the notice rather than joining for them', function () {
    $unit = CollectionsFixture::unit('Lot 21');

    $template = DunningTemplate::create([
        'key' => 'denormalised-test',
        'label' => 'Reminder 4+',
        'stage' => 4,
        'channel' => 'push+email+sms',
        'subject' => 'Final notice — {unit_label}',
        'body' => '{unit_label} is {days_overdue} days overdue with {amount_due} outstanding.',
        'days_overdue' => 90,
        'is_active' => true,
    ]);

    $notice = $this->collections->send($unit, $template);

    $template->forceFill(['label' => 'Renamed', 'stage' => 9, 'channel' => 'letter'])->save();

    $reloaded = DunningNotice::findOrFail($notice->id);

    // Asked in a year which step went out today, the row answers on its own.
    expect($reloaded->template_label)->toBe('Reminder 4+')
        ->and($reloaded->stage)->toBe(4)
        ->and($reloaded->channel)->toBe('push+email+sms')
        ->and($reloaded->channelLabel())->toBe('Push + Email + SMS');
});

it('starts a notice queued rather than claiming a delivery nobody made', function () {
    $unit = CollectionsFixture::unit('Lot 9');

    $template = DunningTemplate::where('key', 'reminder1')->firstOrFail();
    $notice = $this->collections->send($unit, $template, new User(['name' => 'Treasurer']));

    // Nothing here has handed anything to a push, mail or SMS provider, and a
    // log claiming delivery on the strength of an insert is the first thing a
    // dispute would disprove.
    expect($notice->delivery_state)->toBe(DunningNotice::QUEUED)
        ->and($notice->statusLabel())->toBe('Queued')
        ->and($notice->sent_by_name)->toBe('Treasurer');

    // Board 5's "Last reminder" reads this log, so a household just chased is
    // never shown as never reminded.
    $row = collect(app(Dues::class)->arrearsBoard()['rows'])->firstWhere('id', $unit->id);

    if ($row !== null) {
        expect($row['last_reminder'])->toStartWith('Today, ');
    }
});

/* ------------------------------------------------------------------ */
/* what the boards read */
/* ------------------------------------------------------------------ */

it('draws board 8 balances from the ledger and nowhere else', function () {
    $board = $this->collections->dunningBoard();

    expect($board['sends'])->not->toBeEmpty();

    foreach ($board['sends'] as $row) {
        // No notice stores an amount, so the Balance column cannot drift from
        // the arrears board beside it. Re-summed here in raw SQL to prove it.
        expect($row['balance_minor'])->toBe(unitReceivable($row['unit_id']));
    }

    expect($board['mergeFields'])->toBe(DunningTemplate::MERGE_FIELDS);
});

it('gives board 7 the unit, the balance and a preview that has written nothing', function () {
    $unit = CollectionsFixture::unit('Lot 63');

    $before = PaymentPlan::query()->count();
    $board = $this->collections->planBoard($unit);

    expect($board['balance_minor'])->toBe(unitReceivable($unit->id))
        ->and($board['resident_first_name'])->toBe('Devon')
        ->and($board['preview']['instalments'])->toBe(Collections::DEFAULT_INSTALMENTS)
        ->and($board['frequencies'])->toBe([PaymentPlan::FREQUENCY])

        // Board 7 draws the schedule before anything is agreed, so drawing it
        // must not commit the household to anything.
        ->and(PaymentPlan::query()->count())->toBe($before);
});

it('draws board 7\'s preview for the count and the start that were typed, not four from the 28th (Hassam, 12 Oct)', function () {
    // On live the instalment amount read the same for two instalments as for four: the count was never read.
    $unit = CollectionsFixture::unit('Lot 63');
    $owed = unitReceivable($unit->id);

    $two = $this->collections->planBoard($unit, 2, '2026-11-15')['preview'];
    $four = $this->collections->planBoard($unit, 4, '2026-11-15')['preview'];

    expect($two['instalments'])->toBe(2)
        ->and($two['starts_on'])->toBe('2026-11-15')
        ->and($two['rows'])->toHaveCount(2)
        ->and(array_sum(array_column($two['rows'], 'amount_minor')))->toBe($owed)
        ->and($two['instalment_minor'])->toBe(intdiv($owed, 2))
        ->and($four['instalment_minor'])->toBe(intdiv($owed, 4))
        ->and($two['instalment_minor'])->not->toBe($four['instalment_minor']);

    // A count out of range or a start that is not a date falls back to the defaults rather than refusing the read.
    $fallback = $this->collections->planBoard($unit, 99, 'not a date')['preview'];

    expect($fallback['instalments'])->toBe(Collections::DEFAULT_INSTALMENTS);
});

/* ------------------------------------------------------------------ */
/* the seed the boards are reviewed against */
/* ------------------------------------------------------------------ */

it('seeds board 8 ladder, its log and Lot 47 plan', function () {
    // By key, not by counting the table: other assertions in this file create
    // templates of their own, and a test that broke because a neighbour added
    // a row would be testing the file rather than the seeder.
    $templates = DunningTemplate::query()
        ->whereIn('key', ['day20', 'reminder1', 'reminder4plus'])
        ->orderBy('stage')
        ->get();

    expect($templates->pluck('label')->all())->toBe(['Day 20', 'Reminder 1', 'Reminder 4+'])
        ->and($templates->pluck('stage')->all())->toBe([0, 1, 4])
        ->and($templates->pluck('channel')->all())->toBe(['push+email', 'push+email', 'push+email+sms'])
        ->and($templates->firstWhere('key', 'reminder1')->body)
        ->toBe(
            'Hi {resident_first_name}, your maintenance fee of {amount_due} for {unit_label} is now '.
            'overdue. Please settle it at your earliest convenience to avoid further reminders.'
        );

    /*
     * THE SEEDER'S LOG, NAMED BY THE LADDER IT WAS SENT FROM.
     *
     * `orderBy('id')->limit(5)` said "the first five rows of the table", which is
     * a claim about a position rather than about anything this fixture owns: a
     * seeder that made four would have quietly borrowed a fifth row from
     * whatever wrote next, and the assertion would have gone on passing while
     * testing somebody else. Scoped to the three seeded rungs, with the count
     * stated outright, it can only be the seeder's own log.
     */
    $log = DunningNotice::query()
        ->whereIn('dunning_template_id', $templates->pluck('id')->all())
        ->orderBy('id')
        ->get();

    expect($log)->toHaveCount(5)
        ->and($log->pluck('template_label')->all())
        ->toBe(['Reminder 1', 'Reminder 4', 'Reminder 2', 'Reminder 5', 'Advance notice'])
        ->and($log->firstWhere('template_label', 'Reminder 5')->delivery_state)->toBe(DunningNotice::FAILED)
        ->and($log->firstWhere('template_label', 'Reminder 5')->delivery_detail)->toBe('SMS failed')
        ->and($log->firstWhere('template_label', 'Reminder 5')->statusLabel())->toBe('SMS failed');

    // Board 7, figure for figure: J$12,400.00 over four instalments of
    // J$3,100.00, agreed by Andrea Fletcher.
    $plan = PaymentPlan::query()
        ->where('unit_id', CollectionsFixture::unit('Lot 47')->id)
        ->orderBy('id')
        ->firstOrFail();

    expect($plan->status)->toBe(PaymentPlan::ACTIVE)
        ->and($plan->agreed_by_name)->toBe('Andrea Fletcher')
        ->and($plan->total_minor)->toBe(12_400_00)
        ->and($plan->instalments)->toBe(4)
        ->and($plan->schedule->pluck('amount_minor')->all())->toBe([3_100_00, 3_100_00, 3_100_00, 3_100_00]);
});

it('adds nothing on a second run', function () {
    $notices = DunningNotice::query()->count();
    $templates = DunningTemplate::query()->count();
    $plans = PaymentPlan::query()->count();
    $instalments = PaymentPlanInstalment::query()->count();

    (new CollectionsSeeder)->run();

    // A dunning log is the one record that must never be padded: a second run
    // stacking a second week of reminders on real history would make the log
    // evidence of something that did not happen.
    expect(DunningNotice::query()->count())->toBe($notices)
        ->and(DunningTemplate::query()->count())->toBe($templates)
        ->and(PaymentPlan::query()->count())->toBe($plans)
        ->and(PaymentPlanInstalment::query()->count())->toBe($instalments);
});

it('does not restore a template a treasurer has reworded', function () {
    $template = DunningTemplate::where('key', 'reminder1')->firstOrFail();

    $template->forceFill(['body' => 'The treasurer rewrote this on board 8.'])->save();

    (new CollectionsSeeder)->run();

    // The structure of a step is the estate's to define; its wording belongs to
    // whoever last edited it.
    expect(DunningTemplate::where('key', 'reminder1')->firstOrFail()->body)
        ->toBe('The treasurer rewrote this on board 8.');
});
