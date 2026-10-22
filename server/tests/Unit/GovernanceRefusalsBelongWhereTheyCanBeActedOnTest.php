<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A refusal belongs where the person can act on it, and is never said twice (Hassam, 12 October 2026, D-235)
|--------------------------------------------------------------------------
|
| "INSIDE THE DIALOG, in the amber band: any refusal of the thing that dialog is doing. UNDER THE FIELD, in amber: a
| fault with one field. AS A TOAST, top right with a cross: anything that is NOT a refusal of an open dialog. NEVER BOTH
| AT ONCE." Because at 390 px a toast while a modal is open is often hidden behind it or scrolled out of view.
|
| This file was GovernanceRefusalsAreToastsTest (dd709727), which had the lane's dialogs toast their refusals and drop
| the band: the double was real, and it was fixed the wrong way round. Inverted to the rule: every dialog in the lane
| that stays open when its act is refused hands the refusal to the shared dialog's band (`error`) and raises no toast
| for it; each page holds its own refusal toasts while such a dialog is open, and says them as toasts otherwise. EVERY
| dialog stays open on a refusal, the confirmations with no field of their own included: never closed and then toasted
| (the hub's sweep counted "closes and then toasts" as breaking the rule; 12 Oct, after bfe8d593).
|
| Read from the files, as GovernanceSavesKeepWhatWasTypedTest reads them: the rule lives in the templates.
|
*/

function refusalRuleSource(string $file): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$file));
}

/** @return list<string> every `<ConsoleDialog …>` opening tag, whole, whatever its attribute values hold */
function refusalRuleDialogTags(string $source): array
{
    preg_match_all('/<ConsoleDialog(?:\s+[@:#\w.\-]+(?:="[^"]*")?)*\s*>/', $source, $found);

    return $found[0];
}

/** @return list<string> every `watch(…)` that runs at once (`{ immediate: true }`), from `watch(` to its close */
function refusalRuleWatches(string $source): array
{
    preg_match_all('/watch\((?:(?!watch\()[\s\S])*?\{ immediate: true \},\n\)/', $source, $found);

    return $found[0];
}

dataset('every dialog in the lane', [
    'a meeting\'s life: date, postpone, cancel, delete, archive, edit' => ['Components/MeetingLife.vue', 6, 6],
    'an election\'s life: extend, reopen nominations, reopen voting, cancel, delete, archive, challenge' => ['Components/ElectionLife.vue', 7, 7],
    'add a candidate' => ['Components/AddCandidate.vue', 1, 1],
    'refuse a nomination, or ask for more' => ['Pages/Estate/Governance/Nominations.vue', 1, 1],
    'certify and publish' => ['Pages/Estate/Governance/Results.vue', 1, 1],
    'the control room: all ten, its questions with no field of their own included' => ['Pages/Estate/Governance/ControlRoom.vue', 10, 10],
    'a meeting: Publish and Publish minutes' => ['Pages/Estate/Governance/Meeting.vue', 2, 2],
    'post a notice' => ['Pages/Estate/Governance/Notices.vue', 1, 1],
]);

it('never closes a dialog on a refusal, to say it afterwards as a toast', function (string $file) {
    // An onError that shuts its dialog: setting the ref that opens it to false or null.
    expect(preg_match('/onError:\s*\([^)]*\)\s*=>\s*\{[^}]*\.value\s*=\s*(false|null)\b/', refusalRuleSource($file)))
        ->toBe(0, "{$file} closes a dialog on a refusal");
})->with([
    'Components/MeetingLife.vue',
    'Components/ElectionLife.vue',
    'Components/AddCandidate.vue',
    'Pages/Estate/Governance/Meeting.vue',
    'Pages/Estate/Governance/Notices.vue',
    'Pages/Estate/Governance/ControlRoom.vue',
    'Pages/Estate/Governance/Nominations.vue',
    'Pages/Estate/Governance/Results.vue',
]);

it('hands a refusal to the dialog\'s amber band wherever the dialog stays open', function (string $file, int $dialogs, int $banded) {
    $tags = refusalRuleDialogTags(refusalRuleSource($file));

    expect(count($tags))->toBe($dialogs, "{$file}: a dialog came or went, so say where its refusal goes")
        ->and(count(array_filter($tags, static fn (string $tag): bool => str_contains($tag, ':error='))))
        ->toBe($banded, "{$file}: a dialog that stays open on a refusal does not show it in its band");
})->with('every dialog in the lane');

