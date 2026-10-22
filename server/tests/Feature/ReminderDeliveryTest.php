<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Notifications\ArrearsReminder;
use App\Services\Estate\Collections;
use App\Services\Estate\ReminderDelivery;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Reminder delivery, with sending OFF (M84 §3.5)
|--------------------------------------------------------------------------
|
| The arrears ladder has queued a reminder every morning since 29 September and nothing ever took one off the queue.
| The pipeline now does: queued, sending, delivered or failed, the failure's reason on the row. Three rules, the most
| important of the night: `reminders.delivery_enabled` ships false, and while it is false the pipeline only previews
| (what would go out, to whom, and nothing leaves the building); turned on, the old queue is never flushed, everything
| queued before delivery started is marked expired and never sent; and it is not turned on until EST-B-01 is fixed and
| Hassam says so (that one is his, not a test's).
|
| The fixture estate, lots 7481 (an address on file), 7482 (no address: the forced failure) and 7483 (a reminder
| queued on 29 September). Its database is committed: this file removes its own notices.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The ladder the estate sends from.
    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    config(['reminders.delivery_enabled' => false, 'reminders.deliver_from' => null, 'estate_features.reminder_delivery' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();
    $units = Unit::query()->whereIn('reference', ['Lot 7481', 'Lot 7482', 'Lot 7483'])->pluck('id');
    DunningNotice::query()->whereIn('unit_id', $units)->delete();
});

/** A lot of this file's own with a primary resident, and that resident's address or none. */
function deliveryUnit(string $reference, ?string $email): Unit
{
    $unit = FacilitiesFixture::scratchUnit($reference);

    if ($unit->household === null) {
        $household = Household::create(['unit_id' => $unit->id, 'name' => $reference.' household', 'access_restricted' => false]);

        Resident::create([
            'household_id' => $household->id,
            'full_name' => 'Delroy Campbell',
            'email' => $email,
            'relationship' => 'owner',
            'is_primary' => true,
        ]);
    }

    return $unit->refresh();
}

/** A reminder queued for the unit, as the morning ladder queues one: through `Collections::send()`. */
function deliveryQueued(Unit $unit, ?Carbon $at = null): DunningNotice
{
    if ($at !== null) {
        Carbon::setTestNow($at);
    }

    try {
        return app(Collections::class)->send($unit, DunningTemplate::query()->where('key', 'reminder1')->firstOrFail(), senderName: 'Automated run');
    } finally {
        Carbon::setTestNow();
    }
}

it('ships with sending off: a run previews, changes nothing and sends nothing', function () {
    Notification::fake();
    // The moment set, as if delivery were about to start: the switch alone has to hold.
    config(['reminders.deliver_from' => Carbon::now()->subMinutes(5)->toIso8601String()]);
    $unit = deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test');
    $notice = deliveryQueued($unit);
    $old = deliveryQueued(deliveryUnit('Lot 7483', 'lot7483@reminder-delivery.test'), Carbon::parse('2026-09-29 14:00:00'));

    $report = app(ReminderDelivery::class)->run();

    expect($report['enabled'])->toBeFalse()
        ->and($report['delivered'])->toBe(0)
        ->and($report['failed'])->toBe(0)
        ->and($report['expired'])->toBe(0)
        ->and($notice->refresh()->delivery_state)->toBe(DunningNotice::QUEUED)
        ->and($old->refresh()->delivery_state)->toBe(DunningNotice::QUEUED);
    Notification::assertNothingSent();
});

it('previews the right households with the right text, and counts what would expire', function () {
    $fresh = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));
    $old = deliveryQueued(deliveryUnit('Lot 7483', 'lot7483@reminder-delivery.test'), Carbon::parse('2026-09-29 14:00:00'));

    $preview = app(ReminderDelivery::class)->preview();
    $rows = collect($preview['rows'])->keyBy('id');

    expect($rows->has($fresh->id))->toBeTrue()
        ->and($rows->has($old->id))->toBeFalse()
        ->and($rows[$fresh->id]['unit'])->toBe('Lot 7481')
        ->and($rows[$fresh->id]['to'])->toBe('Delroy Campbell')
        ->and($rows[$fresh->id]['subject'])->toBe($fresh->subject)
        ->and($rows[$fresh->id]['body'])->toBe($fresh->body)
        ->and(collect($rows[$fresh->id]['legs'])->firstWhere('channel', 'Email')['to'] ?? null)->toBe('lot7481@reminder-delivery.test')
        ->and($preview['would_expire'])->toBeGreaterThanOrEqual(1)
        ->and($preview['enabled'])->toBeFalse();
});

