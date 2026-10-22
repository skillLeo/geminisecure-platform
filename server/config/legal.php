<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public pages both app stores require (M5 §4, §10)
|--------------------------------------------------------------------------
|
| Privacy policy, terms of use, support, and the account-deletion request
| page are PUBLIC: reachable with no sign-in, from the store listings and from
| inside both apps. Their routes are in routes/public.php.
|
| The WORDS of the privacy policy and the terms are the company's, reviewed by
| its lawyer. They live in resources/legal/*.md. While `status` below is
| `draft`, each page carries a banner saying so, and the mobile build's
| `gate:store --release` refuses to pass — a draft must not reach a store.
| When the final text is pasted in, set LEGAL_TEXT_STATUS=final.
|
| Everything the company must supply is an env value here, so going live is a
| configuration change and never a code change.
|
*/

/*
| The contact details below are the COMPANY'S OWN, given by the client on 2026-09-21 in answer to
| Q-M33, and they are the DEFAULTS rather than blanks waiting on an env file. That is deliberate:
| both stores load these pages themselves, a missing support email is a rejection, and a value that
| only exists in one server's `.env` is a value a fresh deployment does not have. `env()` still wins
| where it is set, so nothing here stops the company moving an address later without a release.
|
| THE SUPPORT EMAIL IS THE CLIENT'S gemisecja@gmail.com since 4 October 2026. The address Q-M33 gave was a
| mailbox on geminisl.com, a domain with no mail server (it publishes no MX record), so nothing sent there was
| ever read. The policy, the terms and a payslip's employer line all read this one value, and
| ContactAddressIsTheClientsTest fails if that old mailbox, or any other address, is offered as a contact again
| (M33 §3). The first Director a brand-new server creates (deploy/50-estates.sh) uses this address too.
|
| There is NO support telephone number. The client gave an email and a postal address and no phone,
| so `support_phone` stays null and every page simply leaves the line out (the support page and the
| footer are both written to). The numbers the client gave for panic escalation are a control-room
| escalation list, NOT a public support line, and must never be published as one.
*/

return [
    'status' => env('LEGAL_TEXT_STATUS', 'draft'),

    // The developer named on both store listings and on every public page.
    // (The registered legal name — with its "Limited" or otherwise — is the company's to supply.)
    'company' => env('LEGAL_COMPANY_NAME', 'Gemini Security'),
    'product' => 'GeminiSecure',
    'address' => env('LEGAL_COMPANY_ADDRESS', 'Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies'),
    'support_email' => env('LEGAL_SUPPORT_EMAIL', 'gemisecja@gmail.com'),
    'support_phone' => env('LEGAL_SUPPORT_PHONE'),
    'country' => env('LEGAL_COUNTRY', 'Jamaica'),

    // The two apps, as the stores will name them.
    'apps' => [
        'resident' => 'GeminiSecure (Resident App)',
        'guard' => 'GeminiSecure Guard',
    ],

    /*
     * Where each app is downloaded (M11 §5.1), once it is listed. Null until the store listing exists: the landing
     * page then says the app is coming to that store, and never shows a store badge or a link that goes nowhere.
     * Setting the env value is the whole change on the day a listing goes live.
     */
    'stores' => [
        'resident' => ['android' => env('STORE_RESIDENT_ANDROID_URL'), 'ios' => env('STORE_RESIDENT_IOS_URL')],
        'guard' => ['android' => env('STORE_GUARD_ANDROID_URL'), 'ios' => env('STORE_GUARD_IOS_URL')],
    ],

    // True once the Resident App's "Explore a demo community" path is live on this server (M5 §3) — the public page then says so.
    'demo_community' => (bool) env('LEGAL_DEMO_COMMUNITY', false),

    // Kept only as long as it takes to act on them (stated on the pages).
    'public_request_retention_days' => 90,
];
