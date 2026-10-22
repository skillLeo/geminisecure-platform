<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The estate's heaviest writes, through the console, at 2,000 lots
|--------------------------------------------------------------------------
|
| Before M11 A2 these were the five estate routes the query guard had to be told
| to allow: a whole-estate charge wrote a journal per lot, and posting or
| reversing a month of dues wrote each of its 2,001 journal lines with its own
| INSERT. `EstateChargeRunAtScaleTest` and `EstateBulkChargeIsUnchangedTest`
| prove the services; this proves the REQUESTS — what a treasurer's press
| actually runs, middleware and all — against Phoenix Park's real size, within
| the guard's default budget, with no override in `config/perf.php`.
|
| THESE WRITES ARE REAL. A request opens the estate on a connection of its own,
| so they cannot be held in a transaction, and the ledger is append-only, so
| they cannot be taken back out. The central half (the viewer, the audit log)
| is rolled back as everywhere else; the estate is DROPPED afterwards
| (`EstateScaleFixture::discard()`), and the next test to need it builds it
| again as it was.
|
*/

use App\Models\Estate\Charge;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Role;
use App\Support\Performance\QueryBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EstateScaleFixture;
use Tests\Support\QueryProbe;

beforeEach(function (): void {
    EstateScaleFixture::boot();
    EstateScaleFixture::matrix();

    DB::connection('mysql')->beginTransaction();

    EstateScaleFixture::enter();

    $this->withoutVite();

    $this->treasurer = EstateScaleFixture::viewer(Role::TREASURER);

    EstateScaleFixture::boot();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    EstateScaleFixture::discard();
});

/**
 * One press, measured: it must have worked, and stayed inside the default budget.
 *
 * @param  Closure(): TestResponse  $press
 */
function pressAtScale(string $label, string $route, Closure $press): TestResponse
{
    // Held to the default, like every other estate route: no override of its own.
    $budget = QueryBudget::for($route);

    // M11_SCALE_NO_ASSERT=1 measures without holding the press to anything but working —
    // how the same presses were measured on the code before M11 A2.
    $asserting = getenv('M11_SCALE_NO_ASSERT') !== '1';

    expect(Route::has($route))->toBeTrue($route.' is not a route');

    if ($asserting) {
        expect($budget)->toBe(QueryBudget::for(null), $route.' has a budget of its own in config/perf.php');
    }

    $measured = QueryProbe::measure($press);

    /** @var TestResponse $response */
    $response = $measured['result'];

    expect($response->getStatusCode())->toBe(302, $label.' answered '.$response->getStatusCode()
        .($response->exception ? ': '.$response->exception->getMessage() : ''));

    $response->assertSessionHasNoErrors();

    $report = getenv('M11_SCALE_REPORT');

    if (is_string($report) && $report !== '') {
        file_put_contents($report, json_encode([
            'label' => $label,
            'queries' => $measured['tally']->count,
            'db_ms' => round($measured['tally']->databaseMs, 1),
            'cold_ms' => round($measured['ms'], 1),
            'repeated' => $measured['tally']->mostRepeated(3),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    // The guard would have refused the request itself; this says by how much it passed.
    if ($asserting) {
        expect($measured['tally']->count)->toBeLessThanOrEqual($budget, $label."\n".$measured['tally']->describe(5));
    }

    return $response;
}

it('bills every lot, and posts and reverses a month of dues, through the console within the default budget', function (): void {
    $charges = Charge::query()->count();

    /* A whole-estate charge: the preview, then the press. */
    $preview = pressAtScale('POST bulk charge preview, 2,000 lots', 'estate.dues.charge.preview', fn () => $this->actingAs($this->treasurer)
        ->post(EstateScaleFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'special_assessment',
            'amount' => '1500.00',
            'description' => 'M11 scale perimeter assessment',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '4000',
        ]));

    $token = str_replace('preview=', '', (string) parse_url((string) $preview->headers->get('Location'), PHP_URL_QUERY));

    pressAtScale('POST bulk charge, 2,000 lots', 'estate.dues.charge.bulk', fn () => $this->actingAs($this->treasurer)
        ->withSession($preview->baseResponse->getSession()->all())
        ->post(EstateScaleFixture::url('/finance/charges/bulk'), ['token' => $token]));

    EstateScaleFixture::boot();

    expect(Charge::query()->count())->toBe($charges + EstateScaleFixture::LOTS)
        ->and((int) Charge::query()->where('description', 'M11 scale perimeter assessment')->distinct()->count('unit_id'))->toBe(EstateScaleFixture::LOTS);

    /* A month of dues on a schedule of its own: defined, posted, reversed. */
    pressAtScale('POST charge schedule create', 'estate.dues.schedule.create', fn () => $this->actingAs($this->treasurer)
        ->post(EstateScaleFixture::url('/finance/charge-schedule'), [
            'description' => 'M11 scale maintenance fee',
            'amount' => '6200.00',
            'scope' => 'estate',
            'due_day' => 1,
            'account_code' => '4000',
        ]));

    EstateScaleFixture::boot();

    $schedule = ChargeSchedule::query()->where('description', 'M11 scale maintenance fee')->sole();

    pressAtScale('POST charge run, 2,000 lots', 'estate.dues.schedule.run', fn () => $this->actingAs($this->treasurer)
        ->post(EstateScaleFixture::url('/finance/charge-schedule/'.$schedule->id.'/runs'), [
            'period' => now()->format('Y-m'),
            'expected_count' => EstateScaleFixture::LOTS,
            'expected_total_minor' => EstateScaleFixture::LOTS * 6_200_00,
        ]));

    EstateScaleFixture::boot();

    $run = ChargeRun::query()->where('charge_schedule_id', $schedule->id)->sole();

    expect($run->unit_count)->toBe(EstateScaleFixture::LOTS)
        ->and((int) DB::connection('tenant')->table('journal_lines')->where('entry_ref', $run->journal_ref)->count())->toBe(EstateScaleFixture::LOTS + 1);

    pressAtScale('POST charge run reversal, 2,000 lots', 'estate.dues.schedule.run.reverse', fn () => $this->actingAs($this->treasurer)
        ->post(EstateScaleFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Billed at last year\'s rate.']));

    EstateScaleFixture::boot();

    $run->refresh();

    expect($run->reversal_journal_ref)->not->toBeNull()
        ->and((int) DB::connection('tenant')->table('journal_lines')->where('entry_ref', $run->reversal_journal_ref)->count())->toBe(EstateScaleFixture::LOTS + 1)
        ->and((int) Charge::query()->where('journal_ref', $run->journal_ref)->where('status', 'reversed')->count())->toBe(EstateScaleFixture::LOTS);
});
