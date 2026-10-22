<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A meeting's paper is titled with the middot, and refused in one line (M53 Laws 2 and 3; step 6 of the walk)
|--------------------------------------------------------------------------
|
| The AGM's page lists its agenda as "Agenda — AGM 2026": the title each meeting paper is issued under. Papers issued
| from now on read "Agenda · AGM 2026"; those already issued keep their title, as an issued record does. Asking for
| minutes that were never recorded said "…yet. A blank page that looks like a record of a meeting is worse than no
| page."; it says the fact. Checked in the source: an issued paper can never be deleted, so a test that issued one would
| leave it in the shared estate for good.
|
*/

it('titles a meeting\'s paper with the middot, and refuses minutes not yet recorded in one line', function (): void {
    $php = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Estate/DocumentController.php');

    expect(str_contains($php, "title: ucfirst(\$kind).' · '.\$meeting->title,"))->toBeTrue('the paper is not titled with the middot')
        ->and(str_contains($php, "ucfirst(\$kind).' — '"))->toBeFalse('the paper is titled with an em dash')
        ->and(str_contains($php, 'worse than no page'))->toBeFalse('the refusal still explains itself');
});
