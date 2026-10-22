<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| No console screen says "This screen does not update by itself" (M18H §7.2)
|--------------------------------------------------------------------------
|
| M11 requires every screen to update live, and the client found the Residents page saying it did not. Every page a
| controller renders on either console is held here to one of three things, which is what makes the honesty line say
| something true about it:
|
|   useLiveReload(…) / useLive(…) / useLiveDispatch(…)   it moves by itself
|   useLiveKind('form')                                   an edit page: nothing to go stale, never re-read under typing
|   useLiveKind('snapshot')                               a report: "As of …"
|
| A create page (`…/Create`, `…/New…`, `…/Edit…`) is a form by its name, as LiveStatus.vue reads it.
|
*/

/** Every Inertia page a controller renders on the estate or Gemini console. @return list<string> */
function renderedConsolePages(): array
{
    $pages = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/(?:inertia|Inertia::render)\(\s*'((?:Estate|Gemini)\/[A-Za-z\/]+)'/", (string) file_get_contents($file->getPathname()), $m);
        array_push($pages, ...$m[1]);
    }

    $pages = array_values(array_unique($pages));
    sort($pages);

    return $pages;
}

it('finds the console pages it is holding', function () {
    expect(count(renderedConsolePages()))->toBeGreaterThan(90);
});

it('holds every rendered console page to live, form or report', function () {
    $silent = [];

    foreach (renderedConsolePages() as $page) {
        $path = resource_path('js/Pages/'.$page.'.vue');
        expect(is_file($path))->toBeTrue("A controller renders [{$page}], and there is no {$page}.vue.");

        $source = (string) file_get_contents($path);
        $binds = preg_match('/\buseLive(Reload|Dispatch)?\(|\buseLiveKind\(\s*\'(form|snapshot)\'\s*\)/', $source) === 1;
        $formByName = preg_match('/\/(Create|New|Edit)[A-Za-z]*$/', $page) === 1;

        if (! $binds && ! $formByName) {
            $silent[] = $page;
        }
    }

    expect($silent)->toBe([], 'These pages would say "This screen does not update by itself": bind them with useLiveReload, or say what they are with useLiveKind.');
});

it('says only the three true things about a page, and nothing about a page that said nothing', function () {
    $status = (string) file_get_contents(resource_path('js/Components/LiveStatus.vue'));
    $kind = (string) file_get_contents(resource_path('js/composables/useLiveKind.js'));

    // A declared kind is taken at its word; an undeclared one is still judged by its bindings.
    expect($status)->toContain('const declared = state.pageKind')
        ->and($status)->toContain("'This screen does not update by itself'")
        ->and($kind)->toContain('liveState.pageKind = kind')
        // …and forgets it when the page goes, so the next page is not told it is a form.
        ->and($kind)->toContain('liveState.pageKind = null');
});
