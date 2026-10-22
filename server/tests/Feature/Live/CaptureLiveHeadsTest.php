<?php

declare(strict_types=1);

use App\Http\Middleware\CaptureLiveHeads;
use App\Live\Channels;
use App\Live\LiveBus;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use App\Models\Guard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\ProbeEvent;
use Tests\Live\Support\SpyWaker;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The first cursor is a lower bound: read before the page reads anything (DESIGN.md §5.2)
|--------------------------------------------------------------------------
|
| A page's data and its live cursor must meet with nothing between them. The heads are read when
| the request starts, before the controller, so an event committed and sequenced while the
| controller runs has a HIGHER seq than the head the page is given, and is applied on top of the
| page's data by the first catch-up; and every event at or below that head was committed before the
| request, so the page's data already includes it.
|
*/

beforeEach(function () {
    LiveLog::reset();
    app()->instance(Waker::class, new SpyWaker);
});

afterEach(function () {
    DB::purge('live_second');
    LiveLog::leave();
});

it('reads the heads before the controller, so an event sequenced while it runs is above the head the page is given', function () {
    foreach (['one', 'two', 'three'] as $id) {
        DB::connection('mysql')->transaction(fn () => LiveBus::record(ProbeEvent::on(Stream::central(), [Channels::user(3)], $id)));
    }

    Sequencer::run(Stream::central());

    Route::middleware(CaptureLiveHeads::class)->get('/__live-heads-probe', function () {
        // While the "controller" runs, another request commits a change and the relay sequences it.
        $second = DB::connection(LiveLog::secondConnection());
        $second->table('live_events')->insert([
            'tenant_id' => null, 'type' => 'probe.changed', 'entity' => 'probe', 'entity_id' => 'during', 'mode' => 'ref',
            'channels' => json_encode(['user.3']), 'available_at' => DB::raw('UTC_TIMESTAMP(6)'), 'created_at' => DB::raw('UTC_TIMESTAMP(6)'),
        ]);
        Sequencer::run(Stream::central());

        return response()->json([
            'heads' => CaptureLiveHeads::forRequest(),
            'during' => (int) DB::connection('mysql')->table('live_events')->where('entity_id', 'during')->value('seq'),
            'seen' => DB::connection('mysql')->table('live_events')->whereNotNull('seq')->pluck('entity_id')->all(),
        ]);
    });

    // A page load (an Inertia visit): the one kind of console request whose `live` prop the heads seed.
    $answer = $this->get('/__live-heads-probe', ['X-Inertia' => 'true'])->assertOk()->json();

    expect($answer['heads'])->toBe(['c' => 3])
        ->and($answer['during'])->toBe(4)
        ->and($answer['during'])->toBeGreaterThan($answer['heads']['c'])
        // Everything at or below the head was there before the request began.
        ->and(array_slice($answer['seen'], 0, 3))->toBe(['one', 'two', 'three']);
});

it('reads nothing for a console screen\'s own JSON read or a write: neither has a live prop to seed (W0)', function () {
    Route::middleware(CaptureLiveHeads::class)->match(['get', 'post'], '/__live-heads-skip', fn () => response()->json([
        'captured' => request()->attributes->has(CaptureLiveHeads::ATTRIBUTE),
    ]));

    expect($this->getJson('/__live-heads-skip')->assertOk()->json('captured'))->toBeFalse()
        ->and($this->postJson('/__live-heads-skip')->assertOk()->json('captured'))->toBeFalse()
        ->and($this->get('/__live-heads-skip')->assertOk()->json('captured'))->toBeTrue();
});

it('puts X-Live-Head on an authenticated API answer, naming the platform\'s head and the handset\'s own estate\'s', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    LiveAuthFixture::credentials();

    DB::connection('tenant')->table('live_streams')->update(['head_seq' => 41]);
    DB::connection('mysql')->table('live_streams')->update(['head_seq' => 1042]);
    tenancy()->end();

    $guard = LiveAuthFixture::guard(FacilitiesFixture::ESTATE);
    $token = LiveAuthFixture::token($guard);

    $response = $this->withToken($token)->getJson('/api/v1/live/config')->assertOk();

    expect($response->headers->get('X-Live-Head'))->toBe('c=1042;e=41');

    // An answer that is not a success carries no head, and neither does one with no handset.
    app('auth')->forgetGuards();

    expect($this->withToken('invented-'.bin2hex(random_bytes(8)))->getJson('/api/v1/live/config')->headers->has('X-Live-Head'))->toBeFalse();

    FacilitiesFixture::boot();
    DB::connection('tenant')->table('live_streams')->update(['head_seq' => 0]);
    tenancy()->end();

    Guard::query()->whereKey($guard->id)->delete();
    DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $guard->id)->where('tokenable_type', $guard->getMorphClass())->delete();
});

it('formats the heads as the phone reads them, and gives none for a log it cannot read', function () {
    expect(CaptureLiveHeads::format(['c' => 1042, 'e' => 88]))->toBe('c=1042;e=88')
        ->and(CaptureLiveHeads::format(['c' => 7]))->toBe('c=7');

    // Outside any estate: the platform's head only.
    expect(array_keys(CaptureLiveHeads::read()))->toBe(['c']);
});
