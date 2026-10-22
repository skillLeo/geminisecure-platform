<?php

declare(strict_types=1);

/*
 * Stand-in for `ln -s [-f] [-n] <target> <link>`, the only form the deploy scripts use. A
 * directory target becomes a directory link (a junction on Windows, which needs no privilege);
 * a file target a symlink, or on Windows a copy, which is all the scripts need of it.
 */

require __DIR__.'/lib.php';

$args = array_slice($argv, 2);
fake_log('ln '.implode(' ', $args));

$flags = '';
$positional = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '-') && $arg !== '-') {
        $flags .= substr($arg, 1);
    } else {
        $positional[] = $arg;
    }
}
if (! str_contains($flags, 's') || count($positional) !== 2) {
    fwrite(STDERR, "ln (stand-in): only `ln -s[f][n] <target> <link>` is supported\n");
    exit(2);
}
[$target, $link] = $positional;

if (str_contains($flags, 'f')) {
    fake_unlink($link);
} elseif (file_exists($link) || fake_link_target($link) !== null) {
    fwrite(STDERR, "ln: failed to create symbolic link '{$link}': File exists\n");
    exit(1);
}

if (is_dir($target)) {
    fake_link($link, $target);
} elseif (PHP_OS_FAMILY === 'Windows') {
    if (is_file($target)) {
        copy($target, $link);
    } else {
        file_put_contents($link, '');
    }
} elseif (! symlink($target, $link)) {
    exit(1);
}
exit(0);
