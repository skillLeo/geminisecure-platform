<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingRound;
use App\Models\Guard;
use App\Models\Role;
use App\Services\Dispatch\AlertProgress;
use App\Services\Push\PanicFanOut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| AN OFFICER RUNG AT THE ESCALATION CAN ANSWER — the guard ring §3.3, and at once
|--------------------------------------------------------------------------
|
| The escalation rings every active officer of the estate, off duty too. One who hears it
| and runs must be able to open the alert and say "on my way" from the Guard App, and a
| dispatcher must be able to send them. Before the ring, `guardsFor()` was only the
| officers on duty and rostered, and they were refused `403 not_on_shift`.
|
| And when the raise reached NOBODY, the escalation does not wait sixty seconds.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T21:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g1, , $this->t1] = RingFixture::guard('GS-RE-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g3, $this->b3, $this->t3] = RingFixture::guard('GS-RE-3', push: RingFixture::ready(), postId: $this->post->id);
});

afterEach(fn () => RingFixture::tearDown());

it('lets an off-duty officer rung at the escalation open the alert, say on my way, and be dispatched — and nobody never rung', function () {
    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);
    [, $bNever] = RingFixture::guard('GS-RE-9', push: RingFixture::ready(), postId: $this->post->id);
    $never = Guard::query()->where('employee_number', 'GS-RE-9')->firstOrFail();

    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.escalated@example.com', 'Lot 818'), 'duress.store', ['mode' => 'audible'])->json('id'));

    // Before the escalation, G3 is not on the list and is refused.
    RingFixture::runJobs([RingRound::class]);
    expect(app(PanicFanOut::class)->guardsFor($alert))->not->toContain($this->g3->id);
    RingFixture::call($this, $this->b3, 'alerts.show', path: ['alert' => $alert->id], status: 403);

    // G9 is posted here but is not active any more by the time the escalation rings.
    $never->forceFill(['status' => 'inactive'])->saveQuietly();

    Carbon::setTestNow($alert->server_time->copy()->addSeconds(60));
    RingFixture::runJobs([RingRound::class]);

    expect(app(PanicFanOut::class)->guardsFor($alert))->toContain($this->g3->id)->not->toContain($never->id)
        ->and(app(AlertProgress::class)->candidates($alert)->pluck('id')->all())->toContain($this->g3->id);

    $shown = RingFixture::call($this, $this->b3, 'alerts.show', path: ['alert' => $alert->id]);
    expect($shown->json('can_en_route'))->toBeTrue();

    $going = RingFixture::call($this, $this->b3, 'alerts.en_route', path: ['alert' => $alert->id]);
    expect($going->json('responder_is_you'))->toBeTrue();
});

it('lets a dispatcher send an officer the escalation rang', function () {
    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.dispatch@example.com', 'Lot 819'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);

    $sent = app(AlertProgress::class)->dispatch($alert, $this->g3, FacilitiesFixture::geminiViewer(Role::DISPATCHER));

    expect($sent->responding_guard_id)->toBe($this->g3->id);
});

it('escalates at once when nobody was on duty or rostered, with no call-style ring at the raise', function () {
    $resident = RingFixture::residentToken('ring.nobody@example.com', 'Lot 820');
    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, $resident, 'duress.store', ['mode' => 'audible'])->json('id'));

    // The resident's read-back before the escalation has run: the raise reached nobody, and the ring has sent to nobody yet.
    $before = RingFixture::call($this, $resident, 'duress.show', path: ['alert' => $alert->id]);
    expect($before->json('alerted_handsets'))->toBe(0)->and($before->json('rang_handsets'))->toBe(0);

    $rounds = DuressAlertRingRound::query()->where('duress_alert_id', $alert->id)->orderBy('round')->get();

    expect($rounds[0]->outcome)->toBe('none')
        ->and($rounds[0]->note)->toContain('no officer is clocked on or rostered here today')
        ->and($rounds[1]->reason)->toBe('nobody_on_duty')
        ->and($rounds[1]->due_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and(RingFixture::queued(RingRound::class)->map(fn (object $j): int => $j->command->round)->all())->toBe([1]);

    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::sent()->where('data.type', 'site_ring')->pluck('to')->sort()->values()->all())
        ->toBe(collect([$this->t1->expo_token, $this->t3->expo_token])->sort()->values()->all())
        ->and(RingFixture::sent()->where('data.type', 'site_alert')->first()['title'])->toBe('NO OFFICER REACHED · Panic alert');

    /*
     * M18 §1.6: and the resident is TOLD. The raise's count stays 0 — it is what the raise reached — but the read-back
     * now says two officer phones are being rung, so the screen stops saying "No officer's phone was reached".
     */
    $after = RingFixture::call($this, $resident, 'duress.show', path: ['alert' => $alert->id]);
    expect($after->json('alerted_handsets'))->toBe(0)->and($after->json('rang_handsets'))->toBe(2);
});

it('escalates at once when officers were on shift but no phone of theirs could be pushed', function () {
    [$g5] = RingFixture::guard('GS-RE-5', push: null, postId: $this->post->id);
    RingFixture::shift($g5, $this->post->id, clockedOn: true);

    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.nophone@example.com', 'Lot 821'), 'duress.store', ['mode' => 'audible'])->json('id'));

    $rounds = DuressAlertRingRound::query()->where('duress_alert_id', $alert->id)->orderBy('round')->get();

    expect($rounds[0]->outcome)->toBe('none')
        ->and($rounds[1]->reason)->toBe('no_handset')
        ->and($rounds[1]->due_at->getTimestamp())->toBe(now()->getTimestamp());
});
