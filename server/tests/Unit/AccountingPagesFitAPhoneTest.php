<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The money pages fit a phone's width (Hassam, 12 Oct, batch 1 item 12: "ultra class")
|--------------------------------------------------------------------------
|
| At 390 px the page's column is about 358 px. A row that holds five money figures side by side (now written with their
| cents, "$1,234,567.00") or a line that refuses to wrap pushes the whole page sideways. Each is given a phone rule in
| the page's own scoped style; the shared stylesheet and components are not touched.
|
| Read from each page's source: the rule is present inside a `@media (max-width: 640px)` block.
|
*/

/** The declarations of the first rule for `$selector` inside any `@media (max-width: …)` block of the page. */
function phoneRuleOf(string $page, string $selector): string
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$page);

    preg_match_all('/@media \(max-width: (?:640|720|767|768|1023)px\) \{(.*?)\n\}/s', $source, $blocks);

    foreach ($blocks[1] as $block) {
        if (preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/s', $block, $rule) === 1) {
            return $rule[1];
        }
    }

    return '';
}

it('lets the reconciliation summary\'s five figures wrap into columns a phone can hold', function (): void {
    $rule = phoneRuleOf('Estate/Accounting/Reconciliation.vue', '.rs-figures');

    expect($rule)->not->toBe('', 'no phone rule for the five figures')
        ->and(str_contains($rule, 'repeat(5'))->toBeFalse()
        ->and(preg_match('/grid-template-columns:\s*repeat\(2,/', $rule))->toBe(1, 'the figures are not two to a row on a phone');
});

it('stacks the sign-off footer of the reconciliation summary on a phone', function (): void {
    $rule = phoneRuleOf('Estate/Accounting/Reconciliation.vue', '.rs-foot');

    expect(preg_match('/flex-direction:\s*column/', $rule))->toBe(1, 'the note and the button stay in one row on a phone');
});

/*
 * SEEN ON THE DEV SERVER AT 390 PX (batch 1, 12 Oct): the page itself ran sideways on these five, as the demo Treasurer
 * and Gemini Accountant (429, 477, 440, 565 and 460 px wide). The Gemini console's shell carries none of the estate
 * shell's phone rules, so each page says its own.
 */
it('puts the two panes of the bank reconciliation one above the other on a phone', function (): void {
    $rule = phoneRuleOf('Estate/Accounting/Reconciliation.vue', '.recon-layout');

    expect(preg_match('/grid-template-columns:\s*minmax\(0,\s*1fr\)/', $rule))->toBe(1, 'the statement and the estate entries stay side by side on a phone');
});

it('lets the Gemini billing and report pages fall to the width of a phone', function (string $page, string $selector, string $declaration): void {
    $rule = phoneRuleOf($page, $selector);

    expect($rule)->not->toBe('', $page.': no phone rule for '.$selector)
        ->and(preg_match($declaration, $rule))->toBe(1, $page.': '.$selector.' keeps its desktop layout on a phone');
})->with([
    'invoices: the four tiles' => ['Gemini/Billing/Index.vue', '.kpi-row', '/grid-template-columns:\s*repeat\(2,/'],
    'plans: the three tier cards' => ['Gemini/Billing/Plans.vue', '.plan-grid', '/grid-template-columns:\s*minmax\(0,\s*1fr\)/'],
    'plans: the add-on card' => ['Gemini/Billing/Plans.vue', '.addon-card', '/flex-wrap:\s*wrap/'],
    'mrr: the tiles' => ['Gemini/Reports/Mrr.vue', '.kpi-row', '/grid-template-columns:\s*repeat\(2,/'],
    'mrr: the annotation' => ['Gemini/Reports/Mrr.vue', '.chart-annot', '/white-space:\s*normal/'],
    'revenue: the donut and its legend' => ['Gemini/Reports/Revenue.vue', '.donut-wrap', '/flex-wrap:\s*wrap/'],
]);

it('lets a credit note\'s row wrap on a phone, its amount line included', function (): void {
    $row = phoneRuleOf('Gemini/Billing/Invoice.vue', '.inv-row');
    $meta = phoneRuleOf('Gemini/Billing/Invoice.vue', '.inv-row-meta');

    expect(preg_match('/flex-direction:\s*column/', $row))->toBe(1, 'a credit note\'s words and its figures stay side by side')
        ->and(preg_match('/white-space:\s*normal/', $meta))->toBe(1, 'the credit note\'s amount line cannot wrap');
});
