<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A household's open items are named without an em dash (M53 Law 3; step 3 of Sunday's walk)
|--------------------------------------------------------------------------
|
| The household page's linked activity read "90+ days arrears — $12,400.00", "Ticket #1063 — Gate light out" and
| "Gazebo booked — Oct 18". The m56 session fixed the same titles on the dashboard with the console's separator, the
| middot ("Gazebo booked · Sun, Sep 13", `DashboardReportsAndSeededAccountsSayItWithoutEmDashesTest`); the household
| page, the QA session's, says them the same way now.
|
*/

it('names a household\'s arrears, open ticket and booking with the middot, never an em dash', function () {
    $php = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Estate/Residents.php');
    $start = strpos($php, 'private function linkedActivity(');
    expect($start)->not->toBeFalse();

    $body = substr($php, (int) $start, (int) strpos($php, "\n    }\n", (int) $start) - (int) $start);

    expect($body)->not->toContain('—')
        ->and($body)->toContain("' arrears · '")
        ->and($body)->toContain("' · '.\$ticket->title")
        ->and($body)->toContain("' booked · '");
});
