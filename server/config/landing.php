<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The root host's public site
|--------------------------------------------------------------------------
|
| M24 (D-223): the website says nothing about the product. The M11 page that described it, and its words that
| lived here, are gone. What is left is the client's own site, as he built it.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | The client's own site (M14, D-194)
    |--------------------------------------------------------------------------
    |
    | The client's landing page, its Apply page and its Privacy Policy, built from the client's own code in landing/
    | into public/landing. While that build is not there, `/` is a plain page with the company's name and Log in,
    | and the other two are 404.
    |
    */
    'site' => [
        'index' => env('CLIENT_SITE_INDEX', public_path('landing/index.html')),

        // Where the site's two forms are sent: the client's address, gemisecja@gmail.com, which he confirmed on
        // 4 October 2026 (QUESTIONS LANDING-02). The page's "Support & Sales" used to show another spelling.
        'enquiries_to' => env('CLIENT_SITE_ENQUIRIES_TO', 'gemisecja@gmail.com'),

        /*
         * The Apply form's "Community Type". The client's old backend held the list, and it cannot be read (its
         * certificate no longer matches its name). These are the three kinds the page itself names — "Community,
         * Corporate, and Commercial" — until the client gives its own (QUESTIONS LANDING-03).
         */
        'community_types' => [
            ['id' => 'community', 'name' => 'Community'],
            ['id' => 'corporate', 'name' => 'Corporate'],
            ['id' => 'commercial', 'name' => 'Commercial'],
        ],
    ],
];
