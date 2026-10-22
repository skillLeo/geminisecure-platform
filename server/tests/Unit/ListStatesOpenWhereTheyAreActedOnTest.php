<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A state shown in a list opens where it is acted on (Hassam, batch 1 item 10, 12 Oct, live)
|--------------------------------------------------------------------------
|
| "Action required" sat in a list as plain text. In this lane's lists the state is now a link to the page where it is
| acted on: a work order's state to the work order, a booking's to the booking, a person's or household's to the
| household's page. (The claims queue and Deleted accounts already draw each state on the card or row that carries its
| own actions.) Read as text: a unit test boots no application, and the JS runner is banned.
|
*/

function listPage(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/'.$path));
}

/** The body of a DataTable cell template, from its opening tag to its closing one. */
function listCell(string $vue, string $cell): string
{
    $at = strpos($vue, '<template #cell-'.$cell.'="{ row }">');
    expect($at)->not->toBeFalse('no '.$cell.' cell');

    return substr($vue, (int) $at, (int) strpos($vue, '</template>', (int) $at) - (int) $at);
}

it('links a work order\'s state, a booking\'s state and a register row\'s state to where each is acted on', function (): void {
    expect(str_contains(listCell(listPage('Facilities/Maintenance.vue'), 'status'), '<Link :href="ticketHref(row)" class="mq-status status-link"'))->toBeTrue('the queue\'s state opens the work order')
        ->and(str_contains(listCell(listPage('Facilities/Bookings.vue'), 'status'), ':href="facilities(`/amenities/bookings/${row.id}`)"'))->toBeTrue('a booking\'s state opens the booking');

    $register = listPage('Residents/Index.vue');

    foreach (['state', 'status'] as $cell) {
        expect(str_contains(listCell($register, $cell), ':href="unitPath(row.unit_slug, row.household_param)"'))->toBeTrue('the register\'s '.$cell.' opens the household');
    }
});
