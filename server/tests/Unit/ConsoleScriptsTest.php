<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| THE CONSOLES' OWN SCRIPTS, IN THE SUITE
|--------------------------------------------------------------------------
|
| What a panic pop-up says, whether its bar keeps blinking after a page
| change, whether the siren stops when an alert is acknowledged — all of it is
| decided in the browser, and a PHP test can only show what the server sent.
| `tests/js/` mounts the real components and composables with Vue's own
| runtime, in Node, with the router, the page props and the socket as
| stand-ins a test drives (see tests/js/support/register.mjs).
|
| This file is what makes them part of the suite rather than a script somebody
| has to remember: one Pest test per file, failing with Node's own report.
| Skipped, and saying why, only where Node or the installed packages are
| missing — never passed without having run.
|
*/

$root = dirname(__DIR__, 2);

$files = array_map('basename', glob($root.'/tests/js/*.test.mjs') ?: []);

it('passes the console script tests in tests/js', function (string $file) use ($root): void {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/vue')) {
        $this->markTestSkipped('Node, or the installed node_modules, is not available here, so tests/js/'.$file.' cannot run.');
    }

    $process = new Process([$node, '--import', './tests/js/support/register.mjs', '--test', '--test-reporter=spec', 'tests/js/'.$file], $root);
    $process->setTimeout(180);
    $process->run();

    expect($process->getExitCode())->toBe(0, "tests/js/{$file} failed:\n".$process->getOutput().$process->getErrorOutput());
})->with(array_combine($files, $files));
