<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What the walk's pages show carries no em dash (M53 Law 3; M72 §2.6, the remaining copy)
|--------------------------------------------------------------------------
|
| The lines a person reads during Sunday's ten steps on this session's pages: the new work order's labels and its
| head, Assign vendor's note and Mark completed's field (step 4), New charge's unit line (step 5), the meeting
| preview's title, its venue label and a meeting's quorum line (step 6), the committee card's title and the page's tab
| (step 7), and the household page's people and invite lines (step 3). Each said its second half after an em dash; the
| middot sits between two names now, and a colon, a semicolon or brackets carry a sentence on. Error and empty states
| that the walk does not reach are the register's (DR-037), next.
|
*/

it('leaves no em dash in the lines the walk shows on this session\'s pages', function (string $page, array $gone): void {
    $vue = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$page);

    expect($vue)->not->toBe('', "{$page} was not read");

    // `toContain` takes several needles, so a message passed to it would be one more needle: asked plainly instead.
    foreach ($gone as $old) {
        expect(str_contains($vue, $old))->toBeFalse("{$page} still says: {$old}");
    }
})->with([
    'step 4, the queue and its new work order' => ['Pages/Estate/Facilities/Maintenance.vue', [
        'The service clock runs from the time reported — ',
        'Unit — if it is a household',
        'are listed — type more',
        'Priority — sets the target',
        'Reported at — blank for now',
        'Detail — what the technician',
        '— changing the priority moves',
    ]],
    'step 4, a ticket' => ['Pages/Estate/Facilities/Ticket.vue', [
        'What was done — the resident',
        'is running regardless — assigning',
    ]],
    'step 5, New charge' => ['Pages/Estate/Dues/NewCharge.vue', [
        "' — ' + props.unit.resident",
    ]],
    'step 6, the scheduler' => ['Pages/Estate/Governance/MeetingScheduler.vue', [
        '${props.estate.name} — ${typeLabel}',
        'Venue — adding one makes it hybrid',
    ]],
    'step 6, a meeting' => ['Pages/Estate/Governance/Meeting.vue', [
        '{{ meeting.quorum.basis }} — {{ meeting.quorum.required }}',
    ]],
    'step 7, the elections page' => ['Pages/Estate/Governance/ControlRoom.vue', [
        '${props.estate.name} — ${props.heading}',
        '${heading} — control room',
    ]],
    'step 3, a household' => ['Pages/Estate/Residents/Show.vue', [
        'Lapsed without being used — send',
        'Accepted — they signed in',
        '${person.full_name} — ${person.app_access.label}',
        '{{ chosenAccess.full_name }} — {{ chosenAccess.app_access.label }}',
        'It was just sent — the timer',
        'has no email — alerts go',
        'A household has one — ticking',
    ]],
    'step 3, the register' => ['Pages/Estate/Residents/Index.vue', [
        'It was just sent — the timer',
    ]],
]);
