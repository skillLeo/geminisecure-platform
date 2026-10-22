<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M11 changed how the arrears are read, and not one figure
|--------------------------------------------------------------------------
|
| M11 A2 moved the FIFO ageing out of PHP into one SQL statement with a window
| function, stopped reading every unit's balance to answer for one, and cut the
| arrears register into pages. Each of those is a rewrite of money arithmetic,
| and a rewrite of money arithmetic is only acceptable if it gives the same
| answer — so this file asks the new code and the old code the same questions
| and requires the same answers, to the cent and to the day.
|
| THE OLD CODE IS `Tests\Support\LegacyDues`, copied verbatim from `Dues` as it
| stood before M11. The estate is `EstateScaleFixture`: Phoenix Park's real size,
| 2,000 lots and 12,015 charges, 163 units in arrears across all four buckets.
| And the dates are several, not one: the ageing of the same estate as at today
| and as at five earlier days, because a bucket boundary is exactly where a
| rewrite of "days overdue" would go wrong.
|
| The seeded estate cannot reach every case the FIFO walk has to get right — a
| unit that has overpaid, a payment that clears a charge to the cent, two charges
| due the same day, a charge not yet due, a reversal — so the last test posts
| those onto units of their own inside a transaction on the estate's connection,
| compares, and rolls every one of them back.
|
*/

use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Residents;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\Support\LegacyDues;

beforeEach(function (): void {
    EstateScaleFixture::boot();

    $this->dues = app(Dues::class);
});

/** A page of the register as an address asks for it (M32 F5): the search, the sort, its way and the page, read by the one rule. */
function arrearsAt(Dues $dues, string $phase = '', bool $overdue = false, string $search = '', string $sort = '', string $dir = 'asc', int $page = 1, bool $maySeePosition = true): array
{
    $query = array_filter(['q' => $search, 'sort' => $sort, 'dir' => $dir, 'page' => $page], static fn ($value): bool => $value !== '' && $value !== 1);

    return $dues->arrearsPage($phase, $overdue, $maySeePosition, Dues::arrearsTable(Request::create('/finance/arrears', 'GET', $query)));
}

/** Where a page sits in the register, in the five words the pager has always been tested by. */
function arrearsPager(?array $table): ?array
{
    return $table === null ? null : ['current' => $table['page'], 'last' => $table['last'], 'from' => $table['from'], 'to' => $table['to'], 'total' => $table['total']];
}

/**
 * The dates asked about: the whole ledger as it stands, and five as-at dates
 * either side of the bucket boundaries.
 *
 * @return list<Carbon|null>
 */
function arithmeticDates(): array
{
    // The estate's today, which is what every figure with no date is read as at (bug hunt B023).
    $today = Dues::siteDate();

    return [null, $today->copy(), $today->copy()->subDays(15), $today->copy()->subDays(45), $today->copy()->subDays(75), $today->copy()->subDays(100), $today->copy()->subDays(170)];
}

/** @param  array<int, Carbon>  $dates */
function dayStrings(array $dates): array
{
    return array_map(static fn (Carbon $d): string => $d->toDateString(), $dates);
}

