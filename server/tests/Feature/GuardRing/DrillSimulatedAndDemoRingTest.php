<?php

declare(strict_types=1);

use App\Jobs\Alerts\SendRingEscalationSms;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingRound;
use App\Models\Role;
use App\Models\Tenant;
use App\Notifications\PanicStillUnanswered;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| A DRILL RINGS AS A DRILL; A SIMULATION RINGS NOBODY; A DEMO RINGS ONCE — §3.5, G26
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-26T02:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g1, , $this->t1] = RingFixture::guard('GS-RZ-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g2, , $this->t2] = RingFixture::guard('GS-RZ-2', postId: $this->post->id);

    $this->head = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    $this->head->forceFill(['contact_email' => 'hos.drill@example.com', 'contact_email_verified_at' => now()->subMinute(), 'contact_phone' => '876-555-0143'])->save();
});

afterEach(fn () => RingFixture::tearDown());

it('rings a drill as a drill, at the raise and the escalation only, and never re-rings real officers about it', function () {
    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    $drill = app(AlertIntake::class)->record(tenantId: FacilitiesFixture::ESTATE, kind: 'panic', raisedByName: 'Drill (panic:walk)', unitReference: 'Lot 47', isDrill: true);

    foreach ([0, 60, 180, 300] as $at) {
        Carbon::setTestNow($drill->server_time->copy()->addSeconds($at));
        RingFixture::runJobs([RingRound::class]);
    }

    expect(RingFixture::to($this->t1, 'site_ring')->pluck('data.drill')->unique()->all())->toBe([1])
        ->and(RingFixture::to($this->t2, 'site_alert')->first()['title'])->toBe('DRILL: STILL UNANSWERED · Panic alert')
        ->and(DuressAlertRing::query()->where('duress_alert_id', $drill->id)->value('max_round'))->toBe(1)
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $drill->id)->where('round', '>=', 2)->pluck('outcome')->unique()->all())->toBe(['skipped_drill'])
        ->and(RingFixture::sent()->pluck('data.r')->filter(fn ($r) => $r !== null)->max())->toBe(1);

    Notification::assertSentTo($this->head, PanicStillUnanswered::class, fn (PanicStillUnanswered $mail): bool => str_starts_with((string) $mail->toMail($this->head)->subject, 'DRILL: '));
});

it('rings nobody for a simulated alert — no ring is even begun', function () {
    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    $simulated = app(AlertIntake::class)->record(tenantId: FacilitiesFixture::ESTATE, kind: 'panic', raisedByName: 'Simulator', isSimulated: true);

    expect(DuressAlertRing::query()->where('duress_alert_id', $simulated->id)->exists())->toBeFalse()
        ->and(RingFixture::queued(RingRound::class))->toBeEmpty();
});

it('rings the demonstration estate\'s on-shift phones once, and never escalates, re-rings, mails or texts anybody — even with nobody on shift', function () {
    Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->forceFill(['demo' => true])->save();

    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);
    $withSomebody = app(AlertIntake::class)->record(tenantId: FacilitiesFixture::ESTATE, kind: 'panic', raisedByName: 'Reviewer', unitReference: 'Lot 47');

    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::to($this->t1, 'site_ring'))->toHaveCount(1)
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $withSomebody->id)->where('round', '>=', 1)->pluck('outcome')->unique()->all())->toBe(['skipped_demo']);

    \App\Models\Shift::query()->where('tenant_id', FacilitiesFixture::ESTATE)->delete();
    $nobody = app(AlertIntake::class)->record(tenantId: FacilitiesFixture::ESTATE, kind: 'panic', raisedByName: 'Reviewer', unitReference: 'Lot 47');

    Carbon::setTestNow(now()->addMinutes(10));
    RingFixture::runJobs([RingRound::class], evenIfNotDue: true);

    expect(DuressAlertRingRound::query()->where('duress_alert_id', $nobody->id)->where('round', '>=', 1)->pluck('outcome')->unique()->all())->toBe(['skipped_demo'])
        ->and(RingFixture::queued(SendRingEscalationSms::class))->toBeEmpty()
        ->and(RingFixture::sent()->count())->toBe(1);

    Notification::assertNotSentTo($this->head, PanicStillUnanswered::class);

    Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->forceFill(['demo' => false])->save();
});
