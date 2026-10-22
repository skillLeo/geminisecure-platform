<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A bulk charge writes exactly what it wrote before, in a handful of statements
|--------------------------------------------------------------------------
|
| M11 A2 rewrote `Dues::chargeMany()` — a whole-phase or whole-estate charge —
| from "`charge()` once per unit", about eight statements a unit, into batched
| inserts through `Ledger::postEach()`. The ledger is append-only and the records
| a charge leaves are what a household disputes and an auditor reads, so the
| rewrite is held here to one standard: post the same charge to one set of lots
| the old way and to another set the new way, in the same transaction, and every
| column of every charge, journal and journal line must match — the references
| continuing one sequence across both, each entry balanced by the database's own
| trigger, each charge pointing at its own entry and each entry back at it.
|
| On the scale estate, inside a transaction on its connection that is rolled back,
| so nothing posted here outlives the test.
|
*/

use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\Support\QueryProbe;

beforeEach(function (): void {
    EstateScaleFixture::boot();

    $this->tenant = DB::connection('tenant');
    $this->tenant->beginTransaction();
});

afterEach(function (): void {
    $this->tenant->rollBack();

    expect(EstateScaleFixture::volumes()['charges'])->toBe(EstateScaleFixture::CHARGES);
});

/**
 * `chargeMany()` AS IT WAS BEFORE M11, verbatim but for `$this` — as it stood at
 * a342c56, with the bug hunt's once-per-preview token (B020) and its income-account
 * refusal (B024): every unit through `charge()`, in reference order, inside one
 * transaction, and a second post of one preview refused.
 *
 * @param  list<int>  $unitIds
 */
function legacyChargeMany(array $unitIds, Money $amount, string $description, Carbon|string $dueOn, string $type, string $account, User $by, ?string $previewToken = null): array
{
    $dues = app(Dues::class);
    $units = Unit::query()->whereIn('id', $unitIds)->orderBy('reference')->get();

    // The refusals' words moved with the live code's on 12 October (no em dash, M53 Law 3); the rows are the yardstick.
    if ($units->count() !== count($unitIds)) {
        throw new DomainException('The estate register has changed since this list was drawn: a unit on it is no longer there. Nothing has been posted; take the preview again.');
    }

    try {
        return DB::connection('tenant')->transaction(function () use ($units, $amount, $description, $dueOn, $type, $account, $by, $dues, $previewToken): array {
            foreach ($units as $unit) {
                $dues->charge(unit: $unit, amount: $amount, description: $description, dueOn: $dueOn, type: $type, account: $account, by: $by, bulkToken: $previewToken);
            }

            return ['posted' => $units->count(), 'total_minor' => $amount->getMinorAmount()->toInt() * $units->count()];
        });
    } catch (UniqueConstraintViolationException) {
        throw new DomainException('This list has already been posted: every unit on it carries the charge once. Nothing more was raised.');
    }
}

/**
 * Everything one set of charges left behind, with the parts that differ by nature —
 * ids, which lot, the sequence number and the second the row was written — taken out.
 *
 * @param  list<int>  $unitIds
 * @return array{charges: list<array<string, mixed>>, references: list<string>, journals: list<string>}
 */
function chargeTrail(array $unitIds, string $description): array
{
    $tenant = DB::connection('tenant');

    $charges = $tenant->table('charges')->whereIn('unit_id', $unitIds)->where('description', $description)->orderBy('reference')->get();

    $rows = [];

    foreach ($charges as $charge) {
        $journal = $tenant->table('journals')->where('reference', $charge->journal_ref)->first();
        $lines = $tenant->table('journal_lines')->where('entry_ref', $charge->journal_ref)->orderBy('line_no')->get();

        expect($journal)->not->toBeNull()
            ->and((int) $journal->source_id)->toBe((int) $charge->id)
            ->and($lines)->toHaveCount(2)
            ->and((int) $lines[0]->unit_id)->toBe((int) $charge->unit_id);

        $rows[] = [
            'charge' => collect((array) $charge)->except(['id', 'unit_id', 'reference', 'journal_ref', 'created_at', 'updated_at'])->all(),
            'journal' => collect((array) $journal)->except(['id', 'reference', 'source_id', 'created_at'])->all(),
            'lines' => $lines->map(static fn (object $line): array => collect((array) $line)->except(['id', 'entry_ref', 'unit_id', 'created_at'])->all())->all(),
        ];
    }

    return [
        'charges' => $rows,
        'references' => $charges->pluck('reference')->all(),
        'journals' => $charges->pluck('journal_ref')->all(),
    ];
}

