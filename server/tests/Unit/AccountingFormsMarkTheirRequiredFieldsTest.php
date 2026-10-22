<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A form says which fields must be filled in, in short labels (Hassam, 12 Oct, batch 1 item 3)
|--------------------------------------------------------------------------
|
| "Why the mandatory fields are not mentioned across the website." The one mark is Components/RequiredMark.vue:
| in a required field's label `<RequiredMark />`, once above a form's first field `<RequiredMark legend />`, and
| `required` on the input. A label is a short name; what a field costs or accepts is a hint under it, never a sentence
| in the label ("TRN: nine digits; blank until it arrives, and no payment until it does"). An example is a placeholder
| that starts "e.g.", never a real value that reads as typed.
|
| Read from each page's source, for the pages listed in the dataset: a page joins the list in the commit that fixes it.
|
*/

function requiredFormsSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$path);
}

/** The `<template>` block only: the script and the style say nothing a person reads. */
function requiredFormsTemplate(string $source): string
{
    $start = strpos($source, '<template>');
    $end = strpos($source, '<style');

    return $start === false ? $source : substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
}

/** The words of a label: its text with tags and interpolation read as their own words. */
function requiredFormsLabelWords(string $inner): string
{
    $inner = preg_replace('/<RequiredMark\s*\/>/', '', $inner) ?? $inner;
    $inner = preg_replace('/<[^>]+>/', '', $inner) ?? $inner;
    $inner = preg_replace('/\{\{[^}]*\}\}/', 'x', $inner) ?? $inner;

    return trim((string) preg_replace('/\s+/', ' ', $inner));
}

it('marks every required field in its label, names it in a short label, and shows the legend once above the form', function (string $page): void {
    $source = requiredFormsSource($page);
    $template = requiredFormsTemplate($source);

    // Drawn, so imported: an unimported component is an unknown element, and the mark would not show at all.
    expect(preg_match('/^import RequiredMark from .+Components\/RequiredMark\.vue/m', $source))->toBe(1, $page.': uses the required mark and never imports it');

    preg_match_all('/<(?:input|select|textarea)\b[^>]*?\bid="([^"]+)"[^>]*>/s', $template, $fields, PREG_SET_ORDER);

    $required = [];

    foreach ($fields as [$tag, $id]) {
        // A real attribute of the element, not a word inside a `:disabled="… required …"` binding.
        $bare = preg_replace('/"[^"]*"/', '""', $tag) ?? $tag;

        if (preg_match('/\s(required|aria-required)\b/', $bare) === 1) {
            $required[$id] = true;
        }
    }

    expect($required)->not->toBe([], $page.': a form with no required field to mark');

    foreach (array_keys($required) as $id) {
        expect(preg_match('/<label\b[^>]*\bfor="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/label>/s', $template, $label))
            ->toBe(1, $page.': the required field '.$id.' has no label');

        expect(preg_match('/<RequiredMark\s*\/>/', $label[1]))->toBe(1, $page.': the label of the required field '.$id.' carries no required mark');
    }

    // Every label is a short name, a hint carrying whatever else there is to say.
    preg_match_all('/<label\b[^>]*>(.*?)<\/label>/s', $template, $labels);

    foreach ($labels[1] as $inner) {
        // A tick box's label is its sentence: the words sit beside the box and name nothing above it.
        if (str_contains($inner, '<input')) {
            continue;
        }

        $words = requiredFormsLabelWords($inner);

        expect(mb_strlen($words))->toBeLessThanOrEqual(36, $page.': the label "'.$words.'" is a sentence; a short name and a hint under the field');
    }

    // A page with required fields names the mark at least once (a form on the page itself, or in its dialogs).
    expect(preg_match_all('/<RequiredMark\b[^>]*\blegend\b/', $template))->toBeGreaterThanOrEqual(1, $page.': the mark is never explained');

    // The legend: once above the first field of each dialog that has a required field.
    preg_match_all('/<ConsoleDialog\b.*?<\/ConsoleDialog>/s', $template, $dialogs);

    foreach ($dialogs[0] as $dialog) {
        if (preg_match('/<(?:input|select|textarea)\b[^>]*?\bid="([^"]+)"/s', $dialog, $first) === 1 && isset($required[$first[1]])) {
            expect(preg_match_all('/<RequiredMark\b[^>]*\blegend\b/', $dialog))->toBe(1,$page.': a dialog with a required field names the mark once');
        }
    }
})->with([
    'vendors: add a vendor' => ['Estate/Accounting/Vendors.vue'],
    'a vendor: edit it, record a bill' => ['Estate/Accounting/Vendor.vue'],
    'bills: record, void and pay' => ['Estate/Accounting/Bills.vue'],
    'chart of accounts: add an account' => ['Estate/Accounting/ChartOfAccounts.vue'],
    'a manual journal' => ['Estate/Accounting/ManualJournal.vue'],
    'gemini: onboard a client' => ['Gemini/Clients/Create.vue'],
    'gemini: change a client\'s plan' => ['Gemini/Clients/Plan.vue'],
    'gemini: credit note on an invoice' => ['Gemini/Billing/Invoice.vue'],
]);

