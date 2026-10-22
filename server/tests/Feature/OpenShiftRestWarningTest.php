<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An open shift on top of the guard's own is flagged — bug hunt B064
|--------------------------------------------------------------------------
|
| `rest_warning` is "true when it falls within 11 hours of another of the guard's
| shifts". It was worked out as the distance from one shift's END to the other's
| START, each way, with the sign thrown away. Two shifts over the same 07:00–19:00
| are twelve hours apart on both of those measures, so an open shift lying exactly
| on top of the guard's own read as nothing to warn about — and so did one that
| contained it. The rest between two shifts is the gap from the end of the first
| to the start of the second; when they overlap there is none at all.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Carbon::setTestNow(Carbon::parse('2026-09-24 10:00:00', 'UTC'));

    $this->site = FacilitiesFixture::ESTATE;
    $this->mine = Post::create(['tenant_id' => $this->site, 'name' => 'Rest Gate', 'type' => 'gate', 'is_active' => true]);
    $this->other = Post::create(['tenant_id' => $this->site, 'name' => 'Rest Patrol', 'type' => 'patrol', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Rest Probe', 'employee_number' => 'GS-RST-1', 'psra_number' => 'PSRA-RST-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $this->mine->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Rest handset')['token'];

    // The guard's own shift: noon to midnight, UTC, tomorrow.
    Shift::create([
        'tenant_id' => $this->site, 'guard_id' => $this->guard->id, 'post_id' => $this->mine->id,
        'rostered_start' => Carbon::parse('2026-09-25 12:00:00', 'UTC'), 'rostered_end' => Carbon::parse('2026-09-26 00:00:00', 'UTC'),
        'status' => 'rostered',
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('warns about an open shift that overlaps the guard\'s own, and only about one too close to rest between', function () {
    $open = fn (string $start, string $end): int => Shift::create([
        'tenant_id' => $this->site, 'guard_id' => null, 'post_id' => $this->other->id,
        'rostered_start' => Carbon::parse($start, 'UTC'), 'rostered_end' => Carbon::parse($end, 'UTC'), 'status' => 'open',
    ])->id;

    $expected = [
        $open('2026-09-25 12:00:00', '2026-09-26 00:00:00') => true,   // exactly on top of it
        $open('2026-09-25 11:00:00', '2026-09-26 01:00:00') => true,   // contains it
        $open('2026-09-25 14:00:00', '2026-09-25 20:00:00') => true,   // inside it
        $open('2026-09-25 06:00:00', '2026-09-25 13:00:00') => true,   // runs into its start
        $open('2026-09-25 01:00:00', '2026-09-25 07:00:00') => true,   // ends five hours before it starts
        $open('2026-09-26 06:00:00', '2026-09-26 14:00:00') => true,   // starts six hours after it ends
        $open('2026-09-26 11:00:00', '2026-09-26 19:00:00') => false,  // eleven hours' rest: enough
        $open('2026-09-24 12:00:00', '2026-09-24 20:00:00') => false,  // sixteen hours before it
        $open('2026-09-28 12:00:00', '2026-09-28 20:00:00') => false,  // days away
    ];

    app('auth')->forgetGuards();
    $response = $this->withToken($this->token)->getJson('/api/v1/'.Catalogue::endpoints()['shifts.open']['uri'])->assertOk();
    ApiContract::assertMatches($response, 'shifts.open');
    FacilitiesFixture::boot();

    $warnings = collect($response->json('items'))->whereIn('id', array_keys($expected))->pluck('rest_warning', 'id')->all();

    ksort($warnings);
    ksort($expected);

    expect($warnings)->toBe($expected);
});
