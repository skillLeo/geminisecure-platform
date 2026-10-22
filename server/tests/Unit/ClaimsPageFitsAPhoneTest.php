<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The unit claims page fits a phone (Hassam, batch 1 item 12, 12 Oct, live)
|--------------------------------------------------------------------------
|
| The one page in the Residents lane with no rule for a phone. The board draws each claim's two comparison columns side
| by side at any width, so at 390 px an email or a lot's address had about 145 px and ran past its column; the three
| decisions (Approve, Request ID document, Reject) sat on one row that does not wrap, wider than the card; the title's
| pills and the access line did not wrap either; the queue's five columns squeezed each word onto its own line. On a
| phone the columns stack, the rows wrap, a long value breaks, and the table keeps readable columns and scrolls inside its
| own card, as the maintenance and bookings queues do. Doubled classes outrank the board's `body.wf-… .claim-top`.
| Read as text: a unit test boots no application, and the JS runner is banned.
|
*/

/** The page's style rules for a phone: the body of its `@media (max-width: 640px)` block. */
function claimsPhoneRules(): string
{
    $vue = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Residents/Claims.vue'));
    $at = strpos($vue, '@media (max-width: 640px) {');
    expect($at)->not->toBeFalse('Claims.vue has no rule for a phone');

    return substr($vue, (int) $at, (int) strpos($vue, "\n}\n", (int) $at) - (int) $at);
}

/** One rule's declarations inside the phone block, from its selector to its closing brace. */
function claimsPhoneRule(string $selector): string
{
    $rules = claimsPhoneRules();
    $at = strpos($rules, $selector.' {');
    expect($at)->not->toBeFalse('no phone rule for '.$selector);

    return substr($rules, (int) $at, (int) strpos($rules, '}', (int) $at) - (int) $at);
}

it('stacks a claim\'s two comparison columns on a phone, and lets a long value break', function (): void {
    expect(str_contains(claimsPhoneRule('.compare-grid.compare-grid'), 'grid-template-columns: minmax(0, 1fr);'))->toBeTrue('the columns stack')
        ->and(str_contains(claimsPhoneRule('.compare-row.compare-row span'), 'overflow-wrap: anywhere;'))->toBeTrue('a long value breaks inside its column');
});

it('wraps the decisions, the title\'s pills and the access line on a phone', function (string $selector): void {
    expect(str_contains(claimsPhoneRule($selector), 'flex-wrap: wrap;'))->toBeTrue($selector.' wraps');
})->with(['.claim-actions.claim-actions', '.claim-top.claim-top', '.claim-access']);

it('keeps the queue\'s columns readable on a phone, scrolling inside its card', function (): void {
    expect(str_contains(claimsPhoneRule(':deep(.data-table)'), 'min-width: 560px;'))->toBeTrue();
});