it('when turned on: queued becomes delivered, a forced failure shows failed with its reason, and the old queue expires unsent', function () {
    Notification::fake();
    config(['reminders.delivery_enabled' => true, 'reminders.deliver_from' => Carbon::now()->subMinutes(5)->toIso8601String()]);

    $delivered = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));
    $failing = deliveryQueued(deliveryUnit('Lot 7482', null));
    $old = deliveryQueued(deliveryUnit('Lot 7483', 'lot7483@reminder-delivery.test'), Carbon::parse('2026-09-29 14:00:00'));

    $report = app(ReminderDelivery::class)->run();

    expect($report['enabled'])->toBeTrue()
        ->and($report['refused'])->toBeNull()
        ->and($delivered->refresh()->delivery_state)->toBe(DunningNotice::DELIVERED)
        ->and($failing->refresh()->delivery_state)->toBe(DunningNotice::FAILED)
        ->and(str_contains((string) $failing->delivery_detail, 'No email address'))->toBeTrue()
        ->and($failing->statusLabel())->toBe($failing->delivery_detail)
        ->and($old->refresh()->delivery_state)->toBe(DunningNotice::EXPIRED)
        ->and($old->statusLabel())->toBe('Expired, never sent');

    Notification::assertSentOnDemand(ArrearsReminder::class, fn (ArrearsReminder $sent, array $channels, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'lot7481@reminder-delivery.test' && $sent->subject === $delivered->subject);
    Notification::assertSentOnDemandTimes(ArrearsReminder::class, 1);
});

it('refuses to deliver without the moment delivery started, and then changes nothing', function () {
    Notification::fake();
    config(['reminders.delivery_enabled' => true, 'reminders.deliver_from' => null]);
    $notice = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));

    $report = app(ReminderDelivery::class)->run();

    expect($report['refused'])->toBeString()
        ->and($report['delivered'] + $report['failed'] + $report['expired'])->toBe(0)
        ->and($notice->refresh()->delivery_state)->toBe(DunningNotice::QUEUED);
    Notification::assertNothingSent();
});

it('never sends a reminder twice: one a run left being sent is left alone', function () {
    Notification::fake();
    config(['reminders.delivery_enabled' => true, 'reminders.deliver_from' => Carbon::now()->subMinutes(5)->toIso8601String()]);
    $notice = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));

    // Taken off the queue an hour ago by a run that died before it could say what became of it.
    DunningNotice::query()->whereKey($notice->id)->toBase()->update(['delivery_state' => DunningNotice::SENDING, 'updated_at' => Carbon::now()->subHour()]);

    app(ReminderDelivery::class)->run();

    expect($notice->refresh()->delivery_state)->toBe(DunningNotice::SENDING)
        ->and($notice->statusLabel())->toBe('Sending');
    Notification::assertNothingSent();
});

it('runs as a command that, with sending off, says so and sends nothing', function () {
    Notification::fake();
    config(['reminders.deliver_from' => Carbon::now()->subMinutes(5)->toIso8601String()]);
    $notice = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));

    expect(Artisan::call('reminders:deliver', ['--estate' => FacilitiesFixture::ESTATE]))->toBe(0);
    // Read once: `Artisan::output()` empties the buffer it reads from.
    $output = Artisan::output();

    FacilitiesFixture::boot();
    expect(str_contains($output, 'delivery is off, nothing was sent'))->toBeTrue()
        ->and($notice->refresh()->delivery_state)->toBe(DunningNotice::QUEUED);
    Notification::assertNothingSent();
});

it('is absent from Dunning & reminders with its switch off, and there with it on, still sending nothing', function () {
    Notification::fake();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $notice = deliveryQueued(deliveryUnit('Lot 7481', 'lot7481@reminder-delivery.test'));

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('delivery'));

    config(['estate_features.reminder_delivery' => true]);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/finance/dunning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('delivery.enabled', false)
            ->where('delivery.rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $notice->id && $row['unit'] === 'Lot 7481')));

    FacilitiesFixture::boot();
    expect($notice->refresh()->delivery_state)->toBe(DunningNotice::QUEUED);
    Notification::assertNothingSent();
});
