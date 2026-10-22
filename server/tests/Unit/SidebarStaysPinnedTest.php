<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The sidebar holds still while the page scrolls
|--------------------------------------------------------------------------
|
| Releasing the shell's fixed height let long tables show every row, and took
| the sidebar with it: it stretched to the height of the page, scrolled away
| with the table, and put the account chip — the only way to sign out — at the
| bottom of the longest page. Both consoles now pin it to the window: the logo
| at the top, the account at the bottom, and between them the links, which
| scroll only when the pointer is over the sidebar and there are more than fit.
|
| What a browser does with the rules is proven in a browser (both consoles,
| two window heights, and under the red emergency bar; the bar on the ten
| Sunday rehearsal screens by `tools/sunday/banner-check.mjs`). This holds the
| source to them, so a later change cannot quietly undo it.
|
*/

$root = dirname(__DIR__, 2);

it('pins the sidebar to the window, below the emergency bar when it shows', function () use ($root): void {
    $css = (string) file_get_contents($root.'/resources/css/app.css');

    expect($css)->toContain("body[class*='wf-'] .app-shell > .sidebar {")
        ->and($css)->toContain('position: sticky;')
        ->and($css)->toContain('top: var(--panic-bar-h, 0px);')
        ->and($css)->toContain('height: calc(100vh - var(--panic-bar-h, 0px));');
});

it('draws the emergency bar in the page, pushing it down rather than covering it (M60 §C4)', function () use ($root): void {
    $overlay = (string) file_get_contents($root.'/resources/js/Components/PanicOverlay.vue');
    $css = (string) file_get_contents($root.'/resources/css/app.css');

    expect(preg_match('/\n\.panic-bar \{([^}]*)\}/', $overlay, $bar))->toBe(1)
        ->and($bar[1])->toContain('position: sticky;')
        ->and($bar[1])->toContain('top: 0;')
        ->and($bar[1])->not->toContain('position: fixed;')
        ->and($css)->toContain('min-height: calc(100vh - var(--panic-bar-h, 0px));')
        // No padding made for the bar any more; the window's scroll-padding-top (DR-053) is another property.
        ->and($css)->not->toMatch('/(?<![-\w])padding-top: var\(--panic-bar-h/');
});

it('stops whatever the browser scrolls to below the emergency bar, by its measured height (DR-053)', function () use ($root): void {
    $css = (string) file_get_contents($root.'/resources/css/app.css');

    // The bar's own height as PanicOverlay measures it, never a number of pixels: it wraps to two lines at 1280.
    expect($css)->toMatch('/\nhtml \{\s*scroll-padding-top: var\(--panic-bar-h, 0px\);\s*\}/');
});

it('puts the emergency bar before the shell in both consoles, above the page and not inside it', function (string $layout) use ($root): void {
    $vue = (string) file_get_contents($root.'/resources/js/Layouts/'.$layout);

    $bar = strpos($vue, '<PanicOverlay');
    $shell = strpos($vue, '<div class="app-shell">');

    expect($bar)->not->toBeFalse()
        ->and($shell)->not->toBeFalse()
        ->and($bar < $shell)->toBeTrue();
})->with(['GeminiConsole.vue', 'EstateConsole.vue']);

it('scrolls only the links, and only on hover, by keyboard focus, or where there is no hover', function () use ($root): void {
    $css = (string) file_get_contents($root.'/resources/css/app.css');

    expect($css)->toMatch('/\.sidebar > \.side-scroll \{[^}]*overflow-y: hidden;/s')
        ->and($css)->toContain("body[class*='wf-'] .sidebar:hover > .side-scroll,")
        ->and($css)->toContain("body[class*='wf-'] .sidebar > .side-scroll:focus-within {")
        ->and($css)->toContain('@media (hover: none)')
        ->and($css)->toContain('overscroll-behavior: contain;');
});

it('gives both consoles the links a box of their own, between the logo and the account', function (string $layout) use ($root): void {
    $vue = (string) file_get_contents($root.'/resources/js/Layouts/'.$layout);

    $logo = strpos($vue, 'class="side-logo"');
    $links = strpos($vue, '<nav class="side-scroll"');
    $firstItem = strpos($vue, 'class="nav-item"');
    $foot = strpos($vue, 'class="side-foot"');

    expect($logo)->not->toBeFalse()
        ->and($links)->not->toBeFalse()
        ->and($foot)->not->toBeFalse()
        ->and($logo < $links && $links < $firstItem && $firstItem < $foot)->toBeTrue()
        ->and(substr_count($vue, '</nav>'))->toBe(1);
})->with(['GeminiConsole.vue', 'EstateConsole.vue']);

it('publishes the emergency bar\'s height while it shows, and takes it back when it goes', function () use ($root): void {
    $overlay = (string) file_get_contents($root.'/resources/js/Components/PanicOverlay.vue');

    expect($overlay)->toContain('ref="bar" class="panic-bar"')
        ->and($overlay)->toContain("root.style.setProperty('--panic-bar-h'")
        ->and($overlay)->toContain("root.style.removeProperty('--panic-bar-h')")
        ->and($overlay)->toContain('new ResizeObserver');
});
