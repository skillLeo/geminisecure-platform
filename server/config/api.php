<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The mobile API's own settings (13 D1)
|--------------------------------------------------------------------------
|
| Everything that describes an ENDPOINT lives in `App\Api\Catalogue` and
| nowhere else. This file is for the few things the API does BETWEEN
| endpoints, which an operator may want to change without a deploy.
|
*/

return [

    /*
     * How many days a retried write's answer is kept.
     *
     * A retried write is answered, not repeated: the first attempt's response is
     * stored against the handset and the key it chose, and every retry is handed
     * that same response (`App\Http\Middleware\Api\Idempotent`). The record has to
     * outlive a RETRY, not a customer.
     *
     * The handset chooses its key before the first attempt and reuses it until it
     * gets an answer; its outbox warns the person at twelve hours and drops
     * nothing, so the longest honest gap between a first attempt and its last retry
     * is a phone that was switched off. Thirty days is sixty times the point at
     * which the app itself calls the queue old — long enough that no real retry can
     * outlive it, short enough that an answer is gone within the month rather than
     * kept for the life of the platform.
     *
     * `api:prune-idempotency` (scheduled nightly) is what enforces it, in the
     * central table and in every estate.
     */
    'idempotency_retention_days' => (int) env('API_IDEMPOTENCY_RETENTION_DAYS', 30),

    /*
     * How long a handset may sit UNTOUCHED before its token stops working.
     *
     * THE CLIENT'S RULING (M6, Q-M42): "Automatically logs him out within 30 minutes."
     *
     * IT IS AN IDLE WINDOW AND IT SLIDES. Every authenticated request renews it, so the
     * thirty minutes are counted from the LAST request the token made, not from when it
     * was issued. Somebody working is never interrupted; a phone left on a desk, in a
     * drawer, in a taxi or sold has thirty minutes of life left in it and no more.
     *
     * An ABSOLUTE thirty minutes was the other reading of the same sentence, and it is
     * the dangerous one: a guard on a twelve-hour watch would be signed out twenty-four
     * times, each time needing a fresh enrolment code from an office that is shut. See
     * `App\Support\SessionWindow`, and DECISIONS D-M126.
     *
     * Enforced in `App\Providers\AppServiceProvider` through Sanctum's own
     * `authenticateAccessTokensUsing` hook, against `personal_access_tokens.last_used_at`
     * — which Sanctum already writes on every authenticated request, so this costs no
     * extra write. `config/sanctum.php`'s own `expiration` stays null on purpose: it is
     * an absolute expiry measured from `created_at` and is the wrong instrument.
     */
    'session_idle_minutes' => (int) env('API_SESSION_IDLE_MINUTES', 30),

];
