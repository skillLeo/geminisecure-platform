<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| On duty — who is working, when their shift ends, who is next and who is away (the client's report C4, 26 Sep)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function dutyOfficer(string $name, array $overrides = []): Guard
{
    return Guard::query()->create([
        'full_name' => $name,
        'employee_number' => 'GS-DUTY-'.uniqid(),
        'psra_number' => 'PSRA-DUTY-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        ...$overrides,
    ]);
}

function dutyShift(Post $post, ?Guard $guard, int $fromHours, int $toHours, bool $clockedIn = false): Shift
{
    return Shift::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $post->id,
        'guard_id' => $guard?->id,
        'rostered_start' => now()->addHours($fromHours),
        'rostered_end' => now()->addHours($toHours),
        'actual_start' => $clockedIn ? now()->addHours($fromHours) : null,
        'status' => $guard === null ? Shift::OPEN : Shift::ROSTERED,
    ]);
}

it('lists who is on duty and when each shift ends, who has not clocked in, who starts soon, what is open and who is away', function () {
    $post = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Duty Gate '.uniqid(), 'type' => 'gate', 'is_active' => true]);

    $working = dutyOfficer('Duty Working Officer');
    $late = dutyOfficer('Duty Late Officer');
    $next = dutyOfficer('Duty Next Officer');
    $leave = dutyOfficer('Duty Leave Officer');

    $on = dutyShift($post, $working, -2, 4, clockedIn: true);
    $missing = dutyShift($post, $late, -1, 5);
    $soon = dutyShift($post, $next, 3, 9);
    $gap = dutyShift($post, null, 5, 11);

    GuardRequest::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $leave->id, 'kind' => GuardRequest::LEAVE, 'subject' => 'Annual leave',
        'starts_on' => SiteTime::today()->subDay()->toDateString(), 'ends_on' => SiteTime::today()->addDays(2)->toDateString(),
        'status' => 'approved', 'decided_at' => now(),
    ]);

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/guards/on-duty')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Operations/OnDuty')
            ->where('onDuty', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['id'] === $on->id && $r['guard'] === 'Duty Working Officer' && str_contains($r['ends'], SiteTime::local($on->rostered_end)->format('g:i A')) && $r['overdue'] === false))
            ->where('notClockedIn', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['id'] === $missing->id))
            ->where('startingSoon', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['id'] === $soon->id) && ! collect($rows)->contains(fn ($r) => $r['id'] === $on->id))
            ->where('open', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['id'] === $gap->id && $r['guard'] === null))
            ->where('away', fn ($rows): bool => collect($rows)->contains(fn ($r) => $r['guard_id'] === $leave->id && str_contains($r['why'], 'On leave until'))));
});

it('is read by every role that reads the workforce, the Accountant included', function () {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::ACCOUNTANT))
        ->get('/guards/on-duty')
        ->assertOk();
});
