<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| A push says whose clock its time is on (M19A §8.7, §10.7)
|--------------------------------------------------------------------------
|
| A resident reading a meeting's push on a lock screen, on a phone set to another zone, has no header clock and
| no banner. So the time in the push names the estate's clock, by its tag: "Wed 30 Sep, 7:00 PM JA" at a Jamaican
| estate, "… NY" at one in New York — each estate's own 7 PM, on either side of a change of clocks. The push is
| caught at the notifier, exactly as it would leave for the phones.
|
*/

const MPC_KINGSTON = 'mpckingston';
const MPC_NEW_YORK = 'mpcnewyork';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();

    foreach ([MPC_KINGSTON => 'America/Jamaica', MPC_NEW_YORK => 'America/New_York'] as $id => $zone) {
        DB::table('tenants')->insert(['id' => $id, 'name' => $id, 'status' => 'active', 'timezone' => $zone, 'created_at' => now(), 'updated_at' => now()]);
    }

    SiteTime::flush();
});

afterEach(function () {
    SiteTime::flush();
    DB::connection('mysql')->rollBack();
});

/** The push a published meeting at this estate sends, as the notifier receives it. */
function mpcPushFor(string $estate, string $localStart): PushMessage
{
    $sent = null;
    $notifier = Mockery::mock(PushNotifier::class);
    $notifier->shouldReceive('toEstateResidents')->once()->andReturnUsing(function (string $tenant, PushMessage $message) use (&$sent): int {
        $sent = $message;

        return 1;
    });
    app()->instance(PushNotifier::class, $notifier);
    // A fresh announcer, so it is handed this notifier and not one resolved before it.
    app()->forgetInstance(PushAnnouncer::class);

    $meeting = new Meeting;
    $meeting->forceFill([
        'title' => 'Special general meeting',
        'starts_at' => SiteTime::parse($localStart, $estate),
        'published_at' => Carbon::now(),
        'status' => Meeting::SCHEDULED,
        'audience_scope' => Meeting::WHOLE_ESTATE,
    ]);
    $meeting->id = 41;

    // The announcer never throws into its caller (`safely`); anything it swallowed would be reported, so none may be.
    Exceptions::fake();
    app(PushAnnouncer::class)->meeting($meeting, 'scheduled', $estate);
    Exceptions::assertNothingReported();
    app()->forgetInstance(PushNotifier::class);

    expect($sent)->toBeInstanceOf(PushMessage::class);

    return $sent;
}

it('names the estate\'s clock in the push: JA at a Jamaican estate, NY in New York', function () {
    expect(mpcPushFor(MPC_KINGSTON, '2026-09-30 19:00')->body)->toEndWith('Wed 30 Sep, 7:00 PM JA')
        ->and(mpcPushFor(MPC_NEW_YORK, '2026-09-30 19:00')->body)->toEndWith('Wed 30 Sep, 7:00 PM NY');
});

it('is each estate\'s own 7 PM on both sides of New York\'s change of clocks', function () {
    // Friday 30 October (EDT) and Monday 2 November (EST): the same digits, the same tag, an hour apart in UTC.
    expect(mpcPushFor(MPC_NEW_YORK, '2026-10-30 19:00')->body)->toEndWith('Fri 30 Oct, 7:00 PM NY')
        ->and(mpcPushFor(MPC_NEW_YORK, '2026-11-02 19:00')->body)->toEndWith('Mon 2 Nov, 7:00 PM NY')
        ->and(mpcPushFor(MPC_KINGSTON, '2026-11-02 19:00')->body)->toEndWith('Mon 2 Nov, 7:00 PM JA');
});
