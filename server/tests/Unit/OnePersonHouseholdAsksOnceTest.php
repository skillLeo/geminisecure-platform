<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A household with one app account is asked about it once, on that person's row (12 Oct)
|--------------------------------------------------------------------------
|
| The hub's finding: on a household where one person has a Resident App account, the household's End app access opened
| the very question that person's End access asks on their own row: two buttons for one act. The household's button is
| for choosing among several (its modal draws the people to choose from only then), greyed with its reason when nobody
| has an account. Read as text: a unit test boots no application, and the JS test runner is banned on this PC
| (estate-household-pass.test.mjs is edited to match, not run).
|
*/

it('draws the household\'s End app access only to choose among several, or greyed for none, never beside one person\'s own', function (): void {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Residents/Show.vue'));

    // The household's button: the one whose words are "End app access" and which opens the chooser.
    $at = strpos($page, '@click="openAccess"');
    expect($at)->not->toBeFalse();
    $button = substr($page, (int) strrpos(substr($page, 0, (int) $at), '<button'), (int) $at - (int) strrpos(substr($page, 0, (int) $at), '<button'));

    expect(str_contains($button, 'v-if="canEndAppAccess && appPeople.length !== 1"'))->toBeTrue('drawn beside one person\'s own End access')
        ->and(str_contains($button, ':disabled="appPeople.length === 0"'))->toBeTrue('greyed for none, with its reason');

    // The person's own row still carries the act, and the chooser is drawn only for several.
    expect(str_contains($page, "{{ person.app_access.can_end ? 'End access' : 'Give access back' }}"))->toBeTrue()
        ->and(str_contains($page, '<div v-if="accessChooser && appPeople.length > 1" class="res-people">'))->toBeTrue();
});
