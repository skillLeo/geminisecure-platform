<?php

declare(strict_types=1);

namespace App\Providers;

use App\Live\Access\Evictor;
use App\Live\Relay\Bell;
use App\Live\Relay\Breaker;
use App\Live\Relay\Clock;
use App\Live\Relay\NullTransport;
use App\Live\Relay\Publisher;
use App\Live\Relay\PusherTransport;
use App\Live\Relay\Signaller;
use App\Live\Relay\SystemClock;
use App\Live\Relay\Transport;
use App\Live\Relay\Wake;
use App\Live\Relay\Waker;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The live relay's parts, and the live updates' web door and its limits (M11 Part C, DESIGN §1.5, §3.4).
 *
 * `routes/live.php` holds the console session's `/live/*` routes, loaded here so they
 * carry their own middleware and not the page stack. The handset's live endpoints are
 * catalogue entries like every other /api/v1 route.
 *
 * PER PRINCIPAL, NOT PER ADDRESS. A control room is many consoles behind one address,
 * and one runaway tab must not take the others' live updates away. A console session is
 * keyed on its user; a handset on its token, as the API's own limiters are
 * (`RateLimitServiceProvider::device()`); and a request with neither falls back to its
 * address on its way to being refused.
 *
 * A BATCH COUNTS ONCE. Two hundred channels in one grant request is one request.
 */
final class LiveServiceProvider extends ServiceProvider
{
    /**
     * THE RELAY'S PARTS (DESIGN.md §1.5). One of each per process: the relay keeps its retry rests,
     * its eviction queue and its timers in them for as long as it runs. LiveBus rings the Wake after
     * every commit and hands ephemeral signals to the Publisher; with `live.wake` none the ring is
     * nothing and the relay polls, and with `live.publish` none every message goes nowhere (the test
     * suite's setting, which follows BROADCAST_CONNECTION=null).
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(Wake::class);
        $this->app->singleton(Waker::class, static fn ($app): Wake => $app->make(Wake::class));
        $this->app->singleton(Bell::class, static fn ($app): Wake => $app->make(Wake::class));
        $this->app->singleton(Breaker::class);
        $this->app->singleton(Transport::class, static fn (): Transport => config('live.publish') === 'reverb' ? new PusherTransport : new NullTransport);
        $this->app->singleton(Evictor::class);
        $this->app->singleton(Publisher::class);
        $this->app->singleton(Signaller::class, static fn ($app): Publisher => $app->make(Publisher::class));
    }

    /** Grant requests a minute: a tab subscribes its shell and page in one or two. */
    public const AUTH_PER_MINUTE = 60;

    /** Catch-up requests a minute: every reconnect, resume and gap, and the fallback poll. */
    public const CATCHUP_PER_MINUTE = 60;

    /** Row, record and tile refetches a minute: each live event on a busy screen is one. */
    public const ROWS_PER_MINUTE = 600;

    public function boot(): void
    {
        RateLimiter::for('live-auth', static fn (Request $request): Limit => Limit::perMinute(self::AUTH_PER_MINUTE)
            ->by('live-auth:'.self::principalKey($request)));

        RateLimiter::for('live-catchup', static fn (Request $request): Limit => Limit::perMinute(self::CATCHUP_PER_MINUTE)
            ->by('live-catchup:'.self::principalKey($request)));

        RateLimiter::for('live-rows', static fn (Request $request): Limit => Limit::perMinute(self::ROWS_PER_MINUTE)
            ->by('live-rows:'.self::principalKey($request)));

        $this->loadRoutesFrom(base_path('routes/live.php'));
    }

    /**
     * Who a limit is counted against: a console session by its user (`u-12`), a handset
     * by its token, and anything else by its address.
     *
     * THE SESSION'S USER FIRST, so a signed-in console cannot start a fresh bucket by
     * sending an Authorization header of its own invention. A handset is read from the
     * header rather than from `$request->user()`, for the reason `RateLimitServiceProvider`
     * gives: whether the limiter runs before or after `auth:sanctum` resolves it is the
     * framework's ordering, not this file's. A handset IS its token; hashed, so a
     * credential never becomes a cache key.
     */
    public static function principalKey(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return 'u-'.$user->id;
        }

        $bearer = $request->bearerToken();

        return $bearer === null || $bearer === '' ? 'ip:'.$request->ip() : 'token:'.hash('sha256', $bearer);
    }
}
