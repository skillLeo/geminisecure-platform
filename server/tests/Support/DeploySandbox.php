<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A scratch directory that stands in for the production server's `/`, for running the real
 * scripts in deploy/ without a server.
 *
 * WHAT IS REAL: bash, the scripts themselves, awk, sed, grep, cmp, ls, mv, rm and PHP (every
 * `php -r` the scripts run is the real interpreter reading the real fixture files).
 *
 * WHAT IS A STAND-IN, and says so in the log it writes: apt-get, apt-cache, pecl, phpenmod,
 * nginx, systemctl, supervisorctl, sudo, chmod, chown, usermod, composer, curl, tar, mysql, ss,
 * sleep and `ln` (see tests/Fixtures/deploy/fakes/). Each appends one line per call to the log,
 * so a test can say exactly what a run did and did not do. Supervisor keeps its programs, their
 * states and pids in state/supervisor/, and a program it (re)starts gets a /proc/<pid>/cwd link
 * to the release `current` points at, the way the kernel resolves supervisor's `directory=`.
 *
 * WHAT IS NOT PROVED HERE: that apt, pecl, nginx and supervisor behave the way the stand-ins
 * do. That is the part only a real Ubuntu 24.04 machine can show (docs/DEPLOY.md, "Live
 * updates", lists it).
 *
 * On Windows the bash is Git's, and links are directory junctions, which need no privilege.
 */
final class DeploySandbox
{
    /** The main tree's scripts, never modified: a test copies what it runs. */
    public const REPO = __DIR__.'/../..';

    public const FAKES = __DIR__.'/../Fixtures/deploy/fakes';

    public const BOOT_ID = '5f0c3a9e-8d1b-4c2a-9e7f-000000000001';

    private static ?string $bash = null;

    private static bool $bashSearched = false;

    /** Whether the bash found is Git for Windows' sh.exe, which must be told it is bash. */
    private static bool $bashIsSh = false;

    public readonly string $dir;

    /** The stand-in for `/`, as PHP sees it. */
    public readonly string $root;

    /** The same directory as bash sees it: /tmp/... under Git for Windows, itself elsewhere. */
    public readonly string $shRoot;

    public readonly string $state;

    public readonly string $log;

    private readonly string $bin;

    private int $clock = 1_900_000_000;

    private function __construct(string $dir)
    {
        $this->dir = $dir;
        $this->root = $dir.'/root';
        $this->state = $dir.'/state';
        $this->bin = $dir.'/bin';
        $this->log = $dir.'/calls.log';

        foreach ([
            'etc/nginx', 'etc/supervisor/conf.d', 'etc/php/8.5/mods-available', 'etc/geminisecure',
            'var/log/geminisecure', 'var/lib/geminisecure', 'var/www/geminisecure/releases',
            'var/www/geminisecure/shared/storage', 'proc/sys/kernel/random', 'opt/gs-deploy',
        ] as $path) {
            self::mkdir($this->root.'/'.$path);
        }
        self::mkdir($this->state.'/supervisor');
        self::mkdir($this->bin);
        file_put_contents($this->root.'/proc/sys/kernel/random/boot_id', self::BOOT_ID."\n");
        file_put_contents($this->log, '');
        $this->writeFakes();

        $this->shRoot = $this->shPath($this->root);
    }

    /** A new sandbox, or null when this machine has no bash to run the scripts with. */
    public static function create(): ?self
    {
        if (self::bash() === null) {
            return null;
        }
        $temp = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
        self::shPath($temp);
        $dir = $temp.'/gs-deploy-'.bin2hex(random_bytes(5));
        self::mkdir($dir);

        return new self($dir);
    }

    public static function bash(): ?string
    {
        if (self::$bashSearched) {
            return self::$bash;
        }
        self::$bashSearched = true;

        $candidates = [];
        $env = getenv('GS_TEST_BASH');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $onPath = (new ExecutableFinder)->find('bash');
        if ($onPath !== null) {
            $candidates[] = $onPath;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // Git for Windows keeps bash beside git; MinGit ships it as usr/bin/sh.exe only.
            $git = (new ExecutableFinder)->find('git');
            if ($git !== null) {
                $gitRoot = dirname($git, 2);
                $candidates[] = $gitRoot.'/bin/bash.exe';
                $candidates[] = $gitRoot.'/usr/bin/bash.exe';
                $candidates[] = $gitRoot.'/usr/bin/sh.exe';
            }
            $candidates[] = 'C:/Program Files/Git/bin/bash.exe';
        }

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }
            // Windows' own bash.exe is WSL's launcher, and with no distribution it runs nothing.
            $probe = new Process([$candidate, '-c', 'echo "$BASH_VERSION"']);
            $probe->setTimeout(20);
            $probe->run();
            $version = trim($probe->getOutput());
            if ($probe->getExitCode() === 0 && preg_match('/^([4-9]|[1-9][0-9])\./', $version) === 1) {
                self::$bash = str_replace('\\', '/', $candidate);
                self::$bashIsSh = strtolower(basename($candidate)) === 'sh.exe';

                return self::$bash;
            }
        }

        return null;
    }

    public static function shellcheck(): ?string
    {
        $env = getenv('SHELLCHECK');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }

        return (new ExecutableFinder)->find('shellcheck');
    }

    public function __destruct()
    {
        if (getenv('GS_KEEP_SANDBOX') === false) {
            self::remove($this->dir);
        }
    }

    // ------------------------------------------------------------------------------------
    // The server's contents
    // ------------------------------------------------------------------------------------

    /**
     * A release directory, as deploy.sh leaves one. Each release is newer than the last one made.
     *
     * @param  array<string, string|null>  $lock  package name => version, over the base lock
     * @param  array<string, array{string, string}>  $lockExact  package name => [version, reference]
     * @param  array<string, string>  $devLock  packages-dev name => version
     * @param  array<string, string|null>  $files  extra files (relative path => contents; null removes)
     */
    public function release(
        string $name,
        bool $relay = true,
        array $lock = [],
        array $lockExact = [],
        array $devLock = [],
        string $reverbConfig = self::REVERB_CONFIG,
        array $files = [],
    ): string {
        $dir = $this->root.'/var/www/geminisecure/releases/'.$name;
        $this->fillRelease($dir, $relay, $lock, $lockExact, $devLock, $reverbConfig, $files);
        touch($dir, $this->clock += 60);

        return $dir;
    }

    /**
     * The same contents as an artifact, for deploy.sh to "unpack" (the tar stand-in copies it).
     *
     * @param  array<string, string|null>  $lock
     * @param  array<string, string|null>  $files
     */
    public function artifact(string $sha, bool $relay = true, array $lock = [], string $reverbConfig = self::REVERB_CONFIG, array $files = []): string
    {
        $dir = $this->dir.'/artifacts/'.$sha;
        $this->fillRelease($dir, $relay, $lock, [], [], $reverbConfig, $files + ['REVISION' => $sha]);

        return $this->shPath($dir);
    }

    /**
     * @param  array<string, string|null>  $lock
     * @param  array<string, array{string, string}>  $lockExact
     * @param  array<string, string>  $devLock
     * @param  array<string, string|null>  $files
     */
    private function fillRelease(string $dir, bool $relay, array $lock, array $lockExact, array $devLock, string $reverbConfig, array $files): void
    {
        foreach (['app/Live/Relay', 'config', 'vendor', 'public', 'bootstrap/cache', 'storage'] as $sub) {
            self::mkdir($dir.'/'.$sub);
        }
        copy(self::FAKES.'/artisan.php', $dir.'/artisan');
        copy(self::FAKES.'/autoload.php', $dir.'/vendor/autoload.php');
        file_put_contents($dir.'/composer.lock', self::composerLock($lock, $lockExact, $devLock));
        file_put_contents($dir.'/config/reverb.php', $reverbConfig);
        file_put_contents($dir.'/config/app.php', "<?php return ['name' => 'GeminiSecure'];\n");
        file_put_contents($dir.'/public/index.php', "<?php\n");
        if ($relay) {
            file_put_contents($dir.'/app/Live/Relay/Relay.php', "<?php\n\nnamespace App\\Live\\Relay;\n\nfinal class Relay {}\n");
        }
        foreach ($files as $path => $contents) {
            if ($contents === null) {
                @unlink($dir.'/'.$path);

                continue;
            }
            self::mkdir(dirname($dir.'/'.$path));
            file_put_contents($dir.'/'.$path, $contents);
        }
    }

    public const REVERB_CONFIG = "<?php\n\nreturn ['apps' => ['apps' => [['key' => env('REVERB_APP_KEY'), 'accept_client_events_from' => 'none']]]];\n";

    /**
     * @param  array<string, string|null>  $versions  name => version over the base (null: absent)
     * @param  array<string, array{string, string}>  $exact  name => [version, commit]
     * @param  array<string, string>  $dev
     */
    public static function composerLock(array $versions = [], array $exact = [], array $dev = []): string
    {
        $base = [
            'laravel/framework' => 'v13.2.0',
            'laravel/reverb' => 'v1.11.1',
            'pusher/pusher-php-server' => '7.3.0',
            'ratchet/rfc6455' => 'v0.4.1',
            'react/event-loop' => 'v1.6.0',
            'react/socket' => 'v1.17.0',
            'symfony/http-foundation' => 'v8.0.1',
        ];
        $packages = [];
        foreach (array_merge($base, $versions) as $name => $version) {
            if ($version === null) {
                continue;
            }
            [$version, $reference] = $exact[$name] ?? [$version, sha1($name.'@'.$version)];
            $packages[] = [
                'name' => $name,
                'version' => $version,
                'source' => ['type' => 'git', 'reference' => $reference],
                'dist' => ['type' => 'zip', 'reference' => $reference],
            ];
        }
        $devPackages = [];
        foreach (array_merge(['react/child-process' => 'v0.6.6', 'pestphp/pest' => 'v4.1.0'], $dev) as $name => $version) {
            $devPackages[] = ['name' => $name, 'version' => $version, 'dist' => ['reference' => sha1($name.'@'.$version)]];
        }

        return json_encode(['packages' => $packages, 'packages-dev' => $devPackages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** Make a release the newest by modification time, the order deploy.sh prunes by. */
    public function makeNewest(string $release): void
    {
        touch($this->releasePath($release), $this->clock += 60);
    }

    /** Point `current` at a release, the way deploy.sh swings it. */
    public function current(string $release): void
    {
        $link = $this->root.'/var/www/geminisecure/current';
        self::unlink($link);
        self::link($link, $this->root.'/var/www/geminisecure/releases/'.$release);
    }

    public function releasePath(string $release): string
    {
        return $this->root.'/var/www/geminisecure/releases/'.$release;
    }

    /** @param  list<string>  $lines */
    public function env(array $lines): void
    {
        file_put_contents($this->envPath(), implode("\n", $lines)."\n");
    }

    public function envPath(): string
    {
        return $this->root.'/var/www/geminisecure/shared/.env';
    }

    public const SECRET = 'reverb-secret-9f8e7d6c5b4a39281706f5e4d3c2b1a0';

    /** @return list<string> */
    public static function baseEnv(): array
    {
        return [
            'APP_NAME=GeminiSecure',
            'APP_ENV=production',
            'APP_KEY=base64:c2FuZGJveC1rZXktbm90LWEtcmVhbC1rZXktYXQtYWxsLTAwMDA=',
            'ESTATE_DOMAIN=geminisl.com',
            'REVERB_APP_ID=482913',
            'REVERB_APP_KEY=4f1d9c0e7b2a6d8e3f5a1c9b7e2d4f60',
            'REVERB_APP_SECRET='.self::SECRET,
            'REVERB_HOST=geminisl.com',
            'REVERB_PORT=443',
            'REVERB_SCHEME=https',
            'REVERB_ALLOWED_ORIGINS=geminisl.com,*.geminisl.com',
            'VITE_REVERB_HOST="${REVERB_HOST}"',
        ];
    }

    /**
     * A supervisor program, in a state, with (for a running one) a pid whose /proc/<pid>/cwd is
     * the given release and whose maps list the given shared objects.
     *
     * @param  list<string>  $loaded
     */
    public function program(string $name, string $state = 'RUNNING', ?int $pid = null, ?string $release = null, array $loaded = ['ev.so']): void
    {
        $pid = $state === 'RUNNING' ? ($pid ?? $this->nextPid()) : 0;
        file_put_contents($this->state.'/supervisor/'.str_replace(':', '~', $name), "{$state} {$pid}\n");
        if ($pid > 0 && $release !== null) {
            $this->process($pid, $this->releasePath($release), $loaded);
        }
    }

    /** @param  list<string>  $loaded */
    public function process(int $pid, string $cwd, array $loaded = ['ev.so']): void
    {
        $dir = $this->root.'/proc/'.$pid;
        self::mkdir($dir);
        self::unlink($dir.'/cwd');
        self::link($dir.'/cwd', $cwd);
        $maps = "5581d4a00000-5581d4a2c000 r--p 00000000 08:01 1310730                    /usr/bin/php8.5\n";
        foreach ($loaded as $so) {
            $maps .= "7f3a2b400000-7f3a2b41c000 r--p 00000000 08:01 1312001                    /usr/lib/php/20250925/{$so}\n";
        }
        file_put_contents($dir.'/maps', $maps);
    }

    public function programState(string $name): ?string
    {
        $file = $this->state.'/supervisor/'.str_replace(':', '~', $name);

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    public function pidOf(string $name): int
    {
        $state = $this->programState($name);

        return $state === null ? 0 : (int) explode(' ', $state)[1];
    }

    /** The release a running program's /proc/<pid>/cwd resolves to, by name. */
    public function cwdOf(string $name): ?string
    {
        $pid = $this->pidOf($name);
        $target = $pid > 0 ? self::linkTarget($this->root.'/proc/'.$pid.'/cwd') : null;

        return $target === null ? null : basename(str_replace('\\', '/', $target));
    }

    public function stateFile(string $name, string $contents): void
    {
        file_put_contents($this->state.'/'.$name, $contents);
    }

    public function removeStateFile(string $name): void
    {
        @unlink($this->state.'/'.$name);
    }

    /** @param  list<string>  $commands */
    public function artisanCommands(array $commands): void
    {
        $this->stateFile('artisan-commands', implode("\n", $commands)."\n");
    }

    /** Make `php artisan <command>` fail the first $times calls (PHP_INT_MAX: always). */
    public function artisanFails(string $command, int $times = PHP_INT_MAX): void
    {
        $this->stateFile('artisan-fail-'.str_replace(':', '~', $command), (string) $times);
    }

    public function stockNginxConf(): string
    {
        return (string) file_get_contents(self::FAKES.'/../nginx.conf.ubuntu-24.04');
    }

    public function file(string $path): ?string
    {
        $full = $this->root.'/'.ltrim($path, '/');

        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    public function put(string $path, string $contents): void
    {
        $full = $this->root.'/'.ltrim($path, '/');
        self::mkdir(dirname($full));
        file_put_contents($full, $contents);
    }

    /**
     * Every file under the stand-in for /, with a hash of its contents and its mtime, for
     * proving that a run changed nothing. /proc is the kernel's, not a file anybody writes.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        $files = [];
        $walk = function (string $dir, string $rel) use (&$walk, &$files): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || ($rel === '' && $entry === 'proc')) {
                    continue;
                }
                $path = $dir.'/'.$entry;
                $key = ltrim($rel.'/'.$entry, '/');
                $target = self::linkTarget($path);
                if ($target !== null) {
                    $files[$key] = 'link -> '.basename(str_replace('\\', '/', $target));
                } elseif (is_dir($path)) {
                    $walk($path, $key);
                } else {
                    clearstatcache(true, $path);
                    $files[$key] = sha1_file($path).' '.filemtime($path);
                }
            }
        };
        $walk($this->root, '');
        ksort($files);

        return $files;
    }

    // ------------------------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------------------------

    /**
     * Run a script with bash, as root would, with the stand-ins first on PATH.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    public function run(string $script, array $args = [], array $env = []): DeployRun
    {
        $bash = self::bash() ?? throw new RuntimeException('no bash');
        self::waitForRoomToFork();
        $command = self::$bashIsSh
            // Invoked as `sh`, bash starts in POSIX mode; the scripts are bash scripts.
            ? [$bash, '-c', 'exec -a bash "$BASH" "$@"', 'bash', $script, ...$args]
            : [$bash, $script, ...$args];

        // The stand-ins first, then bash's own directory: Git for Windows keeps coreutils there.
        $path = implode(PATH_SEPARATOR, [
            str_replace('/', DIRECTORY_SEPARATOR, $this->bin),
            str_replace('/', DIRECTORY_SEPARATOR, dirname($bash)),
            getenv('PATH') ?: '',
        ]);
        $process = new Process($command, $this->dir, $env + [
            'PATH' => $path,
            'GS_LIVE_TEST_ROOT' => $this->shRoot,
            'GS_FAKE_ROOT' => $this->root,
            'GS_FAKE_STATE' => $this->state,
            'GS_FAKE_LOG' => $this->log,
            'GS_FAKE_PHP' => PHP_BINARY,
            'LC_ALL' => 'C',
        ]);
        $process->setTimeout(300);
        $output = '';
        $process->run(function (string $type, string $buffer) use (&$output): void {
            $output .= $buffer;
        });

        $calls = array_values(array_filter(explode("\n", (string) file_get_contents($this->log)), fn (string $l): bool => $l !== ''));
        file_put_contents($this->log, '');

        // Git Bash could not fork a child: the machine ran out of memory under the run. That is a fact about the
        // laptop, never about deploy/, and it must not read as a deploy failure (M21 §3.3).
        if (preg_match(self::FORK_FAILED, $output) === 1) {
            throw new RuntimeException('Git Bash could not fork a process (the machine was out of memory during the run: "'
                .trim((string) (preg_match(self::FORK_FAILED, $output, $m) === 1 ? $m[0] : '')).'"). This says nothing about deploy/; '
                .'run the file again once the machine is idle — never alongside an APK build.');
        }

        return new DeployRun((int) $process->getExitCode(), $output, $calls);
    }

    /** What Git for Windows' bash prints when a fork fails for want of memory (seen 28 Sep: "cygheap read copy failed"). */
    private const FORK_FAILED = '/child_copy: cygheap read copy failed|fork: Resource temporarily unavailable|fork: retry: (No child processes|Resource temporarily unavailable)|couldn\'t allocate heap/';

    /** Commit headroom, in KB, below which bash's forks start failing on Windows: two gigabytes. */
    private const FORK_ROOM_KB = 2 * 1024 * 1024;

    private static float $roomCheckedAt = 0.0;

    /**
     * WAIT FOR ROOM BEFORE BASH FORKS (M21 §3.3). On Windows, Git Bash's fork copies the parent's heap into the child;
     * when the machine's commit charge is nearly spent — a Gradle build holds four gigabytes — the copy fails and the
     * script dies mid-run with exit 127. So on Windows a run waits, up to half an hour, until two gigabytes can be
     * committed. It is asked at most every twenty seconds, and never on Linux, where this does not happen.
     */
    private static function waitForRoomToFork(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || microtime(true) - self::$roomCheckedAt < 20) {
            return;
        }

        $deadline = microtime(true) + 1800;

        do {
            $probe = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', '(Get-CimInstance Win32_OperatingSystem).FreeVirtualMemory']);
            $probe->setTimeout(60);
            $probe->run();
            $free = (int) trim($probe->getOutput());
            self::$roomCheckedAt = microtime(true);

            // Unreadable (0): go ahead, as before; the fork-failure sentence above still names the cause if it fails.
            if ($free === 0 || $free >= self::FORK_ROOM_KB) {
                return;
            }

            fwrite(STDERR, sprintf("DeploySandbox: waiting for memory before running bash (%.1f GB committable, 2 GB wanted)\n", $free / 1048576));
            sleep(15);
        } while (microtime(true) < $deadline);
    }

    /**
     * Copy a script from deploy/ into the sandbox, with its absolute paths moved under the
     * stand-in root. Every replacement must match, so a script that moves on fails loudly here.
     *
     * @param  array<string, string>  $replacements  exact text => text with {root} for the root
     */
    public function installScript(string $name, array $replacements = [], ?string $as = null): string
    {
        $text = (string) file_get_contents(self::REPO.'/deploy/'.$name);
        foreach ($replacements as $from => $to) {
            if (! str_contains($text, $from)) {
                throw new RuntimeException("deploy/{$name} no longer contains `{$from}`; update the test's path map.");
            }
            $text = str_replace($from, str_replace('{root}', $this->shRoot, $to), $text);
        }
        $target = $this->root.'/opt/gs-deploy/'.($as ?? $name);
        file_put_contents($target, $text);

        return $this->shPath($target);
    }

    /** @var array<string, string> Windows directory => the name bash gives it */
    private static array $shNames = [];

    /**
     * A path as bash names it. Git for Windows maps names lexically through its mount table
     * (C:/Users/x/AppData/Local/Temp is /tmp), so a directory asked about once answers for
     * everything below it without asking bash again.
     */
    public static function shPath(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $path;
        }
        $path = rtrim(str_replace('\\', '/', $path), '/');
        foreach (self::$shNames as $win => $sh) {
            if (strcasecmp($path, $win) === 0) {
                return $sh;
            }
            if (strncasecmp($path, $win.'/', strlen($win) + 1) === 0) {
                return $sh.substr($path, strlen($win));
            }
        }
        if (! is_dir($path)) {
            return self::shPath(dirname($path)).'/'.basename($path);
        }
        $bash = self::bash() ?? throw new RuntimeException('no bash');
        $probe = new Process([$bash, '-c', 'cd "$1" && pwd', 'sh', $path]);
        $probe->run();
        $answer = trim($probe->getOutput());
        if ($probe->getExitCode() !== 0 || $answer === '') {
            throw new RuntimeException("bash cannot name {$path}: ".$probe->getErrorOutput());
        }
        self::$shNames[$path] = $answer;

        return $answer;
    }

    // ------------------------------------------------------------------------------------

    private function nextPid(): int
    {
        $file = $this->state.'/supervisor/.next-pid';
        $pid = is_file($file) ? (int) file_get_contents($file) : 4200;
        file_put_contents($file, (string) ($pid + 1));

        return $pid;
    }

    private function writeFakes(): void
    {
        $php = $this->shPath(PHP_BINARY) ?: PHP_BINARY;
        $fakes = $this->shPath(realpath(self::FAKES) ?: self::FAKES);
        $viaPhp = static fn (string $script, string $name): string => "#!/bin/sh\nexec \"{$php}\" \"{$fakes}/{$script}\" {$name} \"\$@\"\n";

        $bashLine = self::$bashIsSh ? '#!/bin/sh' : '#!'.self::$bash;
        $scripts = [
            // `avail` and `pid` are most of the calls, and answered here without starting PHP;
            // everything else is supervisorctl.php.
            'supervisorctl' => <<<SH
                {$bashLine}
                dir="\$GS_FAKE_STATE/supervisor"
                if [ "\$1" = avail ] || [ "\$1" = pid ]; then
                  echo "supervisorctl \$*" >> "\$GS_FAKE_LOG"
                  if [ "\$1" = avail ]; then
                    for f in "\$dir"/*; do
                      [ -f "\$f" ] || continue
                      name="\${f##*/}"
                      printf '%-40s in use    auto      999:999\\n' "\${name//\\~/:}"
                    done
                    exit 0
                  fi
                  file="\$dir/\${2//:/\\~}"
                  if [ ! -f "\$file" ]; then echo "No such process \$2"; exit 1; fi
                  read -r state pid < "\$file"
                  echo "\$pid"
                  [ "\$pid" != 0 ] || exit 3
                  exit 0
                fi
                exec "{$php}" "{$fakes}/supervisorctl.php" supervisorctl "\$@"

                SH,
            'ln' => $viaPhp('ln.php', 'ln'),
        ];
        foreach (['apt-get', 'apt-cache', 'pecl', 'phpenmod', 'nginx', 'systemctl', 'curl', 'composer', 'mysql', 'ss', 'usermod', 'tar'] as $name) {
            $scripts[$name] = $viaPhp('command.php', $name);
        }
        foreach (['chmod', 'chown', 'sleep'] as $name) {
            $scripts[$name] = "#!/bin/sh\necho \"{$name} \$*\" >> \"\$GS_FAKE_LOG\"\nexit 0\n";
        }
        // sudo -u <user> [VAR=value ...] command ...: logged, then run as whoever runs the test.
        $scripts['sudo'] = <<<'SH'
            #!/bin/sh
            printf 'sudo %s\n' "$(printf '%s' "$*" | tr '\n' ' ')" >> "$GS_FAKE_LOG"
            while [ $# -gt 0 ]; do
              case "$1" in
                -u) shift 2 ;;
                -*) shift ;;
                *) break ;;
              esac
            done
            exec env "$@"

            SH;
        // The real PHP, except that `php -m` also lists the event-loop extension the stand-in
        // apt or pecl "installed" (state/loop-ext).
        $scripts['php'] = <<<SH
            #!/bin/sh
            if [ "\$1" = "-m" ]; then
              "{$php}" -m
              if [ -f "\$GS_FAKE_STATE/loop-ext" ]; then cat "\$GS_FAKE_STATE/loop-ext"; fi
              exit 0
            fi
            exec "{$php}" "\$@"

            SH;
        if (self::$bashIsSh) {
            $scripts['bash'] = "#!/bin/sh\nexec -a bash /usr/bin/sh \"\$@\"\n";
        }

        foreach ($scripts as $name => $body) {
            file_put_contents($this->bin.'/'.$name, $body);
            chmod($this->bin.'/'.$name, 0755);
        }
    }

    public static function mkdir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
    }

    /** A directory link: a junction on Windows (no privilege needed), a symlink elsewhere. */
    public static function link(string $link, string $target): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $win = static fn (string $p): string => str_replace('/', '\\', $p);
            exec('cmd /c mklink /J '.escapeshellarg($win($link)).' '.escapeshellarg($win($target)).' >NUL 2>&1', $out, $rc);
            if ($rc !== 0) {
                throw new RuntimeException("mklink /J {$link} {$target} failed");
            }

            return;
        }
        if (! symlink($target, $link)) {
            throw new RuntimeException("symlink {$link} {$target} failed");
        }
    }

    /** Where a link points, or null for anything that is not a link. */
    public static function linkTarget(string $path): ?string
    {
        $target = @readlink($path);
        if (! is_string($target) || $target === '') {
            return null;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return $target;
        }
        // On Windows readlink() answers for anything, with the final path of what it names: for
        // a plain file or directory that is its own path, for a junction the junction's target.
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return $target;
        }
        $self = rtrim($parent, '\\/').'\\'.basename($path);

        return strcasecmp(str_replace('/', '\\', $target), $self) === 0 ? null : $target;
    }

    /** Remove a link without following it, or a file. */
    public static function unlink(string $path): void
    {
        if (self::linkTarget($path) !== null) {
            if (PHP_OS_FAMILY === 'Windows') {
                @rmdir($path) || @unlink($path);
            } else {
                @unlink($path);
            }
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    /** Remove a tree, never following a link out of it. */
    public static function remove(string $path): void
    {
        if (self::linkTarget($path) !== null) {
            self::unlink($path);

            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path.'/'.$entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