it('writes the same charges, entries and lines as posting each unit through charge(), continuing one sequence', function (): void {
    $by = User::factory()->make(['id' => 424_242, 'name' => 'M11 Treasurer']);
    $amount = Money::of('15000.00', 'JMD');
    $due = Carbon::today()->startOfMonth()->addDays(9);
    $description = 'M11 perimeter wall levy';

    $lots = Unit::query()->where('block', 'Phase 3')->orderBy('reference')->limit(60)->pluck('id')->all();
    [$old, $new] = [array_slice($lots, 0, 30), array_slice($lots, 30, 30)];

    // One preview's token on both halves, so `bulk_token` is compared like every other column.
    $token = 'M11-preview-'.bin2hex(random_bytes(6));

    $legacy = legacyChargeMany($old, $amount, $description, $due->toDateString(), 'special_assessment', '4000', $by, $token);
    $batched = app(Dues::class)->chargeMany($new, $amount, $description, $due->toDateString(), 'special_assessment', '4000', $by, $token);

    expect($batched)->toBe($legacy)
        ->and((int) DB::connection('tenant')->table('charges')->where('bulk_token', $token)->count())->toBe(60);

    $before = chargeTrail($old, $description);
    $after = chargeTrail($new, $description);

    // Every column of every record, the same.
    expect($after['charges'])->toBe($before['charges']);

    // One sequence across both: the batch's references pick up exactly where the per-unit ones stopped.
    $number = static fn (string $reference): int => (int) substr($reference, strrpos($reference, '-') + 1);

    $chargeNumbers = array_map($number, [...$before['references'], ...$after['references']]);
    $journalNumbers = array_map($number, [...$before['journals'], ...$after['journals']]);

    expect($chargeNumbers)->toBe(range($chargeNumbers[0], $chargeNumbers[0] + 59))
        ->and($journalNumbers)->toBe(range($journalNumbers[0], $journalNumbers[0] + 59))
        ->and($after['references'][0])->toStartWith('CHG-'.$due->format('Y-m').'-')
        ->and($after['journals'][0])->toStartWith('CHG-'.$due->format('Y-m').'-');

    // The receivable moved by exactly what was billed, on every unit billed.
    $billed = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.memo = ?
    ', ['1200', $description])->net;

    expect((int) $billed)->toBe(60 * 15_000_00);
});