it('writes a form example as a placeholder that starts "e.g."', function (string $page): void {
    $template = requiredFormsTemplate(requiredFormsSource($page));

    preg_match_all('/<(?:input|textarea)\b[^>]*?\splaceholder="([^"]*)"/s', $template, $found);

    foreach ($found[1] as $placeholder) {
        $isExample = str_starts_with($placeholder, 'e.g.');
        $isCommand = preg_match('/^(Search|Type|Add|Choose|Pick|Enter)\b/', $placeholder) === 1;
        // The empty-amount convention of a ledger grid, which is no value anybody typed.
        $isEmptyAmount = $placeholder === '0.00';

        expect($isExample || $isCommand || $isEmptyAmount)->toBeTrue($page.': the placeholder "'.$placeholder.'" reads as a typed value');
    }
})->with([
    'vendors: add a vendor' => ['Estate/Accounting/Vendors.vue'],
    'a vendor: edit it, record a bill' => ['Estate/Accounting/Vendor.vue'],
    'bills: record, void and pay' => ['Estate/Accounting/Bills.vue'],
    'chart of accounts: add an account' => ['Estate/Accounting/ChartOfAccounts.vue'],
    'a manual journal' => ['Estate/Accounting/ManualJournal.vue'],
    'gemini: onboard a client' => ['Gemini/Clients/Create.vue'],
    'gemini: change a client\'s plan' => ['Gemini/Clients/Plan.vue'],
    'gemini: credit note on an invoice' => ['Gemini/Billing/Invoice.vue'],
]);

it('lays the add-a-vendor form out in two columns, one on a phone, with the TRN rule as a hint under its field', function (): void {
    $source = requiredFormsSource('Estate/Accounting/Vendors.vue');
    $template = requiredFormsTemplate($source);

    expect(str_contains($source, 'grid-template-columns: repeat(2, 1fr)'))->toBeTrue('two columns')
        ->and(str_contains($source, 'grid-template-columns: repeat(3'))->toBeFalse('no three-across row left')
        ->and(str_contains($source, 'vnd-field--wide'))->toBeFalse('no field left to sit alone in a row')
        ->and(preg_match('/<label\b[^>]*for="vnd-trn"[^>]*>(.*?)<\/label>/s', $template, $trn))->toBe(1)
        ->and(str_contains(requiredFormsLabelWords($trn[1]), ':'))->toBeFalse('the TRN label is a name, not an explanation')
        ->and(preg_match('/id="vnd-trn-hint"[^>]*>[^<]*nine digits/i', $template))->toBe(1, 'the TRN rule is a hint under the field')
        ->and(preg_match('/<input[^>]*id="vnd-trn"[^>]*aria-describedby="vnd-trn-hint"/s', $template))->toBe(1, 'the hint is read with the field');
});
