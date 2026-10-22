<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Resident;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Vendor;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\Maintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M47 §11–§12 — a request's working half: who has it, who to call, and a message that sends
|--------------------------------------------------------------------------
|
| Board 26 draws "Assigned vendor" — the company, the technician and a button that calls them — and "Add a comment",
| which must actually send; its timeline reads "Acknowledged · Sep 3, 8:15 AM · Property Manager". Board 27's reads
| "Received by security · 9:13 AM · Dispatcher Renae Cross". The household's tickets now carry the vendor and its
| number, and who did each step; a comment is a note on the ticket's history, where the office works the job.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 4781');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Ticket household', 'access_restricted' => false]);
    // The estate database is shared by the run: whatever an earlier run left on this lot goes first.
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
    $email = 'tickets.'.bin2hex(random_bytes(3)).'@example.com';
    $person = new Resident;
    $person->forceFill(['household_id' => $this->household->id, 'full_name' => 'Hassam Ahmed', 'email' => $email, 'phone' => '+1 876 555 0181', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Hassam Ahmed',
        'resident_id' => $person->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $account->createToken('resident-app:tickets', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->manager = User::query()->create(['name' => 'Renae Cross', 'email' => 'pm.'.bin2hex(random_bytes(3)).'@example.com', 'password' => bcrypt(bin2hex(random_bytes(8))), 'status' => 'active']);
    $this->manager->assignRole(Role::PROPERTY_MANAGER);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    FacilitiesFixture::boot();
    MaintenanceTicket::query()->where('unit_id', $this->unit->id)->get()->each(function (MaintenanceTicket $t): void {
        TicketActivity::query()->where('maintenance_ticket_id', $t->id)->delete();
        $t->delete();
    });
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @param  array<string, mixed>  $body */
function m47tCall(TestCase $test, string $token, string $name, array $params = [], array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    app('auth')->forgetGuards();
    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri)
        : $test->withToken($token)->postJson($uri, $body, ['Idempotency-Key' => 'm47t-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()]);
    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('carries the vendor and a number to call, and who did each step — "Property Manager" by role, the resident by name', function () {
    $filed = m47tCall($this, $this->token, 'tickets.store', [], ['title' => 'The gate light dem nuh work since Tuesday — Common area', 'category' => 'electrical', 'description' => 'The gate light dem nuh work since Tuesday, it dark by the visitor parking at night.', 'location' => 'Common area']);
    $ticket = MaintenanceTicket::query()->findOrFail($filed->json('id'));

    $maintenance = app(Maintenance::class);
    $maintenance->acknowledge($ticket, $this->manager);
    $vendor = Vendor::query()->create(['name' => 'Island Electric Services', 'category' => 'electrical', 'contact_name' => 'Desk', 'contact_phone' => '876-555-0190', 'status' => 'active']);
    $maintenance->assign($ticket->refresh(), $vendor, technicianName: 'Owen Grant', technicianPhone: '876-555-0191', by: $this->manager);

    $listed = collect(m47tCall($this, $this->token, 'tickets.index')->json('items'))->firstWhere('id', $ticket->id);
    expect($listed['vendor'])->toBe(['name' => 'Island Electric Services', 'phone' => '876-555-0191'])
        ->and($listed['technician_name'])->toBe('Owen Grant');

    $acknowledged = collect($listed['timeline'])->firstWhere('event', 'acknowledged');
    expect($acknowledged['by'])->toBe(['kind' => 'staff', 'name' => 'Renae Cross', 'role' => 'Property Manager'])
        ->and(collect($listed['timeline'])->firstWhere('event', 'reported')['by'])->toMatchArray(['kind' => 'resident', 'name' => 'Hassam Ahmed', 'role' => null]);

    // No technician's number: the company's.
    $ticket->refresh()->forceFill(['technician_phone' => null])->save();
    expect(collect(m47tCall($this, $this->token, 'tickets.index')->json('items'))->firstWhere('id', $ticket->id)['vendor']['phone'])->toBe('876-555-0190');
});

it('sends a comment: a note on the ticket\'s history, in the resident\'s words and under their name, where the office reads it', function () {
    $filed = m47tCall($this, $this->token, 'tickets.store', [], ['title' => 'A tap that will not stop — Phase 9 · Lot 4781', 'category' => 'plumbing', 'description' => 'A tap that will not stop dripping in the kitchen.']);

    $sent = m47tCall($this, $this->token, 'tickets.comment', ['ticket' => $filed->json('id')], ['body' => '  It is worse at night.  ']);
    expect(collect($sent->json('timeline'))->last())->toMatchArray(['event' => 'note', 'note' => 'It is worse at night.', 'by' => ['kind' => 'resident', 'name' => 'Hassam Ahmed', 'role' => null]]);

    // The office's own view of the ticket: the note is in its history, with the resident's name on the line.
    $history = app(Maintenance::class)->ticketBoard(MaintenanceTicket::query()->findOrFail($filed->json('id')))['history'] ?? null;
    expect($history)->not->toBeNull()
        ->and(collect($history)->firstWhere('note', 'It is worse at night.')['line'])->toContain('Hassam Ahmed');
});

it('refuses an empty comment, and a ticket that is not the household\'s', function () {
    $filed = m47tCall($this, $this->token, 'tickets.store', [], ['title' => 'A tap — Lot 4781', 'category' => 'plumbing', 'description' => 'A tap dripping.']);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/api/v1/tickets/'.$filed->json('id').'/comments', ['body' => '   '], ['Idempotency-Key' => 'm47t-empty', 'X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(422);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/api/v1/tickets/99999999/comments', ['body' => 'Hello'], ['Idempotency-Key' => 'm47t-404', 'X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(404);
});
