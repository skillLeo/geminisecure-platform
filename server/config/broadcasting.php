<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',

                /*
                 * THE SAME BOUND AGAIN, WHERE THE SDK READS IT. pusher-php-server sends its own
                 * `timeout` on every request — 30 seconds unless this key sets it — and a request
                 * option beats the client default below, so `client_options.timeout` alone bounded
                 * nothing once a connection was open. Since M10 §1 the alert is broadcast FIRST,
                 * ahead of the guards' push, the mail and the texts: a Reverb that accepted the
                 * connection and then hung would have held every one of them for half a minute.
                 */
                'timeout' => (float) env('REVERB_TIMEOUT', 2),
            ],
            /*
             * A PANIC NEVER WAITS ON THE LIVE-UPDATE SERVICE. An alert is broadcast inside the request
             * that raised it, and Guzzle's default is to wait with no limit: with Reverb down, Windows
             * takes two seconds to refuse a closed local port, which pushed a resident's panic past the
             * app's send budget and onto "not received yet" although it had been recorded (2026-09-22).
             * The broadcast is rescued either way (`PushServiceProvider::announceAlert`); this only
             * bounds how long it can cost — and since the broadcast now goes first, how long it can
             * hold up the guards' push behind it.
             */
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_CONNECT_TIMEOUT', 0.5),
                'timeout' => (float) env('REVERB_TIMEOUT', 2),
            ],
        ],

        /*
         * THE LIVE RELAY'S OWN WAY IN (M11 Part C, DESIGN.md §1.5). Straight to Reverb on this
         * machine, over plain HTTP, rather than out through nginx and TLS and back in again as
         * `reverb` above does: the relay and Reverb are neighbours, and a publish that does not
         * leave the machine cannot be held up by anything between them. Reverb listens on
         * REVERB_SERVER_HOST, which is usually 0.0.0.0 (every address); nothing connects TO that,
         * so it is read as this machine.
         *
         * The same 0.5 s connect and 2 s total as the panic's broadcast. Relay::flushNow, which
         * runs inside a panic's request, narrows them further to what its budget has left.
         */
        'reverb_relay' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => in_array(env('REVERB_SERVER_HOST', '0.0.0.0'), ['0.0.0.0', '::', '[::]', ''], true)
                    ? '127.0.0.1'
                    : env('REVERB_SERVER_HOST'),
                'port' => (int) env('REVERB_SERVER_PORT', 8080),
                'scheme' => 'http',
                'useTLS' => false,
                'timeout' => 2.0,
            ],
            'client_options' => [
                'connect_timeout' => 0.5,
                'timeout' => 2.0,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
