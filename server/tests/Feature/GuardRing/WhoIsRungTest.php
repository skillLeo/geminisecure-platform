<?php

declare(strict_types=1);

use App\Jobs\Alerts\SendRingEscalationSms;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Jobs\Ring\SendRingPush;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\DuressAlertRingEvent;
use App\Models\DuressAlertRingRound;
use App\Models\DuressAlertRingTarget;
use App\Models\Role;
use App\Notifications\PanicStillUnanswered;
use App\Services\Push\PanicFanOut;
use App\Services\Ring\RingTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| WHO IS RUNG, AND WHEN — the guard ring §3, §15.1
|--------------------------------------------------------------------------
|
| One resident's panic at estate A, and six officers there:
|
|   G1  on duty                     Android, call-style ready
|   G2  rostered today              Android, an older Guard App (no ring module)
|   G3  off duty                    Android, call-style ready
|   G4  suspended                   never rung
|   G6  on duty, under their own    never rung by the ring (R14)
|       open silent duress
|
| and G7 on duty at estate B, who is never rung by estate A's panic.
|
| THE CLOCK IS FAKED, the jobs are read back from the queue and run as a worker runs
| them — with the estate open (G5) — and Expo is faked with an answer per message.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T14:00:00Z'));

    $this->post = RingFixture::post();
    $otherPost = RingFixture::post(RingFixture::OTHER_ESTATE);

    [$this->g1, , $this->t1] = RingFixture::guard('GS-RG-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g2, , $this->t2] = RingFixture::guard('GS-RG-2', postId: $this->post->id);
    [$this->g3, , $this->t3] = RingFixture::guard('GS-RG-3', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g4, , $this->t4] = RingFixture::guard('GS-RG-4', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g6, , $this->t6] = RingFixture::guard('GS-RG-6', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g7, , $this->t7] = RingFixture::guard('GS-RG-7', RingFixture::OTHER_ESTATE, push: RingFixture::ready(), postId: $otherPost->id);

    $this->g4->forceFill(['status' => 'suspended'])->save();

    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);
    RingFixture::shift($this->g2, $this->post->id, clockedOn: false);
    RingFixture::shift($this->g6, $this->post->id, clockedOn: true);
    RingFixture::shift($this->g7, $otherPost->id, clockedOn: true);

    // G6's own open, silent duress.
    DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'kind' => 'duress', 'guard_id' => $this->g6->id, 'status' => 'open',
        'server_time' => now()->subMinutes(5), 'is_simulated' => false,
    ]));

    // The estate's own Head of Security, with a phone and a proven alert address.
    $this->head = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    // A moment BEFORE the frozen clock: the factory proves an address at `now()`, and the
    // same instant again is no change, which the model reads as the address changing unproven.
    $this->head->forceFill(['contact_email' => 'hos.ring@example.com', 'contact_email_verified_at' => now()->subMinute(), 'contact_phone' => '876-555-0141'])->save();

    $this->answer = RingFixture::call($this, RingFixture::residentToken('ring.who@example.com', 'Lot 811'), 'duress.store', ['mode' => 'audible']);
    $this->alert = DuressAlert::query()->findOrFail($this->answer->json('id'));
});

afterEach(fn () => RingFixture::tearDown());

it('leaves the raise exactly as it was, and books round 0 now and the escalation for 60 seconds, on the panic queue', function () {
    // Today's push: the same job, the same officers on duty and rostered, no ring field on it.
    Bus::assertDispatched(SendExpoPush::class, function (SendExpoPush $job): bool {
        return $job->data === ['type' => 'site_alert', 'id' => (string) $this->alert->id, 'kind' => 'panic']
            && in_array($this->t1->id, $job->tokenIds, true)
            && in_array($this->t2->id, $job->tokenIds, true)
            && ! in_array($this->t7->id, $job->tokenIds, true)
            && $job->urgent;
    });

    // The resident is told exactly what today's fan-out reached: never a ring's figure.
    expect($this->answer->json('alerted_handsets'))->toBe(3)
        ->and($this->answer->json('alerted_via'))->toBe(PanicFanOut::ON_DUTY);

    $rounds = RingFixture::queued(RingRound::class);

    expect($rounds->map(fn (object $j): int => $j->command->round)->all())->toBe([0, 1])
        ->and($rounds->pluck('queue')->unique()->all())->toBe([(string) config('push.ring_queue')])
        ->and($rounds[0]->available_at)->toBe(now()->getTimestamp())
        ->and($rounds[1]->available_at)->toBe(now()->addSeconds(60)->getTimestamp())
        // Queued from inside the estate's request, and carrying it, as a worker will run it.
        ->and($rounds[0]->payload['tenant_id'])->toBe(FacilitiesFixture::ESTATE);

    // Six rounds, each due at its time; the officers pushed at the raise are the alert's list.
    expect(DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->orderBy('round')->get()
        ->map(fn (DuressAlertRingRound $r): int => (int) $r->due_at->diffInSeconds($this->alert->server_time, true))->all())
        ->toBe([0, 60, 180, 300, 420, 540])
        ->and(DuressAlertRingTarget::query()->where('duress_alert_id', $this->alert->id)->pluck('guard_id')->sort()->values()->all())
        ->toBe(collect([$this->g1->id, $this->g2->id, $this->g6->id])->sort()->values()->all());
});

