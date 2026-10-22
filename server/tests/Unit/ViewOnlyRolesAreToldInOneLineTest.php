<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A role that may only read is told so in one line, never the permission's machinery (M53 Laws 1 and 2)
|--------------------------------------------------------------------------
|
| If anybody but Hassam signs in on Sunday it is the President (the register's Part 1), who may read the maintenance
| queue, a ticket, the charges and the users list without changing them. Each refusal said which permission it needs
| and that the reader may still look: "Triaging a ticket changes what a vendor is asked to do and needs Facilities
| update access. You are able to read this screen.", twenty five times down the queue. `COPY_REWRITES.md` §1: one line,
| the fact, nothing about permissions.
|
*/

it('tells a role that may only read, in one line, what is not part of it', function (string $file, string $now, string $was): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);

    expect(str_contains($text, $now))->toBeTrue("{$file} does not say: {$now}")
        ->and(str_contains($text, $was))->toBeFalse("{$file} still says: {$was}");
})->with([
    'the maintenance queue' => ['app/Http/Controllers/Estate/FacilitiesController.php', "'blockedReason' => 'Changing a ticket is not part of your role.'", 'Triaging a ticket changes what a vendor is asked to do'],
    'a ticket' => ['app/Http/Controllers/Estate/FacilitiesController.php', "'blockedReason' => 'Changing this ticket is not part of your role.'", 'Completing or reassigning a ticket needs Facilities update access'],
    'New charge' => ['app/Http/Controllers/Estate/DuesController.php', "'blockedReason' => 'Posting charges is not part of your role.'", 'Posting a charge adds to what a household owes'],
    // Since the M98 pass (M96 level 3) the arrears page tells such a role nothing, because it draws no New charge for
    // it at all; the greyed twin and its sentence are gone, and the machinery's sentence never comes back.
    'the arrears page' => ['resources/js/Pages/Estate/Dues/Arrears.vue', '<Link v-if="canCharge" :href="financePath(\'/charges/new\')"', 'Posting a charge adds to what a household owes'],
    'the arrears page, no greyed New charge' => ['resources/js/Pages/Estate/Dues/Arrears.vue', 'M96 level 3', ':title="NO_CHARGE_ACCESS"'],
    // The users list keeps its sentence: OnePermissionPerPowerTest pins that it names Manage people (M11 B3, D-150).
]);