it('reads every balance, bucket, ageing total and phase bar exactly as before, on every date', function (): void {
    expect(array_sum(array_filter($this->dues->unitBalances(), static fn (int $b): bool => $b > 0)))->toBeGreaterThan(0);

    foreach (arithmeticDates() as $asAt) {
        $label = $asAt?->toDateString() ?? 'now';
        $today = $asAt?->copy() ?? Dues::siteDate();

        expect($this->dues->unitBalances($asAt))->toBe(LegacyDues::unitBalances($asAt), 'balances as at '.$label)
            ->and(dayStrings($this->dues->oldestOpenChargeDates($today)))->toEqual(dayStrings(LegacyDues::oldestOpenChargeDates($today)), 'oldest open as at '.$label)
            ->and($this->dues->unitBuckets($asAt))->toBe(LegacyDues::unitBuckets($asAt), 'buckets as at '.$label)
            ->and($this->dues->ageing($asAt))->toBe(LegacyDues::ageing($asAt), 'ageing as at '.$label)
            ->and($this->dues->arrearsByPhase($asAt))->toBe(LegacyDues::arrearsByPhase($asAt), 'phase bars as at '.$label)
            ->and($this->dues->position($asAt))->toBe(['ageing' => LegacyDues::ageing($asAt), 'by_phase' => LegacyDues::arrearsByPhase($asAt)], 'position as at '.$label);

        // Every one of the four buckets is actually reached, so the comparison is not vacuous.
        if ($asAt === null) {
            expect(array_values(array_unique($this->dues->unitBuckets())))->toEqualCanonicalizing(['current', 'd30', 'd60', 'd90']);
        }
    }
});

it('answers for one unit, or a handful, exactly what the whole-estate read answers for them', function (): void {
    $owing = array_keys(array_filter(LegacyDues::unitBalances(), static fn (int $b): bool => $b > 0));

    // Every owing unit, and a spread of the ones that owe nothing or are in credit.
    $sample = array_values(array_unique([...$owing, ...Unit::query()->whereKeyNot($owing)->orderBy('id')->pluck('id')->filter(static fn (int $id): bool => $id % 97 === 0)->all()]));

    foreach (arithmeticDates() as $asAt) {
        $label = $asAt?->toDateString() ?? 'now';
        $today = $asAt?->copy() ?? Dues::siteDate();

        $allBalances = LegacyDues::unitBalances($asAt);
        $allBuckets = LegacyDues::unitBuckets($asAt);
        $allOldest = dayStrings(LegacyDues::oldestOpenChargeDates($today));
        // Each charge in its own bucket (EST-S-02, M85 A3), read once for the whole estate.
        $allStrips = LegacyDues::chargeAgeing($asAt);

        $page = array_slice($sample, 0, 25);

        expect($this->dues->unitBalances($asAt, $page))->toEqual(array_intersect_key($allBalances, array_flip($page)), 'a page of balances as at '.$label)
            ->and($this->dues->unitBuckets($asAt, $page))->toEqual(array_intersect_key($allBuckets, array_flip($page)), 'a page of buckets as at '.$label)
            ->and(dayStrings($this->dues->oldestOpenChargeDates($today, $page)))->toEqual(array_intersect_key($allOldest, array_flip($page)), 'a page of oldest dates as at '.$label)
            ->and($this->dues->unitBalances($asAt, []))->toBe([]);

        foreach (Unit::query()->whereKey($sample)->get() as $unit) {
            $legacyBalance = $allBalances[$unit->id] ?? 0;

            expect($this->dues->balanceOf($unit, $asAt)->getMinorAmount()->toInt())->toBe($legacyBalance, 'balance of '.$unit->reference.' as at '.$label)
                ->and($this->dues->ageingOf($unit, $asAt))->toBe($allStrips[$unit->id] ?? array_fill_keys(array_keys(Dues::BUCKETS), 0), 'strip of '.$unit->reference.' as at '.$label)
                ->and($this->dues->oldestOpenChargeDate($unit, $today)?->toDateString())->toBe($allOldest[$unit->id] ?? null, 'oldest open of '.$unit->reference.' as at '.$label);
        }
    }

    // A unit's strip, each charge in its own bucket, still adds up to that unit's balance; held on a few units.
    foreach (Unit::query()->whereKey(array_slice($owing, 0, 3))->get() as $unit) {
        expect(array_sum(LegacyDues::ageingOf($unit)))->toBe(LegacyDues::unitBalances()[$unit->id] ?? 0);
    }

    // Board 6, for every owing unit: the balance, the bucket and the strip.
    $allBalances = LegacyDues::unitBalances();
    $allBuckets = LegacyDues::unitBuckets();
    $allStrips = LegacyDues::chargeAgeing();

    foreach (Unit::query()->whereKey($owing)->get() as $unit) {
        $board = $this->dues->unitBoard($unit);

        expect($board['balance_minor'])->toBe($allBalances[$unit->id])
            ->and($board['bucket'])->toBe($allBuckets[$unit->id] ?? 'current')
            ->and(array_column($board['strip'], 'value_minor', 'key'))->toBe($allStrips[$unit->id] ?? array_fill_keys(array_keys(Dues::BUCKETS), 0));
    }
});

