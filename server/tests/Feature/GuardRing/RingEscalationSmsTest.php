<?php

declare(strict_types=1);

use App\Jobs\Alerts\SendRingEscalationSms;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| THE ESTATE'S HEAD OF SECURITY, TOLD NOBODY HAS ANSWERED — the guard ring §3.4, G14
|--------------------------------------------------------------------------
|
| Round 1 texts the estate's own Head of Security: read directly, not through the raise's
| plan, which never texts a Head of Security whose phone is one of the site's numbers —
| and theirs usually is. A follow-up, and it says so; its own audience, so the raise's
| staff totals never move.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-26T01:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g1, , $this->t1] = RingFixture::guard('GS-RH-1', push: RingFixture::ready(), postId: $this->post->id);

    // The estate's Head of Security, whose phone is ALSO one of the site's emergency numbers.
    $this->head = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    $this->head->forceFill(['contact_phone' => '876-555-0142'])->save();
    app(SiteEmergencyNumbers::class)->put(FacilitiesFixture::ESTATE, 'head_of_security', 'PPV1 Head of Security', '876-555-0142', 1);

    $this->raise = function (string $lot, bool $onDuty = true): DuressAlert {
        if ($onDuty) {
            RingFixture::shift($this->g1, $this->post->id, clockedOn: true);
        }

        return DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.hos.'.$lot.'@example.com', $lot), 'duress.store', ['mode' => 'audible'])->json('id'));
    };

    $this->escalationRows = fn (DuressAlert $alert) => AlertSmsDispatch::query()->where('duress_alert_id', $alert->id)->where('audience', AlertSmsDispatch::ESCALATION)->get();
});

afterEach(fn () => RingFixture::tearDown());

it('texts the estate\'s Head of Security at 60 s even though their phone is a site number, and leaves the raise\'s staff totals alone', function () {
    $alert = ($this->raise)('Lot 824');

    // At the raise, their phone was covered by the site number's text: not texted twice.
    $staffBefore = $alert->fresh()->only(['staff_sms_recipients', 'staff_sms_delivered', 'staff_sms_deduplicated', 'staff_sms_no_phone']);

    RingFixture::runJobs([RingRound::class]);
    Carbon::setTestNow($alert->server_time->copy()->addSeconds(60));
    RingFixture::runJobs([RingRound::class]);

    $job = RingFixture::queued(SendRingEscalationSms::class)->first()->command;

    expect($job->reason)->toBe('unanswered')
        ->and($job->phones)->toBe(1);

    RingFixture::runJobs([SendRingEscalationSms::class]);

    $rows = ($this->escalationRows)($alert);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($this->head->id)
        ->and($rows->first()->status)->toBe('recorded')
        ->and($rows->first()->e164)->toBeNull()
        ->and($rows->first()->number)->not->toContain('555-0142')
        ->and($alert->fresh()->only(array_keys($staffBefore)))->toBe($staffBefore);

    expect(PanicSms::unansweredBody($alert->fresh(), 'unanswered', 1))
        ->toStartWith('GeminiSecure UNANSWERED 60s: PANIC ALARM at Lot 824, Phoenix Park. Alert #'.$alert->id.'.')
        ->toEndWith('Every officer of the estate is being rung: 1 phone.');
});

it('says FOLLOW-UP, NOBODY ON DUTY when the escalation went at once, and "Send someone" when no phone could be reached', function () {
    $alert = ($this->raise)('Lot 825', onDuty: false);
    $this->t1->forceFill(['revoked_at' => now(), 'revoked_reason' => 'signed_out'])->save();

    RingFixture::runJobs([RingRound::class]);

    $job = RingFixture::queued(SendRingEscalationSms::class)->first()->command;

    expect($job->reason)->toBe('nobody_on_duty')
        ->and($job->phones)->toBe(0);

    $body = PanicSms::unansweredBody($alert->fresh(), $job->reason, $job->phones);

    expect($body)->toStartWith('GeminiSecure FOLLOW-UP, NOBODY ON DUTY: PANIC ALARM at Lot 825, Phoenix Park.')
        ->and($body)->toEndWith('No officer\'s phone could be reached. Send someone.')
        ->and(strlen($body))->toBeLessThanOrEqual(PanicSms::MAX_LENGTH)
        ->and(preg_match('/^[\x20-\x7E]+$/', $body))->toBe(1);
});

it('texts two Heads of Security who share a phone once, and says DRILL first on a drill', function () {
    $second = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    $second->forceFill(['contact_phone' => '(876) 555-0142'])->save();

    $alert = ($this->raise)('Lot 826', onDuty: false);
    RingFixture::runJobs([RingRound::class, SendRingEscalationSms::class]);

    $rows = ($this->escalationRows)($alert);

    expect($rows)->toHaveCount(2)
        ->and($rows->where('status', AlertSmsDispatch::DEDUPLICATED))->toHaveCount(1)
        ->and($rows->where('status', 'recorded'))->toHaveCount(1);

    $alert->forceFill(['is_drill' => true])->saveQuietly();

    expect(PanicSms::unansweredBody($alert->fresh(), 'unanswered', 3))->toStartWith('GeminiSecure DRILL UNANSWERED 60s:');
});

it('texts nobody who is not the estate\'s own Head of Security, and nobody at all once the assignment ends', function () {
    EstateAssignment::query()->where('user_id', $this->head->id)->update(['is_active' => false]);

    $alert = ($this->raise)('Lot 827', onDuty: false);
    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::queued(SendRingEscalationSms::class))->toBeEmpty()
        ->and(\App\Models\DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->where('round', 1)->where('destination', 'estate_head_of_security')->where('channel', 'sms')->value('outcome'))->toBe('none');
});
