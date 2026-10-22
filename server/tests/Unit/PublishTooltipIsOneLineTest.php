<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Publish says what it does, in one line (M53 Law 2; step 6 of Sunday's walk)
|--------------------------------------------------------------------------
|
| On the meetings register and a meeting's page, hovering Publish read "Publish AGM 2026 to committee members. It cannot
| be taken back." Publish asks first now (DR-007, DR-056), and the question names who is told and that it cannot be
| undone, so the tooltip says what the control does: "Publish this meeting." (`COPY_REWRITES.md` §2).
|
*/

it('says "Publish this meeting." on the register and on a meeting\'s page', function (): void {
    $root = dirname(__DIR__, 2).'/resources/js/Pages/Estate/Governance/';

    foreach (['Meetings.vue', 'Meeting.vue'] as $page) {
        $vue = (string) file_get_contents($root.$page);

        expect($vue)->toContain("'Publish this meeting.'")
            ->and($vue)->not->toContain('It cannot be taken back.`');
    }
});