it('builds the arrears register row for row as before, under every filter', function (): void {
    $phases = Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values()->all();

    foreach (['', ...$phases] as $phase) {
        foreach ([false, true] as $overdue) {
            $board = $this->dues->arrearsBoard($phase, $overdue);

            expect($board['rows'])->toBe(LegacyDues::arrearsRows($phase, $overdue), "rows, phase '{$phase}', overdue ".var_export($overdue, true));
        }
    }

    $board = $this->dues->arrearsBoard();

    expect(count($board['rows']))->toBe(163)
        ->and($board['ageing'][0]['value_minor'])->toBe(array_sum(LegacyDues::ageing()))
        ->and(array_column(array_slice($board['ageing'], 1), 'value_minor', 'key'))->toBe(LegacyDues::ageing());
});

it('pages the register without losing, repeating or reordering a unit, and filters it in SQL exactly as the rows are filtered', function (): void {
    $whole = $this->dues->arrearsBoard()['rows'];

    // Every page, in order, is the whole register in its order.
    $first = arrearsAt($this->dues);
    $pages = [];

    for ($page = 1; $page <= $first['table']['last']; $page++) {
        $one = arrearsAt($this->dues, page: $page);
        array_push($pages, ...$one['rows']);

        // Every page carries the register's size and its own place in it, as `paginate()` gave them.
        expect(arrearsPager($one['table']))->toBe([
            'current' => $page,
            'last' => (int) ceil(count($whole) / Dues::ARREARS_PER_PAGE),
            'from' => ($page - 1) * Dues::ARREARS_PER_PAGE + 1,
            'to' => min($page * Dues::ARREARS_PER_PAGE, count($whole)),
            'total' => count($whole),
        ])->and($one['total'])->toBe(count($whole));
    }

    expect($first['total'])->toBe(count($whole))
        ->and($first['table']['last'])->toBe((int) ceil(count($whole) / Dues::ARREARS_PER_PAGE))
        ->and($first['rows'])->toHaveCount(Dues::ARREARS_PER_PAGE)
        ->and($pages)->toBe($whole)
        ->and($first['ageing'])->toBe($this->dues->arrearsBoard()['ageing']);

    // The phase and 90+ filters, on every page, are the unpaginated filters.
    foreach (['Phase 2', 'Phase 5'] as $phase) {
        foreach ([false, true] as $overdue) {
            $expected = $this->dues->arrearsBoard($phase, $overdue)['rows'];
            $got = [];
            $meta = arrearsAt($this->dues, $phase, $overdue);

            for ($page = 1; $page <= max(1, $meta['table']['last']); $page++) {
                array_push($got, ...arrearsAt($this->dues, $phase, $overdue, page: $page)['rows']);
            }

            expect($got)->toBe($expected, "phase {$phase}, overdue ".var_export($overdue, true))
                ->and($meta['total'])->toBe(count($expected))

                /*
                 * The strip is the CHOSEN PHASE's (the client, 26 Sep), read in the page's own statement: the
                 * unpaginated reference's own strip for that phase, whatever the 90+ chip says, and its total is
                 * that phase's bar on the dashboard to the cent.
                 */
                ->and($meta['ageing'])->toBe($this->dues->arrearsBoard($phase)['ageing'], "strip, phase {$phase}, overdue ".var_export($overdue, true))
                ->and($meta['ageing'][0]['value_minor'])->toBe($this->dues->position()['by_phase'][$phase] ?? 0, "strip total, phase {$phase}");
        }
    }

    // And with no phase chosen, the strip is the whole estate's, whatever the 90+ chip says.
    foreach ([false, true] as $overdue) {
        expect(arrearsAt($this->dues, '', $overdue)['ageing'])->toBe($first['ageing'], 'strip, all phases, overdue '.var_export($overdue, true));
    }

    // The phases' strips add up to the estate's, bucket by bucket.
    $byBucket = [];
    foreach ($this->dues->arrearsBoard()['phases'] as $phase) {
        foreach (arrearsAt($this->dues, $phase)['ageing'] as $card) {
            $byBucket[$card['key']] = ($byBucket[$card['key']] ?? 0) + $card['value_minor'];
        }
    }
    expect($byBucket)->toBe(array_column($first['ageing'], 'value_minor', 'key'));

    // Each sort is the whole register ordered in SQL, ties broken by unit id the same way (M32 F5: descending is the
    // exact reverse of ascending, ties and all — `TableQuery::order`).
    $ordered = static function (array $rows, callable $key, string $dir): array {
        usort($rows, static function (array $a, array $b) use ($key, $dir): int {
            $cmp = ($key($a) <=> $key($b)) ?: $a['id'] <=> $b['id'];

            return $dir === 'desc' ? -$cmp : $cmp;
        });

        return array_column($rows, 'id');
    };

    $lastPaid = $this->dues->lastPaymentDates();
    $oldest = dayStrings($this->dues->oldestOpenChargeDates(Dues::siteDate()));
    $today = Dues::siteDate()->toDateString();

    // MySQL orders a NULL first ascending and last descending; '' does the same here.
    $sorts = [
        'balance' => static fn (array $r): int => $r['balance_minor'],
        'last_payment' => static fn (array $r): string => (string) ($lastPaid[$r['id']] ?? ''),
    ];

    foreach (['asc', 'desc'] as $dir) {
        foreach (['balance', 'last_payment'] as $sort) {
            $got = [];
            $meta = arrearsAt($this->dues, sort: $sort, dir: $dir);

            for ($page = 1; $page <= $meta['table']['last']; $page++) {
                array_push($got, ...array_column(arrearsAt($this->dues, sort: $sort, dir: $dir, page: $page)['rows'], 'id'));
            }

            expect($got)->toBe($ordered($whole, $sorts[$sort], $dir), "sorted by {$sort} {$dir}");
        }

        // Ageing: days overdue ascending means the oldest-open DATE descending.
        $got = [];
        $meta = arrearsAt($this->dues, sort: 'ageing', dir: $dir);

        for ($page = 1; $page <= $meta['table']['last']; $page++) {
            array_push($got, ...array_column(arrearsAt($this->dues, sort: 'ageing', dir: $dir, page: $page)['rows'], 'id'));
        }

        $expected = $whole;
        usort($expected, static function (array $a, array $b) use ($oldest, $today, $dir): int {
            $cmp = strcmp($oldest[$b['id']] ?? $today, $oldest[$a['id']] ?? $today) ?: $a['id'] <=> $b['id'];

            return $dir === 'desc' ? -$cmp : $cmp;
        });

        expect($got)->toBe(array_column($expected, 'id'), "sorted by ageing {$dir}")
            ->and($meta['ageing'])->toBe($first['ageing'], "strip, sorted by ageing {$dir}");
    }

    // A column it does not offer is ignored rather than trusted.
    expect(arrearsAt($this->dues, sort: 'household')['rows'])->toBe($first['rows'])
        ->and(arrearsAt($this->dues, sort: 'household')['table']['sort'])->toBeNull();

    // Search reads what the row prints: the lot, the phase, the household, its primary resident — and, since M32 F4,
    // what the register's search reads besides: any of the household's people by name, email or phone however
    // punctuated, and its vehicles. The surname is one of the residents the boards name, so it matches a person and not
    // "Lot"; the email and the phone are an owing household's own, typed as nobody stores them.
    $named = collect($whole)->first(static fn (array $r): bool => $r['resident'] !== 'No resident on record' && ! str_starts_with($r['resident'], 'Lot '));

    expect($named)->not->toBeNull();

    $surname = (string) collect(explode(' ', $named['resident']))->last();

    $people = DB::connection('tenant')->table('residents')
        ->join('households', 'households.id', '=', 'residents.household_id')
        ->whereNull('households.removed_at')
        ->get(['households.unit_id', 'residents.full_name', 'residents.email', 'residents.phone'])
        ->groupBy('unit_id');
    $plates = DB::connection('tenant')->table('household_vehicles')
        ->join('households', 'households.id', '=', 'household_vehicles.household_id')
        ->whereNull('households.removed_at')
        ->get(['households.unit_id', 'household_vehicles.plate'])
        ->groupBy('unit_id');

    $owing = collect($people->get($whole[0]['id'], []))->first();

    expect($owing?->email)->not->toBeNull()
        ->and($owing?->phone)->not->toBeNull();

    $email = mb_strtoupper((string) strstr((string) $owing->email, '@', true)).'@';
    $phone = '+1 '.preg_replace('/\D+/', '', (string) $owing->phone);

    foreach (['Lot 47', 'Phase 3', 'household', $surname, $email, $phone] as $term) {
        $digits = Residents::phoneDigits($term);
        $plate = str_replace([' ', '-'], '', mb_strtoupper($term));

        $expected = array_values(array_filter($whole, static function (array $row) use ($term, $digits, $plate, $people, $plates): bool {
            [$block, $reference] = array_pad(explode(' · ', $row['unit']), 2, '');

            return stripos($block, $term) !== false
                || stripos($reference, $term) !== false
                || stripos($row['household'], $term) !== false
                || stripos($row['resident'], $term) !== false
                || collect($people->get($row['id'], []))->contains(static fn (object $p): bool => stripos((string) $p->full_name, $term) !== false
                    || stripos((string) $p->email, $term) !== false
                    || ($digits !== null && str_contains((string) preg_replace('/\D+/', '', (string) $p->phone), $digits)))
                || collect($plates->get($row['id'], []))->contains(static fn (object $v): bool => str_contains(str_replace([' ', '-'], '', mb_strtoupper((string) $v->plate)), $plate));
        }));

        if (in_array($term, [$email, $phone], true)) {
            expect($expected)->not->toBe([], "search '{$term}' finds the owing household it was taken from, so the comparison is not vacuous");
        }

        $got = [];
        $meta = arrearsAt($this->dues, search: $term);

        for ($page = 1; $page <= max(1, $meta['table']['last']); $page++) {
            array_push($got, ...arrearsAt($this->dues, search: $term, page: $page)['rows']);
        }

        expect($got)->toBe($expected, "search '{$term}'")
            ->and($meta['total'])->toBe(count($expected))
            ->and($meta['filters']['search'])->toBe($term);
    }

    // Past the last page is an empty page, not an error and not page one again — and it
    // still knows how big the register is, which only a count asked on its own can say.
    $past = arrearsAt($this->dues, page: 999);

    expect($past['rows'])->toBe([])
        ->and($past['ageing'])->toBe($first['ageing'], 'a page with no row still carries the whole strip')
        ->and($past['total'])->toBe(count($whole))
        ->and(arrearsPager($past['table']))->toBe([
            'current' => 999,
            'last' => (int) ceil(count($whole) / Dues::ARREARS_PER_PAGE),
            'from' => null,
            'to' => null,
            'total' => count($whole),
        ]);

    // And a register nothing matches is one empty page of nought.
    $none = arrearsAt($this->dues, search: 'nothing-is-called-this');

    expect($none['rows'])->toBe([])
        ->and($none['ageing'])->toBe($first['ageing'], 'a search nothing matches still shows the estate\'s strip')
        ->and($none['total'])->toBe(0)
        ->and(arrearsPager($none['table']))->toBe(['current' => 1, 'last' => 1, 'from' => null, 'to' => null, 'total' => 0]);
});

