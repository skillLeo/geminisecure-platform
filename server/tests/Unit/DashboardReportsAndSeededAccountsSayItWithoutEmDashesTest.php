<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| No em dash in what the dashboard, a report or a seeded account says (Law 3; Hassam, 10 October)
|--------------------------------------------------------------------------
|
| The dashboard's recent activity read "Gazebo booked — Sun, Sep 13", and five more titles were built the same way;
| its position line read "As at 9:00 AM — recomputed whenever…"; a report with nothing in its period answered "That is
| an answer rather than a fault — widen the period…"; and the seeded chart of accounts named 5050 "Security Services —
| Gemini Security Ltd" and 5200 "Utilities — Common Areas". The console's separator is the middot, "Gazebo booked · Sun,
| Sep 13"; a sentence gets a full stop or a colon; an account's second half goes in brackets.
|
*/

$root = dirname(__DIR__, 2);

it('titles every line of the recent activity without an em dash', function () use ($root): void {
    $php = (string) file_get_contents($root.'/app/Services/Estate/ActivityLog.php');

    expect(preg_match_all("/'title' => [^\n]*—/u", $php))->toBe(0)
        ->and($php)->toContain("' booked · '")
        ->and($php)->toContain("'Payment received · '")
        ->and($php)->toContain("'Message from Gemini Security: '");
});

it('says when the dashboard\'s figures were worked out without an em dash', function () use ($root): void {
    $php = (string) file_get_contents($root.'/app/Http/Controllers/Estate/DashboardController.php');

    // M78 §2: when, and nothing about how it is kept (it went on "Recomputed whenever a charge or a payment is posted…").
    expect($php)->not->toContain("' — recomputed whenever")
        ->and($php)->not->toContain('ecomputed whenever')
        ->and($php)->toContain("'As at '.\$this->at(\$position['computed_at']).'.'")
        ->and($php)->toContain("'Estate totals as at '.\$this->at(\$structure['computed_at']).'.'");
});

it('answers a report with nothing in its period without an em dash', function () use ($root): void {
    $vue = (string) file_get_contents($root.'/resources/js/Pages/Estate/Reports/Show.vue');

    // M78 §2: one plain line (it read "…That is an answer rather than a fault: widen the period, or read it as…").
    expect($vue)->toContain('No rows between ${from} and ${to}. Try a wider period.')
        ->and(preg_match_all('/(?:title|body)="[^"]*—/u', $vue))->toBe(0);
});

it('names the whole estate on the meeting form without an em dash (the QA session\'s note, step 6)', function () use ($root): void {
    $php = (string) file_get_contents($root.'/app/Services/Estate/Governance.php');

    expect($php)->not->toContain("'Whole estate — all '")
        ->and($php)->toContain("'audience_label' => 'Whole estate · all '.\$phases->count().' phases',");
});

it('seeds no account whose name has an em dash', function () use ($root): void {
    $php = (string) file_get_contents($root.'/database/seeders/ChartOfAccountsSeeder.php');

    expect(preg_match_all("/\\['\\d{4}', '[^']*—/u", $php))->toBe(0)
        ->and($php)->toContain("['5050', 'Security Services (Gemini Security Ltd)',")
        ->and($php)->toContain("['5200', 'Utilities (Common Areas)',");
});
