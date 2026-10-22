<?php

declare(strict_types=1);

/*
 * Stand-ins for the system commands the deploy scripts call, one behaviour each, steered by
 * files in the state directory (see tests/Support/DeploySandbox.php):
 *
 *   apt-get     `update` fails when state/apt-update-fails exists; `install` of php8.5-ev or
 *               php8.5-event makes PHP load that extension (state/loop-ext), unless
 *               state/apt-broken-<package> exists
 *   apt-cache   `policy <package>` has a candidate only for the packages in state/apt-available
 *   pecl        `install ev` builds it (state/pecl-ev) unless state/pecl-fails exists
 *   phpenmod    enabling ev after the build, with its .ini in place, makes PHP load it
 *   nginx       `-t` fails when state/nginx-t-fails exists
 *   curl        `-w %{http_code}` answers state/up-code (200 by default)
 *   tar         `-xzf <artifact dir> -C <dir>` copies the artifact directory
 *   systemctl, composer, mysql, ss, usermod: logged, and succeed
 */

require __DIR__.'/lib.php';

$name = $argv[1];
$args = array_slice($argv, 2);
fake_log($name.' '.implode(' ', $args));

switch ($name) {
    case 'apt-get':
        if (($args[0] ?? '') === 'update' && is_file(fake_state('apt-update-fails'))) {
            fwrite(STDERR, "E: Failed to fetch http://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease (stand-in)\n");
            exit(100);
        }
        if (in_array('install', $args, true)) {
            $packages = array_values(array_filter($args, fn (string $a): bool => $a !== 'install' && ! str_starts_with($a, '-')));
            foreach ($packages as $package) {
                if (is_file(fake_state('apt-broken-'.$package))) {
                    fwrite(STDERR, "E: Unable to correct problems, you have held broken packages. ({$package})\n");
                    exit(100);
                }
            }
            foreach ($packages as $package) {
                if ($package === 'php8.5-ev' && ! is_file(fake_state('apt-ev-not-loaded'))) {
                    file_put_contents(fake_state('loop-ext'), "ev\n");
                }
                if ($package === 'php8.5-event') {
                    file_put_contents(fake_state('loop-ext'), "event\n");
                }
                echo "Setting up {$package} (stand-in) ...\n";
            }
        }
        exit(0);

    case 'apt-cache':
        $package = $args[1] ?? '';
        $available = is_file(fake_state('apt-available'))
            ? preg_split('/\s+/', trim((string) file_get_contents(fake_state('apt-available')))) ?: []
            : [];
        echo "{$package}:\n  Installed: (none)\n  Candidate: ".(in_array($package, $available, true) ? '1.2.1-1+ubuntu24.04.1+deb.sury.org+1' : '(none)')."\n";
        exit(0);

    case 'pecl':
        if (($args[0] ?? '') === 'list') {
            echo is_file(fake_state('pecl-ev'))
                ? "Installed packages, channel pecl.php.net:\n=========================================\nPackage Version State\nev      1.2.1   stable\n"
                : "(no packages installed from channel pecl.php.net)\n";
            exit(0);
        }
        if (in_array('install', $args, true)) {
            stream_get_contents(STDIN);
            if (is_file(fake_state('pecl-fails'))) {
                echo "ERROR: `make' failed\n";
                exit(1);
            }
            touch(fake_state('pecl-ev'));
            echo "Build process completed successfully\n";
        }
        exit(0);

    case 'phpenmod':
        if (is_file(fake_state('pecl-ev')) && is_file(fake_root().'/etc/php/8.5/mods-available/ev.ini') && ! is_file(fake_state('ev-will-not-load'))) {
            file_put_contents(fake_state('loop-ext'), "ev\n");
        }
        exit(0);

    case 'nginx':
        if (in_array('-t', $args, true)) {
            if (is_file(fake_state('nginx-t-fails'))) {
                fwrite(STDERR, "nginx: [emerg] unexpected end of file (stand-in)\nnginx: configuration file /etc/nginx/nginx.conf test failed\n");
                exit(1);
            }
            fwrite(STDERR, "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: configuration file /etc/nginx/nginx.conf test is successful\n");
        }
        exit(0);

    case 'systemctl':
        if (($args[0] ?? '') === 'is-active') {
            echo str_repeat("active\n", max(1, count($args) - 1));
        }
        exit(0);

    case 'curl':
        if (in_array('-w', $args, true)) {
            echo is_file(fake_state('up-code')) ? trim((string) file_get_contents(fake_state('up-code'))) : '200';
            exit(0);
        }
        if (in_array('-sSI', $args, true)) {
            echo "HTTP/2 200\r\nserver: nginx\r\nstrict-transport-security: max-age=31536000\r\nx-content-type-options: nosniff\r\nx-frame-options: SAMEORIGIN\r\n\r\n";
            exit(0);
        }
        echo "<!doctype html><title>Not Found</title>\n";
        exit(0);

    case 'tar':
        $from = $args[1] ?? '';
        $into = $args[3] ?? '';
        if (! is_dir($from) || ! is_dir($into)) {
            fwrite(STDERR, "tar (stand-in): {$from} -> {$into}: not a directory\n");
            exit(2);
        }
        fake_copy_tree($from, $into);
        exit(0);

    case 'mysql':
        echo "0\n";
        exit(0);

    default:
        exit(0);
}
