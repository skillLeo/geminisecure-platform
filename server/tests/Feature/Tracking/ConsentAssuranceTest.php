<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Tracking\ConsentAssurance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What the estate is told about its guards' consent: assurance, not records (Hassam, 12 October 2026)
|--------------------------------------------------------------------------
|
| One line, no names, no dates, and only ever true: every officer clocked in here now has agreed to the wording in force,
| or nobody is on duty, or not everybody has. An answer to earlier words does not count; an officer on another estate's
| shift, or one whose shift has ended, is not on duty here. One query.
|
*/

function assuranceOnDuty(string $estate, bool $clockedIn = true): Guard
{
    static $n = 0;
    $n++;

    $guard = Guard::create([
        'full_name' => 'Assured Guard '.$n, 'employee_number' => 'GS-ASR-'.$n, 'psra_number' => 'PSRA-ASR-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);

    Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11),
        'actual_start' => $clockedIn ? now()->subMinutes(50) : null, 'status' => $clockedIn ? 'on_duty' : Shift::ROSTERED,
    ]);

    return $guard;
}

function assuranceAnswer(Guard $guard, string $decision, string $version): void
{
    DB::connection('mysql')->table('guard_tracking_consents')->insert([
        'guard_id' => $guard->id, 'policy_version' => $version, 'decision' => $decision, 'decided_at' => Carbon::now('UTC'),
    ]);
}

beforeEach(function () {
    config(['tracking.policy_version' => '2026-10-10']);

    foreach (['assurea', 'assureb'] as $estate) {
        DB::table('tenants')->updateOrInsert(['id' => $estate], ['name' => 'Estate '.$estate, 'data' => json_encode(['name' => 'Estate '.$estate]), 'created_at' => now(), 'updated_at' => now()]);
    }
});

it('says nobody is on duty when no officer is clocked in here, whoever is rostered or elsewhere', function () {
    assuranceOnDuty('assurea', clockedIn: false);
    assuranceAnswer(assuranceOnDuty('assureb'), 'granted', '2026-10-10');

    expect(ConsentAssurance::lineFor('assurea'))->toBe(ConsentAssurance::NOBODY_ON_DUTY);
});

it('gives the assurance only when every officer on duty here agreed to the wording in force, in one query', function () {
    $first = assuranceOnDuty('assurea');
    $second = assuranceOnDuty('assurea');

    assuranceAnswer($first, 'granted', '2026-10-10');
    assuranceAnswer($second, 'refused', '2026-10-10');
    assuranceAnswer($second, 'granted', '2026-10-10');

    DB::connection('mysql')->enableQueryLog();
    DB::connection('mysql')->flushQueryLog();

    expect(ConsentAssurance::lineFor('assurea'))->toBe(ConsentAssurance::ALL_AGREED)
        ->and(DB::connection('mysql')->getQueryLog())->toHaveCount(1);
});

it('says not every officer has agreed, with no name, when one refused, has not answered, or answered earlier words', function (string $how) {
    assuranceAnswer(assuranceOnDuty('assurea'), 'granted', '2026-10-10');
    $other = assuranceOnDuty('assurea');

    match ($how) {
        'refused' => assuranceAnswer($other, 'refused', '2026-10-10'),
        'earlier words' => assuranceAnswer($other, 'granted', '2026-10-03'),
        default => null,
    };

    $line = ConsentAssurance::lineFor('assurea');

    expect($line)->toBe(ConsentAssurance::NOT_ALL_AGREED)
        ->and(str_contains($line, $other->full_name))->toBeFalse();
})->with(['refused', 'not answered', 'earlier words']);