it('keeps the withheld register exactly as it was', function (): void {
    $withheld = arrearsAt($this->dues, 'Phase 2', true, 'Lot', 'balance', 'desc', 3, maySeePosition: false);

    expect($withheld['rows'])->toBe([])
        ->and($withheld['ageing'])->toBe([])
        ->and($withheld['phases'])->toBe([])
        ->and($withheld['filters'])->toBe(['phase' => '', 'overdue' => false])
        ->and($withheld['positionWithheld'])->toBeTrue()
        ->and($withheld['positionReason'])->toBe(Dues::ARREARS_WITHHELD)
        ->and($withheld['table'])->toBeNull();
});

it('ages the cases the seed does not reach exactly as before: credit, a charge cleared to the cent, two due the same day, one not yet due, a reversal', function (): void {
    $tenant = DB::connection('tenant');
    $tenant->beginTransaction();

    try {
        $today = Dues::siteDate();
        $make = static fn (string $reference): Unit => Unit::query()->create(['reference' => $reference, 'block' => 'Phase 1', 'street' => 'Phase 1 Drive', 'type' => 'residential', 'status' => 'occupied']);
        $jmd = static fn (string $amount): Money => Money::of($amount, 'JMD');

        // In credit: paid more than was ever charged.
        $credit = $make('M11 Credit');
        $this->dues->charge($credit, $jmd('5000.00'), 'M11 edge', $today->copy()->subDays(80));
        $this->dues->receive($credit, $jmd('7000.00'), 'cash', $today->copy()->subDays(10));

        // Cleared to the cent: the first charge paid exactly, the second open.
        $exact = $make('M11 Exact');
        $this->dues->charge($exact, $jmd('3100.00'), 'M11 edge', $today->copy()->subDays(95));
        $this->dues->charge($exact, $jmd('3100.00'), 'M11 edge', $today->copy()->subDays(65));
        $this->dues->receive($exact, $jmd('3100.00'), 'bank', $today->copy()->subDays(60));

        // Two charges due the same day, the payment covering the first by id.
        $sameDay = $make('M11 Same day');
        $this->dues->charge($sameDay, $jmd('1000.00'), 'M11 edge first', $today->copy()->subDays(40));
        $this->dues->charge($sameDay, $jmd('2500.00'), 'M11 edge second', $today->copy()->subDays(40));
        $this->dues->receive($sameDay, $jmd('1000.00'), 'cash', $today->copy()->subDays(5));

        // A charge not yet due: owed from its day, not before (EST-B-01, M83 §1.2 item 2; it was read as owed now).
        $future = $make('M11 Future');
        $this->dues->charge($future, $jmd('4200.00'), 'M11 edge', $today->copy()->addDays(20));

        // A reversed charge in the middle of an otherwise open history.
        $reversed = $make('M11 Reversed');
        $this->dues->charge($reversed, $jmd('2000.00'), 'M11 edge', $today->copy()->subDays(120));
        $mistake = $this->dues->charge($reversed, $jmd('9000.00'), 'M11 edge mistake', $today->copy()->subDays(100));
        app(Ledger::class)->reverse(
            Journal::query()->where('reference', $mistake->journal_ref)->firstOrFail(),
            'M11 edge reversal',
        );
        $mistake->forceFill(['status' => 'reversed'])->save();
        $this->dues->charge($reversed, $jmd('2000.00'), 'M11 edge', $today->copy()->subDays(50));
        $this->dues->receive($reversed, $jmd('2000.00'), 'cash', $today->copy()->subDays(3));

        // A payment after an earlier as-at date is not counted as at that date.
        $late = $make('M11 Late payer');
        $this->dues->charge($late, $jmd('6000.00'), 'M11 edge', $today->copy()->subDays(130));
        $this->dues->receive($late, $jmd('6000.00'), 'bank', $today->copy()->subDays(2));

        $edge = [$credit->id, $exact->id, $sameDay->id, $future->id, $reversed->id, $late->id];

        foreach (arithmeticDates() as $asAt) {
            $label = $asAt?->toDateString() ?? 'now';
            $day = $asAt?->copy() ?? Dues::siteDate();

            /*
             * "Now" is the estate's today (bug hunt B023, and EST-B-01 for a charge dated later): the old arithmetic
             * read with no date counted a charge not yet due as owed now, so `now` is compared with the old code as at
             * today, which is what it always meant. Every named day is compared exactly as before.
             */
            expect($this->dues->unitBalances($asAt))->toBe(LegacyDues::unitBalances($day), 'balances with the edge cases as at '.$label)
                ->and(dayStrings($this->dues->oldestOpenChargeDates($day)))->toEqual(dayStrings(LegacyDues::oldestOpenChargeDates($day)), 'oldest open with the edge cases as at '.$label)
                ->and($this->dues->unitBuckets($asAt))->toBe(LegacyDues::unitBuckets($day), 'buckets with the edge cases as at '.$label)
                ->and($this->dues->unitBuckets($asAt, $edge))->toEqual(array_intersect_key(LegacyDues::unitBuckets($day), array_flip($edge)), 'narrowed buckets as at '.$label)
                ->and($this->dues->ageing($asAt))->toBe(LegacyDues::ageing($day), 'ageing with the edge cases as at '.$label);
        }

        // The register's strip, read in the page's own statement, is the old ageing as at today with every edge case.
        expect(array_column(array_slice(arrearsAt($this->dues)['ageing'], 1), 'value_minor', 'key'))->toBe(LegacyDues::ageing($today))
            ->and(arrearsAt($this->dues, search: 'nothing-is-called-this')['ageing'][0]['value_minor'])->toBe(array_sum(LegacyDues::ageing($today)))
            // Owing nothing today, the lot whose only charge is not yet due is not in arrears.
            ->and(arrearsAt($this->dues, search: 'M11 Future')['rows'])->toBe([]);

        // And the cases say what they were built to say, so none of them is vacuous.
        $oldest = dayStrings($this->dues->oldestOpenChargeDates($today, $edge));
        $balances = $this->dues->unitBalances(null, $edge);

        expect($balances[$credit->id])->toBe(-2000_00)
            ->and($oldest[$exact->id])->toBe($today->copy()->subDays(65)->toDateString())
            ->and($oldest[$sameDay->id])->toBe($today->copy()->subDays(40)->toDateString())
            ->and($oldest)->not->toHaveKey($future->id)
            // Not owed today, and owed in full from its own day (EST-B-01).
            ->and($balances[$future->id] ?? 0)->toBe(0)
            ->and($this->dues->unitBuckets(null, [$future->id]))->toBe([])
            ->and($this->dues->unitBalances($today->copy()->addDays(20), [$future->id])[$future->id])->toBe(4200_00)
            ->and($balances[$reversed->id])->toBe(2000_00)
            ->and($oldest[$reversed->id])->toBe($today->copy()->subDays(50)->toDateString())
            ->and($balances[$late->id])->toBe(0)
            ->and($this->dues->unitBalances($today->copy()->subDays(100), [$late->id])[$late->id])->toBe(6000_00);
    } finally {
        $tenant->rollBack();
    }

    // Nothing this test posted outlives it: the estate is the size it was.
    expect(EstateScaleFixture::volumes())->toBe(['lots' => EstateScaleFixture::LOTS, 'households' => EstateScaleFixture::HOUSEHOLDS, 'charges' => EstateScaleFixture::CHARGES]);
});
