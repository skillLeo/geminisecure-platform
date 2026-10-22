<?php

declare(strict_types=1);

use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use Database\Seeders\Estate\ArrearsPlan;
use Illuminate\Support\Carbon;
use Tests\Support\LegacyDues;
use Tests\Support\ScaleFixture;

/*
|--------------------------------------------------------------------------
| One household's dues, read from its own rows, are the estate's answer for it — M11 A2
|--------------------------------------------------------------------------
|
| `Dues::balanceOf()`, `oldestOpenChargeDate()`, `ageingOf()` and the new `bucketOf()` used
| to read EVERY unit's receivable, charges and payments and keep one row: the estate's
| balances grouped by unit, its every charge walked oldest first. At Phoenix Park's 2,000
| lots and 12,015 charges that was most of a resident's dues screen, their eligibility to
| vote and their amenity booking. They now read that unit's rows alone.
|
| The proof that nothing moved is the estate-wide methods themselves, which are untouched:
| for EVERY lot on the scale estate, today and at an earlier date, the per-unit answer must
| be exactly the entry the estate-wide answer holds for that lot.
|
*/

beforeEach(function (): void {
    ScaleFixture::boot();
});

it('gives every one of the 2,000 lots the same balance, oldest open charge, bucket and ageing strip from its own rows as the whole estate\'s reading gives it', function (string $when): void {
    /*
     * The earlier date is the middle of last month, not "45 days ago": the seeder dates its charges from the first of
     * this month (EstateFinanceSeeder), so a fixed count of days back landed in a different part of the billing cycle
     * on every day of the month, and on the 1st fewer than 50 lots owed anything yet (found by M24's suite, 1 Oct).
     */
    $asAt = $when === 'today' ? null : Carbon::today()->startOfMonth()->subMonth()->addDays(14);
    $today = $asAt?->copy() ?? Carbon::today();
    $dues = app(Dues::class);

    $balances = $dues->unitBalances($asAt);
    $oldest = $dues->oldestOpenChargeDates($today);
    $buckets = $dues->unitBuckets($asAt);
    // Each charge in its own bucket (EST-S-02, M85 A3), read once for the whole estate as at the same day.
    $strips = LegacyDues::chargeAgeing($asAt ?? Dues::siteDate());

    $units = Unit::query()->orderBy('id')->get();
    expect($units)->toHaveCount(ScaleFixture::LOTS);

    $owing = 0;

    foreach ($units as $unit) {
        $balance = $dues->balanceOf($unit, $asAt)->getMinorAmount()->toInt();
        $owing += $balance > 0 ? 1 : 0;

        expect($balance)->toBe($balances[$unit->id] ?? 0, "{$unit->reference}'s balance")
            ->and($dues->oldestOpenChargeDate($unit, $asAt)?->toDateString())->toBe(($oldest[$unit->id] ?? null)?->toDateString(), "{$unit->reference}'s oldest open charge")
            ->and($dues->bucketOf($unit, $asAt))->toBe($buckets[$unit->id] ?? null, "{$unit->reference}'s bucket");

        $strip = $dues->ageingOf($unit, $asAt);
        $expected = $strips[$unit->id] ?? array_fill_keys(array_keys(Dues::BUCKETS), 0);

        expect($strip)->toBe($expected, "{$unit->reference}'s ageing strip")
            ->and(array_sum($strip))->toBe(max(0, $balances[$unit->id] ?? 0), "{$unit->reference}'s strip adds up to its balance");
    }

    // The comparison is only a proof if it covered lots in arrears as well as settled ones.
    expect($owing)->toBeGreaterThan(50);
})->with(['today', 'the middle of last month']);

it('still totals the estate\'s ageing to the figure the boards state', function (): void {
    // The estate-wide reads are the ones the committee's screens use, and they are untouched:
    // the fitted arrears the finance seeder solves for still come back to the cent.
    expect(array_sum(app(Dues::class)->ageing()))->toBe(array_sum(ArrearsPlan::AGEING));
});
