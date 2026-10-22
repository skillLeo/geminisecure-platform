<?php

declare(strict_types=1);

use App\Events\AlertRaised;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingRound;
use App\Models\ResidentAccount;
use App\Services\Dispatch\PanicDrillMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| DRILL MODE WAKES NOBODY — M53 §3A.1, M54 §2.3
|--------------------------------------------------------------------------
|
| "Only the server may raise a drill, switched on for an account for a set time from the office. A phone that could
| mark its own panic a drill could hide a real one." And the sign-off: "panic with drill mode wakes nobody".
|
| Each test stands an officer on duty with a phone that can ring, so a press that DID fan out would show here, and
| the first test proves that the same press with drill mode off does reach them.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-10-08T20:00:00Z'));

    $this->post = RingFixture::post();
    [$this->officer, $this->officerBearer, $this->officerPhone] = RingFixture::guard('GS-DRILL-1', push: RingFixture::ready(), postId: $this->post->id);
    RingFixture::shift($this->officer, $this->post->id, clockedOn: true);
});

afterEach(fn () => RingFixture::tearDown());

/** A resident at a lot of this file's own, their bearer token, and their account. */
function drillResident(string $email, string $lot): array
{
    $bearer = RingFixture::residentToken($email, $lot);

    return [$bearer, ResidentAccount::query()->where('tenant_id', FacilitiesFixture::ESTATE)->where('destination', $email)->firstOrFail()];
}

function drillPress(object $test, string $bearer, array $body = ['mode' => 'audible']): DuressAlert
{
    return DuressAlert::query()->findOrFail(RingFixture::call($test, $bearer, 'duress.store', $body)->json('id'));
}

/** The officer on duty was pushed this alert. */
function drillOfficerPushed(object $test): bool
{
    return Bus::dispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => in_array($test->officerPhone->id, $job->tokenIds, true) && ($job->data['type'] ?? null) === 'site_alert')->isNotEmpty();
}

it('reaches the officer on duty with the same press when drill mode is off, so the test can see a fan-out', function () {
    [$bearer] = drillResident('drill.off@example.com', 'Lot 9837');

    $real = drillPress($this, $bearer);

    expect($real->is_drill)->toBeFalse()->and($real->wakesNobody())->toBeFalse()
        ->and(drillOfficerPushed($this))->toBeTrue()
        ->and($real->fresh()->alerted_handsets)->toBe(1)
        ->and((new AlertRaised($real->fresh()))->broadcastWith()['is_urgent'])->toBeTrue();
});

it('raises a press from an account in drill mode as a DRILL that rings, mails and texts nobody, and sounds no siren', function () {
    [$bearer, $account] = drillResident('drill.on@example.com', 'Lot 9831');
    app(PanicDrillMode::class)->switchOn($account, 60, 'Testing the panic button', 'Drill test');

    $alert = drillPress($this, $bearer);
    RingFixture::runJobs([RingRound::class], evenIfNotDue: true);

    expect($alert->is_drill)->toBeTrue()
        ->and($alert->drill_mode)->toBeTrue()
        ->and($alert->wakesNobody())->toBeTrue()
        // No push to any phone, no ring round even planned, no ring sent, and no mail or text queued.
        ->and(drillOfficerPushed($this))->toBeFalse()
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $alert->id)->count())->toBe(0)
        ->and(RingFixture::sent()->all())->toBe([])
        ->and(RingFixture::queued()->map(fn (object $job): string => $job->command::class)->all())->toBe([])
        ->and($alert->fresh()->alerted_handsets)->toBeNull();

    Bus::assertNotDispatched(SendExpoPush::class);
    Notification::assertNothingSent();

    // The consoles have it, labelled, and silent: the siren sounds on `is_urgent` alone.
    $broadcast = (new AlertRaised($alert->fresh()))->broadcastWith();
    expect($broadcast['is_urgent'])->toBeFalse()->and($broadcast['is_drill'])->toBeTrue()->and($broadcast['drill_mode'])->toBeTrue();

    // The resident's own phone is told what happened, in the answer it reads back.
    $shown = RingFixture::call($this, $bearer, 'duress.show', path: ['alert' => $alert->id]);
    expect($shown->json('is_drill'))->toBeTrue()->and($shown->json('drill_mode'))->toBeTrue()->and($shown->json('alerted_handsets'))->toBeNull();
});

it('keeps a drill-mode press off the guards\' phones, by push and by pull', function () {
    [$bearer, $account] = drillResident('drill.pull@example.com', 'Lot 9832');
    app(PanicDrillMode::class)->switchOn($account, 60, 'Testing the panic button', 'Drill test');

    $alert = drillPress($this, $bearer);

    $pulled = RingFixture::call($this, $this->officerBearer, 'sync.pull');
    expect(collect($pulled->json('alerts'))->pluck('alert_id')->all())->not->toContain($alert->id);
});

