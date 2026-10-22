<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The meeting form's quorum fits at 125% zoom (M71 E2E-M4; step 6 of Sunday's walk)
|--------------------------------------------------------------------------
|
| At 125% a laptop's 1440 or 1280 pixels are 1152 or 1024 to the page, and the half of the form the quorum shares with
| Recording is too narrow for its words ("25% of 443 eligible households"): they ran past the box's edge (the m56
| session's 125% check, 10 Oct). Below 1200 pixels of page the two fields stack, each the form's full width. A 1280
| laptop at 100% cut them too (the dev server, 12 Oct), so the quorum's own row stacks below 1400.
|
| A static check of the rule; the layout check run after the deploy (`--layout --widths=1440,1280`) is the browser's.
|
*/

it('stacks the quorum and recording fields when the page is narrower than 1200 pixels', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Governance/MeetingScheduler.vue');

    $at = strpos($page, '@media (max-width: 1200px)');
    expect($at)->not->toBeFalse('no narrow-page rule on the meeting form');

    $block = substr($page, (int) $at, 200);
    expect($block)->toContain('.m-two-col')->toContain('flex-direction: column');
});

it('stacks the quorum\'s own row below 1400 pixels, where a 1280 laptop cut its words', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Governance/MeetingScheduler.vue');

    $at = strpos($page, '@media (max-width: 1400px)');
    expect($at)->not->toBeFalse('no rule for the quorum row on a 1280 laptop');

    expect(substr($page, (int) $at, 160))->toContain('.m-two-col.quorum-row')->toContain('flex-direction: column')
        ->and(str_contains($page, '<div class="m-two-col quorum-row">'))->toBeTrue();
});
