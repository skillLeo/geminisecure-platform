<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    /*
     * NONE (13 D1). /api/v1 is the mobile apps' API and answers bearer tokens
     * only. With `web` here, Sanctum authenticated any request carrying a
     * console session first — a signed-in Director's browser could call a
     * handset endpoint, and its transient token passed every ability check. The
     * consoles authenticate through the `web` guard directly and never through
     * Sanctum, so nothing else depends on this.
     */
    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    /*
     * NULL, AND THAT NO LONGER MEANS "TOKENS NEVER EXPIRE" (M6, Q-M42).
     *
     * Sanctum's `expiration` is measured from `created_at`, so it is an ABSOLUTE life: a
     * token is thrown away N minutes after it was issued however hard the phone is being
     * used. The client's ruling — "Automatically logs him out within 30 minutes" — is an
     * IDLE rule, and an absolute thirty minutes would sign a guard out twenty-four times
     * in a twelve-hour watch, each time needing a one-time enrolment code from an office
     * that is shut.
     *
     * So the window lives in `config/api.php` (`session_idle_minutes`, 30) and is enforced
     * against `last_used_at` through Sanctum's own `authenticateAccessTokensUsing` hook —
     * see `App\Support\SessionWindow` and `App\Providers\AppServiceProvider`. This value
     * stays null so that the two do not fight: setting it would add a second, absolute
     * deadline on top of the sliding one.
     */
    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
