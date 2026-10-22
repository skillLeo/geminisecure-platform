<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A refused save keeps what was typed (11 Oct 2026)
|--------------------------------------------------------------------------
|
| Hassam: "A refused save inside a modal closes the modal and loses everything typed" unless the page keeps its state.
| In @inertiajs 3.7.0 every post, put, patch and delete keeps it by default (`router.post` passes preserveState: true,
| and `useForm` posts through it), so a save with no option keeps the dialog open and the words in it. Two options
| would lose them: `false`, always, and `'errors'`, which keeps state only when the refusal carries a field error, so a
| refusal sent back as a flash would close the dialog and empty it. No Governance page or dialog sets either.
|
*/

dataset('governance files', [
    'Components/MeetingLife.vue',
    'Components/ElectionLife.vue',
    'Components/AddCandidate.vue',
    'Pages/Estate/Governance/ControlRoom.vue',
    'Pages/Estate/Governance/Meeting.vue',
    'Pages/Estate/Governance/MeetingScheduler.vue',
    'Pages/Estate/Governance/Meetings.vue',
    'Pages/Estate/Governance/Nomination.vue',
    'Pages/Estate/Governance/Nominations.vue',
    'Pages/Estate/Governance/Notices.vue',
    'Pages/Estate/Governance/Results.vue',
]);

it('lets every save keep what was typed when it is refused', function (string $file): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$file);

    expect(preg_match('/preserveState\s*:\s*(false|[\'"]errors[\'"])/', $source))->toBe(0);
})->with('governance files');
