<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The meeting form — M12 §4.2
|--------------------------------------------------------------------------
|
| "Make the online options and the joining-link field visible without hunting, fix the preview that wrongly says
| 'there's no shareable link' for online meetings, add a name box for 'Other online', and match Omar's design field
| for field." The differences from board community-admin-12 are listed in D-187.
|
*/

function meetingForm(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Governance/MeetingScheduler.vue');
}

it('shows every way a meeting can be held at once — the board\'s segmented control, not a drop-down', function () {
    $form = meetingForm();

    expect($form)->toContain('class="seg seg-held" role="radiogroup" aria-labelledby="held-as-label"')
        ->and($form)->not->toContain('<select id="held-as"');

    foreach (["'In person'", "'Zoom'", "'Google Meet'", "'Microsoft Teams'", "'Other online'"] as $label) {
        expect($form)->toContain($label);
    }
});

it('opens the joining link under it for any online choice, and a name box for Other online', function () {
    $form = meetingForm();

    expect($form)->toContain('<div v-if="isOnline" class="m-field">')
        // The link is required, the platform's name is not (batch 1 item 3: marked, and "(optional)").
        ->and($form)->toContain('<label for="virtual-link">Joining link <RequiredMark /></label>')
        ->and($form)->toContain("<div v-if=\"form.held_as === 'other'\" class=\"m-field\">")
        ->and($form)->toContain('<label for="platform-name">Platform name (optional)</label>');
});

it('never tells an online meeting there is no shareable link', function () {
    $form = meetingForm();

    // Only the code comment that records the old sentence may still quote it.
    expect(substr_count($form, "there's no shareable link"))->toBe(0)
        ->and($form)->toContain('are given the joining link, in the Resident App and on');
});
