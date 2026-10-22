<?php

declare(strict_types=1);

/*
 * A release's `artisan`, standing in for the application. It logs every call as
 * `artisan[<release>] <arguments>`, so a test can see WHICH release a command ran in.
 *
 *   list --raw         the usual commands, plus those in state/artisan-commands (live:health...)
 *   <command>          succeeds, unless state/artisan-fail-<command> (':' as '~') holds a count
 *                      of calls still to fail
 */

$state = rtrim((string) getenv('GS_FAKE_STATE'), '/\\');
$release = basename(__DIR__);
$args = array_slice($argv, 1);
file_put_contents((string) getenv('GS_FAKE_LOG'), 'artisan['.$release.'] '.implode(' ', $args)."\n", FILE_APPEND);

$command = $args[0] ?? 'list';

$fail = $state.'/artisan-fail-'.str_replace(':', '~', $command);
if (is_file($fail)) {
    $left = (int) file_get_contents($fail);
    if ($left > 0) {
        if ($left !== PHP_INT_MAX) {
            file_put_contents($fail, (string) ($left - 1));
        }
        echo "{$command}: FAILED (stand-in)\n";
        exit(1);
    }
}

if ($command === 'list') {
    $listed = is_file($state.'/artisan-commands')
        ? array_values(array_filter(array_map('trim', file($state.'/artisan-commands') ?: [])))
        : [];
    foreach (array_merge(['config:cache', 'migrate', 'queue:restart', 'route:list'], $listed) as $name) {
        printf("%-32s %s\n", $name, 'A command (stand-in)');
    }
    exit(0);
}

echo "{$command}: ok (stand-in, release {$release})\n";
exit(0);