it('rings at the raise only the on-duty phones set up to ring like a call, with a data-only message and a valid ticket', function () {
    RingFixture::runJobs([RingRound::class]);

    $sent = RingFixture::sent();

    // G1 alone: G2 has no ring module (it had today's alert), G6 is under their own duress, G7 is elsewhere.
    expect($sent->pluck('to')->all())->toBe([$this->t1->expo_token]);

    $ring = $sent->first();

    expect(array_keys($ring))->toBe(['to', 'priority', 'ttl', 'data'])
        ->and($ring['priority'])->toBe('high')
        ->and($ring['ttl'])->toBe(55)
        ->and($ring['data']['type'])->toBe('site_ring')
        ->and($ring['data']['id'])->toBe((string) $this->alert->id)
        ->and($ring['data']['r'])->toBe(0)
        ->and($ring['data']['of'])->toBe(5)
        ->and($ring['data']['esc'])->toBe(0)
        ->and($ring['data']['drill'])->toBe(0)
        ->and($ring['data']['estate'])->toBe('Phoenix Park')
        ->and($ring['data']['where'])->toBe('Lot 811')
        ->and($ring['data']['hold'])->toBe(45)
        ->and(array_intersect(array_keys($ring['data']), ['title', 'message', 'body']))->toBe([]);

    $claims = RingTicket::verify($ring['data']['rt']);

    expect($claims)->toMatchArray(['a' => $this->alert->id, 't' => FacilitiesFixture::ESTATE, 'g' => $this->g1->id, 'p' => $this->t1->id, 'r' => 0, 's' => 0, 'k' => 'ring']);

    $row = DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', 0)->firstOrFail();

    expect($row->outcome)->toBe('sent')
        ->and($row->claimed_by)->toBe('job')
        ->and($row->phones_ring)->toBe(1)
        ->and($row->phones_ready)->toBe(1)
        ->and($row->note)->not->toContain('rang');
});

