<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The live delivery log (M11 Part C, DESIGN.md §1)
|--------------------------------------------------------------------------
|
| Only what an operator may need to change lives here. What each event class makes live (tracked
| tables, hydrators, exemptions) is declared on the class and collected by App\Live\Registry, so
| no unit edits a list in this file (§12.2).
|
*/

return [

    /*
     * How many days of the log `live:prune` keeps (§1.8). Seven, matching `sync.pull`'s default
     * horizon: a handset away for longer than this is told `reset` and resyncs its screen rather
     * than being handed a partial list.
     */
    'retention_days' => (int) env('LIVE_RETENTION_DAYS', 7),

    /*
     * HOW A COMMIT WAKES THE RELAY (§1.3). `redis`: LiveBus rings a Redis bell after each commit
     * (App\Live\Relay\Wake) and the relay publishes within milliseconds. `none`: nothing is rung
     * and the relay looks at every stream every 250 ms instead, which is what the tests and a
     * laptop without Redis use. 80-live.sh sets `redis` on the server.
     */
    'wake' => env('LIVE_WAKE', 'none'),

    /* The Redis connection the bell is rung on. Tenancy never prefixes it (config/tenancy.php). */
    'redis_connection' => env('LIVE_REDIS_CONNECTION', 'default'),

    /*
     * WHERE THE RELAY PUBLISHES. `reverb`: straight to Reverb on this machine over plain HTTP, by
     * the `reverb_relay` connection (config/broadcasting.php). `none`: every publish is accepted
     * and sent nowhere. The default follows BROADCAST_CONNECTION, so the test suite, which sets it
     * to null, never opens a socket to anything.
     */
    'publish' => env('LIVE_PUBLISH', env('BROADCAST_CONNECTION', 'null') === 'null' ? 'none' : 'reverb'),

    /*
     * The relay's clockwork (§1.5). The pulse is what every open screen's "Live" is judged by:
     * a screen that has heard none for 25 s says "delayed", so it must come well inside that.
     * The breaker is how long publishing rests after Reverb failed to answer, while sequencing
     * (and so catch-up) carries on. A publish Reverb refuses is retried after each delay in
     * `retry_seconds`, then every last one, and given up after `give_up_after` attempts.
     */
    'pulse_seconds' => (int) env('LIVE_PULSE_SECONDS', 10),
    'breaker_seconds' => (int) env('LIVE_BREAKER_SECONDS', 10),
    'retry_seconds' => [0.5, 1, 2, 5],
    'give_up_after' => 5,

    /*
     * The build every pulse and catch-up names, so an open console learns that the server moved on
     * and offers "New version — reload". The deploy writes REVISION into the release; `config:cache`
     * reads it once, after it exists.
     */
    'build' => env('LIVE_BUILD') ?: (is_file(base_path('REVISION')) ? substr(trim((string) file_get_contents(base_path('REVISION'))), 0, 7) : null),

    /*
     * The port a browser or a phone opens the socket on, when it is not the one the server
     * publishes to: nginx takes the socket on 443 and hands it to Reverb on 8080 (§9.2).
     */
    'public_ws_port' => env('LIVE_PUBLIC_WS_PORT'),

];
