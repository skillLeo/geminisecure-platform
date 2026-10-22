<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\Dispatch\LiveMap;
use App\Services\Estate\EstateSecurity;
use App\Services\Tracking\EstateBounds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A position too far from its estate is named, never plotted (Hassam, 12 Oct)
|--------------------------------------------------------------------------
|
| Phoenix Park's map spanned Jamaica to Pakistan: its Main Gate is stored 36 km from the estate, and seven open
| panics were pressed on development phones abroad. A map fits itself to everything it draws, so one bad coordinate
| stretched the whole view. A gate, checkpoint, alert or incident outside its estate's boundary is now listed as
| "Position not confirmed" with how far out it is, and only what lies on the estate is plotted, so the map frames the
| estate (Hassam: "Anything outside it, alert or gate"). An estate with no boundary yet draws as it always did. Every
| row is written inside a transaction and rolled back: nothing is deleted.
|
*/

/** A square of about 1.1 km round Phoenix Park's own Back Gate (17.95465, -76.92604). */
const BOUNDS_SQUARE = [[17.950, -76.931], [17.960, -76.931], [17.960, -76.921], [17.950, -76.921]];

beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();

    $suffix = Str::lower(Str::random(5));
    $this->estate = 'bounds'.$suffix;
    $this->open = 'nobounds'.$suffix;

    foreach ([$this->estate => 'Bounded Estate', $this->open => 'Unbounded Estate'] as $id => $name) {
        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id, 'name' => $name.' '.$suffix, 'parish' => 'St. Catherine', 'status' => 'active',
            'demo' => 0, 'created_at' => now(), 'updated_at' => now(), 'data' => '{}',
        ]);
    }

    // The first estate's site plan draws its boundary; the second's draws none yet.
    DB::connection('mysql')->table('estate_sites')->insert([
        'tenant_id' => $this->estate, 'boundary' => json_encode(BOUNDS_SQUARE), 'boundary_source' => 'A test outline',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A viewer who watches these two estates alone, so nothing else on the platform reaches the map.
    $role = Role::findOrCreate('test.bounds_scoped', 'web');
    $role->forceFill(['scope_default' => AccessScope::AssignedSites->value])->save();

    $this->viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $this->viewer->syncRoles([$role->name]);

    foreach ([$this->estate, $this->open] as $id) {
        EstateAssignment::create(['user_id' => $this->viewer->id, 'tenant_id' => $id, 'role_id' => $role->id, 'is_active' => true]);
    }

    $this->viewer = $this->viewer->fresh();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

function boundsGate(string $estate, string $name, float $lat, float $lng): Post
{
    return Post::query()->forceCreate([
        'tenant_id' => $estate, 'name' => $name, 'type' => 'gate', 'is_active' => true,
        'latitude' => $lat, 'longitude' => $lng, 'geofence_radius_m' => 25,
    ]);
}

function boundsAlert(string $estate, string $unit, float $lat, float $lng): DuressAlert
{
    $fired = now()->startOfSecond()->subMinutes(5);

    return DuressAlert::query()->forceCreate([
        'tenant_id' => $estate, 'kind' => 'panic', 'mode' => 'audible', 'raised_by_name' => $unit.' household',
        'unit_reference' => $unit, 'status' => 'open', 'device_time' => $fired, 'server_time' => $fired,
        'latitude' => $lat, 'longitude' => $lng, 'clock_skewed' => false, 'captured_offline' => false,
        'idempotency_key' => 'bounds-'.Str::random(12), 'is_simulated' => false, 'is_drill' => false,
    ]);
}

it('says how far outside the boundary a position is, and nothing for one on the estate', function (): void {
    expect(EstateBounds::notConfirmed(BOUNDS_SQUARE, 17.9546534, -76.9260396))->toBeNull()
        // On the boundary's own line: on the estate.
        ->and(EstateBounds::notConfirmed(BOUNDS_SQUARE, 17.950, -76.926))->toBeNull()
        // 334 m south of the square: outside it, so named rather than drawn; under a kilometre, in metres.
        ->and(EstateBounds::notConfirmed(BOUNDS_SQUARE, 17.947, -76.926))->toBe('334 m outside the estate\'s boundary')
        // 7.4 km south: tenths of a kilometre below ten.
        ->and(EstateBounds::notConfirmed(BOUNDS_SQUARE, 17.8834, -76.926))->toBe('7.4 km outside the estate\'s boundary')
        // Phoenix Park's Main Gate as stored, 38 km from this square: whole kilometres from ten on.
        ->and(EstateBounds::notConfirmed(BOUNDS_SQUARE, 18.0839337, -77.2662547))->toBe('38 km outside the estate\'s boundary')
        // An estate with no boundary has nothing to judge a position against.
        ->and(EstateBounds::notConfirmed(null, 32.07, 72.64))->toBeNull();
});

it('plots on the live map only what lies on its estate, and names the rest with how far out it is', function (): void {
    $backGate = boundsGate($this->estate, 'Back Gate', 17.9546534, -76.9260396);
    $mainGate = boundsGate($this->estate, 'Main Gate', 18.0839337, -77.2662547);
    $elsewhere = boundsGate($this->open, 'Front Gate', 18.10, -76.70);
    $nearby = boundsAlert($this->estate, 'Lot 12', 17.9551, -76.9255);
    $outside = boundsAlert($this->estate, 'Lot 3', 17.947, -76.926);
    $abroad = boundsAlert($this->estate, 'Lot 47', 32.07, 72.64);

    $geo = (new LiveMap)->forViewer($this->viewer)['geo'];
    $named = collect($geo['unconfirmed'])->keyBy('id');

    expect(collect($geo['posts'])->pluck('id')->sort()->values()->all())->toBe(collect(['post-'.$backGate->id, 'post-'.$elsewhere->id])->sort()->values()->all())
        ->and(collect($geo['alerts'])->pluck('id')->all())->toBe(['alert-'.$nearby->id])
        ->and($named->keys()->sort()->values()->all())->toBe(collect(['post-'.$mainGate->id, 'alert-'.$outside->id, 'alert-'.$abroad->id])->sort()->values()->all())
        ->and($named['alert-'.$outside->id]['why'])->toBe('334 m outside the estate\'s boundary')
        ->and($named['alert-'.$outside->id]['href'])->toBe('/dispatch/alerts/'.$outside->id)
        ->and($named['post-'.$mainGate->id]['why'])->toBe('38 km outside the estate\'s boundary')
        ->and($named['alert-'.$abroad->id]['href'])->toBe('/dispatch/alerts/'.$abroad->id)
        ->and($named['alert-'.$abroad->id]['why'])->toEndWith(' km outside the estate\'s boundary')
        ->and(collect($geo['boundaries'])->pluck('id')->all())->toBe(['boundary-'.$this->estate]);

    // The list names; only the map draws. Nothing on it carries a position.
    expect(str_contains((string) json_encode($geo['unconfirmed']), '"lat"'))->toBeFalse();
});

it('does the same on the estate\'s own Security map, for its gates and for the alerts it pins', function (): void {
    $backGate = boundsGate($this->estate, 'Back Gate', 17.9546534, -76.9260396);
    $mainGate = boundsGate($this->estate, 'Main Gate', 18.0839337, -77.2662547);
    $nearby = boundsAlert($this->estate, 'Lot 12', 17.9551, -76.9255);
    $abroad = boundsAlert($this->estate, 'Lot 47', 32.07, 72.64);

    $security = new EstateSecurity;
    $geo = $security->geo($this->estate);
    $rows = collect($security->alerts($this->estate))->keyBy('id');

    expect(collect($geo['posts'])->pluck('id')->all())->toBe(['post-'.$backGate->id])
        ->and($geo['unconfirmed'])->toBe([['id' => 'post-'.$mainGate->id, 'title' => 'Main Gate', 'why' => '38 km outside the estate\'s boundary']])
        ->and($rows[$nearby->id]['off_estate'])->toBeNull()
        ->and($rows[$abroad->id]['off_estate'])->toEndWith(' km outside the estate\'s boundary');

    // With no boundary yet there is nothing to judge against: the estate's gate is drawn where it is stored.
    $far = boundsGate($this->open, 'Front Gate', 18.10, -76.70);

    expect(collect((new EstateSecurity)->geo($this->open)['posts'])->pluck('id')->all())->toBe(['post-'.$far->id]);
});
