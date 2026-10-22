<?php

/*
|--------------------------------------------------------------------------
| THE FIVE SCREEN FAULTS HASSAM FOUND ON LIVE, 12 OCTOBER 2026 (after batch six)
|--------------------------------------------------------------------------
|
| 1. The payment plan's card ran off the top and bottom of the window, its title out of reach, and its dimmed backdrop
|    stopped short: the overlay was pinned to the shell, 236 px from the left, and centred without scrolling.
| 2. A toast sat over the red emergency bar's Open Security and Hide buttons.
| 3. At phone width Dues & ledger ran off the right of the window.
| 4. Accounting's tab row was six tabs on the chart and four on Vendors, Bills & payments and Bank reconciliation.
| 5. The budget page's month and amounts were the browser's bare boxes.
|
| Each is read from the source, as the console's other screen rules are.
*/

function liveFaultSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('keeps the payment plan card inside the window: fixed, below the red bar, scrolling, no inline sidebar offset', function () {
    $plan = liveFaultSource('resources/js/Pages/Estate/Dues/PaymentPlan.vue');

    expect(str_contains($plan, 'style="left: 236px"'))->toBeFalse()
        ->and(str_contains($plan, 'class="modal-overlay pp-overlay"'))->toBeTrue()
        ->and(preg_match('/\.modal-overlay\.pp-overlay \{[^}]*position: fixed;[^}]*top: var\(--panic-bar-h, 0px\);[^}]*overflow-y: auto;/s', $plan))->toBe(1)
        ->and(preg_match('/\.pp-overlay > form\.modal-card \{[^}]*margin: auto;/s', $plan))->toBe(1);
});

it('puts the toast stack below the red emergency bar, on a wide screen and on a phone', function () {
    $stack = liveFaultSource('resources/js/Components/ToastStack.vue');

    // Below the red bar AND the 64 px top bar, so never over Open Security and Hide nor the page's own buttons.
    expect(str_contains($stack, 'top: calc(72px + var(--panic-bar-h, 0px));'))->toBeTrue()
        ->and(str_contains($stack, 'top: calc(64px + var(--panic-bar-h, 0px));'))->toBeTrue();
});

it('lets every estate page fit a phone: the main column shrinks, tab rows scroll, chips wrap, tables scroll in place', function () {
    $shell = liveFaultSource('resources/js/Layouts/EstateConsole.vue');

    expect(preg_match('/\.app-shell > \.main-col \{\s*min-width: 0;/', $shell))->toBe(1)
        ->and(str_contains($shell, ':deep(.subnav)'))->toBeTrue()
        ->and(str_contains($shell, ':deep(.filter-row)'))->toBeTrue()
        ->and(str_contains($shell, ':deep(.age-kpi-row)'))->toBeTrue()
        ->and(preg_match('/:deep\(\.data-table\) \{[^}]*overflow-x: auto;/s', $shell))->toBe(1);
});

it('draws the same Accounting tab row on every screen that has one', function () {
    $controller = liveFaultSource('app/Http/Controllers/Estate/AccountingController.php');
    $payables = liveFaultSource('app/Http/Controllers/Estate/PayablesController.php');

    expect(str_contains($controller, 'public static function optionalTabs(): array'))->toBeTrue()
        ->and(str_contains($controller, '...self::optionalTabs(),'))->toBeTrue()
        ->and(substr_count($payables, "'optionalTabs' => AccountingController::optionalTabs(),"))->toBe(3);

    foreach (['Vendors', 'Bills', 'Reconciliation'] as $page) {
        $source = liveFaultSource("resources/js/Pages/Estate/Accounting/{$page}.vue");

        expect(str_contains($source, 'optionalTabs: { type: Array'))->toBeTrue()
            ->and(str_contains($source, '...props.optionalTabs.map('))->toBeTrue();
    }
});

it('keeps every estate page\'s header, with its actions, in view as the page scrolls (batch 1 item 4)', function () {
    $shell = liveFaultSource('resources/js/Layouts/EstateConsole.vue');

    expect(preg_match('/\n\.main-col > \.topbar \{\s*position: sticky;\s*top: var\(--panic-bar-h, 0px\);/', $shell))->toBe(1);
});

it('has one mark for a field that must be filled in, amber and heard by a screen reader (batch 1 item 3)', function () {
    $mark = liveFaultSource('resources/js/Components/RequiredMark.vue');

    expect(str_contains($mark, 'class="req-mark" aria-hidden="true">*</span>'))->toBeTrue()
        ->and(str_contains($mark, '<span class="req-sr">required</span>'))->toBeTrue()
        ->and(str_contains($mark, 'Fields marked * must be filled in.'))->toBeTrue()
        ->and(str_contains($mark, 'color: var(--amber-700);'))->toBeTrue();
});

it('draws every toast as a white card with its kind as an edge, an icon ring and a title (Hassam: professional everywhere)', function () {
    $stack = liveFaultSource('resources/js/Components/ToastStack.vue');

    expect(str_contains($stack, '<div class="toast-title">{{ label[item.tone] ?? label.info }}</div>'))->toBeTrue()
        ->and(str_contains($stack, 'background: var(--white, #fff);'))->toBeTrue()
        ->and(str_contains($stack, '.toast::before {'))->toBeTrue()
        ->and(str_contains($stack, '<TransitionGroup tag="div" name="toast" class="toast-stack">'))->toBeTrue();
});

it('gives every form inside a dialog one professional field, whatever page opens it', function () {
    $dialog = liveFaultSource('resources/js/Components/ConsoleDialog.vue');

    expect(preg_match('/\.cd-panel \.cd-body :deep\(:where\(input:not\([^)]*\), select, textarea\)\) \{[^}]*min-height: 42px;[^}]*border: 1\.5px solid var\(--navy-200\);[^}]*border-radius: 10px;/s', $dialog))->toBe(1)
        ->and(str_contains($dialog, 'border-color: var(--amber-500);'))->toBeTrue()
        ->and(str_contains($dialog, 'background-image: url("data:image/svg+xml'))->toBeTrue();
});

it('gives the budget page the console field for its month and amounts', function () {
    $budgets = liveFaultSource('resources/js/Pages/Estate/Accounting/Budgets.vue');

    expect(preg_match("/\\.bud-head input\\[type='month'\\],\\s*\\.bud-amount \\{[^}]*border: 1\\.5px solid var\\(--navy-200\\);[^}]*border-radius: 10px;/s", $budgets))->toBe(1);
});
