<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Text messages — the Resident App's one-time sign-in codes (13 D3) AND the site
     * emergency numbers a panic is texted to (Q-M09).
     *
     * NO PROVIDER IS BOUGHT. `log` writes the message down and delivers it to nobody.
     * Everything above it is built and proven: a site's numbers are rows
     * (`site_emergency_numbers`), a panic queues them (`Jobs\Alerts\SendPanicSms`),
     * every attempt is written to `alert_sms_dispatches` with its outcome, and the apps
     * are told `sms_delivered: 0` — because 0 is the truth and this product does not
     * say a message was sent when it was not.
     *
     * WHAT THE CLIENT MUST BUY: one SMS account able to originate to Jamaican mobiles
     * (+1 876 / +1 658) — Twilio, Vonage, Amazon SNS and the local carriers' own
     * gateways all do. It needs an account, a sender (a number or an alphanumeric
     * sender id) and roughly US$0.02 a message; four texts per panic is pennies a month.
     *
     * WHAT THEN CHANGES HERE: ONE SETTING, `SMS_DRIVER`, plus that provider's
     * credentials in this array read from the environment. Plus one class implementing
     * `Services\ResidentApp\SmsGateway` and one `match` arm in `AppServiceProvider`. No
     * caller, no table, no endpoint and no screen changes.
     *
     * The log driver stays refused in production for SIGN-IN CODES only — a one-time
     * code in a log file is a credential (`ResidentSignIn::smsAvailable()`). An
     * emergency message carries no credential, so it is recorded rather than dropped,
     * and it is never reported as sent.
     *
     * M10 §1: THE CONSOLE NOW OVERRIDES ALL FOUR. The Director connects a provider under
     * Platform settings → SMS, the row lives in `platform_sms_settings` with the token
     * encrypted, and `SmsSettings::apply()` puts it in front of these values on every
     * request and every gateway resolution. What is below is the fallback for a server
     * where nothing has been saved — and the way back, since deleting that row returns
     * the platform to exactly this. The names are generic rather than `TWILIO_*`
     * because the account SID, token and sender are the three things every provider on
     * the list asks for, whatever it calls them.
     */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'account_sid' => env('SMS_ACCOUNT_SID'),
        'token' => env('SMS_TOKEN'),
        'from' => env('SMS_FROM'),
    ],

    /*
     * Expo's push service (M4 §3.2). The access token is OPTIONAL — Expo accepts
     * unauthenticated sends unless "enhanced push security" is switched on for the
     * project — and absent by default. See config/push.php for everything else.
     */
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Where an invited resident gets the Resident App. Unset, the invite email says the estate office can tell them.
    'resident_app' => [
        'url' => env('RESIDENT_APP_URL'),

        /*
         * WHETHER A HOUSEHOLD MEMBER MAY BE GIVEN DEPENDENT OR HELPER ACCESS YET (`App\Api\AccessLevels::open`).
         * OFF until the Resident App release that reads `panic.contacts` is out: the app in the field takes the
         * panic screen's emergency contacts only from `me.household`, which a Helper is refused, so a Helper made
         * before that release would open panic to an empty contacts sheet. Off, everybody stays a Member.
         */
        'access_levels' => (bool) env('RESIDENT_APP_ACCESS_LEVELS', false),
    ],

    /*
     * PLACE SEARCH ON THE PLACES SCREEN (M30 §7.3): OpenStreetMap's Nominatim, asked by our server, never by the
     * browser, so every request carries our own name and a contact address (its usage policy), at most one a second
     * across the platform, each answer kept for 30 days (`App\Services\Gemini\PlaceSearch`). No key, no bill. The
     * address is one setting, for a self-hosted Nominatim should the platform ever outgrow the public one.
     */
    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'contact' => env('NOMINATIM_CONTACT', env('MAIL_FROM_ADDRESS')),
    ],

];
