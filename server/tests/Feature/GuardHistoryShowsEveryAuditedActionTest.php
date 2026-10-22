<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Gemini\GuardWorkforce;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A guard's history reads everything the audit log recorded about them (bug hunt B017)
|--------------------------------------------------------------------------
|
| The profile's timeline looked the log up by PSRA number. Two writers keyed a
| guard that way — created, suspended — and every other one by the guard's row
| id: a licence renewal, a reassignment, released shifts, an enrolment code, a
| handset rebind. None of those ever appeared. And because a renewal can change
| the PSRA number, the lookup by the current number then lost "Created" and
| "Suspended" as well.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->actingAs($this->director);
    $this->workforce = app(GuardWorkforce::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** @return list<string> */
function guardHistoryTitles(Guard $guard): array
{
    return array_column(app(GuardWorkforce::class)->deploymentHistory($guard->fresh(['post', 'estate'])), 'title');
}

it('shows a renewal, a reassignment, released shifts and an enrolment code, and still the creation after the number changed', function () {
    $post = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'History Gate', 'type' => 'gate', 'is_active' => true]);

    $guard = $this->workforce->add($this->director, [
        'full_name' => 'History Officer',
        'psra_number' => 'PSRA-HIST-'.uniqid(),
        'psra_expires_on' => now()->addMonths(2)->toDateString(),
        'phone' => '876-555-0199',
        // Nothing is optional (M18C): the office writes to an officer by email — payslips and notices.
        'email' => 'history.officer.'.uniqid().'@example.com',
        'employment_type' => 'full_time',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => (string) $post->id,
        'standard_rate' => '425',
    ]);

    Shift::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->addDays(4)->startOfDay()->addHours(12),
        'rostered_end' => now()->addDays(4)->startOfDay()->addHours(23),
        'status' => Shift::ROSTERED,
    ]);

    // A renewal that also changes the number printed on the certificate.
    $guard = $this->workforce->markLicenceRenewed($guard, now()->addYears(2)->toDateString(), 'PSRA-HIST-RENEWED-'.uniqid(), $this->director);

    $titles = guardHistoryTitles($guard);

    expect($titles)->toContain('Licence renewed')
        ->and($titles)->toContain('Created');

    app(DeviceEnrolment::class)->issueCode($guard, $this->director);

    $this->post('/guards/'.$guard->id.'/reassign', ['tenant_id' => '', 'post_id' => ''])->assertSessionHasNoErrors();

    $titles = guardHistoryTitles($guard);

    expect($titles)->toContain('Reassigned')
        ->and($titles)->toContain('Shifts released')
        ->and($titles)->toContain('Enrolment code issued');
});

it('keeps a suspension on the record, and the entries written before the key was the row id', function () {
    $guard = Guard::query()->create([
        'full_name' => 'History Lapsed Officer',
        'employee_number' => 'GS-HLO-'.uniqid(),
        'psra_number' => 'PSRA-HLO-'.uniqid(),
        'psra_expires_on' => now()->subDays(10)->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    // An entry from before this fix, keyed by the PSRA number the officer then held.
    AuditEntry::query()->create([
        'action' => 'guard.licence_flagged',
        'entity_type' => 'Guard',
        'entity_id' => $guard->psra_number,
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    $this->workforce->suspendFromDuty($guard->fresh(['post', 'estate']));

    // Every entry about a guard written from now on is keyed by the guard's row id.
    expect(AuditEntry::query()->where('action', 'guard.suspended')->orderByDesc('id')->value('entity_id'))
        ->toBe((string) $guard->id);

    $guard = $this->workforce->markLicenceRenewed($guard->fresh(), now()->addYear()->toDateString(), 'PSRA-HLO-NEW-'.uniqid(), $this->director);

    $titles = guardHistoryTitles($guard);

    expect($titles)->toContain('Suspended')
        ->and($titles)->toContain('Licence flagged')
        ->and($titles)->toContain('Licence renewed');
});

it('never shows one officer\'s entries on another whose licence number is only digits and matches the first one\'s row id', function () {
    $first = Guard::query()->create([
        'full_name' => 'History First',
        'employee_number' => 'GS-HF-'.uniqid(),
        'psra_number' => 'PSRA-HF-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    $second = Guard::query()->create([
        'full_name' => 'History Second',
        'employee_number' => 'GS-HS-'.uniqid(),
        'psra_number' => (string) $first->id,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    // About the first officer, keyed — as every entry now is — by their row id.
    AuditEntry::query()->create([
        'action' => 'guard.reassigned',
        'entity_type' => 'Guard',
        'entity_id' => (string) $first->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    // About the second.
    AuditEntry::query()->create([
        'action' => 'dispatch.message_sent',
        'entity_type' => 'Guard',
        'entity_id' => (string) $second->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    expect(guardHistoryTitles($first))->toContain('Reassigned')
        ->and(guardHistoryTitles($second))->not->toContain('Reassigned')
        ->and(guardHistoryTitles($second))->toContain('Message sent');
});
