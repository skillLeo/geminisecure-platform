<?php

declare(strict_types=1);
use App\Support\MoneyFormatter;

/*
|--------------------------------------------------------------------------
| Hassam's batch 1 of live faults, Governance and Settings (12 October 2026, seen on live as the Community Super Admin)
|--------------------------------------------------------------------------
|
| Each case reads the page as it ships and fails on the fault Hassam saw. The pages are Vue; what is pinned is the markup
| and the rule that draws it, read from the source.
|
*/

function batchOneSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('keeps the permissions table\'s header row under the shell\'s pinned top bar, measured, not under it (item 4)', function () {
    $page = batchOneSource('resources/js/Pages/Estate/Settings/RolePermissions.vue');

    // The shell pins its top bar at the red bar's height (85c79680); a header row pinned at that same height slid under it.
    expect(preg_match('/\.rp-table\.rp-table thead th \{\s*position: sticky;\s*top: calc\(var\(--panic-bar-h, 0px\) \+ var\(--rp-head-top, 0px\)\);/', $page))->toBe(1)
        // The bar's height is measured, since below 1023 px it wraps to two rows, and followed as it changes.
        ->and(str_contains($page, "document.querySelector('.main-col > .topbar')"))->toBeTrue()
        ->and(str_contains($page, 'new ResizeObserver('))->toBeTrue()
        ->and(str_contains($page, ":style=\"{ '--rp-head-top': `\${topbarHeight}px` }\""))->toBeTrue();
});

it('says a date too soon once, on the Date field, amber when it is breached, and never again beside Schedule (item 5)', function () {
    $form = batchOneSource('resources/js/Pages/Estate/Governance/MeetingScheduler.vue');

    // "Needs 7 days' notice. Earliest 18 Oct." was drawn under Date and again beside the greyed Schedule button.
    $blocked = substr($form, (int) strpos($form, 'const saveBlockedBy = computed('));
    $blocked = substr($blocked, 0, (int) strpos($blocked, "\n})"));

    expect($blocked)->not->toBe('')
        ->and(str_contains($blocked, 'return noticeLine.value'))->toBeFalse()
        // The same kind on the same form: the latest day and the missing phase are said under their own fields too.
        ->and(str_contains($blocked, 'return props.notice.latest_line'))->toBeFalse()
        ->and(str_contains($blocked, 'return PHASE_MISSING'))->toBeFalse();

    // The hint under Date is the one place: grey, and amber once the date breaks it.
    expect(substr_count($form, '{{ noticeLine }}'))->toBe(1)
        ->and(str_contains($form, ":class=\"insideNotice ? 'act-error' : 'm-hint'\">{{ noticeLine }}</div>"))->toBeTrue();
});