it('refuses what charge() refused, in the same words, and posts nothing', function (): void {
    $by = User::factory()->make(['id' => 424_243, 'name' => 'M11 Treasurer']);
    $dues = app(Dues::class);
    $lots = Unit::query()->orderBy('id')->limit(3)->pluck('id')->all();
    $count = (int) DB::connection('tenant')->table('charges')->count();

    $refusal = static function (callable $post): string {
        try {
            $post();
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return 'nothing was refused';
    };

    foreach ([
        'an amount of nothing' => [Money::of('0.00', 'JMD'), '4000'],
        'an account nobody has' => [Money::of('10.00', 'JMD'), '9999'],
        'the receivable itself, which is not income (B024)' => [Money::of('10.00', 'JMD'), '1200'],
        'the bank, which is not income (B024)' => [Money::of('10.00', 'JMD'), '1000'],
    ] as $case => [$amount, $account]) {
        $old = $refusal(fn () => legacyChargeMany($lots, $amount, 'M11 refused', now()->toDateString(), 'dues', $account, $by));
        $new = $refusal(fn () => $dues->chargeMany($lots, $amount, 'M11 refused', now()->toDateString(), 'dues', $account, $by));

        expect($new)->toBe($old, $case)
            ->and($new)->not->toBe('nothing was refused', $case)
            ->and((int) DB::connection('tenant')->table('charges')->count())->toBe($count, $case);
    }

    // No units at all posts nothing and says so, as the per-unit loop did.
    expect($dues->chargeMany([], Money::of('10.00', 'JMD'), 'M11 none', now()->toDateString(), 'dues', '4000', $by))
        ->toBe(legacyChargeMany([], Money::of('10.00', 'JMD'), 'M11 none', now()->toDateString(), 'dues', '4000', $by));
});

it('refuses a second post of the same preview as the per-unit loop did, in the same words, and bills nobody twice (B020)', function (): void {
    $by = User::factory()->make(['id' => 424_245, 'name' => 'M11 Treasurer']);
    $dues = app(Dues::class);
    $amount = Money::of('750.00', 'JMD');
    $lots = Unit::query()->where('block', 'Phase 2')->orderBy('reference')->limit(20)->pluck('id')->all();

    expect($lots)->toHaveCount(20);
    [$old, $new] = [array_slice($lots, 0, 10), array_slice($lots, 10, 10)];
    [$oldToken, $newToken] = ['M11-old-'.bin2hex(random_bytes(6)), 'M11-new-'.bin2hex(random_bytes(6))];

    $refusal = static function (callable $post): string {
        try {
            $post();
        } catch (DomainException $refused) {
            return $refused->getMessage();
        }

        return 'nothing was refused';
    };

    legacyChargeMany($old, $amount, 'M11 once', now()->toDateString(), 'dues', '4000', $by, $oldToken);
    $dues->chargeMany($new, $amount, 'M11 once', now()->toDateString(), 'dues', '4000', $by, $newToken);

    $count = (int) DB::connection('tenant')->table('charges')->count();

    $again = $refusal(fn () => legacyChargeMany($old, $amount, 'M11 once', now()->toDateString(), 'dues', '4000', $by, $oldToken));
    $twice = $refusal(fn () => $dues->chargeMany($new, $amount, 'M11 once', now()->toDateString(), 'dues', '4000', $by, $newToken));

    expect($twice)->toBe($again)
        ->and($twice)->toBe('This list has already been posted: every unit on it carries the charge once. Nothing more was raised.')
        ->and((int) DB::connection('tenant')->table('charges')->count())->toBe($count)
        ->and((int) DB::connection('tenant')->table('charges')->where('bulk_token', $newToken)->count())->toBe(10)
        ->and((int) DB::connection('tenant')->table('journals')->where('memo', 'M11 once')->count())->toBe(20);

    // A different preview of the same lots is a different decision, and posts.
    expect($dues->chargeMany($new, $amount, 'M11 once', now()->toDateString(), 'dues', '4000', $by, $newToken.'-b'))
        ->toBe(['posted' => 10, 'total_minor' => 10 * 750_00]);
});

it('bills every one of the 2,000 lots in a fixed handful of statements', function (): void {
    $by = User::factory()->make(['id' => 424_244, 'name' => 'M11 Treasurer']);
    $all = Unit::query()->orderBy('id')->pluck('id')->all();

    $measured = QueryProbe::measure(fn () => app(Dues::class)->chargeMany($all, Money::of('500.00', 'JMD'), 'M11 whole estate', now()->toDateString(), 'fine', '4000', $by));

    expect($measured['result'])->toBe(['posted' => EstateScaleFixture::LOTS, 'total_minor' => EstateScaleFixture::LOTS * 500_00])
        ->and((int) DB::connection('tenant')->table('charges')->where('description', 'M11 whole estate')->count())->toBe(EstateScaleFixture::LOTS)
        ->and((int) DB::connection('tenant')->table('journals')->where('memo', 'M11 whole estate')->count())->toBe(EstateScaleFixture::LOTS)
        ->and((int) DB::connection('tenant')->table('journal_lines')->where('memo', 'M11 whole estate')->count())->toBe(2 * EstateScaleFixture::LOTS)
        ->and((int) DB::connection('tenant')->table('charges')->where('description', 'M11 whole estate')->whereNull('journal_ref')->count())->toBe(0);

    // Before M11 this was about eight statements a lot: sixteen thousand for this estate.
    expect($measured['tally']->count)->toBeLessThanOrEqual(40, $measured['tally']->describe(5));

    $report = getenv('M11_SCALE_REPORT');

    if (is_string($report) && $report !== '') {
        file_put_contents($report, json_encode(['label' => 'POST bulk charge, 2,000 lots (service)', 'queries' => $measured['tally']->count, 'db_ms' => round($measured['tally']->databaseMs, 1), 'cold_ms' => round($measured['ms'], 1)])."\n", FILE_APPEND | LOCK_EX);
    }
});
