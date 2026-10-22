<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/*
|--------------------------------------------------------------------------
| The consoles' bundle is split by route — M11 A3
|--------------------------------------------------------------------------
|
| "Split the JavaScript bundle by route so opening the dashboard doesn't
| download the accounting module."
|
| Until M11 every console page downloaded one 1.13 MB script holding all 116
| components under Pages/ and the socket client, and a stylesheet carrying the
| twenty phone-app boards no console page can wear. Three lines put all of that
| back, and none of them fails a feature test: an eager glob, one static import
| of echo.js, or a regenerated stylesheet index. So they are held here, where
| the first person to write any of them again finds out.
|
| A lazy resolver can only fetch a page the build has, so every page the server
| names is checked to be one. And a script that failed to arrive once fails for
| the rest of the tab's life, so the resolver goes through chunkRecovery.js;
| what that does is tests/js/chunk-recovery.test.mjs's to prove.
|
| What they cost is measured, not asserted: the before and after sizes, and
| the walk proving every page still resolves, are in BASELINE.md's M11 A3
| section.
|
| The four tables whose length only the estate bounds — arrears, the arrears
| ageing report, receipts with their gaps, and the maintenance queue — draw
| only the rows on screen past 250 rows, and are untouched below it. How that
| behaves in a browser (every row reachable by scrolling and by Tab, the whole
| table printed, nothing drawn differently at 250) is walked by
| tests/Fidelity/window-check.mjs; what is held here is the wiring, so a page
| that stops using it, or a composable that starts changing short tables, is
| caught without a browser.
|
*/

/** A file under resources/, as text. */
function splitSource(string $relative): string
{
    return (string) file_get_contents(resource_path($relative));
}

/**
 * Every .js and .vue file under resources/js, keyed by its path from there.
 *
 * @return array<string, string>
 */
