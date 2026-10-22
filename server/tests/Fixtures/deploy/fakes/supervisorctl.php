<?php

declare(strict_types=1);

/*
 * Stand-in for supervisorctl (supervisor 4.2, Ubuntu 24.04). It keeps each program as
 * state/supervisor/<name> = "<STATE> <pid>" (a ':' in a group member's name is stored as '~'),
 * answers the way supervisorctl prints, and exits the way supervisor 4 does: `status` 3 for a
 * program that is not running and 4 for one that does not exist; `start`, `stop` and `restart`
 * 1 for any error they print, including the "not running" of restarting a stopped program.
 *
 * `update [name ...]` reads the program files in <root>/etc/supervisor/conf.d: a new one is
 * added and started, a changed one restarted, a removed one stopped and forgotten. Programs a
 * test declares directly (gs-reverb, the queue workers) have no file there and are left alone.
 */

require __DIR__.'/lib.php';

$args = array_slice($argv, 2);
fake_log('supervisorctl '.implode(' ', $args));

$dir = fake_state('supervisor');
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

/** @return array<string, array{string, int}> */
function programs(string $dir): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || $file[0] === '.') {
            continue;
        }
        $parts = explode(' ', trim((string) file_get_contents($dir.'/'.$file)));
        $out[str_replace('~', ':', $file)] = [$parts[0], (int) ($parts[1] ?? 0)];
    }
    ksort($out);

    return $out;
}

function save(string $dir, string $name, string $state, int $pid): void
{
    file_put_contents($dir.'/'.str_replace(':', '~', $name), "{$state} {$pid}\n");
}

function start_program(string $dir, string $name): void
{
    $file = $dir.'/.next-pid';
    $pid = is_file($file) ? (int) file_get_contents($file) : 4200;
    file_put_contents($file, (string) ($pid + 1));
    save($dir, $name, 'RUNNING', $pid);
    if (! str_contains($name, ':')) {
        fake_process($pid);
    }
}

/** @return list<string> */
function matching(string $dir, string $arg): array
{
    $all = programs($dir);
    if (str_ends_with($arg, ':')) {
        return array_values(array_filter(array_keys($all), fn (string $n): bool => str_starts_with($n, $arg)));
    }

    return array_key_exists($arg, $all) ? [$arg] : [];
}

$confDir = fake_root().'/etc/supervisor/conf.d';
$exit = 0;
$command = $args[0] ?? '';
$names = array_slice($args, 1);

switch ($command) {
    case 'avail':
        foreach (programs($dir) as $name => [$state, $pid]) {
            printf("%-40s in use    auto      999:999\n", $name);
        }
        break;

    case 'pid':
        foreach ($names as $name) {
            $all = programs($dir);
            if (! isset($all[$name])) {
                echo "No such process {$name}\n";
                $exit = 1;

                continue;
            }
            echo $all[$name][1], "\n";
            if ($all[$name][1] === 0) {
                $exit = 3;
            }
        }
        break;

    case 'status':
        $all = programs($dir);
        foreach ($names === [] ? array_keys($all) : $names as $name) {
            $found = matching($dir, $name);
            if ($found === []) {
                echo "{$name}: ERROR (no such process)\n";
                $exit = 4;

                continue;
            }
            foreach ($found as $one) {
                [$state, $pid] = $all[$one];
                printf("%-40s %-9s%s\n", $one, $state, $state === 'RUNNING' ? "pid {$pid}, uptime 0:00:05" : '');
                if ($state !== 'RUNNING' && $exit === 0) {
                    $exit = 3;
                }
            }
        }
        break;

    case 'start':
    case 'stop':
    case 'restart':
        foreach ($names as $name) {
            $found = matching($dir, $name);
            if ($found === []) {
                echo "{$name}: ERROR (no such process)\n";
                $exit = 1;

                continue;
            }
            foreach ($found as $one) {
                $state = programs($dir)[$one][0];
                // supervisor 4 sets exit status 1 for each of these errors, and `restart` is
                // `stop` then `start`: restarting a program that is not running prints
                // "ERROR (not running)", starts it, and still exits 1.
                if ($command === 'start' && $state === 'RUNNING') {
                    echo "{$one}: ERROR (already started)\n";
                    $exit = 1;

                    continue;
                }
                if ($command !== 'start' && $state === 'RUNNING') {
                    save($dir, $one, 'STOPPED', 0);
                    echo "{$one}: stopped\n";
                } elseif ($command !== 'start') {
                    echo "{$one}: ERROR (not running)\n";
                    $exit = 1;
                }
                if ($command !== 'stop') {
                    if (is_file(fake_state('supervisor-fails-'.str_replace(':', '~', $one)))) {
                        save($dir, $one, 'FATAL', 0);
                        echo "{$one}: ERROR (spawn error)\n";
                        $exit = 7;

                        continue;
                    }
                    start_program($dir, $one);
                    echo "{$one}: started\n";
                }
            }
        }
        break;

    case 'reread':
        $any = false;
        foreach (glob($confDir.'/*.conf') ?: [] as $conf) {
            $name = basename($conf, '.conf');
            $marker = $dir.'/.conf-'.$name;
            if (! is_file($marker)) {
                echo "{$name}: available\n";
                $any = true;
            } elseif (trim((string) file_get_contents($marker)) !== sha1_file($conf)) {
                echo "{$name}: changed\n";
                $any = true;
            }
        }
        if (! $any) {
            echo "No config updates to processes\n";
        }
        break;

    case 'update':
        $files = [];
        foreach (glob($confDir.'/*.conf') ?: [] as $conf) {
            $files[basename($conf, '.conf')] = $conf;
        }
        foreach (glob($dir.'/.conf-*') ?: [] as $marker) {
            $files += [substr(basename($marker), 6) => null];
        }
        foreach ($files as $name => $conf) {
            if ($names !== [] && ! in_array($name, $names, true)) {
                continue;
            }
            $marker = $dir.'/.conf-'.$name;
            if ($conf === null) {
                @unlink($marker);
                @unlink($dir.'/'.$name);
                echo "{$name}: stopped\n{$name}: removed process group\n";
            } elseif (! is_file($marker)) {
                file_put_contents($marker, (string) sha1_file($conf));
                // `update` says the group was added whether or not its process then starts.
                if (is_file(fake_state('supervisor-fails-'.$name))) {
                    save($dir, $name, 'FATAL', 0);
                } else {
                    start_program($dir, $name);
                }
                echo "{$name}: added process group\n";
            } elseif (trim((string) file_get_contents($marker)) !== sha1_file($conf)) {
                file_put_contents($marker, (string) sha1_file($conf));
                echo "{$name}: stopped\n{$name}: updated process group\n";
                start_program($dir, $name);
            }
        }
        break;

    default:
        echo "*** Unknown syntax: {$command}\n";
        $exit = 2;
}

exit($exit);
