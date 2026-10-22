<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| GET /incidents/mine reads its hundred reports in two queries, not two hundred — M11 A2
|--------------------------------------------------------------------------
|
| Each report on an officer's list re-read its own row for the location and shift the list
| query had already brought back, and counted its own photographs: two queries a report,
| up to a hundred reports. The row is read once, and the photographs are counted for every
| report together.
|
| The oracle is the table itself: every report's location, shift and photograph count, read
| straight from `security_incidents` and `incident_media`, must be what the handset is sent.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Incident Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Incident Reporter', 'employee_number' => 'GS-INC-M11', 'psra_number' => 'PSRA-INC-M11',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Incident handset')['token'];
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** Reports numbered `$from` to `$to`: every third without a location or a shift, photographs on some. */
function fileReports(Guard $guard, int $from, int $to): void
{
    $db = DB::connection('mysql');
    $now = Carbon::now();

    for ($k = $from; $k <= $to; $k++) {
        $shift = $k % 3 === 0 ? null : (int) $db->table('shifts')->insertGetId([
            'tenant_id' => $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $guard->post_id,
            'rostered_start' => $now->copy()->subDays($k), 'rostered_end' => $now->copy()->subDays($k)->addHours(12),
            'status' => 'completed', 'is_simulated' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $incident = (int) $db->table('security_incidents')->insertGetId([
            'tenant_id' => $guard->tenant_id, 'guard_id' => $guard->id, 'guard_name' => $guard->full_name, 'kind' => 'Report '.$k,
            'detail' => 'Reported from the post, number '.$k.'.', 'severity' => 'low', 'status' => 'open',
            'occurred_at' => $now->copy()->subHours($k), 'location' => $k % 3 === 0 ? null : 'Checkpoint '.$k, 'shift_id' => $shift,
            'logged_by_name' => 'Incident Reporter (Guard App)', 'is_simulated' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        for ($p = 0; $p < $k % 4; $p++) {
            $db->table('incident_media')->insert([
                'security_incident_id' => $incident, 'guard_id' => $guard->id, 'path' => 'incident-media/m11/'.$incident.'-'.$p.'.jpg',
                'filename' => 'photo-'.$p.'.jpg', 'content_type' => 'image/jpeg', 'bytes' => 100, 'sha256' => hash('sha256', $incident.'-'.$p),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}

it('sends every report\'s location, shift and photograph count exactly as the table holds them', function (): void {
    fileReports($this->guard, 1, 14);

    $uri = '/api/v1/'.Catalogue::endpoints()['incidents.me']['uri'];
    app('auth')->forgetGuards();
    $response = $this->withToken($this->token)->getJson($uri)->assertOk();
    ApiContract::assertMatches($response, 'incidents.me');

    $truth = DB::connection('mysql')->table('security_incidents')
        ->where('guard_id', $this->guard->id)
        ->orderByDesc('occurred_at')
        ->get(['id', 'location', 'shift_id'])
        ->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'location' => $row->location,
            'shift_id' => $row->shift_id === null ? null : (int) $row->shift_id,
            'media_count' => DB::connection('mysql')->table('incident_media')->where('security_incident_id', $row->id)->count(),
        ])
        ->all();

    $sent = collect($response->json('items'))->map(static fn (array $item): array => [
        'id' => $item['id'], 'location' => $item['location'], 'shift_id' => $item['shift_id'], 'media_count' => $item['media_count'],
    ])->all();

    expect($sent)->toBe($truth)
        // Every shape a report can take was sent: with and without a place and a shift, and
        // with none, one, two and three photographs.
        ->and(collect($sent)->pluck('media_count')->unique()->sort()->values()->all())->toBe([0, 1, 2, 3])
        ->and(collect($sent)->pluck('location')->contains(null))->toBeTrue()
        ->and(collect($sent)->pluck('shift_id')->filter()->count())->toBeGreaterThan(0);
});

it('asks the same number of queries for twenty-eight reports as for fourteen', function (): void {
    $uri = '/api/v1/'.Catalogue::endpoints()['incidents.me']['uri'];
    $count = function () use ($uri): array {
        app('auth')->forgetGuards();
        $this->withToken($this->token)->getJson($uri)->assertOk();
        app('auth')->forgetGuards();
        QueryBudget::asANewRequest();

        return QueryBudget::measure(fn () => $this->withToken($this->token)->getJson($uri)->assertOk());
    };

    fileReports($this->guard, 1, 14);
    $fourteen = $count();

    fileReports($this->guard, 15, 28);
    $twentyEight = $count();

    $perReport = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => str_contains($s['sql'], 'incident_media') || str_contains($s['sql'], 'select `location`, `shift_id`'),
    ));

    // The token's last-used stamp is written only when the second has moved on — the clock's
    // doing, not the endpoint's (see `AppApiQueryBudgetTest`) — so it is not counted.
    $counted = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => ! str_starts_with(strtolower(ltrim($s['sql'])), 'update `personal_access_tokens` set `last_used_at`'),
    ));

    expect(count($twentyEight['result']->json('items')))->toBe(28)
        ->and($perReport($fourteen))->toBe(1)
        ->and($perReport($twentyEight))->toBe(1)
        ->and($counted($twentyEight))->toBe($counted($fourteen));
});