it('ends by itself: a press after the time is a real alert', function () {
    [$bearer, $account] = drillResident('drill.ends@example.com', 'Lot 9833');
    app(PanicDrillMode::class)->switchOn($account, 1, 'Testing the panic button', 'Drill test');

    // Two minutes on: past the switch, and well inside the phone's own 30-minute sign-in window.
    Carbon::setTestNow(now()->addMinutes(2));
    $alert = drillPress($this, $bearer);

    expect($alert->is_drill)->toBeFalse()->and($alert->wakesNobody())->toBeFalse()
        ->and(drillOfficerPushed($this))->toBeTrue();
});

it('never makes a drill of a press that waited on the phone for a signal, or of anything the phone sends', function () {
    [$bearer, $account] = drillResident('drill.offline@example.com', 'Lot 9834');

    // Nothing in the request makes a drill: drill mode is off, and the phone claims otherwise.
    $claimed = drillPress($this, $bearer, ['mode' => 'audible', 'is_drill' => true, 'drill_mode' => true]);
    expect($claimed->is_drill)->toBeFalse()->and($claimed->wakesNobody())->toBeFalse();

    // Drill mode on, but this press was captured offline: nobody can say the switch was on when it was pressed.
    app(PanicDrillMode::class)->switchOn($account, 60, 'Testing the panic button', 'Drill test');
    $offline = drillPress($this, $bearer, ['mode' => 'audible', 'captured_offline' => true]);

    expect($offline->is_drill)->toBeFalse()->and($offline->wakesNobody())->toBeFalse();
});

it('tells the phone while drill mode is on, with when it ends and why, and nothing once it is off', function () {
    [$bearer, $account] = drillResident('drill.me@example.com', 'Lot 9835');

    expect(RingFixture::call($this, $bearer, 'me.show')->json('drill_mode'))->toBeNull();

    app(PanicDrillMode::class)->switchOn($account, 90, 'Testing the panic button', 'Drill test');
    $on = RingFixture::call($this, $bearer, 'me.show')->json('drill_mode');

    expect($on['until'])->toBe(now()->addMinutes(90)->toIso8601String())
        // 21:30 UTC is 4:30 PM in Kingston, the estate's clock.
        ->and($on['until_local'])->toBe('4:30 PM')
        ->and($on['reason'])->toBe('Testing the panic button');

    app(PanicDrillMode::class)->switchOff($account->fresh(), 'Drill test');
    expect(RingFixture::call($this, $bearer, 'me.show')->json('drill_mode'))->toBeNull();
});

it('is switched on and off only by the server command, after a dry run that changes nothing, and audited both ways', function () {
    [, $account] = drillResident('drill.command@example.com', 'Lot 9836');
    $estate = FacilitiesFixture::ESTATE;

    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--unit' => 'Lot 9836'])
        ->expectsOutputToContain('account '.$account->id)
        ->assertSuccessful();

    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--account' => (string) $account->id, '--minutes' => '120', '--reason' => 'Testing the panic button'])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();
    expect(PanicDrillMode::isOn($account->fresh()))->toBeFalse();

    // Never longer than four hours, and never without a reason.
    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--account' => (string) $account->id, '--minutes' => '241', '--reason' => 'Too long', '--confirm' => true])->assertFailed();
    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--account' => (string) $account->id, '--minutes' => '60', '--confirm' => true])->assertFailed();
    expect(PanicDrillMode::isOn($account->fresh()))->toBeFalse();

    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--account' => (string) $account->id, '--minutes' => '120', '--reason' => 'Testing the panic button', '--confirm' => true])
        ->expectsOutputToContain('Drill mode is ON')
        ->assertSuccessful();
    expect(PanicDrillMode::isOn($account->fresh()))->toBeTrue()
        ->and($account->fresh()->drill_mode_until->toIso8601String())->toBe(now()->addMinutes(120)->toIso8601String());

    $this->artisan('panic:drill-mode', ['--estate' => $estate, '--account' => (string) $account->id, '--off' => true, '--confirm' => true])
        ->expectsOutputToContain('Drill mode is OFF')
        ->assertSuccessful();
    expect(PanicDrillMode::isOn($account->fresh()))->toBeFalse();

    expect(AuditEntry::query()->where('entity_type', 'ResidentAccount')->where('entity_id', (string) $account->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['panic.drill_mode_on', 'panic.drill_mode_off']);
});
