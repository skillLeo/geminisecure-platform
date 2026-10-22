<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Ballot;
use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\ApiContract;
use Tests\Support\QueryBudget;
use Tests\Support\ScaleFixture;
use Tests\Support\ScaleVolume;

/*
|--------------------------------------------------------------------------
| Every app read, at production volume, inside a query budget — M11 A2
|--------------------------------------------------------------------------
|
| The Guard App's officer stands at the scale estate — 2,000 lots, 441 households,
| 12,015 charges — among sixty colleagues at twelve clients with their shifts, gate
| log and alerts (`ScaleVolume`). The Resident App's resident lives at Lot 47, the lot
| the boards give the longest history: six months of dues, part-payments, a payment
| plan. Every GET either app calls is then held to:
|
|   1. ITS CATALOGUE ENTRY (`ApiContract`) — the fields the apps read are all still
|      there and nothing new has crept in; invariant 2 on every guard route.
|   2. ITS BUDGET — the count measured after the fixes, plus four, each counted as a new
|      request (`QueryBudget::asANewRequest`), as the server answers every one.
|   3. NO GROWTH — the central platform is built a second time, the officer is given more
|      of their own reports, requests, messages and watches (`ScaleVolume::moreOfTheirOwn`),
|      and every endpoint is counted again; a count that grew is a query per row.
|   4. NOT THE WHOLE LEDGER — the dues, eligibility and amenity answers are about ONE
|      household, and none of them may read every unit's receivable to find it. That
|      is what they did until 24 September 2026: the estate's balances grouped by unit,
|      every charge it had ever raised, and every payment, to answer for one lot.
|
*/

