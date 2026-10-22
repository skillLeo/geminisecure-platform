<?php

declare(strict_types=1);

use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Principal;
use App\Live\Auth\Rules\Rule;
use App\Live\ChannelName;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Live\CrossTenantChannelProbe;
use App\Services\Live\LivePayloadAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The live isolation probe, proved against estates of the suite's own (gate:isolation 11-14)
|--------------------------------------------------------------------------
|
| gate:isolation runs CrossTenantChannelProbe against the real estates, where a test must never
| reach. So the same class runs here, against the facilities estate and a second one, and is shown
| three ways: it passes when isolation holds, it reports a LEAK when a rule is widened, and it
| FAILS, rather than passing, when the door stops signing for real. A proof nobody has seen fail
| is not a proof.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    LiveAuthFixture::credentials();

    $this->a = Tenant::findOrFail(FacilitiesFixture::ESTATE);
    $this->b = Tenant::findOrFail(LiveAuthFixture::twinEstate('probetwin'));

    $unit = FacilitiesFixture::scratchUnit('Lot 961');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Probe household', 'access_restricted' => false]);
    $resident = Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Probe Resident'], ['relationship' => 'owner', 'is_primary' => true]);

    tenancy()->end();

    $this->subjects = [
        'member' => FacilitiesFixture::viewer(Role::PRESIDENT),
        'guard' => LiveAuthFixture::guard(FacilitiesFixture::ESTATE),
        'resident' => LiveAuthFixture::account(FacilitiesFixture::ESTATE, [
            'status' => ResidentAccount::ACTIVE, 'resident_id' => $resident->id, 'unit_id' => $unit->id,
        ]),
    ];
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @return list<array{status: string, proves: string, detail: string}> */
function probeRun(object $test): array
{
    return app(CrossTenantChannelProbe::class)->run($test->a, $test->b, $test->subjects);
}

it('passes against two estates when isolation holds: every control granted and truly signed, every refusal refused, nothing left behind', function () {
    $tokensBefore = DB::connection('mysql')->table('personal_access_tokens')->count();
    $accountsBefore = ResidentAccount::query()->count();

    $results = probeRun($this);
    $failed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'fail'));

    expect($failed)->toBe([], json_encode($failed, JSON_PRETTY_PRINT))
        ->and(collect($results)->where('status', 'skip')->all())->toBe([])
        ->and(collect($results)->where('status', 'pass')->count())->toBeGreaterThan(40);

    // The controls ran, and ran first on each door.
    $proves = array_column($results, 'proves');
    expect($proves[0])->toStartWith('CONTROL')
        ->and(collect($proves)->filter(fn (string $p) => str_starts_with($p, 'CONTROL'))->count())->toBeGreaterThanOrEqual(4)
        // The pending-resident row of section 3.7, on the grant and on catch-up.
        ->and(collect($proves)->contains(fn (string $p) => str_contains($p, 'pending account\'s catch-up is ok for resident.* and claim.*')))->toBeTrue();

    // Its own transaction is gone and everything it made with it; the caller's is still open.
    expect(DB::connection('mysql')->transactionLevel())->toBe(1)
        ->and(DB::connection('mysql')->table('personal_access_tokens')->count())->toBe($tokensBefore)
        ->and(ResidentAccount::query()->count())->toBe($accountsBefore)
        ->and($this->subjects['member']->fresh()->status)->toBe('active')
        ->and($this->subjects['guard']->fresh()->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and(tenancy()->initialized)->toBeFalse();
});

it('reports a LEAK, and fails, when a rule is widened', function () {
    $everyone = new class implements Rule
    {
        public function central(Principal $principal, ChannelName $channel): bool
        {
            return true;
        }

        public function needsEstate(): bool
        {
            return false;
        }

        public function estate(Principal $principal, ChannelName $channel): bool
        {
            return true;
        }
    };

    // The estate feeds let anybody in: another estate's security feed is now "granted".
    app()->instance(ChannelRegistry::class, new ChannelRegistry([ChannelName::ESTATE_FEED => $everyone]));

    $results = probeRun($this);
    $leaks = collect($results)->filter(fn (array $r) => $r['status'] === 'fail' && str_contains($r['detail'], 'LEAK'));

    expect($leaks)->not->toBeEmpty()
        ->and($leaks->pluck('proves')->implode(' | '))->toContain("estate.{$this->b->getTenantKey()}.security");
});

it('fails, rather than passing, when the door answers 200 without truly signing (a null broadcaster)', function () {
    // What a door wired to the null broadcaster would say: yes to everything, signed with nothing.
    Route::middleware(['web', 'auth'])->post('live/auth', fn () => response()->json([
        'channels' => collect((array) request('channel_names'))->mapWithKeys(fn ($w) => [$w => ['auth' => '', 'channel_data' => '', 'streams' => ['c']]])->all(),
        'refused' => [],
    ]));

    $results = probeRun($this);

    expect(collect($results)->where('status', 'fail')->pluck('proves')->first())->toStartWith('CONTROL')
        ->and(collect($results)->where('status', 'pass')->pluck('proves')->filter(fn ($p) => str_starts_with($p, 'refused:') && str_contains($p, "on {$this->a->getTenantKey()}'s host"))->all())->toBe([]);
});

it('skips, and says why, every door it has nobody to probe with, rather than calling that a pass', function () {
    $results = app(CrossTenantChannelProbe::class)->run($this->a, $this->b, []);

    expect(collect($results)->where('status', 'skip')->pluck('proves')->all())->toBe([
        'STEP 11 - the console door', 'STEP 12 - the guard door', 'STEP 12 - the resident door',
    ]);
});

it('STEP 14 passes on a clean log and fails when a forbidden key is planted in one estate\'s summary column', function () {
    $audit = app(LivePayloadAudit::class);

    expect(collect($audit->run([FacilitiesFixture::ESTATE]))->where('status', 'fail')->all())->toBe([]);

    FacilitiesFixture::boot();
    DB::connection('tenant')->table('live_events')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE, 'type' => 'probe.changed', 'entity' => 'probe', 'entity_id' => 'planted', 'mode' => 'ref',
        'summary' => json_encode(['status' => 'open', 'visitor_name' => 'Kemar Brown']), 'channels' => json_encode([]),
        'available_at' => DB::raw('UTC_TIMESTAMP(6)'), 'created_at' => DB::raw('UTC_TIMESTAMP(6)'),
    ]);
    tenancy()->end();

    try {
        $results = collect($audit->run([FacilitiesFixture::ESTATE]));
        $failed = $results->where('status', 'fail')->first();

        expect($failed)->not->toBeNull()
            ->and($failed['proves'])->toContain('estate '.FacilitiesFixture::ESTATE)
            ->and($failed['detail'])->toContain('visitor_name')
            // It names the key, never the value.
            ->and($failed['detail'])->not->toContain('Kemar');
    } finally {
        FacilitiesFixture::boot();
        DB::connection('tenant')->table('live_events')->where('entity_id', 'planted')->delete();
        tenancy()->end();
    }
});
