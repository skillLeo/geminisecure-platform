<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\DuressAlertRingEvent;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Ring\GuardRing;
use App\Support\GrantedTables;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| EACH ROUND IS ON THE HAND-OFF RECORD, AND NEVER COUNTS AS THE RAISE'S REACH — §4.5, G13
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-26T03:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g3, , $this->t3] = RingFixture::guard('GS-RL-3', push: RingFixture::ready(), postId: $this->post->id);
});

afterEach(fn () => RingFixture::tearDown());

it('with nobody on shift at the raise, writes the escalation as a leg of its own and leaves "Guards on shift" not reached', function () {
    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.legs@example.com', 'Lot 828'), 'duress.store', ['mode' => 'audible'])->json('id'));

    $before = app(DeliveryReport::class)->forAlert($alert);

    Carbon::setTestNow(now()->addSeconds(2));
    RingFixture::runJobs([RingRound::class]);

    $after = app(DeliveryReport::class)->forAlert($alert);
    $guards = collect($after['destinations'])->firstWhere('key', DuressAlertDelivery::GUARDS_ON_SHIFT);

    // The round reached an off-duty officer: a leg of its own, under the destination…
    expect($guards['rings'])->toHaveCount(1)
        ->and($guards['rings'][0]['round'])->toBe(1)
        ->and($guards['rings'][0]['recipients_label'])->toBe('1 handset')
        ->and($guards['rings'][0]['round_label'])->toMatch('/^Ring 2 · handed off \d+\.\d s after the alert$/')
        // …and "Guards on shift" is exactly as reached as it was before the ring: not at all.
        ->and($guards['reached'])->toBeFalse()
        ->and($after['reached'])->toBe($before['reached'])
        ->and($after['of'])->toBe(7);

    expect(DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->whereNull('round')->count())->toBe(
        DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->count() - DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->whereNotNull('round')->count(),
    );
});

it('keeps the ring\'s phone ledger append-only, at the database', function () {
    $id = DB::connection('mysql')->table('duress_alert_ring_events')->insertGetId([
        'duress_alert_id' => 999999002, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 0, 'test_seq' => 0,
        'push_token_id' => 1, 'event' => 'sent_ring', 'detail' => 'ready', 'created_at' => now(),
    ]);

    expect(fn () => DB::connection('mysql')->table('duress_alert_ring_events')->where('id', $id)->update(['detail' => 'x']))
        ->toThrow(QueryException::class, 'duress_alert_ring_events is append-only');

    expect(fn () => DB::connection('mysql')->table('duress_alert_ring_events')->where('id', $id)->delete())
        ->toThrow(QueryException::class, 'duress_alert_ring_events is append-only');

    expect(fn () => DuressAlertRingEvent::query()->findOrFail($id)->forceFill(['event' => 'shown'])->save())
        ->toThrow(QueryException::class, 'duress_alert_ring_events is append-only');

    // A second write of the same event for the same phone is not an error and not a second row.
    expect(DuressAlertRingEvent::once(['duress_alert_id' => 999999002, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 0, 'push_token_id' => 1, 'event' => 'sent_ring']))->toBeFalse()
        ->and(GrantedTables::CENTRAL_APPEND_ONLY)->toContain('duress_alert_ring_events')
        ->and(GrantedTables::CENTRAL_MUTABLE)->toContain('duress_alert_rings', 'duress_alert_ring_rounds', 'duress_alert_ring_targets');
});

it('stops the ring from inside an estate, writing the central record (G5)', function () {
    RingFixture::shift($this->g3, $this->post->id, clockedOn: true);
    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.tenancy@example.com', 'Lot 829'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);

    DuressAlert::withoutEvents(fn () => $alert->forceFill(['acknowledged_at' => now()])->save());

    tenancy()->initialize(\App\Models\Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));
    expect(DB::getDefaultConnection())->toBe('tenant');

    $phones = app(GuardRing::class)->stop($alert->fresh());

    tenancy()->end();
    FacilitiesFixture::boot();

    expect($phones)->toBe(1)
        ->and(\App\Models\DuressAlertRing::query()->where('duress_alert_id', $alert->id)->value('answered_why'))->toBe('acknowledged');
});
