<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every form in the Residents lane marks what must be filled in, and shows examples as examples (batch 1 item 3)
|--------------------------------------------------------------------------
|
| Hassam, 12 Oct, live: "why the mandatory fields are not mentioned across the website." The shared mark
| (Components/RequiredMark.vue) on every required field's label, the one-line legend above each form's first field, the
| optional fields saying "(optional)", and every example in a placeholder said as one ("e.g. …"), never a value that
| reads as typed. Add resident, the claims queue, estate structure, the register's import, a household's three forms,
| Deleted accounts, maintenance (the queue's two forms, a work order's five) and facilities (amenities, bookings, a
| booking). Read as text: a unit test boots no application, and the JS runner is banned.
|
*/

function laneForm(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$path));
}

/** File => [the forms it holds (one legend each), labels that must carry the mark]. */
dataset('the lane\'s forms', [
    'Add resident' => ['Components/AddResidentFields.vue', 1, ['Phase <RequiredMark />', 'Lot number <RequiredMark />', 'Full name <RequiredMark />', 'Phone <RequiredMark />', 'Email <RequiredMark v-if="!add.form.no_email" />']],
    'a work order' => ['Pages/Estate/Facilities/Ticket.vue', 5, ['Vendor <RequiredMark />', 'Message <RequiredMark />', 'Why it is being reopened <RequiredMark />', 'Supplier <RequiredMark />', 'Amount (JMD) <RequiredMark />', 'Technician (optional)']],
    'the maintenance queue' => ['Pages/Estate/Facilities/Maintenance.vue', 2, ['What needs doing <RequiredMark />', 'Where <RequiredMark />', 'Category <RequiredMark />', '(optional, blank for now)']],
    'amenity settings' => ['Pages/Estate/Facilities/AmenitySettings.vue', 1, ['Name <RequiredMark />', 'Capacity, guests <RequiredMark />']],
    'bookings' => ['Pages/Estate/Facilities/Bookings.vue', 1, ['Why, in the words the household will be given <RequiredMark />']],
    'a booking' => ['Pages/Estate/Facilities/Booking.vue', 1, ['Received on (optional, blank for today)']],
    'claims' => ['Pages/Estate/Residents/Claims.vue', 1, ['Reason for refusing (it stays on the claim\'s record) <RequiredMark />']],
    'estate structure' => ['Pages/Estate/Structure/Index.vue', 2, ['<label for="ph-name">Name <RequiredMark /></label>', 'Blocks <RequiredMark />', 'Lots from (optional)', '<label for="imp-file">File <RequiredMark /></label>']],
    'the register' => ['Pages/Estate/Residents/Index.vue', 1, ['<label for="res-import-file">File <RequiredMark /></label>']],
    'a household' => ['Pages/Estate/Residents/Show.vue', 3, ['Relationship to the household <RequiredMark />', 'Moved in (optional)', 'Phone <RequiredMark />']],
    'deleted accounts' => ['Pages/Estate/Residents/DeletedAccounts.vue', 1, ['Why it must go now <RequiredMark />']],
]);

it('marks what each form requires, once explained above it', function (string $path, int $forms, array $labels): void {
    $vue = laneForm($path);

    expect(str_contains($vue, "import RequiredMark from '"))->toBeTrue($path.' does not use the shared mark')
        ->and(substr_count($vue, '<RequiredMark legend />'))->toBe($forms, $path.': one legend a form');

    foreach ($labels as $label) {
        expect(str_contains($vue, $label))->toBeTrue($path.' lacks: '.$label);
    }
})->with('the lane\'s forms');

it('says every example as one: a placeholder is "e.g. …", or what a blank means, never a value as if typed', function (string $path): void {
    // What a blank means, in words, and searches: not examples.
    $said = ['Free for residents', 'No deposit', 'Type the lot, e.g. 47', 'Search by name, email, lot or reference'];

    preg_match_all('/(?<![-:])placeholder="([^"]*)"/', laneForm($path), $found);

    foreach ($found[1] as $placeholder) {
        expect(str_starts_with($placeholder, 'e.g. ') || in_array($placeholder, $said, true))->toBeTrue($path.' shows a value as if typed: "'.$placeholder.'"');
    }
})->with(array_map(static fn (string $path): array => [$path], [
    'Components/AddResidentFields.vue', 'Pages/Estate/Facilities/Ticket.vue', 'Pages/Estate/Facilities/Maintenance.vue',
    'Pages/Estate/Facilities/AmenitySettings.vue', 'Pages/Estate/Facilities/Bookings.vue', 'Pages/Estate/Facilities/Booking.vue',
    'Pages/Estate/Residents/Claims.vue', 'Pages/Estate/Structure/Index.vue', 'Pages/Estate/Residents/Show.vue',
    'Pages/Estate/Residents/DeletedAccounts.vue',
]));
