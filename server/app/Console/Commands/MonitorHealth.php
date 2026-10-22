<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE HEALTH PROBE THE CRON RUNS (M16 §6) — the operational watches, beside the panic
 * synthetic monitor (which watches the life-safety path).
 *
 *   php artisan monitor:health
 *
 * Checks queue depth, a stalled queue, failed jobs, disk headroom, certificate expiry and
 * the age of the last backup, and exits non-zero + logs `error` if any HARD check breaches.
 * A monitoring wrapper (a cron-mailer, or an uptime service watching the exit code) turns
 * that into a page. See MONITORING.md for where the alert goes.
 *
 * EACH CHECK DEGRADES GRACEFULLY. The database checks run anywhere; the disk, certificate
 * and backup checks read files that exist on the server and are SKIPPED (not failed) when
 * their input is absent, so the same command runs locally and on the VM. A skipped check is
 * printed as `skip`, never counted as a pass or a failure.
 *
 * Single line per check, machine-readable:
 *   health check=queue_depth status=OK value=3 threshold=500
 *   health OVERALL status=OK checks=6 failed=0 skipped=2
 */
class MonitorHealth extends Command
{
    protected $signature = 'monitor:health
        {--queue-depth=500 : fail if more than this many jobs are waiting}
        {--stall-minutes=15 : fail if the oldest waiting job is older than this}
        {--failed-jobs=1 : fail if at least this many jobs are in failed_jobs}
        {--disk-min-percent=15 : fail if less than this % of the disk is free}
        {--disk-path= : the path to check disk on (default: the app base path)}
        {--cert=/etc/letsencrypt/live/geminisl.com/cert.pem : the live certificate (skipped if absent)}
        {--cert-min-days=14 : fail if the certificate expires within this many days}
        {--backup-log=/var/log/geminisecure/backup.log : the backup log (skipped if absent)}
        {--backup-max-hours=26 : fail if the last backup success is older than this}';

    protected $description = 'Check queue, disk, certificate and backup health; exit non-zero and log on any breach (M16 §6).';

    /** @var list<string> */
    private array $failures = [];

    private int $skipped = 0;

    private int $checks = 0;

    public function handle(): int
    {
        $this->queueDepth();
        $this->stalledQueue();
        $this->failedJobs();
        $this->disk();
        $this->certificate();
        $this->backup();

        $failed = count($this->failures);
        $overall = sprintf('health OVERALL status=%s checks=%d failed=%d skipped=%d', $failed === 0 ? 'OK' : 'FAIL', $this->checks, $failed, $this->skipped);

        if ($failed > 0) {
            Log::error($overall, ['failures' => $this->failures]);
            $this->error($overall);

            return self::FAILURE;
        }

        $this->info($overall);

        return self::SUCCESS;
    }

    /** The DB connection the central `jobs` table lives on (the `central` queue points at it). */
    private function jobsConnection(): string
    {
        return (string) config('queue.connections.central.connection', config('database.default', 'mysql'));
    }

    private function queueDepth(): void
    {
        $limit = (int) $this->option('queue-depth');
        try {
            $depth = (int) DB::connection($this->jobsConnection())->table('jobs')->count();
            $this->record('queue_depth', $depth <= $limit, (string) $depth, (string) $limit);
        } catch (Throwable $e) {
            $this->skip('queue_depth', class_basename($e));
        }
    }

    private function stalledQueue(): void
    {
        $minutes = (int) $this->option('stall-minutes');
        try {
            $oldest = DB::connection($this->jobsConnection())->table('jobs')->min('available_at');

            if ($oldest === null) {
                $this->record('queue_stall', true, 'empty', $minutes.'m');

                return;
            }

            $ageMin = (int) floor((Carbon::now()->getTimestamp() - (int) $oldest) / 60);
            $this->record('queue_stall', $ageMin <= $minutes, $ageMin.'m', $minutes.'m');
        } catch (Throwable $e) {
            $this->skip('queue_stall', class_basename($e));
        }
    }

    private function failedJobs(): void
    {
        $limit = (int) $this->option('failed-jobs');
        try {
            $failedConn = (string) config('queue.failed.database', config('database.default', 'mysql'));
            $failed = (int) DB::connection($failedConn)->table('failed_jobs')->count();
            // OK while strictly fewer than the limit; the default limit of 1 means "any failure fails".
            $this->record('failed_jobs', $failed < $limit, (string) $failed, '<'.$limit);
        } catch (Throwable $e) {
            $this->skip('failed_jobs', class_basename($e));
        }
    }

    private function disk(): void
    {
        $path = (string) ($this->option('disk-path') ?: base_path());
        $min = (float) $this->option('disk-min-percent');

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            $this->skip('disk_free', 'unavailable');

            return;
        }

        $percent = round($free / $total * 100, 1);
        $this->record('disk_free', $percent >= $min, $percent.'%', $min.'%');
    }

    private function certificate(): void
    {
        $path = (string) $this->option('cert');
        $minDays = (int) $this->option('cert-min-days');

        if ($path === '' || ! is_file($path) || ! function_exists('openssl_x509_parse')) {
            $this->skip('cert_expiry', 'no cert here');

            return;
        }

        $pem = @file_get_contents($path);
        $parsed = $pem === false ? false : @openssl_x509_parse($pem);

        if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
            $this->skip('cert_expiry', 'unparseable');

            return;
        }

        $days = (int) floor(((int) $parsed['validTo_time_t'] - Carbon::now()->getTimestamp()) / 86400);
        $this->record('cert_expiry', $days >= $minDays, $days.'d', $minDays.'d');
    }

    private function backup(): void
    {
        $path = (string) $this->option('backup-log');
        $maxHours = (int) $this->option('backup-max-hours');

        if ($path === '' || ! is_file($path)) {
            $this->skip('backup_age', 'no log here');

            return;
        }

        $mtime = @filemtime($path);

        if ($mtime === false) {
            $this->skip('backup_age', 'unreadable');

            return;
        }

        $ageHours = (int) floor((Carbon::now()->getTimestamp() - $mtime) / 3600);
        $this->record('backup_age', $ageHours <= $maxHours, $ageHours.'h', $maxHours.'h');
    }

    private function record(string $check, bool $ok, string $value, string $threshold): void
    {
        $this->checks++;
        $line = sprintf('health check=%s status=%s value=%s threshold=%s', $check, $ok ? 'OK' : 'FAIL', $value, $threshold);

        if (! $ok) {
            $this->failures[] = $check;
            $this->error($line);

            return;
        }

        $this->line($line);
    }

    private function skip(string $check, string $why): void
    {
        $this->skipped++;
        $this->line(sprintf('health check=%s status=skip reason="%s"', $check, $why));
    }
}
