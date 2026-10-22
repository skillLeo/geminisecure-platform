<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\SkippedWithMessageException;

/**
 * The server states the live deploy tests start from, built in a DeploySandbox.
 */
final class LiveServer
{
    public const OLD = '20260920-100000-aaaaaaa';

    public const NEW = '20260925-100000-bbbbbbb';

    public const REVERB_PID = 4100;

    public const RELAY_PID = 4101;

    /** The program file §9.4 of the design gives, byte for byte. */
    public const RELAY_PROGRAM = <<<'CONF'
        [program:gs-live-relay]
        command=php /var/www/geminisecure/current/artisan live:relay --max-time=3600
        directory=/var/www/geminisecure/current
        autostart=true
        autorestart=true
        stopasgroup=true
        killasgroup=true
        stopwaitsecs=15
        user=geminisecure
        numprocs=1
        redirect_stderr=true
        stdout_logfile=/var/log/geminisecure/live-relay.log
        stdout_logfile_maxbytes=20MB
        stdout_logfile_backups=5

        CONF;

    /** A sandbox, or the test is skipped (saying why) on a machine with no bash. */
    public static function sandbox(): DeploySandbox
    {
        return DeploySandbox::create()
            ?? throw new SkippedWithMessageException('No bash on this machine (set GS_TEST_BASH to one), so the deploy scripts cannot run here.');
    }

    /** A script in deploy/, as the sandbox's bash names it. */
    public static function script(string $name = '80-live.sh'): string
    {
        return DeploySandbox::shPath((string) realpath(DeploySandbox::REPO.'/deploy/'.$name));
    }

    /**
     * What 80-live.sh keeps when it starts Reverb, stated independently of the script: the
     * process, and one SHA-256 per REVERB_* value, the first definition winning and one pair of
     * surrounding quotes not being part of the value.
     *
     * @param  list<string>  $env
     */
    public static function reverbRecord(array $env, int $pid, string $boot = DeploySandbox::BOOT_ID): string
    {
        $hashes = [];
        foreach ($env as $line) {
            if (preg_match('/^(REVERB_[A-Z0-9_]*)=(.*)$/', $line, $m) !== 1 || isset($hashes[$m[1]])) {
                continue;
            }
            $value = trim($m[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $hashes[$m[1]] = hash('sha256', $value);
        }
        ksort($hashes);
        $record = "# Written by deploy/80-live.sh each time it starts gs-reverb: that process, and one SHA-256\n"
            ."# per REVERB_* value in .env at that moment. Never a value.\n"
            ."process {$boot} {$pid}\n";
        foreach ($hashes as $key => $hash) {
            $record .= "{$key} {$hash}\n";
        }

        return $record;
    }

    /**
     * A server the way a previous `80-live.sh --on-deploy` left it: release OLD is current,
     * Reverb and the relay run from it with ev loaded, the relay's program file is installed and
     * applied, and Reverb's start is on record. (LiveOnDeployTest proves a real first run leaves
     * exactly this.)
     */
    public static function deployed(bool $relay = true): DeploySandbox
    {
        $sb = self::sandbox();
        $sb->release(self::OLD, relay: $relay);
        $sb->current(self::OLD);
        $sb->env(DeploySandbox::baseEnv());
        $sb->stateFile('loop-ext', "ev\n");
        $sb->program('gs-reverb', 'RUNNING', self::REVERB_PID, self::OLD);
        $sb->program('gs-queue-panic:gs-queue-panic_00');
        $sb->program('gs-queue-default:gs-queue-default_00');
        $sb->put('var/lib/geminisecure/live/reverb-started', self::reverbRecord(DeploySandbox::baseEnv(), self::REVERB_PID));
        if ($relay) {
            $sb->put('etc/supervisor/conf.d/gs-live-relay.conf', self::RELAY_PROGRAM);
            $sb->stateFile('supervisor/.conf-gs-live-relay', (string) sha1(self::RELAY_PROGRAM));
            $sb->program('gs-live-relay', 'RUNNING', self::RELAY_PID, self::OLD);
        }

        return $sb;
    }

    /**
     * A server as it stands before 80-live.sh has ever run: stock nginx.conf, no event-loop
     * extension, no live keys in .env, no relay program; Reverb runs on StreamSelectLoop.
     */
    public static function beforeLive(): DeploySandbox
    {
        $sb = self::sandbox();
        $sb->release(self::OLD);
        $sb->current(self::OLD);
        $sb->env(DeploySandbox::baseEnv());
        $sb->put('etc/nginx/nginx.conf', $sb->stockNginxConf());
        $sb->stateFile('apt-available', "php8.5-ev\n");
        $sb->program('gs-reverb', 'RUNNING', self::REVERB_PID, self::OLD, loaded: []);
        $sb->program('gs-queue-panic:gs-queue-panic_00');
        $sb->program('gs-queue-default:gs-queue-default_00');

        return $sb;
    }

    public static function envValue(DeploySandbox $sb, string $key): ?string
    {
        foreach (explode("\n", (string) $sb->file('var/www/geminisecure/shared/.env')) as $line) {
            if (str_starts_with($line, $key.'=')) {
                return substr($line, strlen($key) + 1);
            }
        }

        return null;
    }

    /** The release `current` points at, by name. */
    public static function current(DeploySandbox $sb): ?string
    {
        $target = DeploySandbox::linkTarget($sb->root.'/var/www/geminisecure/current');

        return $target === null ? null : basename(str_replace('\\', '/', $target));
    }
}
