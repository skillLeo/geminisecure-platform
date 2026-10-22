<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A refusal of an open dialog is said inside it (Hassam, 12 Oct, about 04:50)
|--------------------------------------------------------------------------
|
| "A refusal of what an open dialog is doing goes INSIDE that dialog, in its amber band. The dialog stays open until the
| person fixes it. Never a toast while a dialog is open, never both, and never close the dialog and then toast it." At
| 390 px a toast behind an open modal is hidden or scrolled away. A toast stays for a press made with no dialog open
| (a work order's Acknowledge, a claim's Request ID document).
|
| Every dialog in the Residents lane whose refusal has no field of its own, read from its page: the dialog binds the
| refusal to its band (`:error`), its confirm handler neither closes it and toasts, nor closes it when it finishes, and
| it toasts nothing while it is open. The two imports toast only when their preview has gone, and its dialog with it.
|
*/

function dialogPageSource(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/'.$page);
}

/** The opening tag of the ConsoleDialog whose confirm calls `$confirm`, from `<ConsoleDialog` up to its `@confirm`. */
function dialogOpeningTag(string $source, string $confirm): string
{
    $at = strpos($source, '@confirm="'.$confirm);

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($source, 0, $at), '<ConsoleDialog');

    return $open === false ? '' : substr($source, $open, $at - $open);
}

/** `const $name = (…) => {` up to its closing brace at the start of a line. */
function dialogHandlerSource(string $source, string $name): string
{
    $start = strpos($source, 'const '.$name.' = ');

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n}", $start);

    return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
}

it('says a refusal inside the dialog it refused, which stays open, and never as a toast behind it', function (string $page, string $confirm, string $handler, bool $toastsWhenGone): void {
    $source = dialogPageSource($page);
    $tag = dialogOpeningTag($source, $confirm);
    $body = dialogHandlerSource($source, $handler);

    expect($tag)->not->toBe('', $page.': no dialog confirms with '.$confirm)
        ->and($body)->not->toBe('', $page.': no handler '.$handler)
        ->and(str_contains($tag, ':error='))->toBeTrue($page.': the dialog that confirms with '.$confirm.' has no band for its refusal');

    // Closed, then toasted: what the rule ends.
    expect(preg_match('/(= null|close\w*\(\))\s*\n\s*(toast|refused)\(/', $body))->toBe(0, $page.': '.$handler.' closes its dialog and then toasts');

    // An onFinish runs after a refusal too: one that empties the dialog's open state closes it on every refusal.
    if (preg_match('/:open="(\w+)/', $tag, $open) === 1 && preg_match('/onFinish: \(\) => \{(.*?)\n\s*\}/s', $body, $finish) === 1) {
        expect(str_contains($finish[1], $open[1].'.value = null'))->toBeFalse($page.': '.$handler.' closes its dialog when it finishes, refused or not');
    }

    $toasts = substr_count($body, 'toast(') + substr_count($body, 'refused(');

    if ($toastsWhenGone) {
        // Only when the preview has gone, and its dialog with it: then no dialog is open to hold the refusal.
        expect($toasts)->toBe(1, $page.': '.$handler.' toasts more than the one case with no dialog open')
            ->and(preg_match('/\}\s*else\s*\{\s*toast\(/', $body))->toBe(1, $page.': '.$handler.' toasts while its dialog is open');
    } else {
        expect($toasts)->toBe(0, $page.': '.$handler.' toasts a refusal while its dialog is open');
    }
})->with([
    'a household: send the invite' => ['Residents/Show.vue', 'postInvite', 'postInvite', false],
    'the register: resend the invite' => ['Residents/Index.vue', 'postResend', 'postResend', false],
    'the register: import, the file' => ['Residents/Index.vue', 'previewImport', 'previewImport', false],
    'the register: import, the preview' => ['Residents/Index.vue', 'commitImport', 'commitImport', true],
    'deleted accounts: restore an account' => ['Residents/DeletedAccounts.vue', 'confirmRestore', 'confirmRestore', false],
    'deleted accounts: restore a household' => ['Residents/DeletedAccounts.vue', 'confirmHouseholdRestore', 'confirmHouseholdRestore', false],
    'unit claims: approve' => ['Residents/Claims.vue', 'postApprove', 'postApprove', false],
    'estate structure: import, the file' => ['Structure/Index.vue', 'submitPreview', 'submitPreview', false],
    'estate structure: import, the preview' => ['Structure/Index.vue', 'commitImport', 'commitImport', true],
    'a work order: priority' => ['Facilities/Ticket.vue', 'setPriority', 'setPriority', false],
    'the maintenance queue: priority' => ['Facilities/Maintenance.vue', 'confirmPriority', 'setPriority', false],
    'amenity bookings: approve' => ['Facilities/Bookings.vue', 'postApprove', 'postApprove', false],
    'amenity bookings: charge the fee' => ['Facilities/Bookings.vue', 'postCharge', 'postCharge', false],
]);
