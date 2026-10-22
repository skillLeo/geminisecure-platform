<?php

declare(strict_types=1);

use App\Models\DispatchMessage;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Services\Dispatch\DispatchMessaging;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Dispatch telling one officer to go
|--------------------------------------------------------------------------
|
| The panic screen drew a disabled box reading "Messaging opens when the Guard
| App ships", and beside it a disabled "Dispatch a second guard" saying the same.
| Both sentences were false by then: the app had shipped, and the message model
| they said did not exist was already in the schema —
| `dispatch_messages.direction` has carried `outbound` since the Guard App
| migration, and `MessagesController::index()` has served
| `direction = broadcast OR guard_id = me` since it was written. Only the write
| was missing.
|
| The last test is the one that matters most: it runs the handset's own query and
| proves the message is visible to the officer it was addressed to and to nobody
| else. A write that lands in a table the app does not read is worse than a
| disabled box — the dispatcher believes an instruction was delivered.
|
| No RefreshDatabase: this suite shares the central database, so everything is
| cleaned up in afterEach.
|
*/

/*
 * `DB::connection('mysql')` everywhere, never the default.
 *
 * FacilitiesFixture::platform() initialises tenancy, which repoints the DEFAULT
 * connection at the estate's own database — so a bare `DB::table('tenants')`
 * here looks for `tenants` inside `gs_estate_facilitiestest` and fails with
 * "Base table or view not found". The models are fine (they carry
 * CentralConnection); only the raw builders need saying so.
 */
function messagingEstate(string $id, string $name): void
{
    DB::connection('mysql')->table('tenants')->updateOrInsert(
        ['id' => $id],
        ['name' => $name, 'parish' => 'St. Andrew', 'status' => 'active', 'data' => '{}'],
    );
}

function messagingGuard(string $suffix, ?string $tenant): Guard
{
    $post = $tenant === null ? null : Post::create([
        'tenant_id' => $tenant,
        'name' => 'Msg Gate '.$suffix,
        'type' => 'gate',
        'is_active' => true,
    ]);

    return Guard::create([
        'full_name' => 'Officer '.$suffix,
        'employee_number' => 'GS-MSG-'.$suffix,
        'psra_number' => 'PSRA-MSG-'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $tenant,
        'post_id' => $post?->id,
    ]);
}

beforeEach(function () {
    FacilitiesFixture::platform();

    messagingEstate('msgtesta', 'Messaging Test Estate A');
    messagingEstate('msgtestb', 'Messaging Test Estate B');

    $this->withoutVite();

    $this->here = messagingGuard('A1', 'msgtesta');
    $this->elsewhere = messagingGuard('B1', 'msgtestb');
    $this->unposted = messagingGuard('U1', null);

    $this->alert = DuressAlert::create([
        'tenant_id' => 'msgtesta',
        'kind' => 'panic',
        'raised_by_name' => 'Messaging Test Resident',
        'unit_reference' => 'Lot 9',
        'status' => 'open',
        'server_time' => now(),
        'is_simulated' => false,
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->table('dispatch_messages')->whereIn('tenant_id', ['msgtesta', 'msgtestb'])->delete();
    DuressAlert::query()->whereIn('tenant_id', ['msgtesta', 'msgtestb'])->delete();
    Guard::query()->where('employee_number', 'like', 'GS-MSG-%')->delete();
    Post::query()->whereIn('tenant_id', ['msgtesta', 'msgtestb'])->delete();
    DB::connection('mysql')->table('tenants')->whereIn('id', ['msgtesta', 'msgtestb'])->delete();
});

it('writes an outbound message addressed to the one officer', function () {
    $message = app(DispatchMessaging::class)
        ->toGuard($this->here, 'Go to Lot 9 now.', $this->director, $this->alert);

    expect($message->direction)->toBe(DispatchMessage::OUTBOUND)
        ->and($message->guard_id)->toBe($this->here->id)
        ->and($message->tenant_id)->toBe('msgtesta')
        ->and($message->body)->toBe('Go to Lot 9 now.')
        ->and($message->sent_by)->toBe($this->director->getKey());
});

it('is visible to the officer it was addressed to, by the handset\'s own query', function () {
    /*
     * THE QUERY THE GUARD APP ACTUALLY RUNS, from MessagesController::index():
     * everything at this estate that is either a broadcast or carries my id.
     * Asserting the row exists proves the write; running the app's own read
     * proves the officer will see it.
     */
    app(DispatchMessaging::class)->toGuard($this->here, 'Head to the gate.', $this->director, $this->alert);

    $visibleTo = function (Guard $guard): array {
        return DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $guard->tenant_id)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->pluck('body')
            ->all();
    };

    expect($visibleTo($this->here))->toBe(['Head to the gate.'])
        ->and($visibleTo($this->elsewhere))->toBe([]);
});

it('refuses an officer posted to a different client from the alert', function () {
    expect(fn () => app(DispatchMessaging::class)
        ->toGuard($this->elsewhere, 'Go now.', $this->director, $this->alert))
        ->toThrow(DomainException::class);
});

it('refuses an officer who is not posted to any client', function () {
    // There is no estate thread to send on, so the message would reach nobody.
    expect(fn () => app(DispatchMessaging::class)
        ->toGuard($this->unposted, 'Go now.', $this->director, $this->alert))
        ->toThrow(DomainException::class);
});

it('refuses an empty message and one past the column length', function () {
    $messaging = app(DispatchMessaging::class);

    expect(fn () => $messaging->toGuard($this->here, '   ', $this->director, $this->alert))
        ->toThrow(DomainException::class);

    expect(fn () => $messaging->toGuard($this->here, str_repeat('x', DispatchMessaging::MAX_BODY + 1), $this->director, $this->alert))
        ->toThrow(DomainException::class);
});

it('records the instruction in the audit log, body and all', function () {
    /*
     * The body is deliberately IN the entry. Unlike a ballot, a dispatch
     * instruction is evidence: after an incident the question is not only what
     * happened but who was told to do what, and an audit row that says only
     * "a message was sent" cannot answer it.
     */
    app(DispatchMessaging::class)->toGuard($this->here, 'Meet the ambulance at the main gate.', $this->director, $this->alert);

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'dispatch.message_sent')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((string) $entry->after)->toContain('Meet the ambulance at the main gate.')
        ->and((string) $entry->after)->toContain((string) $this->here->full_name);
});
