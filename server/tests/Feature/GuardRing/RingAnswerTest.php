<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingEvent;
use App\Models\DuressAlertRingRound;
use App\Models\DuressAlertRingTarget;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Dispatch\AlertIntake;
use App\Services\Ring\RingTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| ANSWERING A RING, ON ITS TICKET — the guard ring §6.5, §6.6
|--------------------------------------------------------------------------
|
| G3 is off duty: rung at the escalation, with a Guard App session that timed out long
| ago — exactly the officer the escalation exists to reach, and exactly the one whose
| bearer token can no longer say "on my way". The ring's ticket can, and nothing else
| about the session changes.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T17:00:00Z'));

    $this->post = RingFixture::post();

    [$this->g1, $this->b1, $this->t1] = RingFixture::guard('GS-RA-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g3, $this->b3, $this->t3] = RingFixture::guard('GS-RA-3', push: RingFixture::ready(), postId: $this->post->id);

    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    $resident = RingFixture::residentToken('ring.answer@example.com', 'Lot 814');
    $account = ResidentAccount::query()->where('destination', 'ring.answer@example.com')->firstOrFail();

    // The resident's own phone, so "an officer is on the way" has somewhere to go.
    $this->residentPhone = DevicePushToken::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => DevicePushToken::RESIDENT, 'principal_id' => $account->id, 'app' => AppMatrix::RESIDENT,
        'access_token_id' => null, 'expo_token' => RingFixture::expoToken('residentRA'), 'platform' => 'ios', 'muted' => [], 'last_registered_at' => now(),
    ]);

    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, $resident, 'duress.store', ['mode' => 'audible'])->json('id'));

    RingFixture::runJobs([RingRound::class]);
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
    RingFixture::runJobs([RingRound::class]);

    $this->rt3 = RingFixture::ticketFor($this->t3, 1);

    // G3's Guard App went quiet forty minutes ago: its session has timed out.
    $this->accessToken = (int) $this->t3->access_token_id;
    DB::connection('mysql')->table('personal_access_tokens')->where('id', $this->accessToken)->update(['last_used_at' => now()->subMinutes(40)]);

    $this->answer = fn (array $body, ?int $status = null) => RingFixture::call($this, null, 'ring.answer', $body, status: $status);
});

afterEach(fn () => RingFixture::tearDown());

it('records "I\'m responding" from a phone whose session timed out, as the guard, and stops every other phone', function () {
    // The bearer path is closed to this phone…
    RingFixture::call($this, $this->b3, 'alerts.en_route', path: ['alert' => $this->alert->id], status: 401);

    $lastUsed = DB::connection('mysql')->table('personal_access_tokens')->where('id', $this->accessToken)->value('last_used_at');

    // …and the ring's ticket is not.
    $answer = ($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 3200]);

    expect($answer->json('recorded'))->toBeTrue()
        ->and($answer->json('answer'))->toBe('responding')
        ->and($answer->json('code'))->toBeNull()
        ->and($answer->json('session_open'))->toBeFalse()
        ->and($answer->json('alert.alert_id'))->toBe($this->alert->id)
        ->and($answer->json('alert.responder_is_you'))->toBeTrue()
        ->and($answer->json('alert.status'))->toBe('responding');

    $alert = $this->alert->fresh();

    expect($alert->responding_guard_id)->toBe($this->g3->id)
        ->and($alert->en_route_at)->not->toBeNull()
        // The control room's act stays the control room's.
        ->and($alert->acknowledged_at)->toBeNull();

    // The session was READ, never renewed.
    expect(DB::connection('mysql')->table('personal_access_tokens')->where('id', $this->accessToken)->value('last_used_at'))->toBe($lastUsed);

    // THE AUDIT NAMES THE GUARD, never "the system" (G23).
    foreach (['alert.en_route', 'alert.ring_answered'] as $action) {
        $entry = AuditEntry::query()->where('action', $action)->where('entity_id', (string) $this->alert->id)->latest('id')->firstOrFail();

        expect($entry->actor_name)->toBe('Officer GS-RA-3')
            ->and($entry->actor_role)->toBe('Guard App handset');
    }

    expect(AuditEntry::query()->where('action', 'alert.en_route')->where('entity_id', (string) $this->alert->id)->latest('id')->firstOrFail()->after)->toMatchArray(['recorded_via' => 'handset'])
        ->and(Auth::guard('sanctum')->user())->toBeNull()
        ->and(tenancy()->initialized)->toBeFalse();

    // The resident is told an officer is on the way — the estate was opened, as a bearer request opens it.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->data['type'] === 'panic_update'
        && ($job->data['kind'] ?? null) === 'en_route'
        && $job->tokenIds === [$this->residentPhone->id]);

    // And every other phone is told to stop, naming who is responding.
    expect(DuressAlertRing::query()->where('duress_alert_id', $this->alert->id)->value('answered_why'))->toBe('responding');

    RingFixture::runJobs([\App\Jobs\Ring\SendRingPush::class]);

    expect(RingFixture::to($this->t1, 'ring_stop')->first(fn (array $m): bool => ! isset($m['title']))['data'])->toMatchArray(['why' => 'responding', 'who' => 'Officer GS-RA-3'])
        ->and(DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('event', 'answered_responding')->where('push_token_id', $this->t3->id)->exists())->toBeTrue();
});

