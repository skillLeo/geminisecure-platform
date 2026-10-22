<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What the amenities service says, it says without an em dash
|--------------------------------------------------------------------------
|
| M53 Law 3, routed to the hub by Accounting and Residents (12 October 2026): the deposit refusals and memos, the
| booking detail's timeline ("Quoted — $5,000"), the refusals a resident meets booking, completing, and the morning
| run's report all carried one. Every string literal in the service is read here, code comments aside, so a new one
| cannot slip back in.
|
*/

it('writes no em dash in any string the amenities service can show or post', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Estate/Amenities.php');

    $found = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($token[1], '—')) {
            $found[] = 'line '.$token[2].': '.mb_substr($token[1], 0, 90);
        }
    }

    expect($found)->toBe([], implode("\n", $found));
});
