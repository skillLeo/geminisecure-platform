<?php

declare(strict_types=1);

use App\Live\Access\Evictor;
use App\Live\Auth\Grants;
use App\Live\Channels;
use App\Live\Events\AccessLive;
use App\Live\Relay\Breaker;
use App\Live\Relay\Publisher;
use App\Live\Relay\Sequencer;
use App\Live\Relay\Waker;
use App\Live\Stream;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Live\Support\FakeClock;
use Tests\Live\Support\FakeTransport;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\SpyWaker;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The backstop for a permission change nobody announced (DESIGN.md §7.4)
|--------------------------------------------------------------------------
|
| Somebody's access to an estate ends by a path that recorded no event (a row changed by hand, a
| seeder, a writer that forgot). Their console is still open, on a socket still subscribed to the
| estate's security feed. Within five minutes live:reconcile asks the rules again, tells them
| `access.changed`, and the relay closes their sockets: the next page they see is the one the rules
| allow. Nobody still admitted is touched.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    tenancy()->end();

    LiveLog::reset();
    DB::connection('mysql')->table(Grants::TABLE)->delete();
    app()->instance(Waker::class, new SpyWaker);
    app(Breaker::class)->close();

    $this->t = FacilitiesFixture::ESTATE;
    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $this->treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    tenancy()->end();

    // Giving the two of them the console told Settings → Users (`setting.changed`, M18H §7.2). What these tests count
    // is what live:reconcile says, so the log starts after the fixture.
    LiveLog::reset();

    $grant = fn (string $principal, string $channel, string $socket, ?Carbon $at = null) => DB::connection('mysql')->table(Grants::TABLE)->insert([
        'principal' => $principal, 'channel' => $channel, 'socket_id' => $socket, 'granted_at' => ($at ?? Carbon::now())->format('Y-m-d H:i:s.u'),
    ]);

    $grant('u-'.$this->president->id, Channels::estateFeed($this->t, 'security'), '1.1');
    $grant('u-'.$this->president->id, Channels::user($this->president->id), '1.1');
    $grant('u-'.$this->treasurer->id, Channels::user($this->treasurer->id), '2.2');
    $grant('u-987654321', Channels::user(987654321), '3.3');
    $grant('u-'.$this->treasurer->id, Channels::user($this->treasurer->id), '4.4', Carbon::now()->subHours(27));
});

afterEach(function () {
    DB::connection('mysql')->table('estate_assignments')->where('user_id', $this->president->id)->where('tenant_id', $this->t)->update(['is_active' => true]);
    DB::connection('mysql')->table(Grants::TABLE)->delete();
    LiveLog::reset();
    tenancy()->end();
});

function reconcileGrants(): array
{
    return DB::connection('mysql')->table(Grants::TABLE)->orderBy('principal')->orderBy('channel')->get(['principal', 'channel', 'socket_id'])
        ->map(fn ($g) => "{$g->principal} {$g->channel} {$g->socket_id}")->all();
}

it('tells a principal whose access ended by no announced path, and the relay then closes its sockets; nobody else is touched', function () {
    // The President's assignment to the estate ends, and nothing records an event for it.
    DB::connection('mysql')->table('estate_assignments')->where('user_id', $this->president->id)->where('tenant_id', $this->t)->update(['is_active' => false]);

    expect(Artisan::call('live:reconcile'))->toBe(0);

    $events = LiveLog::events();

    // One event, for the President alone, on their own channel, saying only how it changed.
    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AccessLive::TYPE)
        ->and($events[0]->entity_id)->toBe('u-'.$this->president->id)
        ->and(json_decode((string) $events[0]->channels, true))->toBe([Channels::user($this->president->id)])
        ->and(json_decode((string) $events[0]->summary, true))->toBe(['effect' => 'reduced']);

    // The refused grant is gone; the grant they still hold, the Treasurer's, stay; the vanished
    // principal's grants and the stale one are gone.
    expect(reconcileGrants())->toBe([
        'u-'.$this->president->id.' '.Channels::user($this->president->id).' 1.1',
        'u-'.$this->treasurer->id.' '.Channels::user($this->treasurer->id).' 2.2',
    ]);

    // The relay publishes it, and 250 ms later terminates exactly that principal's sockets.
    $clock = new FakeClock;
    $transport = new FakeTransport;
    $evictor = new Evictor($transport, $clock);

    Sequencer::run(Stream::central());
    (new Publisher($transport, app(Breaker::class), $evictor, $clock))->run(Stream::central());

    expect(collect($transport->envelopes(Channels::user($this->president->id)))->pluck('t')->all())->toBe([AccessLive::TYPE])
        ->and($transport->terminated)->toBe([]);

    $clock->advance(Evictor::DELAY_SECONDS + 0.01);
    $evictor->runDue();

    expect($transport->terminated)->toBe(['u-'.$this->president->id]);

    // A second run finds nothing more to say.
    expect(Artisan::call('live:reconcile'))->toBe(0)
        ->and(LiveLog::events())->toHaveCount(1);
});

it('says nothing when every grant still holds', function () {
    expect(Artisan::call('live:reconcile'))->toBe(0)
        ->and(LiveLog::events())->toHaveCount(0)
        ->and(collect(reconcileGrants())->filter(fn ($g) => str_starts_with($g, 'u-'.$this->president->id))->count())->toBe(2);
});
