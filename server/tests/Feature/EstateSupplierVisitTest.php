<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| "Give this job to a supplier" — the client's reports of 26 September
|--------------------------------------------------------------------------
|
| Three things were wrong on one form. The Phone took any text ("sdacsad"). A visit window that had already
| ended was accepted and told the household to expect a technician in the past. And pressing Assign again for
| the SAME supplier, only to change the time, wrote "Reassigned from Gate2 Contractor Ltd" on the office's
| timeline and the resident's, when nobody had been taken off the job.
|
| Each test raises a work order of its own: the estate's queue is shared and committed, and board 17's own
| tickets are counted by other files.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A work order of this test's own, with its number. */
function svtTicket(TestCase $test, $manager): MaintenanceTicket
{
    $title = 'Supplier visit test '.bin2hex(random_bytes(3));

    $test->actingAs($manager)->post(FacilitiesFixture::url('/facilities/maintenance'), [
        'title' => $title,
        'location' => 'Main Gate',
        'category' => 'Other',
        'priority' => 'low',
    ])->assertRedirect();

    FacilitiesFixture::boot();

    return MaintenanceTicket::query()->where('title', $title)->firstOrFail();
}

/** @param  array<string, mixed>  $fields */
function svtAssign(TestCase $test, $manager, MaintenanceTicket $ticket, array $fields): TestResponse
{
    $response = $test->actingAs($manager)->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/assign'), [
        'vendor_id' => FacilitiesFixture::vendor('Island Electric Services')->id,
        ...$fields,
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** A moment on the estate's clock, as the form's datetime-local box sends it. */
function svtTyped(Carbon $estateLocal): string
{
    return $estateLocal->format('Y-m-d\TH:i');
}

it('refuses a phone that is not a phone number, and takes one written the usual ways', function () {
    $ticket = svtTicket($this, $this->manager);

    svtAssign($this, $this->manager, $ticket, ['technician_phone' => 'sdacsad'])->assertSessionHasErrors('technician_phone');
    svtAssign($this, $this->manager, $ticket, ['technician_phone' => '12'])->assertSessionHasErrors('technician_phone');
    expect($ticket->refresh()->assigned_vendor_id)->toBeNull('a refused form changes nothing');

    foreach (['(876) 555-0110', '+1 876 555 0110', '876.555.0110'] as $written) {
        svtAssign($this, $this->manager, $ticket, ['technician_phone' => $written])->assertSessionHasNoErrors();
        expect($ticket->refresh()->technician_phone)->toBe($written);
    }
});

it('refuses a visit window that has already ended at the estate, and says what time it is there', function () {
    $ticket = svtTicket($this, $this->manager);
    $now = SiteTime::now();

    $response = svtAssign($this, $this->manager, $ticket, [
        'eta_starts_at' => svtTyped($now->copy()->subHours(3)),
        'eta_ends_at' => svtTyped($now->copy()->subHours(2)),
    ]);

    $response->assertSessionHasErrors('eta_ends_at');
    expect(session('errors')->first('eta_ends_at'))->toContain('already passed at the estate')
        ->and($ticket->refresh()->assigned_vendor_id)->toBeNull();

    // A window still to come is taken, read as the estate's wall clock.
    svtAssign($this, $this->manager, $ticket, [
        'eta_starts_at' => svtTyped($now->copy()->addHour()->startOfMinute()),
        'eta_ends_at' => svtTyped($now->copy()->addHours(3)->startOfMinute()),
    ])->assertSessionHasNoErrors();

    expect($ticket->refresh()->eta_starts_at->equalTo(SiteTime::parse(svtTyped($now->copy()->addHour()->startOfMinute()))))->toBeTrue();
});

it('records a new time for the same supplier as a visit change, never as "Reassigned from"', function () {
    $ticket = svtTicket($this, $this->manager);
    $now = SiteTime::now()->startOfMinute();

    svtAssign($this, $this->manager, $ticket, ['technician_name' => 'Owen Grant', 'eta_starts_at' => svtTyped($now->copy()->addHours(2)), 'eta_ends_at' => svtTyped($now->copy()->addHours(4))])
        ->assertSessionHasNoErrors();

    $response = svtAssign($this, $this->manager, $ticket, ['technician_name' => 'Owen Grant', 'eta_starts_at' => svtTyped($now->copy()->addHours(5)), 'eta_ends_at' => svtTyped($now->copy()->addHours(6))]);

    $response->assertSessionHasNoErrors();
    expect(session('success'))->toContain('visit is updated');

    $events = $ticket->activity()->orderBy('id')->get(['event', 'note']);
    expect($events->pluck('event')->all())->not->toContain(TicketActivity::REASSIGNED)
        ->and($events->last()->event)->toBe(TicketActivity::NOTE)
        ->and($events->last()->note)->toStartWith('Visit time changed: ')
        ->and($events->last()->note)->not->toContain('Reassigned');

    // And a different technician is said as such — the phone number never is.
    svtAssign($this, $this->manager, $ticket, ['technician_name' => 'Marcia Bell', 'technician_phone' => '876 555 0199', 'eta_starts_at' => svtTyped($now->copy()->addHours(5)), 'eta_ends_at' => svtTyped($now->copy()->addHours(6))])
        ->assertSessionHasNoErrors();
    $note = $ticket->activity()->latest('id')->value('note');
    expect($note)->toBe('Technician changed: Marcia Bell')
        ->and($note)->not->toContain('555');
});

// Refused since M68 §2.2 (FAC-8: "Reassigning to the same vendor is refused"); before it, a flash said "Nothing changed".
it('writes nothing when the same supplier is assigned again with nothing changed, and refuses it with the reason', function () {
    $ticket = svtTicket($this, $this->manager);
    $window = ['technician_name' => 'Owen Grant', 'eta_starts_at' => svtTyped(SiteTime::now()->addDay()->setTime(10, 0)), 'eta_ends_at' => svtTyped(SiteTime::now()->addDay()->setTime(12, 0))];

    svtAssign($this, $this->manager, $ticket, $window)->assertSessionHasNoErrors();
    $count = $ticket->activity()->count();

    svtAssign($this, $this->manager, $ticket, $window)->assertSessionHasErrors('vendor_id');

    expect($ticket->activity()->count())->toBe($count)
        ->and(session('errors')->first('vendor_id'))->toEndWith('already has this job. Change the technician, the phone or the visit time, or choose another supplier.');
});
