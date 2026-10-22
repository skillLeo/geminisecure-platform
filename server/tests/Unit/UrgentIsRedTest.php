<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| "Saturated red is reserved for panic and life safety. Ordinary errors are amber. If red means two things it means
| nothing." (Hassam's universal order, 11 October 2026, rule 20; it replaces M12 §4.4's "'Urgent' is red".)
|--------------------------------------------------------------------------
|
| The boards drew an overdue invoice red. One override layer in app.css turns standings that only need attention amber
| for both consoles (D-185); these read it, and the inline styles that bypassed the sheets.
|
| THE URGENT NOTICE IS AMBER, PINNED (Hassam's final red rule, 11 Oct 2026, three weights: solid saturated red for an
| open panic or duress alert and nothing else; red outline or muted red for Failed, Rejected and a destructive button;
| amber for errors, warnings, refusals, urgent notices, overdue, blocked and denied). app.css painted every urgent tag
| solid red-700; it is the warning pair now, and this file, which once pinned it red (M12 §4.4), pins it amber.
|
| REJECTED AND FAILED ARE THE MIDDLE WEIGHT, PINNED (12 Oct, routed by the hub from Governance): they sat in the amber
| group; they are a red outline now, the booking pages' own three declarations.
|
*/

/** A file of the repository, read as text (a unit test boots no application, so no base_path()). */
function repoFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

function consoleCss(): string
{
    return repoFile('resources/css/app.css');
}

/** The declarations of the first rule whose selector list names `$selector`. */
function ruleFor(string $css, string $selector): string
{
    $at = strpos($css, $selector);
    expect($at)->not->toBeFalse("no rule names {$selector}");

    $open = strpos($css, '{', (int) $at);
    $close = strpos($css, '}', (int) $open);

    return substr($css, (int) $open, (int) $close - (int) $open);
}

it('keeps saturated red for the panic alarm, the one life-safety screen every console draws', function () {
    expect(repoFile('resources/js/Components/PanicOverlay.vue'))->toContain('background: var(--red-700)');
});

it('draws every standing that only needs attention amber, not red', function () {
    $css = consoleCss();

    // The 90+ days card itself as well as its words (12 Oct, routed by the hub): its ground was the board's red-100.
    foreach (['.status-badge.overdue', '.status-badge.expired', '.status-badge.exceptions', '.age-badge.d90', '.age-kpi.d90,'] as $selector) {
        expect(ruleFor($css, $selector))->toContain('var(--amber-700)')->not->toContain('red');
    }

    expect(ruleFor($css, '.age-kpi.d90,'))->toContain('background: var(--amber-100)');

    // A High maintenance job is a job to do soon, not an emergency.
    expect(ruleFor($css, '.priority-dot.high i'))->toContain('var(--amber-700)');
});

it('draws an urgent notice amber, its tag and its icon, never the panic\'s red (the final red rule, 11 Oct)', function () {
    $css = consoleCss();

    expect(ruleFor($css, '.notice-tag.urgent'))->toContain('var(--amber-100)')->toContain('var(--amber-700)')
        ->and(str_contains(ruleFor($css, '.notice-tag.urgent'), 'red'))->toBeFalse('an urgent notice tag still draws red');

    foreach (['.n-icon.urgent {', '.n-icon.urgent svg'] as $selector) {
        expect(str_contains(ruleFor($css, $selector), 'var(--amber-'))->toBeTrue("{$selector} is not amber")
            ->and(str_contains(ruleFor($css, $selector), 'red'))->toBeFalse("{$selector} still draws red");
    }
});

it('draws Rejected and Failed as a red outline, never filled and never amber (the final red rule\'s middle weight)', function () {
    $css = consoleCss();

    foreach (['.status-badge.rejected', '.dun-status.failed'] as $selector) {
        $rule = ruleFor($css, $selector);

        // The same three declarations the booking pages draw inline, so one look across the product.
        expect(str_contains($rule, 'background: transparent'))->toBeTrue("{$selector} is filled")
            ->and(str_contains($rule, 'box-shadow: inset 0 0 0 1px var(--red-700)'))->toBeTrue("{$selector} has no red outline")
            ->and(str_contains($rule, 'color: var(--red-700)'))->toBeTrue("{$selector} is not red")
            ->and(str_contains($rule, 'amber'))->toBeFalse("{$selector} is still amber")
            ->and(str_contains($rule, 'var(--red-100)'))->toBeFalse("{$selector} is still filled red");
    }
});

it('leaves no inline red on a standing the sheets could not reach', function () {
    foreach (['Estate/Accounting/Vendor.vue', 'Estate/Dues/Arrears.vue', 'Estate/Dues/PaymentPlans.vue'] as $page) {
        expect(repoFile('resources/js/Pages/'.$page))->not->toContain('background:var(--red-100)')->not->toContain("'var(--red-100)'");
    }

    // A supplier's page has no red at all: an overdue bill is amber, and so is a refusal on its forms (rule 20).
    expect(str_contains(repoFile('resources/js/Pages/Estate/Accounting/Vendor.vue'), 'red-'))->toBeFalse('the vendor page still draws red');

    // Nor has the payment plans register: a defaulted plan and a missed instalment are amber (rule 20).
    expect(str_contains(repoFile('resources/js/Pages/Estate/Dues/PaymentPlans.vue'), 'red-'))->toBeFalse('the payment plans register still draws red');

    // The overdue chip on the maintenance queue: a rule of the page's own since it became a filter (FAC-1), amber.
    $queue = repoFile('resources/js/Pages/Estate/Facilities/Maintenance.vue');
    expect(ruleFor($queue, '.f-chip.mq-overdue'))->toContain('var(--amber-100)')->toContain('var(--amber-700)')->not->toContain('red');

    // And a late ticket's own Overdue badge is the shared `.status-badge.overdue`, which the sheet draws amber (above).
    expect($queue)->toContain('<div v-if="row.is_overdue" class="status-badge overdue">Overdue</div>');
});

it('reaches the duty board from every Guard workforce screen (M12 §4.10)', function () {
    // The one list behind every screen's bar, since M30 in lib/workforceTabs.js (both views draw it).
    expect(repoFile('resources/js/Pages/Gemini/Operations/Subnav.vue'))->toContain('workforceTabs(props.active)');
    expect(repoFile('resources/js/lib/workforceTabs.js'))->toContain("href: '/guards/on-duty'");

    foreach (['Index' => 'directory', 'Compliance' => 'compliance', 'Supervisors' => 'supervisors'] as $page => $key) {
        expect(repoFile("resources/js/Pages/Gemini/Guards/{$page}.vue"))->toContain("<Subnav active=\"{$key}\" />");
    }
});
