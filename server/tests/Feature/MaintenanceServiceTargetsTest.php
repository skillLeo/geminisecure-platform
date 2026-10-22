<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate sets its own service targets (M98, maintenance and vendors)
|--------------------------------------------------------------------------
|
| How many hours a ticket of each priority has before it is overdue was a constant, the same for every estate. It is
| now each estate's own, from the queue's Service targets, behind Facilities `configure` (the Community Super Admin and
| the Property Manager). The ladder every estate had is the default. A ticket keeps the target it was raised with: a
| household already shown a deadline is never shown a different one because the office changed a setting.
|
| The estate's database is shared by every test in the process, so each test here puts the ladder back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->maintenance = app(Maintenance::class);
});

afterEach(function () {
    FacilitiesFixture::boot();
    EstateSetting::current()->forceFill(['sla_hours_high' => 24, 'sla_hours_medium' => 72, 'sla_hours_low' => 168])->save();
    DB::connection('mysql')->rollBack();
});

it('starts every estate on the ladder it has always had, and says who may change it', function () {
    expect($this->maintenance->targets())->toBe(Maintenance::SLA_HOURS)
        ->and(Maintenance::SLA_HOURS)->toBe([MaintenanceTicket::HIGH => 24, MaintenanceTicket::MEDIUM => 72, MaintenanceTicket::LOW => 168]);

    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('targets', ['high' => 24, 'medium' => 72, 'low' => 168])
            ->where('longestTarget', Maintenance::LONGEST_TARGET_HOURS)
            ->where('canConfigure', true));
    FacilitiesFixture::boot();

    // A committee officer reads the queue and its targets, and has no Service targets at all (M96 level 3).
    $this->withoutVite()->actingAs(FacilitiesFixture::viewer(Role::VICE_PRESIDENT))
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', false));
});

it('lets the Property Manager set the targets: a new ticket takes them, one already raised keeps its own', function () {
    $before = $this->maintenance->report(title: 'Targets probe, before', locationLabel: 'Club house', priority: MaintenanceTicket::HIGH);
    expect($before->sla_hours)->toBe(24);

    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($manager)
        ->from(FacilitiesFixture::url('/facilities/maintenance'))
        ->post(FacilitiesFixture::url('/facilities/maintenance/targets'), ['high' => 4, 'medium' => 48, 'low' => 120])
        ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Service targets saved. New tickets take them; tickets already raised keep their own.');
    FacilitiesFixture::boot();

    expect($this->maintenance->targets())->toBe([MaintenanceTicket::HIGH => 4, MaintenanceTicket::MEDIUM => 48, MaintenanceTicket::LOW => 120]);

    $after = $this->maintenance->report(title: 'Targets probe, after', locationLabel: 'Club house', priority: MaintenanceTicket::HIGH);
    expect($after->sla_hours)->toBe(4)
        ->and($before->fresh()->sla_hours)->toBe(24);

    // A priority changed now takes the new rung.
    expect($this->maintenance->changePriority($before->fresh(), MaintenanceTicket::MEDIUM, $manager)->sla_hours)->toBe(48);

    // Written to the central log, with what they were. A JSON column keeps the keys in its own order, so compared as sets.
    $entry = AuditEntry::query()->where('action', 'estate.maintenance.targets_updated')->where('actor_id', $manager->id)->latest('id')->firstOrFail();
    expect($entry->before)->toEqual(['high' => 24, 'medium' => 72, 'low' => 168])
        ->and($entry->after)->toEqual(['high' => 4, 'medium' => 48, 'low' => 120]);
});

it('refuses a role that does not configure Facilities, and changes nothing', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::VICE_PRESIDENT))
        ->post(FacilitiesFixture::url('/facilities/maintenance/targets'), ['high' => 4, 'medium' => 48, 'low' => 120])
        ->assertForbidden();
    FacilitiesFixture::boot();

    expect($this->maintenance->targets())->toBe(Maintenance::SLA_HOURS);
});

it('refuses a ladder upside down or out of range, under the field that breaks it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $post = fn (array $hours) => $this->actingAs($manager)
        ->from(FacilitiesFixture::url('/facilities/maintenance'))
        ->post(FacilitiesFixture::url('/facilities/maintenance/targets'), $hours);

    $post(['high' => 96, 'medium' => 48, 'low' => 120])->assertSessionHasErrors(['high' => 'High must be no longer than Medium.']);
    $post(['high' => 4, 'medium' => 200, 'low' => 120])->assertSessionHasErrors(['medium' => 'Medium must be no longer than Low.']);
    $post(['high' => 0, 'medium' => 48, 'low' => 120])->assertSessionHasErrors(['high' => 'At least 1 hour.']);
    $post(['high' => 4, 'medium' => 48, 'low' => 721])->assertSessionHasErrors(['low' => 'At most 720 hours (30 days).']);
    $post(['high' => 4.5, 'medium' => 48, 'low' => 120])->assertSessionHasErrors(['high' => 'Enter whole hours.']);
    FacilitiesFixture::boot();

    expect($this->maintenance->targets())->toBe(Maintenance::SLA_HOURS);

    // The service holds the same ladder for any caller that is not this form.
    expect(fn () => $this->maintenance->setTargets(['high' => 4, 'medium' => 200, 'low' => 120], $manager))
        ->toThrow(DomainException::class, 'Medium must be no longer than Low.');
});