beforeEach(function (): void {
    ScaleFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * The officer's and the resident's tokens, and the ids their reads are made with.
 *
 * @param  array<string, mixed>  $v
 * @return array<string, mixed>
 */
function apiBudgetWorld(array $v): array
{
    $guard = $v['scale_on_duty_guard'];
    $guardToken = app(DeviceEnrolment::class)->enrol($guard, 'Budget handset')['token'];

    ScaleFixture::boot();

    $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
    $household = Household::query()->where('unit_id', $unit->id)->firstOrFail();
    $resident = Resident::query()->where('household_id', $household->id)->orderByDesc('is_primary')->orderBy('id')->firstOrFail();

    $account = ResidentAccount::query()->create([
        'tenant_id' => ScaleFixture::ESTATE, 'channel' => 'email', 'destination' => 'budget.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => (string) $resident->full_name, 'resident_id' => $resident->id, 'unit_id' => $unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [
        'guard' => $guardToken,
        'resident' => $account->createToken('resident-app:budget', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken,
        'household' => $household->id,
        'payment' => (int) Payment::query()->where('unit_id', $unit->id)->orderByDesc('id')->value('id'),
        'ballot' => (int) Ballot::query()->orderByDesc('id')->value('id'),
        'payslip' => (int) DB::connection('mysql')->table('payslips')->where('guard_id', $guard->id)->where('payroll_run_id', $v['approved_run_id'])->value('id'),
        'alert' => $v['household_alert_id'],
        'own_alert' => $v['alert_id'],
    ];
}

/**
 * Every GET, whose token, its path parameters and query, its expected status and its budget.
 *
 * @param  array<string, mixed>  $w
 * @return array<string, array{0: string|null, 1: array<string, scalar>, 2: array<string, scalar>, 3: int, 4: int}>
 */
function apiBudgets(array $w): array
{
    $site = ScaleFixture::ESTATE;

    return [
        'estates.index' => [null, [], [], 200, 5],
        'guards.me' => ['g', [], [], 200, 11],
        'presence.summary' => ['g', [], [], 200, 15],
        'shifts.me' => ['g', [], ['from' => now()->subDays(14)->toDateString(), 'to' => now()->addDays(7)->toDateString()], 200, 12],
        'shifts.me.current' => ['g', [], [], 200, 19],
        'shifts.open' => ['g', [], [], 200, 13],
        'gate.activity' => ['g', [], [], 200, 10],
        'gate.search' => ['g', [], ['unit' => 'Lot'], 200, 14],
        'gate.search#phase' => ['g', [], ['unit' => 'Lot 1', 'phase' => 'Phase 5'], 200, 14],
        'incidents.me' => ['g', [], [], 200, 10],
        'messages.index' => ['g', [], [], 200, 9],
        'requests.index' => ['g', [], [], 200, 9],
        'payslips.me' => ['g', [], [], 200, 10],
        'payslips.pdf' => ['g', ['payslip' => $w['payslip']], [], 200, 12],
        'patrol.checkpoints' => ['g', ['site' => $site], [], 200, 10],
        'passes.keys' => ['g', ['site' => $site], [], 200, 10],
        'orders.current' => ['g', ['site' => $site], [], 200, 11],
        'standing_orders.index' => ['g', [], [], 200, 9],
        'sync.pull' => ['g', [], [], 200, 23],
        'alerts.show' => ['g', ['alert' => $w['alert']], [], 200, 13],
        'duress.show' => ['g', ['alert' => $w['own_alert']], [], 200, 10],
        'me.show' => ['r', [], [], 200, 13],
        // Every member's invite in one read (`ResidentInvites::latestForEach`).
        'me.household' => ['r', [], [], 200, 15],
        'members.index' => ['r', [], [], 200, 12],
        'vehicles.index' => ['r', [], [], 200, 11],
        // The household's pets (M50 §5.3d): one read, as the vehicles are.
        'pets.index' => ['r', [], [], 200, 11],
        'contacts.index' => ['r', [], [], 200, 11],
        'visitor_passes.index' => ['r', [], [], 200, 11],
        'epass.show' => ['r', [], [], 200, 11],
        'households.balance' => ['r', ['household' => $w['household']], [], 200, 15],
        'households.statement' => ['r', ['household' => $w['household']], [], 200, 11],
        'invoices.index' => ['r', [], [], 200, 13],
        'payments.index' => ['r', [], [], 200, 11],
        'payments.receipt' => ['r', ['payment' => $w['payment']], [], 200, 12],
        'tickets.index' => ['r', [], [], 200, 13],
        'notices.index' => ['r', [], [], 200, 15],
        'meetings.index' => ['r', [], [], 200, 14],
        'elections.index' => ['r', [], [], 200, 15],
        'elections.eligibility' => ['r', ['ballot' => $w['ballot']], [], 200, 19],
        // The seeded ballot's results are not published, and the answer says so.
        'elections.results' => ['r', ['ballot' => $w['ballot']], [], 409, 14],
        'amenities.index' => ['r', [], [], 200, 15],
        // 12 + the unit's ledger, once for the list (`Dues::ledgerOf`): what each booking's fee takes with the charges
        // before it, `fee_to_clear` (M12 §2.14, D-179). A fixed cost, never one per booking.
        'bookings.index' => ['r', [], [], 200, 13],
    ];
}

/**
 * One read, twice — the second is the one counted, as a new request: nothing this process
 * remembered from the first (`QueryBudget::asANewRequest`), as on the server.
 *
 * @param  array<string, mixed>  $w
 * @return array{response: TestResponse, count: int, worst: int, repeats: array<string, int>, statements: list<array{connection: string, sql: string, ms: float}>}
 */
function apiCount($test, array $w, string $key, ?string $who, array $params, array $query): array
{
    $name = explode('#', $key)[0];
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    $call = function () use ($test, $w, $who, $uri, $query): TestResponse {
        app('auth')->forgetGuards();

        $client = $who === null ? $test : $test->withToken($who === 'g' ? $w['guard'] : $w['resident']);
        $response = $client->getJson($uri.($query === [] ? '' : '?'.http_build_query($query)));

        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }

        ScaleFixture::boot();

        return $response;
    };

    $call();
    QueryBudget::asANewRequest();
    $warm = QueryBudget::measure($call);

    /*
     * THE TOKEN'S LAST-USED STAMP IS NOT COUNTED. Sanctum writes `last_used_at` on every
     * request — unless the new value falls in the same second as the old one, when nothing is
     * dirty and nothing is written. Whether it is there depends on the clock, not on the
     * endpoint, so a count that included it would move by one between two runs of nothing.
     */
    $counted = array_values(array_filter(
        $warm['statements'],
        static fn (array $s): bool => ! str_starts_with(strtolower(ltrim($s['sql'])), 'update `personal_access_tokens` set `last_used_at`'),
    ));

    return [
        'response' => $warm['result'],
        'count' => count($counted),
        'worst' => QueryBudget::worstRepeat($warm),
        'repeats' => $warm['repeats'],
        'statements' => $warm['statements'],
    ];
}

/**
 * The statements of a household's answer that read the ESTATE'S ledger rather than its own:
 * every unit's receivable grouped by unit, or charges or payments with no unit in the WHERE.
 *
 * @param  list<array{connection: string, sql: string, ms: float}>  $statements
 * @return list<string>
 */
function wholeLedgerReads(array $statements): array
{
    $offending = [];

    foreach ($statements as $statement) {
        $sql = strtolower(preg_replace('/\s+/', ' ', $statement['sql']) ?? '');

        /*
         * NARROWED TO ONE UNIT, in either of the two shapes the Dues service writes it: `unit_id = ?`,
         * or — since the M11 merge, where the arrears register's paged reads and a household's own
         * share one method — `unit_id in (?)` with exactly ONE placeholder. A list of several is a
         * page of units, not one household's, and still counts as reading beyond it.
         */
        $oneUnit = str_contains($sql, 'unit_id` = ?') || str_contains($sql, 'unit_id` in (?)') || str_contains($sql, 'unit_id in (?)');

        $groupsEveryUnit = (str_contains($sql, 'group by `journal_lines`.`unit_id`') || str_contains($sql, 'group by journal_lines.unit_id')) && ! $oneUnit;
        $everyCharge = preg_match('/from `?charges`?/', $sql) === 1 && ! $oneUnit && ! str_contains($sql, '`id` = ?');
        $everyPayment = preg_match('/from `?payments`?/', $sql) === 1 && ! $oneUnit && ! str_contains($sql, '`id` = ?');

        if ($groupsEveryUnit || $everyCharge || $everyPayment) {
            $offending[] = $statement['sql'];
        }
    }

    return $offending;
}

it('answers every app read at volume within its catalogue entry and its query budget, and asks no more of a platform twice the size', function (): void {
    $v = ScaleVolume::build();
    $w = apiBudgetWorld($v);
    $budgets = apiBudgets($w);

    $first = [];

    foreach ($budgets as $key => [$who, $params, $query, $status]) {
        $first[$key] = apiCount($this, $w, $key, $who, $params, $query);
        $first[$key]['response']->assertStatus($status);

        // A file is not a JSON body; everything else is held to its catalogue entry.
        if ($key !== 'payslips.pdf') {
            ApiContract::assertMatches($first[$key]['response'], explode('#', $key)[0]);
        }
    }

    // Twice the platform — and, because a second build adds colleagues rather than rows to
    // this officer's own lists, more of the officer's own reports, requests, messages and
    // watches too.
    ScaleVolume::build();
    ScaleVolume::moreOfTheirOwn($v['scale_on_duty_guard']);

    foreach ($budgets as $key => [$who, $params, $query, $status, $budget]) {
        $second = apiCount($this, $w, $key, $who, $params, $query);

        expect($first[$key]['count'])->toBeLessThanOrEqual($budget, "{$key} asked {$first[$key]['count']} queries against a budget of {$budget}")
            // Never MORE of the larger platform. Fewer is not a failure: the property is that
            // the count does not rise with the rows.
            ->and($second['count'])->toBeLessThanOrEqual($first[$key]['count'], "{$key} asked {$first[$key]['count']} queries of the platform and {$second['count']} of one twice its size — a query per row: ".json_encode($second['repeats']))
            ->and($first[$key]['worst'])->toBeLessThanOrEqual(3, "{$key} ran one statement {$first[$key]['worst']} times: ".json_encode($first[$key]['repeats']));
    }
});

it('answers a household\'s dues, its right to vote and its bookings from that household\'s own ledger, never the estate\'s', function (): void {
    $v = ScaleVolume::build();
    $w = apiBudgetWorld($v);
    $budgets = apiBudgets($w);

    foreach (['households.balance', 'households.statement', 'invoices.index', 'payments.index', 'elections.eligibility', 'amenities.index'] as $key) {
        [$who, $params, $query] = $budgets[$key];

        $read = apiCount($this, $w, $key, $who, $params, $query);

        expect(wholeLedgerReads($read['statements']))->toBe([], "{$key} read the whole estate's ledger to answer for one household");
    }
});
