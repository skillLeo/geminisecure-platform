<?php

declare(strict_types=1);

use App\Models\Estate\UnitCollectionFlag;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| No em dash in a list to pick from: the QA session's half (M62 B4, DR-026; step 5's account list)
|--------------------------------------------------------------------------
|
| The accounting half calls `Account::optionLabel()` ("1200 · Accounts Receivable", `AccountingListsUseTheMiddotTest`).
| The rest was this session's (Hassam, 10 Oct): New charge's account list (step 5 of Sunday's walk) and the charge
| schedule's still built "1200 — Accounts Receivable"; a unit's two collection flags, the users page's role, status
| and administrator lists, and a role's heading on the roles page carried an em dash; and the charge schedule wrote one
| into what a household reads on its ledger ("Monthly dues — October 2026") and into a reversal's line. The middot
| now, or a colon where a sentence goes on.
|
*/

uses(TestCase::class);

$root = dirname(__DIR__, 2);

it('has New charge and the charge schedule offer accounts through the one helper', function () use ($root): void {
    $dues = (string) file_get_contents($root.'/app/Http/Controllers/Estate/DuesController.php');
    $schedules = (string) file_get_contents($root.'/app/Services/Estate/ChargeSchedules.php');

    expect($dues)->toContain('$a->optionLabel()')->not->toContain("->code.' — '")
        ->and($schedules)->toContain('$account->optionLabel()')->not->toContain("->code.' — '");
});

it('writes no em dash into a charge, a reversal or the schedule\'s own lines', function () use ($root): void {
    $schedules = (string) file_get_contents($root.'/app/Services/Estate/ChargeSchedules.php');

    // Code lines only (a comment's apostrophes read as quotes), and the lone "—" that stands for an empty value waits on
    // Law 5's decision (M53 Law 3), so it alone is let through.
    $offending = collect(explode("\n", $schedules))
        ->reject(static fn (string $line): bool => (bool) preg_match('#^\s*(\*|//|/\*)#', $line))
        ->filter(static fn (string $line): bool => (bool) preg_match("/'(?!—')[^'\n]*—[^'\n]*'/u", $line))
        ->values()
        ->all();

    expect($offending)->toBe([], 'a quoted string in ChargeSchedules carries an em dash');
});

it('names a unit\'s two collection flags without an em dash', function (): void {
    foreach (UnitCollectionFlag::KINDS as $label) {
        expect($label)->not->toContain('—');
    }
});

it('leaves no em dash in what the users and roles pages offer, or say on a role', function () use ($root): void {
    $users = (string) file_get_contents($root.'/resources/js/Pages/Estate/Settings/Users.vue');
    $roles = (string) file_get_contents($root.'/resources/js/Pages/Estate/Settings/Roles.vue');

    expect(preg_match_all('/<option[^>]*>[^<]*—/u', $users))->toBe(0, 'Users.vue has an em dash in an option')
        ->and($users)->not->toContain("' — administrator'")
        ->and($users)->not->toContain('— now')
        ->and($users)->not->toContain('administrator — gives')
        ->and($roles)->not->toContain('${role.label} — ${role.scope_label}');
});
