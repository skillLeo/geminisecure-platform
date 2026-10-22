<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The ticket's Assign keeps the page, as every other save does (M98, the preserveState audit)
|--------------------------------------------------------------------------
|
| Inertia 3.7's post keeps the page's state unless told otherwise, and 102 of the console's 103 saves take that
| default. Assign on a ticket alone kept the page only when the answer carried a field error (FAC-11's rule), so a
| refusal that named no field would have closed the modal and lost what was typed. It now takes the default, and a
| visit that saves closes the panel itself.
|
*/

it('lets the ticket\'s Assign save keep the page, with no rule of its own', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Facilities/Ticket.vue');

    $at = strpos($page, 'assignForm.post(`${ticketPath.value}/assign`');
    expect($at)->not->toBeFalse('the ticket page no longer posts Assign where this test looks');

    $call = substr($page, (int) $at, (int) strpos($page, '})', (int) $at) - (int) $at);

    expect(str_contains($call, 'preserveState'))->toBeFalse('Assign still sets its own preserveState: '.$call)
        ->and(str_contains($call, 'onSuccess: () => closePanel()'))->toBeTrue('a visit that saves no longer closes the panel');
});