it('does not escalate at 59 seconds, and at 60 rings every active officer of the estate, with a visible backup for every phone not yet proven', function () {
    RingFixture::runJobs([RingRound::class]);

    // G1's phone says it showed round 0's ring.
    DuressAlertRingEvent::once(['duress_alert_id' => $this->alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 0, 'push_token_id' => $this->t1->id, 'event' => 'shown', 'detail' => 'imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1']);

    Carbon::setTestNow(now()->addSeconds(59));
    expect(RingFixture::runJobs([RingRound::class]))->toBe(0);

    Carbon::setTestNow(now()->addSecond());
    expect(RingFixture::runJobs([RingRound::class]))->toBe(1);

    $round1 = RingFixture::sent()->filter(fn (array $m): bool => ($m['data']['type'] ?? null) === 'site_ring' && $m['data']['r'] === 1
        || ($m['data']['type'] ?? null) === 'site_alert');

    $rings = $round1->where('data.type', 'site_ring')->pluck('to')->sort()->values()->all();
    $visible = $round1->where('data.type', 'site_alert')->pluck('to')->sort()->values()->all();

    // G1 and G3 are rung call-style; G3 — off duty, first rung now — also gets the visible backup (G9).
    expect($rings)->toBe(collect([$this->t1->expo_token, $this->t3->expo_token])->sort()->values()->all())
        ->and($visible)->toBe(collect([$this->t2->expo_token, $this->t3->expo_token])->sort()->values()->all());

    // Never G4 (suspended), G6 (their own duress) or G7 (another estate), in any form.
    foreach ([$this->t4, $this->t6, $this->t7] as $never) {
        expect(RingFixture::to($never))->toBeEmpty();
    }

    $backup = $round1->where('data.type', 'site_alert')->first();

    expect($backup['tag'])->toBe('ring-'.$this->alert->id)
        ->and($backup['collapseId'])->toBe('ring-'.$this->alert->id)
        ->and($backup['channelId'])->toBe('safety')
        ->and($backup['priority'])->toBe('high')
        ->and($backup['title'])->toBe('STILL UNANSWERED · Panic alert')
        ->and($backup['body'])->toBe('At Lot 811. 1 min since it was raised. Open it to respond.');

    // The escalation: G3 is on the list as the estate's, and the Head of Security is told.
    expect(DuressAlertRingTarget::query()->where('duress_alert_id', $this->alert->id)->where('guard_id', $this->g3->id)->value('audience'))->toBe('estate');

    $sms = RingFixture::queued(SendRingEscalationSms::class);

    expect($sms)->toHaveCount(1)
        ->and($sms[0]->command->reason)->toBe('unanswered')
        ->and($sms[0]->command->phones)->toBe(3);

    Notification::assertSentTo($this->head, PanicStillUnanswered::class, fn (PanicStillUnanswered $mail): bool => str_starts_with((string) $mail->toMail($this->head)->subject, 'UNANSWERED 60 s'));

    // And it is a leg of its own on the hand-off record, with its round.
    $legs = DuressAlertDelivery::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->get();

    expect($legs->pluck('destination')->all())->toContain(DuressAlertDelivery::GUARDS_ON_SHIFT, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)
        ->and($legs->firstWhere('destination', DuressAlertDelivery::GUARDS_ON_SHIFT)->recipients)->toBe(3)
        ->and($legs->firstWhere('destination', DuressAlertDelivery::GUARDS_ON_SHIFT)->note)->toContain('Ring 2 (escalation: every active officer of Phoenix Park)');
});

it('re-rings at 180, 300, 420 and 540 seconds, records each round, and rings nothing after', function () {
    foreach ([0, 60, 180, 300, 420, 540] as $at) {
        Carbon::setTestNow($this->alert->server_time->copy()->addSeconds($at));
        expect(RingFixture::runJobs([RingRound::class]))->toBe(1);
    }

    $rounds = DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->orderBy('round')->get();

    expect($rounds->pluck('outcome')->all())->toBe(['sent', 'sent', 'sent', 'sent', 'sent', 'sent'])
        ->and(DuressAlertDelivery::query()->where('duress_alert_id', $this->alert->id)->where('destination', DuressAlertDelivery::GUARDS_ON_SHIFT)->whereNotNull('round')->pluck('round')->all())
        ->toBe([0, 1, 2, 3, 4, 5]);

    // Each re-ring reached everyone rung so far — G1, G2, G3 — and nobody else.
    foreach ([2, 3, 4, 5] as $round) {
        expect(DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('round', $round)
            ->whereIn('event', ['sent_ring', 'sent_visible'])->pluck('push_token_id')->unique()->sort()->values()->all())
            ->toBe(collect([$this->t1->id, $this->t2->id, $this->t3->id])->sort()->values()->all());
    }

    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(660));

    expect(RingFixture::runJobs([RingRound::class], evenIfNotDue: true))->toBe(0)
        ->and(RingFixture::queued(RingRound::class))->toBeEmpty();

    // The resident's figure was never moved by a ring.
    expect($this->alert->fresh()->alerted_handsets)->toBe(3)
        ->and($this->alert->fresh()->alerted_via)->toBe(PanicFanOut::ON_DUTY);
});

it('sends a round\'s stop-and-ring batches exactly once, however many times its job is run', function () {
    RingFixture::runJobs([RingRound::class]);

    $round0 = RingFixture::queued(RingRound::class);
    $again = new RingRound($this->alert->id, 0);
    app()->call([$again, 'handle']);

    expect(RingFixture::sent())->toHaveCount(1)
        ->and(RingFixture::queued(SendRingPush::class))->toBeEmpty()
        ->and($round0)->toHaveCount(1);
});
