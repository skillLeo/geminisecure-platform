<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate's clock is chosen when it is taken on, and changed only by the Director (M19A §9)
|--------------------------------------------------------------------------
|
| Every estate was left on the column's default, America/Jamaica, whatever it said on the form — because the form
| never asked. A client in Florida would have been an hour out for half the year. The zone is now a required step,
| from the list of IANA zones, and the Director alone changes it afterwards, audited.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Event::fake([TenantCreated::class]);
    SiteTime::flush();

    $this->plan = Plan::query()->create(['key' => 'zonechosen', 'name' => 'Zone Chosen', 'price_per_unit_minor' => 180_00, 'currency' => 'JMD', 'min_units' => 25, 'is_active' => true, 'sort' => 97]);
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->payload = [
        'name' => 'Coral Springs Villas', 'address' => '9 Palm Way, Broward', 'phases' => 1, 'plan_id' => $this->plan->id,
        'units' => 120, 'guards' => 0, 'term_months' => 12, 'contact_name' => 'Dana Reyes',
        'contact_email' => 'president.'.bin2hex(random_bytes(3)).'@coralsprings.test', 'contact_phone' => '+1 954 555 0142',
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

it('offers every IANA zone on the form, and chooses none for the operator', function () {
    $this->actingAs($this->director)->get('/clients/new')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Clients/Create')
            ->where('zones', fn ($zones): bool => collect($zones)->contains('America/New_York') && collect($zones)->contains('Asia/Karachi') && collect($zones)->contains('America/Jamaica')));
});

it('refuses a client with no zone, or one that is not a zone', function () {
    $this->actingAs($this->director)->post('/clients/new', $this->payload)->assertSessionHasErrors('timezone');
    $this->actingAs($this->director)->post('/clients/new', [...$this->payload, 'timezone' => 'UTC-5'])->assertSessionHasErrors('timezone');

    // And the service itself, for any caller that skips the form: no default, no guess, nothing written (M19A §9).
    $onboarding = app(App\Services\Gemini\ClientOnboarding::class);
    foreach ([null, '', 'UTC-5', '-05:00', 'Not/AZone'] as $zone) {
        expect(fn () => $onboarding->start([...$this->payload, 'timezone' => $zone], $this->director))
            ->toThrow(DomainException::class, App\Services\Gemini\ClientOnboarding::ZONE_REQUIRED);
    }
    expect(Tenant::query()->where('name', 'Coral Springs Villas')->exists())->toBeFalse();
});

it('keeps the zone chosen — New York, not the column\'s default', function () {
    $this->actingAs($this->director)->post('/clients/new', [...$this->payload, 'timezone' => 'America/New_York'])->assertSessionHasNoErrors();

    $estate = Tenant::query()->where('name', 'Coral Springs Villas')->firstOrFail();
    expect($estate->getAttribute('timezone'))->toBe('America/New_York')
        ->and(SiteTime::zone((string) $estate->getTenantKey()))->toBe('America/New_York');
});

it('remembers a Gemini viewer\'s "my time", reads the console on it, and forgets it at the end of the request (M19A §7)', function () {
    $this->actingAs($this->director)->post('/account/time-view', ['view' => 'mine', 'zone' => 'Asia/Karachi'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->director->refresh()->console_time_view)->toBe('mine')
        ->and($this->director->console_zone)->toBe('Asia/Karachi');

    $this->actingAs($this->director)->get('/clients')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('timeView', ['view' => 'mine', 'zone' => 'Asia/Karachi']));

    // Nothing carries it past the request that asked for it.
    expect(SiteTime::viewerZone())->toBeNull();

    // A zone that is not a zone is refused; site time needs none.
    $this->actingAs($this->director)->post('/account/time-view', ['view' => 'mine', 'zone' => 'UTC+5'])->assertSessionHasErrors('zone');
    $this->actingAs($this->director)->post('/account/time-view', ['view' => 'site'])->assertSessionHasNoErrors();
    expect($this->director->refresh()->console_time_view)->toBe('site');
});

it('lets the Director change it, warned and audited — and nobody else', function () {
    $this->actingAs($this->director)->post('/clients/new', [...$this->payload, 'timezone' => 'America/New_York'])->assertSessionHasNoErrors();
    $estate = Tenant::query()->where('name', 'Coral Springs Villas')->firstOrFail();
    $id = (string) $estate->getTenantKey();

    // The Operations Manager may manage the client, and still may not move its clock.
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER))->post("/clients/{$id}/timezone", ['timezone' => 'America/Chicago'])->assertForbidden();

    $this->actingAs($this->director)->post("/clients/{$id}/timezone", ['timezone' => 'America/Chicago'])->assertRedirect()->assertSessionHasNoErrors();

    expect($estate->refresh()->getAttribute('timezone'))->toBe('America/Chicago')
        ->and(SiteTime::zone($id))->toBe('America/Chicago');

    $audit = AuditEntry::query()->where('action', 'client.timezone_changed')->where('entity_id', $id)->firstOrFail();
    expect($audit->before)->toBe(['timezone' => 'America/New_York'])
        ->and($audit->after)->toBe(['timezone' => 'America/Chicago']);
});
