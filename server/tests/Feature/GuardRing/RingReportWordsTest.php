<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingEvent;
use App\Models\Role;
use App\Services\Ring\RingReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| WHAT THE CONSOLE SAYS A RING REACHED — the guard ring §9, G11, G12, G15
|--------------------------------------------------------------------------
|
| Never "rang". Sent is the push service taking it; call-style ready and partly ready are
| counted apart; shown on the phone is the phone's own receipt; "with the alarm sound on"
| is that receipt's own sound check, and anything short of it is said with its reason.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    $this->withoutVite();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T23:00:00Z'));

    $this->post = RingFixture::post();

    [$this->g1, , $this->t1] = RingFixture::guard('GS-RW-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g2, , $this->t2] = RingFixture::guard('GS-RW-2', push: RingFixture::ready('partial'), postId: $this->post->id);
    [$this->g3, , $this->t3] = RingFixture::guard('GS-RW-3', push: [], postId: $this->post->id);
    [$this->g4, , $this->t4] = RingFixture::guard('GS-RW-4', push: ['platform' => 'ios'], postId: $this->post->id);
    [$this->g5, , $this->t5] = RingFixture::guard('GS-RW-5', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g6, , $this->t6] = RingFixture::guard('GS-RW-6', push: RingFixture::ready(), postId: $this->post->id);

    foreach ([$this->g1, $this->g2, $this->g3, $this->g4, $this->g5, $this->g6] as $guard) {
        RingFixture::shift($guard, $this->post->id, clockedOn: true);
    }

    // The phones last reported their readiness a minute ago, and a little over a day ago.
    $this->t1->forceFill(['ring_checked_at' => now()->subMinute()])->save();
    $this->t2->forceFill(['ring_checked_at' => now()->subDay()->subMinutes(5)])->save();

    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.words@example.com', 'Lot 823'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);

    $receipt = fn ($token, string $event, ?string $detail = null) => DuressAlertRingEvent::once(['duress_alert_id' => $this->alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 0, 'push_token_id' => $token->id, 'guard_id' => $token->principal_id, 'event' => $event, 'detail' => $detail]);

    $receipt($this->t1, 'shown', 'imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1');
    $receipt($this->t1, 'full_screen');
    $receipt($this->t2, 'shown', 'imp=4;snd=1;vol=0/7;dnd=pass;fsi=1;lk=1');
    $receipt($this->t2, 'opened');
    $receipt($this->t5, 'shown', 'imp=4;snd=0;vol=5/7;dnd=block;fsi=1;lk=1');
    $receipt($this->t6, 'shown', 'imp=4;snd=1;vol=5/7;dnd=unknown;fsi=1;lk=1');
    $receipt($this->t6, 'dismissed');

    $this->report = app(RingReport::class)->forAlert($this->alert);
    $this->round0 = $this->report['rounds'][0]['summary'];
});

afterEach(fn () => RingFixture::tearDown());

it('counts sent, call-style ready and partly ready apart, and says when each phone reported', function () {
    expect($this->round0)->toContain('6 officers')
        ->and($this->round0)->toContain('4 phones sent the ring (3 call-style ready, 1 partly ready, readiness reported 1 min to 1 day before)');
});

it('counts shown on the phone from receipts only, and the alarm sound only when every part of the check says so', function () {
    expect($this->round0)->toContain('4 shown on the phone: 1 with the alarm sound on, 3 without it (1 alarm volume 0; 1 no sound on the ring channel; 1 Do Not Disturb not confirmed)');
});

it('counts full screen only from the system\'s own launches, never a tap, and says what was dismissed', function () {
    expect($this->round0)->toContain('1 of them full screen')
        ->and($this->round0)->toContain('1 dismissed on the phone');
});

it('never says a phone rang, anywhere, and the caption uses the panel\'s own words', function () {
    $everything = json_encode($this->report).json_encode(app(RingReport::class)->timeline($this->alert));

    expect(strtolower($everything))->not->toContain('rang')
        ->and($this->report['caption'])->toContain('Shown on the phone means the phone itself reported it put the ring on screen')
        ->and($this->report['caption'])->toContain('"with the alarm sound on"');
});

it('reads a silent channel, volume 0, total silence and an unknown or unreadable check as no sound, each with its reason', function () {
    expect(RingReport::soundProblem('imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1'))->toBeNull()
        ->and(RingReport::soundProblem('imp=2;snd=1;vol=5/7;dnd=pass'))->toBe('ring channel set below high')
        ->and(RingReport::soundProblem('imp=4;snd=0;vol=5/7;dnd=pass'))->toBe('no sound on the ring channel')
        ->and(RingReport::soundProblem('imp=4;snd=1;vol=0/7;dnd=pass'))->toBe('alarm volume 0')
        ->and(RingReport::soundProblem('imp=4;snd=1;vol=5/7;dnd=block'))->toBe('Do Not Disturb on total silence')
        ->and(RingReport::soundProblem('imp=4;snd=1;vol=5/7;dnd=unknown'))->toBe('Do Not Disturb not confirmed')
        ->and(RingReport::soundProblem('it rang'))->toBe('sound not confirmed')
        ->and(RingReport::soundProblem(null))->toBe('sound not confirmed');
});

it('shows the ring on the dispatcher\'s screen and timeline, and each officer\'s phone on their record', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)->get('/dispatch/alerts/'.$this->alert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alert')
            ->where('rings.active', true)
            ->where('rings.rounds.0.label', 'Ring 1 · 0 s · call-style, at the raise')
            ->where('rings.next', fn ($next) => str_contains((string) $next, 'Ring 2 due'))
            ->where('timeline', fn ($steps) => collect($steps)->contains(fn (array $step): bool => $step['title'] === 'Ring 1 · 0 s · call-style, at the raise')));

    $this->actingAs($director)->get('/guards/'.$this->g1->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('callStyle.state', 'ready')
            ->where('callStyle.label', 'Ready to ring like a call')
            ->where('callStyle.detail', fn ($detail) => str_contains((string) $detail, 'reported 1 minute ago') && str_contains((string) $detail, 'no test ring shown yet')));

    // An older Guard App and an iPhone are ordinary alerts, and say so.
    $this->actingAs($director)->get('/guards/'.$this->g3->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('callStyle.label', 'Older app — ordinary alerts only'));
    $this->actingAs($director)->get('/guards/'.$this->g4->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('callStyle.label', 'iPhone — ordinary time-sensitive alerts'));

    // A report older than a day is said to be possibly out of date, and "partly" says what is off.
    $this->t2->forceFill(['ring_readiness' => ['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'off', 'battery' => 'optimised', 'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on']])->save();

    $this->actingAs($director)->get('/guards/'.$this->g2->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('callStyle.label', 'Partly — rings, but Do Not Disturb bypass OFF and battery saver ON')
            ->where('callStyle.detail', fn ($detail) => str_contains((string) $detail, 'last reported 1 day ago — may have changed')));

    $this->actingAs($director)->get('/guards')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('callStyle.'.$this->g1->id.'.short', 'Ready to ring like a call'));
});
