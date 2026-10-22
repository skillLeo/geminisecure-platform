<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The priority control says what it does, in one line (M53 Law 2; step 4 of Sunday's walk)
|--------------------------------------------------------------------------
|
| On the maintenance queue (fourteen times down the page) and on a ticket, hovering the priority read "Raise or lower
| the priority. The service target moves with it and the clock does not reset — a ticket is always measured from the
| moment it was reported." `COPY_REWRITES.md` §2: "Change the priority." The rule it explained stays in the code.
|
*/

it('says "Change the priority." on the queue and on a ticket, and nothing about the clock', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['Maintenance.vue', 'Ticket.vue'] as $page) {
        $vue = (string) file_get_contents($root.'/resources/js/Pages/Estate/Facilities/'.$page);

        // A bound tooltip (the queue's) or a plain one (the ticket's priority button, since its modal, 4568b81d): the
        // words are what is checked. This read only the bound form, and was red on the ticket from then until 12 Oct.
        expect(str_contains($vue, "'Change the priority.'") || str_contains($vue, 'title="Change the priority."'))->toBeTrue($page.' has no "Change the priority." tooltip')
            ->and(str_contains($vue, 'Raise or lower the priority'))->toBeFalse($page.' says the long priority words');
    }
});
