<?php

declare(strict_types=1);

/*
 * Shared by the stand-ins in this directory. Each stand-in is run by a two-line sh wrapper that
 * tests/Support/DeploySandbox.php writes into the sandbox's bin/, first on PATH:
 *
 *   GS_FAKE_ROOT   the directory standing in for /
 *   GS_FAKE_STATE  what the stand-ins remember between calls (supervisor's programs, what apt
 *                  "installed", which artisan command should fail)
 *   GS_FAKE_LOG    one line per call, which the test reads back
 */

function fake_log(string $line): void
{
    file_put_contents((string) getenv('GS_FAKE_LOG'), rtrim($line)."\n", FILE_APPEND);
}

function fake_state(string $name): string
{
    return rtrim((string) getenv('GS_FAKE_STATE'), '/\\').'/'.$name;
}

function fake_root(): string
{
    return rtrim((string) getenv('GS_FAKE_ROOT'), '/\\');
}

function fake_link(string $link, string $target): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        $win = static fn (string $p): string => str_replace('/', '\\', $p);
        exec('cmd /c mklink /J '.escapeshellarg($win($link)).' '.escapeshellarg($win($target)).' >NUL 2>&1', $out, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "stand-in: mklink /J {$link} {$target} failed\n");
            exit(70);
        }

        return;
    }
    if (! symlink($target, $link)) {
        fwrite(STDERR, "stand-in: symlink {$link} {$target} failed\n");
        exit(70);
    }
}

function fake_link_target(string $path): ?string
{
    $target = @readlink($path);
    if (! is_string($target) || $target === '') {
        return null;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return $target;
    }
    $parent = realpath(dirname($path));
    if ($parent === false) {
        return $target;
    }

    return strcasecmp(str_replace('/', '\\', $target), rtrim($parent, '\\/').'\\'.basename($path)) === 0 ? null : $target;
}

function fake_unlink(string $path): void
{
    if (fake_link_target($path) !== null) {
        if (PHP_OS_FAMILY === 'Windows') {
            @rmdir($path) || @unlink($path);
        } else {
            @unlink($path);
        }
    } elseif (is_file($path)) {
        @unlink($path);
    }
}

/**
 * A process supervisor just started: its /proc/<pid>/cwd is the release `current` points at (the
 * kernel resolves supervisor's `directory=` link at chdir), and its maps list the event-loop
 * extension PHP loads at that moment.
 */
function fake_process(int $pid): void
{
    $dir = fake_root().'/proc/'.$pid;
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $current = fake_link_target(fake_root().'/var/www/geminisecure/current');
    fake_unlink($dir.'/cwd');
    if ($current !== null) {
        fake_link($dir.'/cwd', $current);
    }
    $maps = "5581d4a00000-5581d4a2c000 r--p 00000000 08:01 1310730                    /usr/bin/php8.5\n";
    $ext = is_file(fake_state('loop-ext')) ? trim((string) file_get_contents(fake_state('loop-ext'))) : '';
    if ($ext !== '') {
        $maps .= "7f3a2b400000-7f3a2b41c000 r--p 00000000 08:01 1312001                    /usr/lib/php/20250925/{$ext}.so\n";
    }
    file_put_contents($dir.'/maps', $maps);
}

function fake_copy_tree(string $from, string $to): void
{
    if (! is_dir($to)) {
        mkdir($to, 0777, true);
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($from.'/'.$entry)) {
            fake_copy_tree($from.'/'.$entry, $to.'/'.$entry);
        } else {
            copy($from.'/'.$entry, $to.'/'.$entry);
        }
    }
}
