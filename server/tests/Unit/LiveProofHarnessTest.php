<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| THE C8 HARNESS'S OWN TESTS, IN THE SUITE (M11 Part C, DESIGN.md §11)
|--------------------------------------------------------------------------
|
| tests/Live/proof measures every change side by side; these prove the
| measuring: that it reads a known delay within ±50 ms, that touching the
| receiver fails a run, that the pairs every transition's audience reaches are
| derived from docs/realtime/DESIGN.md and each needs a row, and that the
| report refuses a slow row with no reason. One Pest test per file, failing
| with Node's own report; skipped, and saying why, only where Node or the
| packages are missing (the browser test skips itself without Chromium).
|
*/

$root = dirname(__DIR__, 2);

$files = array_map('basename', glob($root.'/tests/Live/proof/__tests__/*.test.mjs') ?: []);

it('passes the C8 harness tests in tests/Live/proof', function (string $file) use ($root): void {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/playwright')) {
        $this->markTestSkipped('Node, or the installed node_modules, is not available here, so tests/Live/proof/__tests__/'.$file.' cannot run.');
    }

    $process = new Process([$node, '--test', '--test-reporter=spec', 'tests/Live/proof/__tests__/'.$file], $root);
    $process->setTimeout(180);
    $process->run();

    expect($process->getExitCode())->toBe(0, "tests/Live/proof/__tests__/{$file} failed:\n".$process->getOutput().$process->getErrorOutput());
})->with(array_combine($files, $files));
