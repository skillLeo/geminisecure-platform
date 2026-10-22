<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A request over its query budget fails loudly — M11 A2
|--------------------------------------------------------------------------
|
| The guard is the thing that stops the next N+1 reaching an estate of two
| thousand lots, so it is proven the way a guard has to be: a request over the
| line is refused, a request on the line is not, the refusal names the
| statement that was repeated, and the line is the one config/perf.php draws
| for that route. And it is proven OFF where it must be off.
|
| The probe routes are this file's own: each runs exactly the number of
| statements its path asks for, so every count below is known in advance
| rather than read off whatever a real page happens to do today.
|
*/

use App\Support\Performance\QueryBudget;
use App\Support\Performance\QueryBudgetExceeded;
use App\Support\Performance\QueryMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    // No `web` group: no session, no user, no tenancy — so the only statements
    // a probe runs are the ones it is told to.
    Route::get('/__query-budget/probe/{n}', static function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            DB::connection('mysql')->select('select ? as n', [$i]);
        }

        return response('ran '.$n);
    })->whereNumber('n')->name('perf.probe');

    Route::get('/__query-budget/other/{n}', static function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            DB::connection('mysql')->select('select ? as n', [$i]);
        }

        return response('ran '.$n);
    })->whereNumber('n')->name('perf.other.probe');

    Route::get('/__query-budget/broken/{n}', static function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            DB::connection('mysql')->select('select ? as n', [$i]);
        }

        throw new RuntimeException('the controller itself failed');
    })->whereNumber('n')->name('perf.broken');

    config([
        'perf.query_budget.enabled' => true,
        'perf.query_budget.mode' => 'throw',
        'perf.query_budget.default' => 10,
        'perf.query_budget.routes' => [],
        'perf.query_budget.report' => null,
    ]);
});

it('lets a request through at exactly its budget', function (): void {
    $this->get('/__query-budget/probe/10')->assertOk()->assertSeeText('ran 10');
});

it('fails a request one query over its budget, naming the route, the count, the budget and the repeated statement', function (): void {
    $response = $this->get('/__query-budget/probe/11');

    $response->assertStatus(500);

    expect($response->exception)->toBeInstanceOf(QueryBudgetExceeded::class);

    /** @var QueryBudgetExceeded $exceeded */
    $exceeded = $response->exception;

    expect($exceeded->budget)->toBe(10)
        ->and($exceeded->tally->count)->toBe(11)
        ->and($exceeded->getMessage())->toContain('GET perf.probe')
        ->and($exceeded->getMessage())->toContain('ran 11 queries against a budget of 10')
        ->and($exceeded->getMessage())->toContain('config/perf.php')
        // The eleven lookups are ONE statement run eleven times, which is how an N+1 reads.
        ->and($exceeded->getMessage())->toContain('11× mysql: select ? as n');
});

it('holds a route to its own ceiling when config/perf.php names it', function (): void {
    config(['perf.query_budget.routes' => ['perf.probe' => 3]]);

    $this->get('/__query-budget/probe/3')->assertOk();
    $this->get('/__query-budget/probe/4')->assertStatus(500);

    // The default still governs every other route.
    $this->get('/__query-budget/other/10')->assertOk();
});

it('matches a pattern after the exact names, in the order written', function (): void {
    config(['perf.query_budget.routes' => [
        'perf.other.probe' => 20,
        'perf.*' => 2,
    ]]);

    expect(QueryBudget::for('perf.probe'))->toBe(2)
        ->and(QueryBudget::for('perf.other.probe'))->toBe(20)
        ->and(QueryBudget::for('somewhere.else'))->toBe(10)
        ->and(QueryBudget::for(null))->toBe(10);

    $this->get('/__query-budget/probe/3')->assertStatus(500);
    $this->get('/__query-budget/other/15')->assertOk();
});

