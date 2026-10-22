<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A status shown in a list leads to where it is acted on (Hassam, 12 Oct, batch 1 item 10)
|--------------------------------------------------------------------------
|
| "Statuses sitting as plain text." A bill's Approved, a vendor's No TRN, an invoice's Overdue, a journal's Draft are
| each the first thing a person reads about the row and the thing they would act on, so each is a link (or a control
| that opens the row's own dialog), never bare text. A status of a row that has nowhere to go stays text, and says why
| in its own words (the payment methods list: its Edit is greyed with the reason above the table).
|
| Read from each list's source.
|
*/

function statusListSource(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$page);
}

it('wraps every status badge of the list in the control that acts on its row', function (string $page, int $minimum): void {
    $source = statusListSource($page);

    $badges = preg_match_all('/class="status-badge"/', $source);
    $wrapped = preg_match_all('/<(?:Link|button)\b[^>]*>\s*<(?:span|div)\b[^>]*class="status-badge"/s', $source);

    expect($badges)->toBeGreaterThanOrEqual($minimum, $page.': the list draws no status badge to check')
        ->and($wrapped)->toBe($badges, $page.': a status badge in the list is plain text, not a link to where it is acted on');
})->with([
    'vendors: status and no-TRN' => ['Estate/Accounting/Vendors.vue', 2],
    'bills: status' => ['Estate/Accounting/Bills.vue', 2],
    'gemini invoices: status' => ['Gemini/Billing/Index.vue', 1],
]);

it('links the entry a bank line is matched to, so the claim can be checked', function (): void {
    $source = statusListSource('Estate/Accounting/Reconciliation.vue');

    expect(preg_match('/<Link\b[^>]*journals\/\$\{line\.matched_entry_ref\}/s', $source))->toBe(1, 'the matched entry reference is plain text')
        ->and(str_contains($source, '[line.date, line.note, line.matched_entry_ref]'))->toBeFalse('the reference is still joined into the plain line');
});

it('links a manual journal\'s status to the journal it is the state of', function (): void {
    $source = statusListSource('Estate/Accounting/ManualJournals.vue');

    // The status cell is the last cell of its row and holds a link, to the draft or to the posted journal.
    expect(preg_match('/<td>\s*<Link\b[^>]*>\s*\{\{\s*draft\.status === \'Posted\'/s', $source))->toBe(1, 'the journal status is plain text');
});