function splitScripts(): array
{
    $root = resource_path('js').DIRECTORY_SEPARATOR;
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (in_array($file->getExtension(), ['js', 'vue'], true)) {
            $files[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

it('resolves every page lazily, so a page is fetched only when the server names it', function () {
    $app = splitSource('js/app.js');

    // No second argument: `{ eager: true }` there is exactly what put every page in one file.
    expect(preg_match_all('/import\.meta\.glob\(([^)]*)\)/', $app, $globs))->toBe(1)
        ->and(trim($globs[1][0]))->toBe("'./Pages/**/*.vue'")
        // The page's loader goes through loadPage, which opens the page afresh if its script fails to arrive.
        ->and(preg_match('/resolve:\s*\(name, page\)\s*=>\s*\{.*?return loadPage\(load, page\?\.url \?\? window\.location\.href\)\s*\}/s', $app))->toBe(1, 'resolve() must hand the page\'s loader to loadPage, with the address being opened')
        ->and(preg_match('/^watchLateChunks\(\)$/m', $app))->toBe(1, 'a late chunk that fails outside a page resolve must be watched for')
        ->and(str_contains($app, 'Inertia page not found'))->toBeTrue('a page the server names and the build does not have must still fail loudly');
});

it('names only pages the lazy resolver can find', function () {
    // Every component the server renders, by its literal name — no call passes one in a variable.
    $named = [];

    foreach (['app', 'bootstrap', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/(?:\binertia|Inertia::render|Route::inertia)\s*\(\s*([^,)]+)/', $source, $calls);

            foreach ($calls[1] as $argument) {
                expect(preg_match("/^'([A-Za-z\\/]+)'$/", trim($argument), $name))->toBe(1, "{$file->getFilename()} names a page with {$argument}, which a test cannot check");
                $named[$name[1]] = $file->getFilename();
            }
        }
    }

    expect(count($named))->toBeGreaterThan(90)->and($named)->toHaveKey('ErrorPage');

    foreach ($named as $component => $where) {
        expect(is_file(resource_path("js/Pages/{$component}.vue")))->toBeTrue("{$where} renders {$component}, and resources/js/Pages has no such page for the resolver to load");
    }
});

it('loads the socket client only where something listens, and only lazily', function () {
    $static = [];
    $dynamic = [];
    $transport = [];

    foreach (splitScripts() as $path => $source) {
        if (preg_match('/^\s*import\b[^;\n]*from\s+[\'"](?:\.\.?\/)+echo(?:\.js)?[\'"]/m', $source) === 1) {
            $static[] = $path;
        }

        if (preg_match('/import\(\s*[\'"](?:\.\.?\/)+echo(?:\.js)?[\'"]\s*\)/', $source) === 1) {
            $dynamic[] = $path;
        }

        if (preg_match('/from\s+[\'"](?:laravel-echo|pusher-js)[\'"]/', $source) === 1) {
            $transport[] = $path;
        }
    }

    sort($transport);

    expect($static)->toBe([], 'echo.js is imported statically by: '.implode(', ', $static))
        ->and($dynamic)->toBe(['composables/useAlertStream.js', 'composables/useConsolePanic.js', 'composables/useEstatePanic.js'])
        // Echo, and the one socket it shares with the live client (M11 Part C, live/socket.js).
        ->and($transport)->toBe(['echo.js', 'live/socket.js'])
        // tests/Fidelity/echo-check.mjs drives the socket through this handle.
        ->and(splitSource('js/echo.js'))->toContain('window.Echo = echo');

    // The entry script pulls in nothing of the live client, which would put pusher-js back in every page's bundle.
    expect(preg_match('/from\s+[\'"]\.\/live(?:\/[^\'"]*)?[\'"]/', splitSource('js/app.js')))->toBe(0);
});

it('fetches the socket client with a signed-in page, and never with the sign-in form', function () {
    // A build of three files, so the page's head is the real @vite output for a known manifest.
    $dir = 'build-m11a3-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists(public_path($dir));
    File::put(public_path($dir.'/manifest.json'), (string) json_encode([
        'resources/css/app.css' => ['file' => 'assets/app-TEST.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.js' => ['file' => 'assets/app-TEST.js', 'src' => 'resources/js/app.js', 'isEntry' => true, 'dynamicImports' => ['resources/js/echo.js']],
        'resources/js/echo.js' => ['file' => 'assets/echo-TEST.js', 'src' => 'resources/js/echo.js', 'isDynamicEntry' => true],
    ]));

    try {
        Vite::useBuildDirectory($dir);
        $page = ['component' => 'Auth/Login', 'props' => [], 'url' => '/login', 'version' => 'test'];
        $preload = '<link rel="modulepreload" href="'.asset($dir.'/assets/echo-TEST.js').'">';

        $guest = view('app', ['page' => $page])->render();
        expect($guest)->not->toContain('echo-TEST.js');

        $this->actingAs(User::factory()->make(['id' => 1]));
        $signedIn = view('app', ['page' => $page])->render();

        // Fetched once, and only fetched: nothing in the head runs it.
        expect($signedIn)->toContain($preload)
            ->and(substr_count($signedIn, 'echo-TEST.js'))->toBe(1);

        // A build with the socket client inside the entry (as before M11 A3) has no such file:
        // the page is drawn without the preload rather than not drawn at all.
        File::ensureDirectoryExists(public_path($dir.'-one-file'));
        File::put(public_path($dir.'-one-file/manifest.json'), (string) json_encode([
            'resources/css/app.css' => ['file' => 'assets/app-TEST2.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/app-TEST2.js', 'src' => 'resources/js/app.js', 'isEntry' => true],
        ]));
        Vite::useBuildDirectory($dir.'-one-file');
        $oneFile = view('app', ['page' => $page])->render();
        expect($oneFile)->toContain('app-TEST2.js')->and($oneFile)->not->toContain('echo');
    } finally {
        File::deleteDirectory(public_path($dir));
        File::deleteDirectory(public_path($dir.'-one-file'));
        Vite::useBuildDirectory('build');
    }
});

it('bundles only the board stylesheets a console page can wear, and every one of them', function () {
    preg_match_all("/^@import '\\.\\/scoped\\/([a-z0-9-]+)\\.css';$/m", splitSource('css/wireframe-index.css'), $imports);
    $bundled = $imports[1];

    $consoleSheets = array_values(array_map(
        static fn (string $file): string => basename($file, '.css'),
        glob(resource_path('css/scoped/{super-admin,community-admin}-*.css'), GLOB_BRACE) ?: [],
    ));

    sort($bundled);
    sort($consoleSheets);

    // The phone boards are React Native's; their sheets stay on disk for diffing and out of the bundle.
    expect($bundled)->toBe($consoleSheets)
        ->and($bundled)->not->toBeEmpty();

    preg_match_all("/^\\s+'([a-z0-9-]+)': 'wf-([a-z0-9-]+)',$/m", splitSource('js/wireframe-map.js'), $pairs, PREG_SET_ORDER);
    $boards = [];

    foreach ($pairs as [, $board, $sheet]) {
        $boards[] = $board;
        expect(in_array($sheet, $bundled, true))->toBeTrue("the map hands {$board} the class of a sheet the bundle does not carry");
    }

    // Every board any console source names is one the map knows, so useWireframe() finds its CSS.
    foreach (splitScripts() as $path => $source) {
        if ($path === 'wireframe-map.js') {
            continue;
        }

        preg_match_all('/[\'"]((?:super-admin|community-admin|guard-app|resident-app)-\d{2}-[a-z0-9-]+)[\'"]/', $source, $named);

        foreach ($named[1] as $board) {
            expect(in_array($board, $boards, true))->toBeTrue("{$path} names the board {$board}, which the bundle does not carry");
        }
    }
});

it('windows the four tables only the estate bounds, and leaves every table of 250 rows or fewer as it was', function () {
    $composable = splitSource('js/composables/useWindowedRows.js');

    // At or below the threshold the page is handed its own array, and a row gets no attribute.
    expect($composable)->toContain('export const WINDOW_ABOVE = 250')
        ->and($composable)->toContain('items.value.length > above && !printing.value')
        ->and(preg_match('/const visible = computed\(\(\) => \{\s+if \(!windowed\.value\) \{\s+return items\.value\s+\}/', $composable))->toBe(1, 'a short table must be handed its own array')
        ->and(preg_match('/rowAttrs: \(i\) => \{\s+if \(!windowed\.value\) \{\s+return \{\}\s+\}/', $composable))->toBe(1, 'a short table\'s rows must get no attribute')
        ->and($composable)->toContain('rowCount: computed(() => (windowed.value ? items.value.length + 1 : null))')
        // A long table's first frame carries a column sample, which is out again before a paint.
        ->and($composable)->toContain('sampling.value = length > above')
        // Printing draws every row, and lets the held column widths go first.
        ->and($composable)->toContain("window.addEventListener('beforeprint', beforePrint)");

    $users = [];

    foreach (splitScripts() as $path => $source) {
        if (preg_match('/\buseWindowedRows\(/', $source) === 1 && $path !== 'composables/useWindowedRows.js') {
            $users[] = $path;
        }
    }

    /*
     * The Arrears register, the Receipt register and the Maintenance queue were windowed here; since M32 F5 the server
     * orders and pages them, and no page is longer than the largest size a reader can choose, which is under the
     * threshold — so none of the three is long enough to window any more. The report screen still draws a whole report.
     */
    expect($users)->toBe(['Pages/Estate/Reports/Show.vue'])
        ->and(max(App\Support\TableQuery::SIZES))->toBeLessThanOrEqual(250);

    foreach (['Pages/Estate/Dues/Arrears.vue', 'Pages/Estate/Dues/Receipts.vue', 'Pages/Estate/Facilities/Maintenance.vue'] as $paged) {
        expect(splitSource("js/{$paged}"))->toContain('<DataTable');
    }

    foreach ($users as $path) {
        $page = splitSource("js/{$path}");

        preg_match('/const (\w+) = useWindowedRows\(/', $page, $binding);
        $rows = $binding[1];

        // Bound to the body it measures, a stand-in at each end that a screen reader skips,
        // the rows drawn from `visible` with their attributes, and the table's row count.
        expect($page)->toContain(":ref=\"{$rows}.bind\"")
            ->and(substr_count($page, "<tr v-if=\"{$rows}.before\" data-window-spacer=\"before\" aria-hidden=\"true\">"))->toBe(1, $path)
            ->and(substr_count($page, "<tr v-if=\"{$rows}.after\" data-window-spacer=\"after\" aria-hidden=\"true\">"))->toBe(1, $path)
            ->and(preg_match("/v-for=\"\\((?:row|item), (?:i|index)\\) in {$rows}\\.visible\"/", $page))->toBe(1, $path)
            ->and($page)->toContain("v-bind=\"{$rows}.rowAttrs(")
            ->and($page)->toContain(":aria-rowcount=\"{$rows}.rowCount\"");
    }
});
