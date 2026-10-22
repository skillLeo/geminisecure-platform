<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A ticket's action that cannot be used looks it (M53 Law 1; step 4 of Sunday's walk)
|--------------------------------------------------------------------------
|
| The 9 October walk found Message resident off on a ticket raised in the console ("Only a request a resident reported
| has a resident to message.") and drawn exactly like the live buttons beside it: the sheets change only the cursor of a
| disabled button. The ticket page now draws its disabled action buttons faded, so off looks off before anybody presses.
|
*/

it('draws a disabled action button on the ticket page faded, not like a live one', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Facilities/Ticket.vue');

    $at = strpos($page, 'button.stack-btn[disabled] {');
    expect($at)->not->toBeFalse('the ticket page has no rule for a disabled action button');

    $rule = substr($page, (int) $at, (int) strpos($page, '}', (int) $at) - (int) $at);
    expect($rule)->toContain('opacity:');
});
