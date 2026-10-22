<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ShiftAutoClosed;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\ShiftAutoClose;
use App\Services\Dispatch\ShiftClock;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A SHIFT CLOCKED ON AFTER ITS ROSTERED END IS NEVER CLOSED BEFORE IT BEGAN
| (bug hunt B100)
|--------------------------------------------------------------------------
|
| `shifts:auto-close` closes a shift nobody clocked out of, some hours past its
| rostered end, AT the rostered end — "the last hour anyone can evidence". But a
| clock-on can land after the rostered end (an offline start uploaded late, an
| officer covering late), and then the rostered end is NOT the last hour anyone can
| evidence: the clock-on is. The shift was closed at 14:00 thirty minutes after an
| officer clocked on at 18:30 — `actual_end` before `actual_start`, the officer
| dropped off the on-duty list within the hour, and a push and a mail describing a
| close at a time before they had started.
|
| The rule's clock now runs from the later of the two, and a shift closes there.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica', ShiftAutoClose::SITE_KEY => null])->save();
    SiteTime::flush();

    Shift::query()->where('tenant_id', $this->site)->delete();
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);

    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Late Gate', 'type' => 'gate', 'is_active' => true]);

    $this->head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create(['user_id' => $this->head->id, 'tenant_id' => $this->site, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);

    // 06:00–14:00 on the site's clock, today.
    $day = SiteTime::today($this->site);
    $this->rosteredStart = $day->copy()->setTime(6, 0)->utc();
    $this->rosteredEnd = $day->copy()->setTime(14, 0)->utc();
    $this->clockedOn = $day->copy()->setTime(18, 30)->utc();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/** @return array{0: Guard, 1: DevicePushToken} */
function lateOfficer(TestCase $test, string $number): array
{
    $guard = Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    app(DeviceEnrolment::class)->enrol($guard, 'Late handset '.$number);

    $phone = DevicePushToken::query()->create([
        'tenant_id' => $test->site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
        'access_token_id' => null, 'expo_token' => 'ExponentPushToken[late'.substr(md5($number), 0, 18).']', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);

    return [$guard->fresh(), $phone];
}

function lateShift(TestCase $test, Guard $guard, Carbon $clockedOn): Shift
{
    return Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
        'rostered_start' => $test->rosteredStart, 'rostered_end' => $test->rosteredEnd,
        'actual_start' => $clockedOn, 'status' => 'on_duty',
    ]));
}

function lateMail(User $head, Shift $shift): MailMessage
{
    $mail = null;

    Notification::assertSentTo($head, ShiftAutoClosed::class, function (ShiftAutoClosed $notification) use ($head, $shift, &$mail): bool {
        $candidate = $notification->toMail($head);

        if (! str_contains(implode(' ', $candidate->introLines), '#'.$shift->id.'.')) {
            return false;
        }

        $mail = $candidate;

        return true;
    });

    return $mail;
}

it('does not close a shift thirty minutes after its officer clocked on, however long ago its rostered end was', function () {
    [$guard] = lateOfficer($this, 'GS-LATE-1');
    $shift = lateShift($this, $guard, $this->clockedOn);

    // 19:00 on the site's clock: five hours past the rostered end, thirty minutes past the clock-on.
    app(ShiftAutoClose::class)->run($this->clockedOn->copy()->addMinutes(30));

    $shift->refresh();

    expect($shift->actual_end)->toBeNull()
        ->and($shift->auto_closed_at)->toBeNull();

    // …so the officer standing there is still on the list a panic is pushed to.
    Carbon::setTestNow($this->clockedOn->copy()->addMinutes(30));
    expect(app(PushNotifier::class)->onDutyGuardIds($this->site))->toContain($guard->id);
});

it('closes it at the clock-on, four hours after the clock-on — never before it began — and says so', function () {
    [$guard, $phone] = lateOfficer($this, 'GS-LATE-2');
    $shift = lateShift($this, $guard, $this->clockedOn);

    $now = $this->clockedOn->copy()->addHours(4)->addMinutes(30);
    app(ShiftAutoClose::class)->run($now);

    $shift->refresh();

    expect($shift->actual_end)->not->toBeNull()
        ->and($shift->actual_end->gte($shift->actual_start))->toBeTrue()
        ->and($shift->actual_end->utc()->toIso8601String())->toBe($this->clockedOn->copy()->utc()->toIso8601String())
        ->and($shift->auto_closed_at)->not->toBeNull();

    // The audit entry: an end the start does not follow is never written.
    $audit = AuditEntry::query()->where('action', 'shift.auto_closed')->where('entity_id', (string) $shift->id)->firstOrFail();
    expect(Carbon::parse($audit->after['actual_end'])->gte($shift->actual_start))->toBeTrue()
        ->and($audit->after['reason'])->toBe('No clock-out was recorded. Closed at the time it was clocked on, which was after its rostered end.');

    $onSite = SiteTime::local($this->clockedOn, $this->site)->format('D j M, g:i A');

    // The officer is told the close that happened, not one before they had started.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$phone->id]
        && str_contains($job->body, 'closed at the time you clocked on ('.$onSite.')')
        && ! str_contains($job->body, 'rostered end'));

    // The Head of Security: the same, in the mail and on the bell.
    $lines = implode("\n", lateMail($this->head, $shift)->introLines);

    expect($lines)->toContain('AT THE TIME IT WAS CLOCKED ON')
        ->and($lines)->not->toContain('AT THE ROSTERED END');

    $bell = collect(app(PlatformNotifications::class)->attention($this->head->fresh())['items'])->firstWhere('key', 'autoclose:'.$shift->id);

    expect($bell['detail'])->toContain('closed at the time it was clocked on')
        ->and($bell['detail'])->not->toContain('closed at its rostered end');
});

it('never lets anything write an end before the start: the clock itself refuses one', function () {
    [$guard] = lateOfficer($this, 'GS-LATE-4');
    $shift = lateShift($this, $guard, $this->clockedOn);

    expect(fn () => app(ShiftClock::class)->clockOut($shift, $this->rosteredEnd))
        ->toThrow(DomainException::class, 'A shift cannot end before it began');

    expect($shift->fresh()->actual_end)->toBeNull();

    // An end at the start itself is not before it, and is the one an automatic close writes.
    app(ShiftClock::class)->clockOut($shift, $this->clockedOn);
    expect($shift->fresh()->actual_end->equalTo($shift->fresh()->actual_start))->toBeTrue();
});

it('still closes an ordinary forgotten clock-out at its rostered end, and says so', function () {
    [$guard, $phone] = lateOfficer($this, 'GS-LATE-3');
    $shift = lateShift($this, $guard, $this->rosteredStart->copy()->addMinutes(5));

    app(ShiftAutoClose::class)->run($this->rosteredEnd->copy()->addHours(5));

    $shift->refresh();

    expect($shift->actual_end->utc()->toIso8601String())->toBe($this->rosteredEnd->copy()->utc()->toIso8601String());

    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$phone->id]
        && str_contains($job->body, 'closed at its rostered end'));

    expect(implode("\n", lateMail($this->head, $shift)->introLines))->toContain('AT THE ROSTERED END');

    $bell = collect(app(PlatformNotifications::class)->attention($this->head->fresh())['items'])->firstWhere('key', 'autoclose:'.$shift->id);
    expect($bell['detail'])->toContain('closed at its rostered end');
});
