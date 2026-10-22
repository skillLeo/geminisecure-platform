<?php

declare(strict_types=1);

use App\Live\Audience;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Waker;
use App\Live\Stream;
use App\Support\SampleData;
use Illuminate\Support\Facades\DB;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| A demonstration purge never takes the live log (M11 Part C, DESIGN.md §1.8)
|--------------------------------------------------------------------------
|
| A Phoenix seeding run records, for every estate table with an integer id, the ids it added
| (`SampleData::highWater()` before and after), and `phoenix:demo-purge` later deletes every row
| inside those ranges. The estate's `live_events` has an integer id, so without this it would be
| swept too, whoever's events fell inside the range, and its deliveries (which have no id) would
| be left pointing at nothing. That is a hole ABOVE `floor_seq`, which is the one thing the log
| promises catch-up never happens: a phone would be handed a list with events missing and no
| reset to say so. The log is machinery, like `cache` and `jobs`; only `live:prune` removes it.
|
*/

beforeEach(function () {
    app()->instance(Waker::class, new SpyWaker);
});

afterEach(function () {
    LiveLog::leave();
});

it('leaves the estate\'s live log out of every seeding run\'s ranges', function () {
    LiveLog::enter();

    DB::connection('tenant')->transaction(fn () => LiveBus::record(new ProbeEvent(
        Stream::estate(LiveLog::ESTATE),
        [new Audience([Channels::estateFeed(LiveLog::ESTATE, 'facilities')])],
        id: 'seeded',
    )));

    expect(DB::connection('tenant')->table('live_events')->count())->toBe(1)
        ->and(SampleData::highWater('tenant'))->not->toHaveKey('live_events')
        ->and(SampleData::highWater('mysql'))->not->toHaveKey('live_events')
        ->and(SampleData::NOT_RECORDS)->toContain('live_events');
});
