<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Gemini console's messages, sorted into three kinds (Hassam, 12 October)
|--------------------------------------------------------------------------
|
| "A message about something that JUST HAPPENED becomes a toast, top right, with a cross. 'Invoice resent.' 'Could not
| reach the gateway.' A STANDING FACT about the page or the estate stays a banner. 'Text messages are not available on
| this estate.' A refusal of an OPEN DIALOG goes in the dialog's amber band and never becomes a toast at all."
|
| Read from the pages: the Gemini shell says the server's flash as toasts, so no Gemini page draws it as a banner of its
| own; the two whose message carries something to act on keep it up until it is closed; the standing facts are still
| banners; and every question that posts keeps its refusal inside it and stays open, with a list of them all so a new one
| cannot slip past.
|
*/

function geminiJs(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$path);
}

/** What a single-file component draws: its `<template>`, not its script. */
function geminiTemplateOf(string $source): string
{
    $start = strpos($source, "\n<template>");
    $end = strrpos($source, "\n</template>");

    return $start === false || $end === false ? '' : substr($source, $start, $end - $start);
}

/** The opening tag of the ConsoleDialog whose confirm is `$confirm`, from `<ConsoleDialog` up to its `@confirm`. */
function geminiDialogTag(string $source, string $confirm): string
{
    $at = strpos($source, '@confirm="'.$confirm.'"');

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($source, 0, $at), '<ConsoleDialog');

    return $open === false ? '' : substr($source, $open, $at - $open);
}

/** `const $name = (…) => {` up to its closing brace at the start of a line. */
function geminiDialogHandler(string $source, string $name): string
{
    $start = strpos($source, 'const '.$name.' = ');

    if ($start === false) {
        return '';
    }

    $end = strpos($source, "\n}", $start);

    return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
}

/** One of the visit's callbacks (`onError`, `onFinish`), one line or a block, up to the next callback or the end. */
function geminiDialogCallback(string $handler, string $callback): string
{
    $at = strpos($handler, $callback.':');

    if ($at === false) {
        return '';
    }

    $rest = substr($handler, $at + strlen($callback) + 1);

    return preg_match('/\bon[A-Z]\w*:/', $rest, $next, PREG_OFFSET_CAPTURE) === 1 ? substr($rest, 0, $next[0][1]) : $rest;
}

it('says the server\'s message after a press as a toast, from the Gemini shell, for every page', function (): void {
    $shell = geminiJs('Layouts/GeminiConsole.vue');

    expect(str_contains($shell, "import { toast } from '../lib/toasts.js'"))->toBeTrue('the shell does not import the toasts')
        ->and(preg_match('/watch\(\s*\(\) => page\.props\.flash,/', $shell))->toBe(1, 'the shell does not watch the flash')
        ->and(str_contains($shell, "toast(flash.success, { tone: 'success' })"))->toBeTrue('a success is not toasted')
        ->and(str_contains($shell, "toast(flash.error, { tone: 'error' })"))->toBeTrue('an error is not toasted')
        ->and(str_contains($shell, "toast(flash.status, { tone: 'info' })"))->toBeTrue('a status is not toasted');
});

it('draws the server\'s message on no Gemini page as a banner of its own', function (): void {
    $drawn = [];
    $root = dirname(__DIR__, 2).'/resources/js/Pages/Gemini';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.vue') && preg_match('/flash\??\.(success|error|status)/', geminiTemplateOf((string) file_get_contents((string) $file))) === 1) {
            $drawn[] = str_replace([$root.DIRECTORY_SEPARATOR, '\\'], ['', '/'], (string) $file);
        }
    }

    expect($drawn)->toBe([]);
});

it('keeps the message up until it is closed where it carries something to act on', function (string $page): void {
    expect(str_contains(geminiJs('Pages/Gemini/'.$page), "toast(flash.success, { tone: 'success', sticky: true })"))->toBeTrue($page.': the message closes by itself');
})->with([
    'a dispatch step: "confirm by radio that they have it"' => ['Dispatch/Alert.vue'],
    'a guard added: the employee number the server allocated' => ['Guards/Create.vue'],
]);

it('keeps a standing fact about the page or the estate as a banner that stays', function (string $page, string $banner): void {
    expect(str_contains(geminiTemplateOf(geminiJs('Pages/Gemini/'.$page)), $banner))->toBeTrue($page.': the standing fact '.$banner.' is gone');
})->with([
    'no text messages are being sent' => ['Settings/Sms.vue', 'class="sm-warning"'],
    'the package template, not one client' => ['Settings/Packages.vue', 'class="warn-banner"'],
    'an invoice breakdown that does not reconcile' => ['Billing/Invoice.vue', 'v-if="invoice.reconciliation" class="warn-banner"'],
    'a compliance case on an officer' => ['Guards/ComplianceAction.vue', '{{ action.case.headline }}'],
    'a matrix this viewer may only read' => ['Settings/RoleMatrix.vue', 'class="rm-bar"'],
    'an alert that is a drill' => ['Dispatch/Alert.vue', 'class="drill-note"'],
    'location sharing switched off' => ['Dispatch/Map.vue', 'geo.tracking === false'],
]);