it('answers a replay of the same claim with already_yours, and records nothing twice', function () {
    ($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 1000]);
    $again = ($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 1200]);

    expect($again->json('code'))->toBe('already_yours')
        ->and($again->json('recorded'))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'alert.en_route')->where('entity_id', (string) $this->alert->id)->count())->toBe(1);
});

it('refuses a stale "I\'m responding", writing nothing but the refusal, and still takes a late "can\'t respond" (G10)', function () {
    $stale = ($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 121_000], 409);

    expect($stale->json('error.code'))->toBe('ring_answer_stale')
        ->and($stale->json('error.message'))->toContain('Radio dispatch to say whether you are going')
        ->and($this->alert->fresh()->responding_guard_id)->toBeNull()
        ->and(DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('event', 'refused')->where('detail', 'stale')->exists())->toBeTrue();

    Bus::assertNotDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->data['type'] === 'panic_update');

    expect(($this->answer)(['rt' => $this->rt3, 'answer' => 'cannot', 'age_ms' => 121_000])->json('answer'))->toBe('cannot');
});

it('takes "can\'t respond" for the whole alert off every later round, and from the lock screen for this round only (R10b)', function () {
    [, , $t4] = RingFixture::guard('GS-RA-4', push: RingFixture::ready(), postId: $this->post->id);
    // G4 was rung at the escalation too (added after the fact, as a guard posted since).
    DuressAlertRingTarget::query()->insert(['duress_alert_id' => $this->alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $t4->principal_id, 'audience' => 'estate', 'first_round' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $rt4 = RingTicket::issue(['a' => $this->alert->id, 't' => FacilitiesFixture::ESTATE, 'g' => (int) $t4->principal_id, 'p' => $t4->id, 'r' => 1, 's' => 0, 'k' => 'ring']);

    $whole = ($this->answer)(['rt' => $this->rt3, 'answer' => 'cannot', 'scope' => 'alert']);
    $lock = ($this->answer)(['rt' => $rt4, 'answer' => 'cannot', 'scope' => 'round']);

    expect($whole->json('session_open'))->toBeFalse()
        ->and($lock->json('recorded'))->toBeTrue()
        ->and(DuressAlertRingTarget::query()->where('duress_alert_id', $this->alert->id)->where('guard_id', $this->g3->id)->value('answer'))->toBe('cannot')
        ->and(DuressAlertRingTarget::query()->where('duress_alert_id', $this->alert->id)->where('guard_id', $t4->principal_id)->value('answer'))->toBeNull()
        ->and(AuditEntry::query()->where('action', 'alert.ring_declined')->where('entity_id', (string) $this->alert->id)->count())->toBe(2);

    // Round 2: G4 is rung again, G3 is not.
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(180));
    RingFixture::runJobs([RingRound::class]);

    $round2 = DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('round', 2)->where('event', 'sent_ring')->pluck('push_token_id')->all();

    expect($round2)->toContain($t4->id)->not->toContain($this->t3->id);
});

it('brings the escalation forward when every officer rung at the raise says they cannot respond — not for a lock-screen decline', function () {
    // The first alert is closed, and a fresh one raised: only G1 is on shift, and its escalation is still 60 s away.
    app(AlertIntake::class)->resolve($this->alert, FacilitiesFixture::geminiViewer(Role::DISPATCHER), 'Handled.', classification: 'genuine_security');
    Carbon::setTestNow(Carbon::parse('2026-09-25T18:00:00Z'));
    $alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.decline@example.com', 'Lot 815'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);
    $rt1 = RingFixture::ticketFor($this->t1, 0);

    Carbon::setTestNow(now()->addSeconds(8));
    ($this->answer)(['rt' => $rt1, 'answer' => 'cannot', 'scope' => 'round']);

    $round1 = fn () => DuressAlertRingRound::query()->where('duress_alert_id', $alert->id)->where('round', 1)->firstOrFail();
    expect($round1()->reason)->toBe('unanswered');

    ($this->answer)(['rt' => $rt1, 'answer' => 'cannot', 'scope' => 'alert']);

    expect($round1()->reason)->toBe('declined')
        ->and($round1()->due_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and(RingFixture::queued(RingRound::class, dueOnly: true)->map(fn (object $j): int => $j->command->round)->all())->toContain(1);
});

it('refuses forged, expired, other-estate, revoked and test tickets alike', function () {
    // One character of the signature changed.
    [$version, $claims, $signature] = explode('.', $this->rt3);
    $forged = $version.'.'.$claims.'.'.($signature[0] === 'A' ? 'B' : 'A').substr($signature, 1);
    expect(($this->answer)(['rt' => $forged, 'answer' => 'cannot'], 401)->json('error.code'))->toBe('ring_ticket_invalid');

    // Signed, but naming another estate: the same answer as a wrong id.
    $elsewhere = RingTicket::issue(['a' => $this->alert->id, 't' => RingFixture::OTHER_ESTATE, 'g' => $this->g3->id, 'p' => $this->t3->id, 'r' => 1, 's' => 0, 'k' => 'ring']);
    expect(($this->answer)(['rt' => $elsewhere, 'answer' => 'cannot'], 401)->json('error.code'))->toBe('ring_ticket_invalid');

    // Signed, but for an alert this guard was never rung for.
    $notRung = RingTicket::issue(['a' => $this->alert->id + 1000, 't' => FacilitiesFixture::ESTATE, 'g' => $this->g3->id, 'p' => $this->t3->id, 'r' => 1, 's' => 0, 'k' => 'ring']);
    expect(($this->answer)(['rt' => $notRung, 'answer' => 'cannot'], 401)->json('error.code'))->toBe('ring_ticket_invalid');

    // A test ring's ticket cannot answer an alert.
    $test = RingTicket::issue(['a' => 0, 't' => FacilitiesFixture::ESTATE, 'g' => $this->g3->id, 'p' => $this->t3->id, 'r' => 0, 's' => 1, 'k' => 'test']);
    expect(($this->answer)(['rt' => $test, 'answer' => 'responding', 'age_ms' => 10], 403)->json('error.message'))->toBe('A test ring cannot answer an alert.');

    // Thirty minutes on, the real ticket has expired.
    Carbon::setTestNow(now()->addMinutes(31));
    expect(($this->answer)(['rt' => $this->rt3, 'answer' => 'cannot'], 410)->json('error.code'))->toBe('ring_ticket_expired');

    // And a push address revoked since answers nothing, however fresh its ticket.
    Carbon::setTestNow(now()->subMinutes(31));
    $this->t3->forceFill(['revoked_at' => now(), 'revoked_reason' => DevicePushToken::SIGNED_OUT])->save();
    expect(($this->answer)(['rt' => $this->rt3, 'answer' => 'cannot'], 401)->json('error.code'))->toBe('ring_ticket_invalid');

    expect($this->alert->fresh()->responding_guard_id)->toBeNull();
});

it('passes the response\'s own refusals through with their sentence: another officer responding, the alert closed', function () {
    RingFixture::call($this, $this->b1, 'alerts.en_route', path: ['alert' => $this->alert->id]);

    $another = ($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 500], 403);

    expect($another->json('error.code'))->toBe('another_officer_responding')
        ->and($another->json('error.message'))->toContain('Officer GS-RA-1 is the officer responding');

    app(AlertIntake::class)->resolve($this->alert->fresh(), FacilitiesFixture::geminiViewer(Role::DISPATCHER), 'Handled.', classification: 'genuine_security');

    expect(($this->answer)(['rt' => $this->rt3, 'answer' => 'responding', 'age_ms' => 500], 409)->json('error.code'))->toBe('alert_closed');
});

it('limits forged tickets per address, not only per ticket (G28)', function () {
    $status = [];

    foreach (range(1, 121) as $i) {
        app('auth')->forgetGuards();
        $status[] = $this->postJson('/api/v1/ring/answer', ['rt' => 'v1.forged'.$i.'.x', 'answer' => 'cannot'])->status();
    }

    expect(array_count_values(array_slice($status, 0, 120)))->toBe([401 => 120])
        ->and($status[120])->toBe(429);
});
