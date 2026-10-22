<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| What a button did is said where the page can read it (M53 §4.5)
|--------------------------------------------------------------------------
|
| Payroll's "Send back" saved the run and showed nothing, and the user read a working button as a dead one. Its
| controller flashed `->with('flash', …)`, and the console reads `success`, `error` and `status` from the session
| (HandleInertiaRequests), never a key called `flash` — so five payroll answers and one settings answer went nowhere.
| No controller may flash a key the console does not read.
|
*/

it('never flashes a confirmation under a key the console does not read', function () {
    $shared = (string) file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));

    // The keys the console reads, as the middleware itself lists them.
    expect($shared)->toContain("session()->get('success')")
        ->and($shared)->toContain("session()->get('error')")
        ->and($shared)->toContain("session()->get('status')");

    $offenders = [];

    foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
        if (str_contains((string) file_get_contents($file->getPathname()), "->with('flash'")) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
