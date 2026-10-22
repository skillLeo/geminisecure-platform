<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A refusal of an open dialog is said inside it: the Accounting lane's dialogs (Hassam, 12 Oct, first hand)
|--------------------------------------------------------------------------
|
| "A refusal of what a dialog is doing stays INSIDE the dialog, in the amber band, with what was typed still there.
| The dialog does not close. A toast is only for things that happen outside a dialog, or after it closes
| successfully." At 390 px a toast behind an open modal is hidden or scrolled away.
|
| Every ConsoleDialog on the Accounting pages, read from its page. Its confirm handler never closes the dialog on a
| refusal, neither in onError nor in onFinish (which runs after a refusal too), and toasts nothing while the dialog is
| open. A dialog whose refusal can name no field of its own (archiving an account, posting a journal, signing off a
| reconciliation, a bill's record, void and pay) binds the refusal to its band (`:error`), and a band held in a ref is
| filled by the handler's onError. The vendor dialogs need no band: every refusal their server sends names a field the
| dialog draws under it (a vendor's name or TRN, a bill's work order number).
|
| Row presses with no dialog open (matching a bank line, saving a journal's draft, a bill's View receipt) still toast.
|
*/

function accountingDialogPage(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Accounting/'.$page);
}

/** The opening tag of the ConsoleDialog whose confirm is `$confirm`, from `<ConsoleDialog` up to its `@confirm`. */
function accountingDialogTag(string $source, string $confirm): string
{
    $at = strpos($source, '@confirm="'.$confirm.'"');

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($source, 0, $at), '<ConsoleDialog');

    return $open === false ? '' : substr($source, $open, $at - $open);
}

/** `const $name = (…) => {` up to its closing brace at the start of a line. */
function accountingDialogHandler(string $source, string $name): string
{
    $start = strpos($source, 'const '.$name.' = ');

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n}", $start);

    return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
}

/** The body of one of the visit's callbacks (`onError`, `onFinish`), or '' when the handler has none. */
function accountingDialogCallback(string $handler, string $callback): string
{
    return preg_match('/'.$callback.':\s*\(?[\w\s,]*\)?\s*=>\s*\{(.*?)\n\s*\},?\n/s', $handler, $body) === 1 ? $body[1] : '';
}

it('keeps a refusal inside the dialog it refused, which stays open, and never toasts it behind the dialog', function (string $page, string $confirm, string $handler, bool $band): void {
    $source = accountingDialogPage($page);
    $tag = accountingDialogTag($source, $confirm);
    $body = accountingDialogHandler($source, $handler);

    expect($tag)->not->toBe('', $page.': no dialog confirms with '.$confirm)
        ->and($body)->not->toBe('', $page.': no handler '.$handler)
        ->and(preg_match('/:open="(\w+)/', $tag, $open))->toBe(1, $page.': the dialog that confirms with '.$confirm.' has no :open');

    $openState = $open[1];

    // Closed, then toasted: what the rule ends.
    expect(preg_match('/(= null|= false|close\w*\(\))\s*\n\s*(toast|refused)\(/', $body))->toBe(0, $page.': '.$handler.' closes its dialog and then toasts');

    // Closed by a refusal at all, in onError or in an onFinish (which runs after a refusal too).
    foreach (['onError', 'onFinish'] as $callback) {
        $said = accountingDialogCallback($body, $callback);
        $closes = str_contains($said, $openState.'.value = null') || str_contains($said, $openState.'.value = false');

        expect($closes)->toBeFalse($page.': '.$handler.' closes its dialog in '.$callback);
    }

    // Nothing toasted from the dialog's own save: the dialog is open while it runs.
    expect(substr_count($body, 'toast(') + substr_count($body, 'refused('))->toBe(0, $page.': '.$handler.' toasts a refusal while its dialog is open');

    if ($band) {
        expect(preg_match('/:error="([^"]+)"/', $tag, $error))->toBe(1, $page.': the dialog that confirms with '.$confirm.' has no band for its refusal');

        // A band held in a ref of the page's own is filled by the refusal; a form's errors fill themselves.
        if (preg_match('/^\w+$/', $error[1]) === 1) {
            expect(str_contains(accountingDialogCallback($body, 'onError'), $error[1].'.value ='))->toBeTrue($page.': '.$handler.' never puts the refusal in the band '.$error[1]);
        }
    }
})->with([
    'chart of accounts: archive an account' => ['ChartOfAccounts.vue', 'confirmArchive', 'confirmArchive', true],
    'chart of accounts: add an account' => ['ChartOfAccounts.vue', 'submitAccount', 'submitAccount', true],
    'a manual journal: post it' => ['ManualJournal.vue', 'post', 'post', true],
    'the bank reconciliation: sign it off' => ['Reconciliation.vue', 'complete', 'complete', true],
    'bills: record a bill' => ['Bills.vue', 'submitRecord', 'submitRecord', true],
    'bills: void a bill' => ['Bills.vue', 'voidRow && submitVoid(voidRow)', 'submitVoid', true],
    'bills: pay a bill' => ['Bills.vue', 'openRow && submitPayment(openRow)', 'submitPayment', true],
    'a vendor: edit its details' => ['Vendor.vue', 'submitEdit', 'submitEdit', false],
    'a vendor: record a bill' => ['Vendor.vue', 'submitRecord', 'submitRecord', false],
    'vendors: add a vendor' => ['Vendors.vue', 'submitVendor', 'submitVendor', false],
]);

it('reads every dialog on the Accounting pages, so a new one cannot slip past', function (): void {
    $confirms = [];

    foreach (glob(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Accounting/*.vue') ?: [] as $file) {
        preg_match_all('/@confirm="([^"]+)"/', (string) file_get_contents($file), $found);

        foreach ($found[1] as $confirm) {
            $confirms[] = basename($file).' '.$confirm;
        }
    }

    sort($confirms);

    expect($confirms)->toBe([
        'Bills.vue openRow && submitPayment(openRow)',
        'Bills.vue submitRecord',
        'Bills.vue voidRow && submitVoid(voidRow)',
        'ChartOfAccounts.vue confirmArchive',
        'ChartOfAccounts.vue submitAccount',
        'ManualJournal.vue post',
        'Reconciliation.vue complete',
        'Vendor.vue submitEdit',
        'Vendor.vue submitRecord',
        'Vendors.vue submitVendor',
    ]);
});