it('keeps a refusal inside the question it refused, which stays open, and never toasts it behind the question', function (string $page, string $confirm, string $handler, bool $band, ?string $state = null): void {
    $source = geminiJs('Pages/Gemini/'.$page);
    $tag = geminiDialogTag($source, $confirm);
    $body = geminiDialogHandler($source, $handler);

    expect($tag)->not->toBe('', $page.': no dialog confirms with '.$confirm)
        ->and($body)->not->toBe('', $page.': no handler '.$handler)
        ->and(preg_match('/:open="(\w+)/', $tag, $open))->toBe(1, $page.': the dialog that confirms with '.$confirm.' has no :open');

    // What the handler sets to close it: the :open's own name, or the state a computed :open is read from.
    $openState = $state ?? $open[1];

    expect(preg_match('/(= null|= false|close\w*\(\))\s*\n\s*(toast|refused)\(/', $body))->toBe(0, $page.': '.$handler.' closes its question and then toasts');

    // Closed by a refusal at all: in onError, or in an onFinish (which runs after a refusal too).
    foreach (['onError', 'onFinish'] as $callback) {
        $said = geminiDialogCallback($body, $callback);
        $closes = preg_match('/\b'.$openState.'(\.value)?\s*=\s*(null|false)\b/', $said) === 1;

        expect($closes)->toBeFalse($page.': '.$handler.' closes its question in '.$callback);
    }

    expect(substr_count($body, 'toast(') + substr_count($body, 'refused('))->toBe(0, $page.': '.$handler.' toasts a refusal while its question is open');

    if ($band) {
        expect(preg_match('/:error="([^"]+)"/', $tag, $error))->toBe(1, $page.': the question that confirms with '.$confirm.' has no band for its refusal');

        // A band held in a ref of the page's own is filled by the refusal (from onError, or from a refused outcome the
        // server answers with); a form's errors fill themselves.
        if (preg_match('/^(\w+)(\s*\|\|\s*null)?$/', $error[1], $ref) === 1) {
            expect(preg_match('/\b'.$ref[1].'\.value\s*=\s*(?!null\b|\'\')/', $body))->toBe(1, $page.': '.$handler.' never puts the refusal in the band '.$ref[1]);
        }
    }
})->with([
    'an invoice: resend it' => ['Billing/Invoice.vue', 'postResend', 'postResend', true],
    'an invoice: issue a credit note' => ['Billing/Invoice.vue', 'submitCredit', 'submitCredit', true],
    'an invoice preview: raise it' => ['Billing/Preview.vue', 'raise', 'raise', true],
    'a client: send the contact\'s invitation' => ['Clients/Show.vue', 'postInvitation', 'postInvitation', true],
    // Refused only on its note, which the question draws under the note.
    'an alert: resolve and classify' => ['Dispatch/Alert.vue', 'submitResolve', 'submitResolve', false],
    // Refused only on its note, which the question draws under the note.
    'a guard: refuse a handset' => ['Guards/Show.vue', 'confirmRefuse', 'confirmRefuse', false],
    'an incident: close it' => ['Operations/Incident.vue', 'resolve', 'resolve', true],
    'the incident log: log one' => ['Operations/Incidents.vue', 'submit', 'submit', true],
    'the roster: answer a claim' => ['Operations/Roster.vue', 'answerClaim', 'answerClaim', true, 'claimAsk'],
    'the schedule: answer a claim' => ['Operations/Schedule.vue', 'answerClaim', 'answerClaim', true, 'claimAsk'],
    'the schedule: decide leave' => ['Operations/Schedule.vue', 'confirmLeave', 'confirmLeave', true],
    'the schedule: copy last week' => ['Operations/Schedule.vue', 'confirmCopy', 'confirmCopy', true],
    'statutory filings: start one' => ['Payroll/Filings.vue', 'prepare', 'prepare', true],
    'a guard pay run: approve it' => ['Payroll/Show.vue', 'postApprove', 'postApprove', true],
    'platform admins: resend an invitation' => ['Settings/Admins.vue', 'postResend', 'postResend', true],
]);

it('reads every question on the Gemini console\'s pages, so a new one cannot slip past', function (): void {
    $confirms = [];
    $root = dirname(__DIR__, 2).'/resources/js/Pages/Gemini';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with((string) $file, '.vue')) {
            continue;
        }

        preg_match_all('/@confirm="([^"]+)"/', (string) file_get_contents((string) $file), $found);

        foreach ($found[1] as $confirm) {
            $confirms[] = str_replace([$root.DIRECTORY_SEPARATOR, '\\'], ['', '/'], (string) $file).' '.$confirm;
        }
    }

    sort($confirms);

    expect($confirms)->toBe([
        'Billing/Invoice.vue postResend',
        'Billing/Invoice.vue submitCredit',
        'Billing/Preview.vue raise',
        'Clients/Show.vue postInvitation',
        'Dispatch/Alert.vue submitResolve',
        'Guards/Show.vue confirmRefuse',
        'Operations/Incident.vue resolve',
        'Operations/Incidents.vue submit',
        'Operations/Roster.vue answerClaim',
        'Operations/Schedule.vue answerClaim',
        'Operations/Schedule.vue confirmCopy',
        'Operations/Schedule.vue confirmLeave',
        'Payroll/Filings.vue prepare',
        'Payroll/Show.vue postApprove',
        'Settings/Admins.vue postResend',
    ]);
});
