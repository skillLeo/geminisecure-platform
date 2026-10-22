<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The gate's unit search on an estate the size Phoenix Park actually is
|--------------------------------------------------------------------------
|
| Phoenix Park Village 1 has 2,000 lots across FIVE phases. Two things were
| true before 23 September 2026 and both are wrong at that size:
|
|   1. The phase chips were `[1, 2, 3]` written into the Guard App's screen,
|      because the board drew three chips. Phases 4 and 5 could not be
|      filtered at all — not at Phoenix Park, not at any estate that is not
|      three phases.
|   2. The filter was applied by the app to the twenty rows the server had
|      already returned. That READS as a phase filter and is not one:
|      "Phase 5" applied to twenty Phase 1 rows shows an empty screen to an
|      officer with a visitor standing in front of them.
|
| So the phase list belongs to the estate, the filter belongs to the query,
| and the count belongs to the whole estate rather than to the page.
|
*/

use App\Models\Estate\Household;
use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/** Enough lots to be an estate rather than a fixture: more than one page of results per phase. */
const SCALE_PHASES = 5;
const SCALE_LOTS_PER_PHASE = 25;

/** Every row this test makes is named so, and only these are swept up afterwards. */
const SCALE_MARK = 'Scale';

beforeEach(function (): void {
    FacilitiesFixture::boot();

    // Swept here as well as in afterEach: a run that died mid-test leaves its handset behind, and
    // the service number is unique.
    DB::connection('mysql')->table('guards')->where('employee_number', 'GS-SCALE-1')->delete();
    DB::connection('mysql')->table('posts')->where('name', 'Scale Gate')->delete();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Scale Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Kemar Scale',
        'employee_number' => 'GS-SCALE-1',
        'psra_number' => 'PSRA-SCALE-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Scale handset')['token'];

    foreach (range(1, SCALE_PHASES) as $sequence) {
        $name = SCALE_MARK.' Phase '.$sequence;
        Phase::query()->create(['name' => $name, 'sequence' => 100 + $sequence, 'block_count' => 1, 'status' => 'active']);

        foreach (range(1, SCALE_LOTS_PER_PHASE) as $lot) {
            $number = ($sequence - 1) * SCALE_LOTS_PER_PHASE + $lot;
            $unit = Unit::query()->create([
                'reference' => $name.' · Lot '.$number,
                'block' => $name,
                'status' => 'occupied',
            ]);
            Household::query()->create(['unit_id' => $unit->id, 'name' => SCALE_MARK.' household '.$number, 'access_restricted' => false]);
        }
    }
});

afterEach(function (): void {
    // An HTTP request leaves the default connection pointed at tenancy's own making; boot() puts it
    // back, or these deletes look for the estate's tables in the platform database.
    FacilitiesFixture::boot();

    $units = Unit::query()->where('block', 'like', SCALE_MARK.'%')->pluck('id');
    Household::query()->whereIn('unit_id', $units)->delete();
    Unit::query()->whereIn('id', $units)->delete();
    Phase::query()->where('name', 'like', SCALE_MARK.'%')->delete();

    DB::connection('mysql')->table('guards')->where('employee_number', 'GS-SCALE-1')->delete();
    DB::connection('mysql')->table('posts')->where('name', 'Scale Gate')->delete();
});

/** @param array<string, string> $query */
function scaleSearch(string $token, array $query): array
{
    app('auth')->forgetGuards();

    $answer = test()->withToken($token)->getJson('/api/v1/gate/search?'.http_build_query($query))->assertOk()->json();

    FacilitiesFixture::boot();

    return $answer;
}

it("hands the Guard App the estate's OWN phases, in the estate's own order", function (): void {
    app('auth')->forgetGuards();
    $me = test()->withToken($this->token)->getJson('/api/v1/guards/me')->assertOk();
    FacilitiesFixture::boot();

    $mine = collect($me->json('site.phases'))->pluck('name')->filter(fn (string $n): bool => str_starts_with($n, SCALE_MARK))->values();

    expect($mine)->toHaveCount(SCALE_PHASES)
        ->and($mine->first())->toBe(SCALE_MARK.' Phase 1')
        ->and($mine->last())->toBe(SCALE_MARK.' Phase 5');

    $fifth = collect($me->json('site.phases'))->firstWhere('name', SCALE_MARK.' Phase 5');
    expect($fifth['unit_count'])->toBe(SCALE_LOTS_PER_PHASE);
});

it('filters by phase in the QUERY, so the fifth phase is reachable though no fifth-phase lot is on the first page', function (): void {
    // The page the app used to filter: one page out of the whole estate.
    $all = scaleSearch($this->token, ['unit' => SCALE_MARK.' Phase']);
    expect($all['total'])->toBe(SCALE_PHASES * SCALE_LOTS_PER_PHASE)->and($all['items'])->toHaveCount($all['limit']);

    $onThePage = collect($all['items'])->pluck('unit');
    $fifthOnThePage = $onThePage->filter(fn (string $u): bool => str_contains($u, SCALE_MARK.' Phase 5'));

    $fifth = scaleSearch($this->token, ['unit' => SCALE_MARK.' Phase', 'phase' => SCALE_MARK.' Phase 5']);

    // The whole phase is found — not the part of it that happened to fit on the unfiltered page,
    // which is all a filter applied to `items` could ever have returned.
    expect($fifth['total'])->toBe(SCALE_LOTS_PER_PHASE)
        ->and($fifth['total'])->toBeGreaterThan($fifthOnThePage->count());

    foreach ($fifth['items'] as $item) {
        expect($item['unit'])->toContain(SCALE_MARK.' Phase 5');
    }

    // And it reaches lots the unfiltered page never carried, which is the proof the ESTATE was
    // searched rather than the page re-read.
    expect(collect($fifth['items'])->pluck('unit')->diff($onThePage)->all())->not->toBe([]);
});

it('says how many the ESTATE matched, not how many fitted on the page', function (): void {
    $page = scaleSearch($this->token, ['unit' => SCALE_MARK.' Phase']);

    expect($page['total'])->toBeGreaterThan(count($page['items']))
        ->and(count($page['items']))->toBe($page['limit']);
});

it('comes back empty honestly, and a phase the estate has not got never widens to everything', function (): void {
    expect(scaleSearch($this->token, ['unit' => SCALE_MARK.' Lot 9999'])['total'])->toBe(0);

    // A dropped filter would answer with the whole estate here. It answers with nothing.
    expect(scaleSearch($this->token, ['unit' => SCALE_MARK.' Phase', 'phase' => SCALE_MARK.' Phase 9'])['total'])->toBe(0);
});

it('hands back one page however much of the estate matches, and answers fast enough for a gate', function (): void {
    // The widest search this estate allows: every lot in it.
    $started = microtime(true);
    $widest = scaleSearch($this->token, ['unit' => SCALE_MARK]);
    $elapsedMs = (microtime(true) - $started) * 1000;

    expect($widest['total'])->toBe(SCALE_PHASES * SCALE_LOTS_PER_PHASE)
        ->and(count($widest['items']))->toBe($widest['limit'])
        ->and(count($widest['items']))->toBeLessThan($widest['total']);

    // Generous on purpose. This is a floor against a search that reads the estate into memory and
    // slices it in PHP — which is what 2,000 lots punishes — not a benchmark. The production figure
    // is measured against the real estate and recorded in STATE.md.
    expect($elapsedMs)->toBeLessThan(2000);
});