it('exempts a route only when config/perf.php names it with null', function (): void {
    config(['perf.query_budget.routes' => ['perf.probe' => null]]);

    expect(QueryBudget::for('perf.probe'))->toBeNull();

    $this->get('/__query-budget/probe/40')->assertOk();
});

it('counts every connection, the estate\'s and the owner\'s as well as the platform\'s', function (): void {
    Route::get('/__query-budget/connections', static function () {
        DB::connection('mysql')->select('select 1');
        DB::connection('mysql_owner')->select('select 1');
        DB::connection('mysql_owner')->select('select 1');

        return response('ok');
    })->name('perf.connections');

    config(['perf.query_budget.routes' => ['perf.connections' => 2]]);

    $response = $this->get('/__query-budget/connections');

    $response->assertStatus(500);
    expect($response->exception->tally->count)->toBe(3);
});

it('writes a warning instead of failing in log mode', function (): void {
    config(['perf.query_budget.mode' => 'log']);

    Log::spy();

    $this->get('/__query-budget/probe/12')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        static fn (string $message): bool => str_contains($message, 'ran 12 queries against a budget of 10'),
    );
});

it('leaves a request that has already failed to report its own exception', function (): void {
    $response = $this->get('/__query-budget/broken/30');

    $response->assertStatus(500);

    expect($response->exception)->toBeInstanceOf(RuntimeException::class)
        ->and($response->exception)->not->toBeInstanceOf(QueryBudgetExceeded::class)
        ->and($response->exception->getMessage())->toBe('the controller itself failed');
});

it('never counts in production, whatever the configuration says', function (): void {
    $environment = app()->environment();

    app()->instance('env', 'production');

    try {
        expect(QueryBudget::enabled())->toBeFalse();

        $this->get('/__query-budget/probe/50')->assertOk();
    } finally {
        app()->instance('env', $environment);
    }

    expect(QueryBudget::enabled())->toBeTrue();
});

it('is off when config/perf.php turns it off', function (): void {
    config(['perf.query_budget.enabled' => false]);

    $this->get('/__query-budget/probe/50')->assertOk();
});

it('counts nothing outside a request', function (): void {
    $meter = app(QueryMeter::class);

    DB::connection('mysql')->select('select 1');

    expect($meter->isCounting())->toBeFalse()
        ->and($meter->end())->toBeNull();
});

it('appends one line per request to the report file when one is configured', function (): void {
    $report = storage_path('framework/testing/query-budget-report-'.getmypid().'.jsonl');
    @unlink($report);

    config(['perf.query_budget.report' => $report]);

    try {
        $this->get('/__query-budget/probe/4')->assertOk();

        $lines = file($report, FILE_IGNORE_NEW_LINES);
        $row = json_decode($lines[0], true);

        expect($lines)->toHaveCount(1)
            ->and($row['route'])->toBe('perf.probe')
            ->and($row['queries'])->toBe(4)
            ->and($row['budget'])->toBe(10)
            ->and($row['status'])->toBe(200)
            ->and($row['top']['count'])->toBe(4);
    } finally {
        @unlink($report);
    }
});

it('gives the Pest helper the same count, and fails it over budget with the repeated statement named', function (): void {
    assertQueryBudget(5, fn () => $this->get('/__query-budget/probe/5'))->assertOk();

    try {
        assertQueryBudget(5, fn () => $this->get('/__query-budget/probe/6'), 'The probe');

        $this->fail('A request over its budget passed the helper.');
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain('The probe ran 6 queries against a budget of 5')
            ->and($failure->getMessage())->toContain('6× mysql: select ? as n');
    }
});

it('does not switch the middleware off for the request the helper is measuring', function (): void {
    // The helper counts with a meter of its own. Had it bracketed the application's
    // meter, the middleware's bracket would be an inner one and never see a tally.
    $response = assertQueryBudget(100, fn () => $this->get('/__query-budget/probe/11'));

    $response->assertStatus(500);
    expect($response->exception)->toBeInstanceOf(QueryBudgetExceeded::class);
});
