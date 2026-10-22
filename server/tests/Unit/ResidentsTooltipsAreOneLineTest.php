<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The residents pages' tooltips say what a thing is, in one line (M53 Laws 2 and 3; step 3 of Sunday's walk)
|--------------------------------------------------------------------------
|
| A sample household's badge, down every sample row of the register (sixteen times) and on the household and new
| resident pages, read "Made by the demonstration seed, not a person the estate added"; `COPY_REWRITES.md` §2: "Sample
| data." A claim's Approve read "Bind this claimant to the household. Whose guest passes they may issue and whose gate
| they may be admitted at both follow from this — it cannot be undone by an edit afterwards."; it asks first now (DR-072),
| so it says "Approve this claim." The register's "View household" stays: the link is an icon, and its one line is its name.
|
*/

it('calls a sample household "Sample data." on every residents page, and a claim\'s Approve "Approve this claim."', function (): void {
    $root = dirname(__DIR__, 2).'/resources/js/Pages/Estate/Residents/';

    /*
     * The Add resident form is one component since the register's modal (Hassam, 11 Oct): New.vue and the register both
     * draw Components/AddResidentFields.vue, so the form's sample badge is read there (this test read New.vue, and was red
     * from that change until the test campaign, 12 Oct).
     */
    $form = dirname(__DIR__, 2).'/resources/js/Components/AddResidentFields.vue';
    expect(str_contains((string) file_get_contents($root.'New.vue'), '<AddResidentFields'))->toBeTrue('the Add resident page draws the shared form');

    foreach ([$root.'Index.vue', $form, $root.'Show.vue'] as $file) {
        $vue = (string) file_get_contents($file);

        expect(str_contains($vue, 'Made by the demonstration seed'))->toBeFalse(basename($file).' says the long sample words')
            ->and(str_contains($vue, 'title="Sample data."'))->toBeTrue(basename($file).' has no "Sample data." badge');
    }

    $claims = (string) file_get_contents($root.'Claims.vue');

    expect($claims)->toContain("'Approve this claim.'")
        ->and($claims)->not->toContain('Bind this claimant to the household');
});
