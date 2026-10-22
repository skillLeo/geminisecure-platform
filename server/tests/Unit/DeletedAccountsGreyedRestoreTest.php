<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Deleted accounts: a Restore whose time has run out is plainly off, with its reason under it (12 Oct)
|--------------------------------------------------------------------------
|
| The hub's finding: on an account past the office's window, the greyed Restore looked almost live (grey on grey, no
| edge), its reason sat under Delete permanently, and the row said "0 days left" in the amber that means "expiring".
| The count above the list is the server's (ResidentAccountDeletionTest); the page is read as text here: a unit test
| boots no application, and the JS test runner is banned on this PC.
|
*/

it('keeps a greyed Restore\'s reason under its own button, greys it as this lane greys a button, and calls nothing past its window expiring', function (): void {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Residents/DeletedAccounts.vue'));

    // The reason inside Restore's own group, not under the whole row of actions.
    $group = (string) substr($page, (int) strpos($page, '<div v-if="canRestore" class="da-restore">'));
    $group = (string) substr($group, 0, (int) strpos($group, '<button
                        v-if="canDeletePermanently"'));

    expect(str_contains($page, '<div v-if="canRestore" class="da-restore">'))->toBeTrue('Restore has a group of its own')
        ->and(str_contains($group, ':disabled="!row.can_restore"'))->toBeTrue()
        ->and(str_contains($group, '<span v-if="!row.can_restore" class="da-why">{{ NO_LONGER_RESTORABLE }}</span>'))->toBeTrue('the reason is under its own button')
        ->and(str_contains($page, '<div v-if="canRestore && !row.can_restore" class="da-why">'))->toBeFalse('the reason under every action is gone');

    // Faded, and an outline on white: never a grey pill that reads as a quiet live button.
    $at = strpos($page, ".da-actions .btn-primary-sm:disabled {\n    border: 1.5px solid var(--navy-200);");
    expect($at)->not->toBeFalse();
    $rule = substr($page, (int) $at, (int) strpos($page, '}', (int) $at) - (int) $at);
    expect(str_contains($rule, 'background: var(--white);'))->toBeTrue()
        ->and(str_contains($rule, 'opacity: 0.55;'))->toBeTrue();

    // Amber "expiring" only while it can still come back; past its window the days say "None".
    expect(str_contains($page, 'const soon = (row) => row.can_restore && row.days_left <= 7'))->toBeTrue()
        ->and(str_contains($page, "const daysLine = (row) => (!row.can_restore ? 'None' :"))->toBeTrue();
});
