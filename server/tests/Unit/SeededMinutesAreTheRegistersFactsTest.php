<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Seeded minutes say what the register says and nothing addressed to a developer (M68 §2.6, GV-5)
|--------------------------------------------------------------------------
|
| The seeded minutes of a held meeting ended with a paragraph explaining to developers why no business was recorded
| ("No account of the business taken is recorded here. The approved designs state none…"), and residents read minutes.
| They end at the register's facts now, which is what the QA session's live correction leaves behind
| (docs/qa/gs-fix-seed-minutes.php on qa/sweep): a fresh estate and a corrected one read the same.
|
*/

$root = dirname(__DIR__, 2);

it('ends a held meeting\'s seeded minutes at the register\'s facts', function () use ($root): void {
    $php = (string) file_get_contents($root.'/database/seeders/Estate/GovernanceSeeder.php');

    expect($php)->not->toContain('No account of the business taken')
        ->and($php)->not->toContain('a seeder is entitled')
        ->and($php)->toContain("'A quorum of %d was required and was %s; the meeting proceeded.',");
});
