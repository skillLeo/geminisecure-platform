<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A month's dues run, posted and reversed across 2,000 lots
|--------------------------------------------------------------------------
|
| Posting a charge schedule's month is one entry with a line for every lot, and
| reversing it is that entry mirrored. Before M11 A2 the ledger wrote each line
| with its own INSERT — 2,001 of them to post Phoenix Park's month and 2,001
| again to reverse it, plus a lookup of every line's account for the mirror —
| which is a request that outlasts its own timeout. This proves both acts at the
| estate's real size: what they post, that the reversal nets to nothing, and that
| each runs a fixed number of statements however many lots there are.
|
| Inside transactions on both connections, rolled back: the estate is the size
| it was afterwards, and so is the audit log.
|
*/

use App\Models\Estate\ChargeSchedule;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstateScaleFixture;
use Tests\Support\QueryProbe;

beforeEach(function (): void {
    EstateScaleFixture::boot();
    EstateScaleFixture::matrix();

    DB::connection('mysql')->beginTransaction();

    EstateScaleFixture::enter();
    EstateScaleFixture::boot();

    DB::connection('tenant')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
    DB::connection('mysql')->rollBack();

    expect(EstateScaleFixture::volumes())->toBe(['lots' => EstateScaleFixture::LOTS, 'households' => EstateScaleFixture::HOUSEHOLDS, 'charges' => EstateScaleFixture::CHARGES]);
});

/** Append one line to the M11 measurement report, when one is being taken. */
function chargeRunReport(string $label, array $measured): void
{
    $report = getenv('M11_SCALE_REPORT');

    if (is_string($report) && $report !== '') {
        file_put_contents($report, json_encode(['label' => $label, 'queries' => $measured['tally']->count, 'db_ms' => round($measured['tally']->databaseMs, 1), 'cold_ms' => round($measured['ms'], 1)])."\n", FILE_APPEND | LOCK_EX);
    }
}

it('posts a month to every lot, and reverses it, each in a fixed number of statements', function (): void {
    // An address no committed user of another suite can already hold (see `EstateScaleFixture::viewer()`).
    $by = User::factory()->create(['name' => 'M11 Treasurer', 'email' => 'm11-scale-'.Str::lower((string) Str::ulid()).'@example.test', 'status' => 'active']);
    $schedules = app(ChargeSchedules::class);
    $tenant = DB::connection('tenant');

    $schedule = $schedules->create([
        'description' => 'M11 scale maintenance fee',
        'amount' => '6200.00',
        'scope' => ChargeSchedule::ESTATE,
        'due_day' => 1,
        'account_code' => '4000',
    ], $by);

    $period = $schedules->nextPeriod($schedule);
    $preview = $schedules->preview($schedule, $period);

    expect($preview['count'])->toBe(EstateScaleFixture::LOTS)
        ->and($preview['total_minor'])->toBe(EstateScaleFixture::LOTS * 6200_00);

    $posted = QueryProbe::measure(fn () => $schedules->post($schedule, $period->format('Y-m'), $preview['count'], $preview['total_minor'], $by));
    $run = $posted['result'];

    chargeRunReport('POST charge run, 2,000 lots (service)', $posted);

    $lines = (int) $tenant->table('journal_lines')->where('entry_ref', $run->journal_ref)->count();
    $billed = (int) $tenant->table('charges')->where('journal_ref', $run->journal_ref)->count();

    expect($run->unit_count)->toBe(EstateScaleFixture::LOTS)
        ->and($billed)->toBe(EstateScaleFixture::LOTS)
        ->and($lines)->toBe(EstateScaleFixture::LOTS + 1)

        // Before M11: 2,001 line INSERTs alone. Now a handful of statements, whatever the size.
        ->and($posted['tally']->count)->toBeLessThanOrEqual(40, $posted['tally']->describe(5));

    $reversed = QueryProbe::measure(fn () => $schedules->reverse($run, 'M11 scale proof — posted to be reversed', $by));

    chargeRunReport('POST charge run reversal, 2,000 lots (service)', $reversed);

    $mirror = (string) $reversed['result']->reversal_journal_ref;

    // What the run added to the receivable, and what the mirror took back out of it:
    // every lot's own line on 1200 cancelled by its mirror, so the pair nets to nothing.
    $net = (int) $tenant->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.entry_ref IN (?, ?)
    ', ['1200', $run->journal_ref, $mirror])->net;

    $raised = (int) $tenant->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS raised
          FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.entry_ref = ?
    ', ['1200', $run->journal_ref])->raised;

    expect($raised)->toBe(EstateScaleFixture::LOTS * 6200_00);

    expect((int) $tenant->table('journal_lines')->where('entry_ref', $mirror)->count())->toBe(EstateScaleFixture::LOTS + 1)
        ->and($net)->toBe(0)
        ->and((int) $tenant->table('charges')->where('journal_ref', $run->journal_ref)->where('status', 'reversed')->count())->toBe(EstateScaleFixture::LOTS)

        // Before M11: 2,001 INSERTs and 2,001 account lookups for the mirror.
        ->and($reversed['tally']->count)->toBeLessThanOrEqual(40, $reversed['tally']->describe(5));
});
