<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The profile's Reassign form starts where the officer is (bug hunt B009)
|--------------------------------------------------------------------------
|
| Show.vue prefills the form from `guard.tenant_id` and the renewal form from
| `guard.psra_number`, and the profile carried neither. So Reassign always
| opened on "No client — off posting" with an empty post list, and one press
| of the button with those defaults took the officer off their client and
| opened every future shift they held. The renewal's PSRA number, meant to be
| prefilled, was blank.
|
| And the screen's answer to a move said "any future shifts at the previous
| client are open on the rota" whatever had happened — including a move that
| changed only the post, where nothing was opened and there is no previous
| client.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->gate = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Reassign Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->service = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Reassign Service Gate', 'type' => 'gate', 'is_active' => true]);

    $this->officer = Guard::query()->create([
        'full_name' => 'Reassign Form Officer',
        'employee_number' => 'GS-RFO-'.uniqid(),
        'psra_number' => 'PSRA-RFO-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->gate->id,
    ]);

    $this->ahead = Shift::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $this->officer->id,
        'post_id' => $this->gate->id,
        'rostered_start' => now()->addDays(3)->startOfDay()->addHours(12),
        'rostered_end' => now()->addDays(3)->startOfDay()->addHours(23),
        'status' => Shift::ROSTERED,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('hands the profile the officer\'s current client, post and licence number, so both forms open on what is true', function () {
    $this->actingAs($this->director)
        ->get('/guards/'.$this->officer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('guard.tenant_id', FacilitiesFixture::ESTATE)
            ->where('guard.post_id', $this->gate->id)
            ->where('guard.psra_number', $this->officer->psra_number));
});

it('moves only the post when only the post changes, and says so without claiming shifts were opened', function () {
    $this->actingAs($this->director)
        ->post('/guards/'.$this->officer->id.'/reassign', [
            'tenant_id' => FacilitiesFixture::ESTATE,
            'post_id' => $this->service->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->officer->fresh()->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($this->officer->fresh()->post_id)->toBe($this->service->id)
        ->and($this->ahead->fresh()->guard_id)->toBe($this->officer->id)
        ->and((string) session('success'))->not->toContain('are open on the rota')
        ->and((string) session('success'))->toContain('Reassign Service Gate');
});

it('refuses a reassignment that changes nothing rather than writing an audit entry saying something moved', function () {
    $before = DB::connection('mysql')->table('audit_log')->where('action', 'guard.reassigned')->count();

    $this->actingAs($this->director)
        ->post('/guards/'.$this->officer->id.'/reassign', [
            'tenant_id' => FacilitiesFixture::ESTATE,
            'post_id' => $this->gate->id,
        ])
        ->assertSessionHasErrors('tenant_id');

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'guard.reassigned')->count())->toBe($before)
        ->and($this->ahead->fresh()->guard_id)->toBe($this->officer->id);
});

it('says how many shifts a move to another client opened, from what was actually released', function () {
    $this->actingAs($this->director)
        ->post('/guards/'.$this->officer->id.'/reassign', ['tenant_id' => '', 'post_id' => ''])
        ->assertSessionHasNoErrors();

    expect($this->ahead->fresh()->guard_id)->toBeNull()
        ->and((string) session('success'))->toContain('1 future shift');
});