it('opens Draft an election empty, with examples as placeholders, the year marked required and room under the text (item 7)', function () {
    $room = batchOneSource('resources/js/Pages/Estate/Governance/ControlRoom.vue');

    // It opened filled with "2031" and "2031 Election", real values typed in for the Secretary.
    $open = substr($room, (int) strpos($room, 'const openCreate = () => {'));
    $open = substr($open, 0, (int) strpos($open, "\n}"));

    expect(str_contains($open, "electionForm.year = ''"))->toBeTrue()
        ->and(str_contains($open, "electionForm.title = ''"))->toBeTrue()
        ->and(str_contains($open, '${year} Election'))->toBeFalse()
        // The examples are placeholders, "e.g." first.
        ->and(str_contains($room, ':placeholder="`e.g. ${suggestedYear}`"'))->toBeTrue()
        ->and(str_contains($room, ':placeholder="`e.g. ${suggestedYear} Election`"'))->toBeTrue()
        // Marked required, with the one legend above the first field; the optional line says so.
        ->and(str_contains($room, '<label for="new-election-year">Year <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($room, '<label for="new-election-title">Name <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($room, "<RequiredMark legend />\n            <div class=\"cr-create-fields\">"))->toBeTrue()
        ->and(str_contains($room, 'What it is for: the line a household reads (optional)'))->toBeTrue()
        // Draft is greyed, with why, until both are filled (Law 1).
        ->and(str_contains($room, ':confirm-blocked-reason="canCreate ? draftBlockedBy : createReason"'))->toBeTrue()
        // The Year label no longer touches the paragraph above it.
        ->and(preg_match('/\.cr-ask \+ \.req-legend \{\s*margin-top: 12px;/', $room))->toBe(1);
});

it('marks the meeting form\'s mandatory fields, types nothing in for the Secretary, and gives examples as "e.g." (items 3 and 7)', function () {
    $form = batchOneSource('resources/js/Pages/Estate/Governance/MeetingScheduler.vue');

    // The one legend above the first field, and the mark in every mandatory label.
    expect(str_contains($form, "import RequiredMark from '../../../Components/RequiredMark.vue'"))->toBeTrue()
        ->and(substr_count($form, '<RequiredMark legend />'))->toBe(1)
        ->and(str_contains($form, '<label for="meeting-title">Meeting title <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($form, '<label for="starts-on">Date <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($form, '<label for="starts-on-time">Time <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($form, '<label for="virtual-link">Joining link <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($form, '<label for="platform-name">Platform name (optional)</label>'))->toBeTrue()
        // The title opens empty, the type's name as its example; it was typed in as if the Secretary had chosen it.
        ->and(preg_match("/title: '',/", $form))->toBe(1)
        ->and(str_contains($form, ':placeholder="`e.g. ${titleExample}`"'))->toBeTrue()
        // Every placeholder is an example, "e.g." first: none is an instruction.
        ->and(preg_match_all('/\splaceholder="(?!e\.g\. )[^"]*"/', $form))->toBe(0);
});

it('marks the mandatory fields on the notice composer and the Settings forms, with examples as "e.g." (items 3 and 7)', function () {
    $marked = static fn (string $page): bool => str_contains($page, "import RequiredMark from '../../../Components/RequiredMark.vue'")
        && str_contains($page, '<RequiredMark legend />');
    $examplesOnly = static fn (string $page): int => preg_match_all('/\splaceholder="(?!e\.g\. )[^"]*"/', $page);

    $notices = batchOneSource('resources/js/Pages/Estate/Governance/Notices.vue');
    expect($marked($notices))->toBeTrue()
        ->and(str_contains($notices, '<label for="notice-title">Title <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($notices, '<label for="notice-body">Message <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($notices, 'Attach a file to the notice: a map, a letter, a form (optional)'))->toBeTrue()
        ->and($examplesOnly($notices))->toBe(0);

    $users = batchOneSource('resources/js/Pages/Estate/Settings/Users.vue');
    expect($marked($users))->toBeTrue()
        ->and(str_contains($users, '<label for="inv-email">Email address <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($users, '<label for="inv-role">Role <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($users, '<label for="adm-pick">Committee member <RequiredMark /></label>'))->toBeTrue();

    $profile = batchOneSource('resources/js/Pages/Estate/Settings/Profile.vue');
    expect($marked($profile))->toBeTrue()
        ->and(str_contains($profile, "const REQUIRED_FIELDS = ['security_provider']"))->toBeTrue()
        ->and(substr_count($profile, '<RequiredMark v-if="mustFill('))->toBe(2);

    $roles = batchOneSource('resources/js/Pages/Estate/Settings/Roles.vue');
    expect($marked($roles))->toBeTrue()
        ->and(str_contains($roles, '<label for="rl-new-label">Name <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($roles, '<label for="rl-rename">New name <RequiredMark /></label>'))->toBeTrue()
        ->and(str_contains($roles, ":placeholder=\"createSource ? `e.g. Copy of \${createSource.label}` : 'e.g. Gate supervisor'\""))->toBeTrue();

    $features = batchOneSource('resources/js/Pages/Estate/Settings/Features.vue');
    expect(str_contains($features, 'Why is this changing? <RequiredMark />'))->toBeTrue()
        ->and($examplesOnly($features))->toBe(0);
});

it('marks what each meeting and election dialog requires, with the legend in every dialog that asks for something (item 3)', function () {
    foreach (['MeetingLife' => 'life-label', 'ElectionLife' => 'el-label'] as $component => $labelClass) {
        $source = batchOneSource("resources/js/Components/{$component}.vue");

        expect(str_contains($source, "import RequiredMark from './RequiredMark.vue'"))->toBeTrue("{$component} does not import the mark");

        // Every dialog with a field in it says what the mark means, and marks at least one field.
        preg_match_all('#<ConsoleDialog\b.*?</ConsoleDialog>#s', $source, $dialogs);

        foreach ($dialogs[0] as $dialog) {
            if (! str_contains($dialog, "class=\"{$labelClass}\"")) {
                continue;
            }

            preg_match('/title="([^"]*)"|:title="([^"]*)"/', $dialog, $title);

            expect(str_contains($dialog, '<RequiredMark legend />'))->toBeTrue("{$component}: a dialog with fields has no legend ({$title[0]})")
                ->and(str_contains($dialog, '<RequiredMark />'))->toBeTrue("{$component}: a dialog with fields marks none ({$title[0]})");
        }
    }

    // A field that may be left empty says so.
    expect(str_contains(batchOneSource('resources/js/Components/MeetingLife.vue'), 'New date and time (optional, if you know it)'))->toBeTrue();
});

it('marks what the control room, Add a candidate and a nomination\'s decision require, examples as "e.g." (items 3 and 7)', function () {
    foreach ([
        'resources/js/Pages/Estate/Governance/ControlRoom.vue' => "import RequiredMark from '../../../Components/RequiredMark.vue'",
        'resources/js/Pages/Estate/Governance/Nominations.vue' => "import RequiredMark from '../../../Components/RequiredMark.vue'",
        'resources/js/Components/AddCandidate.vue' => "import RequiredMark from './RequiredMark.vue'",
    ] as $path => $import) {
        $source = batchOneSource($path);

        expect(str_contains($source, $import))->toBeTrue("{$path} does not import the mark")
            ->and(preg_match_all('/\splaceholder="(?!e\.g\. )[^"]*"/', $source))->toBe(0, "{$path} has a placeholder that is not an example");

        preg_match_all('#<ConsoleDialog\b.*?</ConsoleDialog>#s', $source, $dialogs);

        foreach ($dialogs[0] as $dialog) {
            if (! str_contains($dialog, '<label')) {
                continue;
            }

            expect(str_contains($dialog, '<RequiredMark legend />') && str_contains($dialog, '<RequiredMark />'))
                ->toBeTrue("{$path}: a dialog with fields has no legend or no mark: ".mb_substr(trim(strip_tags($dialog)), 0, 80));
        }
    }
});

it('marks the minutes as required and the outcome statement as optional, its example "e.g." (items 3 and 7)', function () {
    $meeting = batchOneSource('resources/js/Pages/Estate/Governance/Meeting.vue');
    $results = batchOneSource('resources/js/Pages/Estate/Governance/Results.vue');

    expect(str_contains($meeting, "import RequiredMark from '../../../Components/RequiredMark.vue'"))->toBeTrue()
        ->and(str_contains($meeting, 'Saved minutes can be corrected until they are published. <RequiredMark />'))->toBeTrue()
        ->and(str_contains($meeting, '<textarea id="mt-minutes-body" v-model="minutesForm.body" rows="10" maxlength="20000" required></textarea>'))->toBeTrue()
        ->and(str_contains($results, 'quorum and the elected names are written from the counts (optional).'))->toBeTrue()
        ->and(str_contains($results, ':placeholder="turnout && quorum ? `e.g. Turnout'))->toBeTrue();
});

it('writes a payment in the activity feed to the cent, in the console\'s one form of money (item 10)', function () {
    $log = batchOneSource('app/Services/Estate/ActivityLog.php');

    // "$6,200" with the cents dropped: a payment of $6,200.50 read as $6,201 (Law 5: one way to write money).
    expect(str_contains($log, 'number_format($minor / 100, 0)'))->toBeFalse()
        ->and(str_contains($log, 'MoneyFormatter::fromMinor($payment->amount_minor, (string) ($payment->currency ?: MoneyFormatter::DEFAULT_CURRENCY))'))->toBeTrue()
        ->and(MoneyFormatter::fromMinor(620050))->toEndWith('6,200.50');
});

it('makes a status in a list lead to where it is acted on (item 10)', function () {
    // A meeting's stage chip (Draft, Postponed) opens the meeting, whose page holds its acts.
    $meetings = batchOneSource('resources/js/Pages/Estate/Governance/Meetings.vue');
    expect(str_contains($meetings, '<Link :href="governance(`/meetings/${row.id}`)" class="mt-stage" :class="`stage-${row.stage}`"'))->toBeTrue();

    // A nomination's status opens its record, where the decision and its eligibility snapshot are read.
    $nominations = batchOneSource('resources/js/Pages/Estate/Governance/Nominations.vue');
    expect(str_contains($nominations, '<Link :href="nominationPath(row.id, \'\')" class="status-badge nm-badge"'))->toBeTrue();

    // A committee member's status opens Change role or access, where it is changed; an expired invitation, its Resend.
    $users = batchOneSource('resources/js/Pages/Estate/Settings/Users.vue');
    expect(preg_match('/class="status-badge status-act"[^>]*@click="openManage\(row\)"/s', $users))->toBe(1)
        ->and(preg_match('/class="status-badge status-act"[^>]*@click="resend\(invitation\)"/s', $users))->toBe(1);
});

it('stacks the two-column Governance pages into one column on a phone (item 12)', function () {
    foreach ([
        'MeetingScheduler.vue' => 'ms-grid',
        'Notices.vue' => 'notices-grid',
        'Meeting.vue' => 'mt-grid',
        'Results.vue' => 'rs-cards',
    ] as $page => $class) {
        $source = batchOneSource("resources/js/Pages/Estate/Governance/{$page}");

        // Side by side at 390 px, each column a third of a phone: the shell's frame stops at 1023 px, a page's grid is its own.
        expect(preg_match('/@media \(max-width: 1023px\) \{\s*\.'.preg_quote($class, '/').'(?:\.[\w-]+)? \{\s*grid-template-columns: 1fr;/', $source))
            ->toBe(1, "{$page}: .{$class} does not stack at 1023 px");
    }

    // And no page sets its two columns inline, where no rule can reach them.
    foreach (['MeetingScheduler.vue', 'Results.vue'] as $page) {
        expect(str_contains(batchOneSource("resources/js/Pages/Estate/Governance/{$page}"), 'grid-template-columns: 1.3fr 1fr; gap')
            || str_contains(batchOneSource("resources/js/Pages/Estate/Governance/{$page}"), 'style="display: grid; grid-template-columns: 1fr 1fr'))->toBeFalse();
    }
});

it('lets a phone-width column shrink to the phone, and scrolls the election stepper in its own frame (item 12, seen at 390)', function () {
    // Seen on the dev server at 390 px: Notices was 539 px wide, its table holding the grid column open (a grid item does
    // not shrink below its content), and the control room's nine-step stepper ran 10 px past the edge.
    foreach (['MeetingScheduler.vue' => 'ms-grid', 'Notices.vue' => 'notices-grid', 'Meeting.vue' => 'mt-grid', 'Results.vue' => 'rs-cards'] as $page => $class) {
        expect(preg_match('/\.'.preg_quote($class, '/').' > \* \{\s*min-width: 0;/', batchOneSource("resources/js/Pages/Estate/Governance/{$page}")))
            ->toBe(1, "{$page}: .{$class}'s columns cannot shrink below their content");
    }

    expect(preg_match('/@media \(max-width: 1023px\) \{\s*\.stepper \{\s*overflow-x: auto;/', batchOneSource('resources/js/Pages/Estate/Governance/ControlRoom.vue')))->toBe(1);
});

it('answers the audit log\'s addresses, while its switch is off, with a clean page saying so, never the bare 404 (seen at 390)', function () {
    // Seen on the dev server: /settings/audit with the switch off drew "Not found ... that is worth reporting".
    $controller = batchOneSource('app/Http/Controllers/Estate/AuditLogController.php');
    $page = batchOneSource('resources/js/Pages/Estate/NotInPlan.vue');

    expect(str_contains($controller, 'abort_unless(self::on(), 404)'))->toBeFalse()
        ->and(substr_count($controller, 'return self::switchedOff();'))->toBe(3)
        ->and(str_contains($controller, "'switchedOff' => true"))->toBeTrue()
        ->and(str_contains($page, 'switchedOff: { type: Boolean, default: false }'))->toBeTrue()
        ->and(str_contains($page, '`${props.module} is switched off`'))->toBeTrue()
        // The plan's own page is unchanged.
        ->and(str_contains($page, 'is not included in your plan'))->toBeTrue();
});

it('lines up the meeting register\'s row actions: right-aligned, the menu in a column of its own, Publish a button (item 6)', function () {
    $register = batchOneSource('resources/js/Pages/Estate/Governance/Meetings.vue');

    // Publish, View agenda, Open and View minutes differ in width, so the "..." buttons wandered row to row.
    expect(str_contains($register, "{ key: 'actions', label: '', class: 'mt-actions-col' }"))->toBeTrue()
        ->and(preg_match('/\.mt-acts \{\s*display: grid;\s*grid-template-columns: auto 32px;\s*justify-content: end;/', $register))->toBe(1)
        ->and(preg_match('/\.mt-acts > \.mt-row-menu \{\s*grid-column: 2;/', $register))->toBe(1)
        ->and(preg_match('/:deep\(td\.mt-actions-col\) \{\s*text-align: right;/', $register))->toBe(1)
        // Publish reads as a button, not a link like the others.
        ->and(str_contains($register, 'class="mt-publish-btn"'))->toBeTrue();
});

it('seeds meeting titles and agenda lines without an em dash, and still finds the meetings it seeded before (item 6)', function () {
    $seeder = batchOneSource('database/seeders/Estate/GovernanceSeeder.php');

    $titles = substr($seeder, (int) strpos($seeder, 'private function meetingTitle('));
    $titles = substr($titles, 0, (int) strpos($titles, "\n    }"));
    $agenda = substr($seeder, (int) strpos($seeder, 'private function seedAgenda('));
    $agenda = substr($agenda, 0, (int) strpos($agenda, "\n    }"));

    expect(str_contains($titles, '—'))->toBeFalse('a seeded meeting title carries an em dash')
        ->and(str_contains($titles, "'Executive Committee, '.\$startsAt->format('F')"))->toBeTrue()
        ->and(str_contains($titles, "'EGM: Security budget increase'"))->toBeTrue()
        ->and(preg_match("/'text' => '[^']*—/u", $agenda))->toBe(0)
        // An estate seeded before the rename is found by its old title too, so a second run adds no second EGM.
        ->and(str_contains($seeder, "->whereIn('title', ['EGM: Security budget increase', 'EGM — Security budget increase'])"))->toBeTrue();

    // The notices seeded beside them, the same kind: no em dash in a title, and the old title renamed, not doubled.
    $notices = batchOneSource('database/seeders/Estate/NoticesSeeder.php');

    expect(preg_match("/'title' => '[^']*—/u", $notices))->toBe(0)
        ->and(str_contains($notices, "Notice::query()->where('title', \$row['was'])->update(['title' => \$row['title']]);"))->toBeTrue();
});