it('raises no toast for a refusal the dialog itself shows', function (string $file) {
    $source = refusalRuleSource($file);

    expect(str_contains($source, 'toasts.js'))->toBeFalse("{$file} still reaches the toast")
        ->and(str_contains($source, 'toast('))->toBeFalse("{$file} still toasts a refusal");
})->with([
    'Components/MeetingLife.vue',
    'Components/ElectionLife.vue',
    'Components/AddCandidate.vue',
]);

it('raises no toast for a decision its own dialog refuses', function () {
    $source = refusalRuleSource('Pages/Estate/Governance/Nominations.vue');

    expect(preg_match('/const submitDecision = .*?\n}\n/s', $source, $found))->toBe(1)
        ->and(str_contains($found[0], 'toast('))->toBeFalse('the refused decision is toasted as well as banded');
});

it('holds the page\'s refusal toast while a dialog that shows it is open, and toasts it otherwise', function (string $file) {
    $saying = array_values(array_filter(
        refusalRuleWatches(refusalRuleSource($file)),
        static fn (string $watch): bool => str_contains($watch, 'page.props.errors') && str_contains($watch, 'toast('),
    ));

    expect(count($saying))->toBe(1, "{$file}: the page no longer says what it is handed, or says it twice")
        ->and(preg_match('/=> \{\n\s+if \(refusalInADialog\.value\) \{\n\s+return\n\s+\}\n/', $saying[0]))
        ->toBe(1, "{$file}: the toast is not held while a dialog shows the refusal");
})->with([
    'Pages/Estate/Governance/Meeting.vue',
    'Pages/Estate/Governance/ControlRoom.vue',
    'Pages/Estate/Governance/Nominations.vue',
    'Pages/Estate/Governance/Results.vue',
    'Pages/Estate/Governance/Notices.vue',
]);

it('tells the page while one of a component\'s dialogs is open', function (string $component, string $page, string $tag) {
    $source = refusalRuleSource($component);
    $pageSource = refusalRuleSource($page);

    expect(preg_match("/watch\\(open, \\(dialog\\) => emit\\('dialog', dialog !== null\\), \\{ immediate: true \\}\\)/", $source))
        ->toBe(1, "{$component} does not say when its dialogs open and close")
        ->and(str_contains($source, "onBeforeUnmount(() => emit('dialog', false))"))
        ->toBeTrue("{$component} leaves the page holding its toasts once it is gone")
        ->and(preg_match('/<'.$tag.'\b(?:\s+[@:#\w.\-]+(?:="[^"]*")?)*\s+@dialog="(\w+) = \$event"/', $pageSource, $heard))
        ->toBe(1, "{$page} does not listen");

    // What it hears holds the page's toast: it is the flag itself, or one of the dialogs the flag counts.
    expect($heard[1] === 'refusalInADialog'
        || preg_match('/const refusalInADialog = computed\(\(\) =>(?:(?!\n\)\n)[\s\S])*?\b'.$heard[1].'\.value/', $pageSource) === 1)
        ->toBeTrue("{$page}: what {$tag} says does not hold the toast");
})->with([
    'a meeting\'s life, on the meeting\'s page' => ['Components/MeetingLife.vue', 'Pages/Estate/Governance/Meeting.vue', 'MeetingLife'],
    'an election\'s life, in the control room' => ['Components/ElectionLife.vue', 'Pages/Estate/Governance/ControlRoom.vue', 'ElectionLife'],
]);

it('keeps a field\'s own fault under that field, in amber', function () {
    expect(str_contains(refusalRuleSource('Components/MeetingLife.vue'), '<p v-if="attendanceForm.errors.lot || attendanceForm.errors.state" class="mt-refusal">'))
        ->toBeTrue('the register\'s lot and attendance faults are not under the form')
        ->and(str_contains(refusalRuleSource('Pages/Estate/Governance/ControlRoom.vue'), '<div v-if="motionForm.errors.closes_at" class="act-error">'))
        ->toBeTrue('a motion\'s closing time fault is not under its field');
});
