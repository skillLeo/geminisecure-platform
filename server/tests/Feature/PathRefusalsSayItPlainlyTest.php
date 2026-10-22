<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The walk's two forms refuse in plain words (DR-028, M20; M72 §1.4: "one line, says what would make it available")
|--------------------------------------------------------------------------
|
| Assign vendor (step 4) and New charge (step 5) answered a mistake in Laravel's own words, naming database columns:
| "The eta ends at field must be a date after eta starts at." and "The due on field is required." The amount's two
| refusals ran on with em dashes and wrote money as "J$" where the console writes "$" (Laws 3 and 5). Each now says,
| in one line, what would make the form go through.
|
| Each test raises a work order of its own (the estate's queue is shared and committed); nothing is posted to a ledger:
| every charge here is refused before it reaches one.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function prpTicket(object $test, $manager): MaintenanceTicket
{
    $title = 'Plain refusal test '.bin2hex(random_bytes(3));

    $test->actingAs($manager)->post(FacilitiesFixture::url('/facilities/maintenance'), [
        'title' => $title,
        'location' => 'Main Gate',
        'category' => 'Other',
        'priority' => 'low',
    ])->assertRedirect();

    FacilitiesFixture::boot();

    return MaintenanceTicket::query()->where('title', $title)->firstOrFail();
}

it('says a visit must end after it starts, and an ended visit without an em dash (Assign vendor, step 4)', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $ticket = prpTicket($this, $manager);
    $assign = function (array $fields) use ($manager, $ticket) {
        $response = $this->actingAs($manager)->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/assign'), [
            'vendor_id' => FacilitiesFixture::vendor('Island Electric Services')->id,
            ...$fields,
        ]);
        FacilitiesFixture::boot();

        return $response;
    };
    $now = SiteTime::now();

    $assign(['eta_starts_at' => $now->copy()->addHours(5)->format('Y-m-d\TH:i'), 'eta_ends_at' => $now->copy()->addHours(4)->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors(['eta_ends_at' => 'The visit must end after it starts.']);

    $assign(['eta_starts_at' => $now->copy()->subHours(3)->format('Y-m-d\TH:i'), 'eta_ends_at' => $now->copy()->subHours(2)->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors('eta_ends_at');

    expect(session('errors')->first('eta_ends_at'))->toContain('already passed at the estate')->not->toContain('—');
});

it('says in one line that a ticket is already completed, and what Mark completed needs (DR-029, M21; step 4)', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $maintenance = app(Maintenance::class);

    // "Ticket #1067 — … is already completed. Closing it a second time would overwrite the day the work…"
    $done = prpTicket($this, $manager);
    $maintenance->resolve($done->refresh(), 'Replaced the gate light.');

    expect(fn () => $maintenance->resolve($done->refresh(), 'Again.'))
        ->toThrow(DomainException::class, 'Ticket #'.$done->number.' is already completed.');

    try {
        $maintenance->resolve($done->refresh(), 'Again.');
    } catch (DomainException $refused) {
        expect($refused->getMessage())->toBe('Ticket #'.$done->number.' is already completed.');
    }

    // "Say what was done." and nothing about who it tells.
    $open = prpTicket($this, $manager);

    try {
        $maintenance->resolve($open->refresh(), '   ');
        $this->fail('an empty resolution was taken');
    } catch (DomainException $refused) {
        expect($refused->getMessage())->toBe('Say what was done.');
    }
});

it('asks for a due date and a right amount in one line each, with no em dash and the console\'s dollar sign (New charge, step 5)', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $charge = fn (array $fields) => $this->actingAs($treasurer)->post(FacilitiesFixture::url('/finance/charges'), [
        'unit' => 'Lot 47',
        'type' => 'dues',
        'amount' => '6200.00',
        'description' => 'Plain refusal test',
        'due_on' => SiteTime::date()->addDays(14)->toDateString(),
        ...$fields,
    ]);

    $charge(['due_on' => ''])->assertSessionHasErrors(['due_on' => 'Choose a due date.', 'account' => 'Choose the income account the charge goes to.']);
    $charge(['amount' => '', 'description' => ''])->assertSessionHasErrors(['amount' => 'Enter an amount.', 'description' => 'Say what the charge is for.']);
    $charge(['amount' => '0'])->assertSessionHasErrors(['amount' => 'Enter an amount.']);
    $charge(['amount' => '6200.005'])->assertSessionHasErrors(['amount' => 'Enter dollars and cents, two decimal places at most, as in 6200.00.']);
    $charge(['amount' => '100000000'])->assertSessionHasErrors(['amount' => 'No single amount here can be more than $99,999,999.99.']);
});
