<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\PatrolCheckpoint;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §7.2 step 4, §11: Places' settings, which client it opens on, and sample rows marked
|--------------------------------------------------------------------------
*/

const M30_SETTINGS = 'm30settings';
const M30_INTERNAL = 'm30internal';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    FacilitiesFixture::platform();

    foreach ([M30_SETTINGS => ['Settings Test Estate', true], M30_INTERNAL => ['Aaa Internal Test Estate', false]] as $id => [$name, $listed]) {
        DB::connection('mysql')->table('tenants')->where('id', $id)->delete();
        DB::connection('mysql')->table('tenants')->insert(['id' => $id, 'name' => $name, 'listed' => $listed, 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    }
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_SETTINGS, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);

    $this->director = User::factory()->create(['email' => 'm30-settings-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

it('saves the supervisor\'s number and keeps the estate\'s area as it was', function () {
    DB::connection('mysql')->table('estate_sites')->insert(['tenant_id' => M30_SETTINGS, 'latitude' => 17.9612, 'longitude' => -76.8821, 'geofence_radius_m' => 400, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->director)->post('/guards/places/'.M30_SETTINGS.'/supervisor', ['phone' => '876-555-0142'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'The supervisor\'s number is saved.');

    $site = DB::connection('mysql')->table('estate_sites')->where('tenant_id', M30_SETTINGS)->first();
    expect($site->supervisor_phone)->toBe('876-555-0142')
        ->and((float) $site->latitude)->toBe(17.9612)
        ->and((int) $site->geofence_radius_m)->toBe(400);

    $this->actingAs($this->director)->post('/guards/places/'.M30_SETTINGS.'/supervisor', ['phone' => 'call me'])->assertSessionHasErrors('places');
});

it('shows a client its guards\' positions, or stops, on its contract; and says why not without one', function () {
    $this->actingAs($this->director)->post('/guards/places/'.M30_SETTINGS.'/guard-positions', ['sees' => true])
        ->assertSessionHasErrors(['places' => 'This client has no contract yet. Set its plan first; the setting belongs to the contract.']);

    $plan = Plan::query()->create(['key' => 'm30settings', 'name' => 'Settings Test', 'price_per_unit_minor' => 300_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 99]);
    Subscription::query()->create(['tenant_id' => M30_SETTINGS, 'plan_id' => $plan->id, 'unit_count' => 40, 'status' => 'active', 'started_on' => now()->toDateString()]);

    // M57 §9.1: while positions are held from every estate console, the switch is saved and says it changes nothing yet.
    $this->actingAs($this->director)->post('/guards/places/'.M30_SETTINGS.'/guard-positions', ['sees' => true])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved. No estate console shows an officer\'s position yet: positions are held from every estate until that is confirmed with the client.');
    expect((bool) DB::connection('mysql')->table('subscriptions')->where('tenant_id', M30_SETTINGS)->value('estate_sees_guard_positions'))->toBeTrue();

    $this->actingAs($this->director)->get('/guards/places?client='.M30_SETTINGS.'&step=settings')
        ->assertInertia(fn (Assert $page) => $page->where('estateSeesGuards', true));
});

it('marks a checkpoint the demonstration seed made as sample, never a gate, and deletes nothing', function () {
    $stop = static fn (string $label, int $sequence): PatrolCheckpoint => PatrolCheckpoint::query()->create(['tenant_id' => M30_SETTINGS, 'post_id' => null, 'label' => $label, 'code' => PatrolCheckpoint::newCode(), 'sequence' => $sequence, 'is_active' => true, 'radius_m' => 25]);
    $seeded = $stop('Seeded pool deck', 1);
    $own = $stop('Front entrance', 2);

    // What the seed records as it runs: the ids it wrote, as a range (SampleData::record).
    DB::connection('mysql')->table('sample_data_ranges')->insert(['tag' => 'phoenix-demo', 'tenant_id' => M30_SETTINGS, 'connection' => 'mysql', 'table_name' => 'patrol_checkpoints', 'from_id' => $seeded->id - 1, 'to_id' => $seeded->id, 'append_only' => false, 'seeded_at' => now()]);
    // A range over the gates too: posts are the estate's structure, and are never called sample.
    DB::connection('mysql')->table('sample_data_ranges')->insert(['tag' => 'phoenix-demo', 'tenant_id' => M30_SETTINGS, 'connection' => 'mysql', 'table_name' => 'posts', 'from_id' => $this->main->id - 1, 'to_id' => $this->main->id, 'append_only' => false, 'seeded_at' => now()]);

    $this->actingAs($this->director)->get('/guards/places?client='.M30_SETTINGS.'&step=patrol')
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkpoints', fn ($checkpoints) => collect($checkpoints)->pluck('sample', 'label')->all() === ['Seeded pool deck' => true, 'Front entrance' => false])
            ->where('gates.0', fn ($gate) => ! array_key_exists('sample', (array) $gate)));

    expect(PatrolCheckpoint::query()->whereKey([$seeded->id, $own->id])->count())->toBe(2);
});

it('opens on a real client, never an internal one, and lists the internal ones last, under their own heading', function () {
    $this->actingAs($this->director)->get('/guards/places')
        ->assertInertia(fn (Assert $page) => $page
            ->where('client.internal', false)
            ->where('clients', function ($clients) {
                $clients = collect($clients);
                $internalAt = $clients->search(fn ($c) => $c['id'] === M30_INTERNAL);

                // Named "Aaa", first by the alphabet, and still after every real client.
                return $internalAt !== false
                    && $clients[$internalAt]['internal'] === true
                    && $clients->slice($internalAt)->every(fn ($c) => $c['internal'] === true);
            }));
});
