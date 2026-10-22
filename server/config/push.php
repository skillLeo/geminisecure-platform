<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Push notifications to a closed app (M4 §3.2)
|--------------------------------------------------------------------------
|
| Expo's push service carries both platforms. `App\Services\Push\PushNotifier`
| is the one place a push is decided; `App\Jobs\Push\SendExpoPush` is the one
| place the network is touched, and it is always queued.
|
*/

return [

    /*
     * The switch. ON everywhere except the test suite, where a test that wants a
     * push turns it on for itself and fakes the Http client — a test never reaches
     * Expo. Turning it off stops sending and nothing else: phones still register.
     */
    'enabled' => (bool) env('PUSH_ENABLED', env('APP_ENV') !== 'testing'),

    'expo_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),

    'expo_receipts_url' => env('EXPO_PUSH_RECEIPTS_URL', 'https://exp.host/--/api/v2/push/getReceipts'),

    /* Expo accepts at most 100 messages in one request. */
    'batch' => 100,

    /*
     * One push per (recipient, type, record) inside this many seconds. Panic,
     * duress and the other always-on urgent types are never coalesced.
     */
    'coalesce_seconds' => (int) env('PUSH_COALESCE_SECONDS', 300),

    /*
     * How long after a send the carriers' verdict is asked for. A phone that
     * uninstalled the app is usually reported HERE (DeviceNotRegistered), not on
     * the ticket. Expo keeps receipts for a day. 0 never asks.
     */
    'receipt_delay_seconds' => (int) env('PUSH_RECEIPT_DELAY_SECONDS', 900),

    /*
     * The queue connection the sender is put on. With the `database` driver the
     * default connection follows tenancy into an estate's database, which has no
     * `jobs` table — so the sender is pinned to `central` (config/queue.php),
     * which writes the same central `jobs` table the worker already reads. With
     * any other driver the application's default is used.
     */
    'queue_connection' => env('PUSH_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database') === 'database' ? 'central' : null),

    /*
     * THE QUEUE A PANIC JUMPS TO. One notice to a two-thousand-phone estate is
     * twenty `SendExpoPush` jobs, each worth up to twelve seconds of network, and a
     * panic raised behind them would wait for all of it. So the urgent types
     * (`PushTypes::urgent` — panic, duress, an alertness check, somebody at the
     * gate) are put on their own queue, and the worker is told to read it FIRST:
     *
     *     php artisan queue:work --queue=push-urgent,default
     *
     * THE WORKER HAS TO NAME IT. A queue nobody reads is worse than no queue at
     * all, so every place that starts a worker lists it — `docs/DEPLOY.md` §4 and
     * the mobile repo's `start-phone-preview.ps1` — and `PushNotificationsTest`
     * reads the deploy guide and fails if the two ever disagree. Set this to an
     * empty string and every push goes back onto one queue: slower for a panic,
     * but never lost.
     */
    'urgent_queue' => env('PUSH_URGENT_QUEUE', 'push-urgent'),

    /*
     * THE RING'S OWN QUEUE (M18 §1.4). A panic queues its mail and its texts on the urgent queue a moment before its
     * first ring round, and a round behind ten mails was picked up five seconds late on production. So the call-style
     * ring — its rounds, its pushes, its stops and the officer's test ring — has a queue of its own, with a worker of
     * its own (`gs-queue-ring`), and every panic worker reads it FIRST:
     *
     *     php artisan queue:work --queue=ring                  (the ring's worker, nothing else)
     *     php artisan queue:work --queue=ring,push-urgent      (the panic workers)
     *
     * Empty, and the ring goes back on the urgent queue (`App\Services\Ring\RingQueue`).
     */
    'ring_queue' => env('PUSH_RING_QUEUE', 'ring'),

    /*
     * How long a `push_coalescing` row is kept after it was last written.
     *
     * One row per (recipient, type, record), never updated away, so without this
     * the table grows for ever: every notice, ticket, payment and election, once
     * per household, for the life of the estate.
     *
     * A week, rather than the coalescing window, because two kinds of row live in
     * that table. `PushNotifier::firstInWindow` needs its row only for
     * `coalesce_seconds`; `PushAnnouncer::firstTime` needs its row until the meeting
     * or election it names has passed, or the hourly `push:reminders` would say
     * "meeting tomorrow" a second time. `push:prune-coalescing` never deletes
     * inside `coalesce_seconds`, whatever this is set to.
     */
    'coalesce_retention_seconds' => (int) env('PUSH_COALESCE_RETENTION_SECONDS', 7 * 24 * 60 * 60),

];
