<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Api\IdempotencyStore;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan api:prune-idempotency [--dry-run]
 *
 * A retried write is answered, not repeated: the first attempt's response is kept
 * against the handset and the key it chose, and every retry is handed it back
 * (`App\Http\Middleware\Api\Idempotent`). One record per write, for every write the
 * mobile API has ever taken, and until this command existed there was no prune and
 * no time limit — the record and its answer were kept for the life of the platform.
 *
 * AN IDEMPOTENCY RECORD HAS TO OUTLIVE A RETRY, NOT A CUSTOMER. The window is
 * `config('api.idempotency_retention_days')`, thirty days by default, and
 * `App\Api\IdempotencyStore::retentionDays()` says why.
 *
 * THE ORDER MATTERS AND IT IS THE CENTRAL ROW FIRST. An idempotency record that
 * loses its answer but keeps its key is the dangerous half: the retry that arrives
 * next is refused rather than answered, which is safe but visible, and a record with
 * no answer and no key is simply a fresh act. So the key goes first and the answer
 * second; a crash between the two leaves an orphan answer in an estate, which the
 * next run sweeps.
 *
 * Safe to run as often as anybody likes, and safe to run twice at once: every delete
 * is bounded by the same cut-off.
 */
class PruneIdempotencyRecords extends Command
{
    protected $signature = 'api:prune-idempotency
        {--dry-run : count what would go, and delete nothing}
        {--clear-legacy : also forget the plaintext answers left in the central table by records written before answers moved into the estates}';

    protected $description = 'Delete idempotency records, and the answers kept in each estate, older than the retention window';

    /**
     * How old a pre-move record must be before `--clear-legacy` forgets its answer.
     *
     * A day, because the handset's outbox warns the person at twelve hours and the
     * retry that would still want this answer is long since sent. Anything younger
     * is left alone: clearing it would refuse a retry that is genuinely in flight.
     */
    private const LEGACY_GRACE_HOURS = 24;

    public function handle(): int
    {
        $days = IdempotencyStore::retentionDays();
        $before = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Retention: '.$days.' day(s) — records created before '.$before->toIso8601String().' (UTC).');

        if ($this->option('clear-legacy')) {
            $this->clearLegacyAnswers($dryRun);
        }

        $centralDue = IdempotencyStore::centralDue($before);

        if ($dryRun) {
            $this->line('  dry run — '.$centralDue.' central record(s) would be deleted');
        } else {
            $this->line('  central: deleted '.IdempotencyStore::pruneCentral($before).' record(s)');
        }

        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->warn('No estates provisioned — no stored answers to sweep.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($estates as $estate) {
            try {
                $estate->run(function () use ($estate, $before, $dryRun): void {
                    $answers = $dryRun
                        ? IdempotencyStore::estateDue($before)
                        : IdempotencyStore::pruneEstate($before);

                    $this->line('  <fg=green>OK</> '.$estate->getTenantKey().': '.$answers.' answer(s) '.($dryRun ? 'would be deleted' : 'deleted'));
                });
            } catch (Throwable $failure) {
                $failed++;
                $this->line('  <fg=red>FAIL</> '.$estate->getTenantKey().': '.$failure->getMessage());
            }
        }

        /*
         * A failure here is not a reason to stop: the estates that were swept stay
         * swept, and the next run takes the rest. It is a reason to exit non-zero,
         * so a scheduled run that never succeeds is noticed.
         */
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The one-off deploy step: forget the answers stored centrally, in the clear,
     * by every record written BEFORE they moved into the estates.
     *
     * Without this those bodies sit in gs_platform until the ordinary prune reaches
     * them, which is up to a retention window away. With it they are gone the
     * moment the release lands — at the cost that a retry of one of those acts is
     * refused (`idempotent_answer_missing`) rather than answered, which is why only
     * records past `LEGACY_GRACE_HOURS` are touched and why the record itself is
     * kept: the key still stands, so the act is still never done twice.
     */
    private function clearLegacyAnswers(bool $dryRun): void
    {
        $before = Carbon::now()->subHours(self::LEGACY_GRACE_HOURS);

        $rows = DB::connection('mysql')->table(IdempotencyStore::CENTRAL_TABLE)
            ->whereNull('body_store')
            ->whereNotNull('response_body')
            ->where('created_at', '<', $before);

        if ($dryRun) {
            $this->line('  dry run — '.(int) $rows->count().' answer(s) written before the move would be cleared from the central table');

            return;
        }

        $this->line('  central: cleared '.$rows->update(['response_body' => null, 'updated_at' => Carbon::now()]).' answer(s) written before the move');
    }
}
