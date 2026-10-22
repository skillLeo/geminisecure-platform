<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every disabled control on these three screens says why (M62 B5, DR-024)
|--------------------------------------------------------------------------
|
| The browser sweep found fourteen controls drawn disabled with no reason on them: the day buttons, the two times and
| the two "who is told" lists on resident access, the review box of a new account on the chart of accounts, and the
| level list and the Approver box in the roles editor. Law 1: a control that cannot be used says why, one line, saying
| what would make it available, on the control itself (its title, which is what the sweep reads and what a pointer
| shows). The roles editor's reasons were on the label beside each box and in a column that left out the structural
| minimum; the minimum's own reason is the server's (EstateRolesEditorTest).
|
*/

$root = dirname(__DIR__, 2);
$page = static fn (string $path): string => (string) file_get_contents($root.'/resources/js/Pages/Estate/'.$path);

it('puts the page\'s reason on every control resident access disables for want of the permission', function () use ($page): void {
    $vue = $page('Settings/ResidentAccess.vue');

    // The seven days, the two times and the two lists: each one disabled while the viewer may not change this screen,
    // and each one saying so. Save is no longer one of them: a reader has no Save at all, a "View only" badge in its
    // place (M96 level 3; the Settings lane, 12 Oct 2026).
    preg_match_all('/<(button|select|input)\b[^>]*:disabled="!canEdit"[^>]*>/s', $vue, $tags);

    expect(count($tags[0]))->toBe(4)
        ->and(str_contains($vue, '<span v-if="!canEdit" class="view-badge">View only</span>'))->toBeTrue('a reader is not told what the screen is to them');

    foreach ($tags[0] as $tag) {
        expect(str_contains($tag, ':title="canEdit ? undefined : blockedReason"'))->toBeTrue($tag);
    }

    // The switches (DR-025, 47ca7a06): disabled and titled through switchBlocked(), whose first answer, while the viewer
    // may not change this screen, is that same reason; a row that needs another row on says which, once that one is off.
    expect(preg_match('/<button\b[^>]*role="switch"[^>]*>/s', $vue, $switch))->toBe(1)
        ->and(str_contains($switch[0], ':disabled="switchBlocked(level, group) !== null"'))->toBeTrue('the switch is not disabled through switchBlocked()')
        ->and(str_contains($switch[0], ':title="switchBlocked(level, group) ?? undefined"'))->toBeTrue('the switch is not titled through switchBlocked()')
        ->and(preg_match('/const switchBlocked = \(level, group\) => \{\s*if \(!props\.canEdit\) \{\s*return props\.blockedReason/s', $vue))
        ->toBe(1, 'switchBlocked() does not answer with the page reason first');
});

it('says resident access\'s reason in one line, with no em dash (Laws 2 and 3)', function () use ($root): void {
    $php = (string) file_get_contents($root.'/app/Http/Controllers/Estate/SettingsController.php');

    expect($php)->toContain("'blockedReason' => 'Changing what Dependents and Helpers may do needs Settings configure access.',");
});

it('says why a new account\'s review box is not ready yet: a code and a name first', function () use ($page): void {
    $vue = $page('Accounting/ChartOfAccounts.vue');

    expect(preg_match('/<input v-model="accountForm\.reviewed"[^>]*>/', $vue, $tag))->toBe(1)
        ->and($tag[0])->toContain(':title="review === null ? \'Enter a code and a name first.\' : undefined"');
});

it('puts the reason on the roles editor\'s level list and Approver box, and says it in words for every locked cell', function () use ($page): void {
    // A role's permissions are a page of their own since 12 Oct (Hassam: a long table read across is never a modal).
    $vue = $page('Settings/RolePermissions.vue');

    expect(preg_match('/<select\s[^>]*class="rl-select"[^>]*>/s', $vue, $level))->toBe(1)
        ->and($level[0])->toContain(':title="isLocked(module.key) ? lockedReason(module.key) : undefined"')
        ->and(preg_match('/<input\s[^>]*:checked="draft\[module\.key\]\.can_approve"[^>]*>/s', $vue, $approver))->toBe(1)
        ->and($approver[0])->toContain(':title="approveTitle(module.key)"')
        // The why column: a structural minimum is locked too, and was left out of it.
        ->and($vue)->toContain('<template v-if="isLocked(module.key)">');
});
