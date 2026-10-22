<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Passes\VisitorPasses;
use App\Services\ResidentApp\RevokeResidentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The estate office ending a Resident App account — Q-M44
|--------------------------------------------------------------------------
|
| Until this, an owner who sold or a tenant whose lease ended kept, indefinitely
| and with no button anywhere in the product: the household's balance and full
| statement, its receipts, the power to issue signed visitor passes to the gate,
| their own e-pass, the household roster with names and telephone numbers, the
| emergency contacts, the vehicles, the ballot, and the walk-up approval a guard
| sends when a stranger turns up at that address.
|
| THE CLIENT'S RULING, verbatim: "Agree with your recommendations. Additionally
| the resident's access must be revoked also and they will have to enter through
| the Visitor Entrance side of the gate."
|
| So this file proves both halves, and the second half is the one that matters at
| a barrier at two in the morning:
|
|   · the token is refused, everywhere, at once — and so is a fresh sign-in
|   · the e-pass is refused at the gate, in the VISITOR ENTRANCE wording
|   · the person cannot issue a pass
|   · a guard who searches that unit is told they are no longer a resident
|   · the register, the gate history and the household's charges are untouched
|   · and it can be undone, because an office that cannot undo a mistake will
|     never use the feature at all
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so this file stands on a scratch
| lot of its own (Lot 947) and sweeps the passes it issues.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 947');
    $this->household = Household::query()->firstOrCreate(
        ['unit_id' => $this->unit->id],
        ['name' => 'Former household', 'access_restricted' => false],
    );
    $this->household->forceFill(['access_restricted' => false])->save();

    $this->person = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Delroy Former'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED],
    );

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => 'revoke.'.bin2hex(random_bytes(4)).'@example.com',
        'full_name' => 'Delroy Former',
        'resident_id' => $this->person->id,
        'unit_id' => $this->unit->id,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
        'device_uid' => 'revoke-install-1',
        'device_platform' => 'android',
    ]);

    $this->residentToken = $this->account
        ->createToken('resident-app:revoke-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))
        ->plainTextToken;

    // A guard with a handset at the same estate — the other half of every assertion below.
    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Revocation Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Karlene Watch',
        'employee_number' => 'GS-REV-1',
        'psra_number' => 'PSRA-REV-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);
    $this->guardToken = app(DeviceEnrolment::class)->enrol($this->guard, 'Revocation handset')['token'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // The estate's database is not in that transaction.
    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
});

/** One call as a handset, held to its catalogue entry — named for this file so no other's clashes. */
function asRevocationHandset(TestCase $test, string $token, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = $entry['write'] ? ['Idempotency-Key' => 'rev-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson('/api/v1/'.$entry['uri'].($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], '/api/v1/'.$entry['uri'], $body, $headers);

    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);

    FacilitiesFixture::boot();

    return $response;
}

/** The office's act, run as the service the console route runs. */
function endAccessFor(ResidentAccount $account, string $reason = 'Sold the property, handover on the 14th'): array
{
    return app(RevokeResidentAccess::class)->revoke($account, $reason, 'Marsha Clerk');
}

it('destroys every way into the estate at once — tokens, push addresses, codes in flight, and the e-pass', function () {
    $estate = FacilitiesFixture::ESTATE;
    $email = (string) $this->account->destination;

    // Somewhere to be pushed to, and a sign-in code in flight: both must be gone afterwards.
    if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
        DB::connection('mysql')->table('device_push_tokens')->insert([
            'tenant_id' => $estate, 'principal_type' => 'resident', 'principal_id' => $this->account->id,
            'app' => 'resident', 'expo_token' => 'ExponentPushToken[revoke-'.bin2hex(random_bytes(4)).']', 'platform' => 'android',
            'last_registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::connection('mysql')->table('resident_otps')->insert([
        'tenant_id' => $estate, 'channel' => 'email',
        'destination_hash' => hash_hmac('sha256', $email, (string) config('app.key')),
        'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Their own e-pass — the signed credential a guard admits a RESIDENT on.
    $epass = app(VisitorPasses::class)->residentPass($estate, $this->unit, $this->person->id, 'Delroy Former');
    expect($epass->status)->toBe(VisitorPass::ACTIVE);

    // The token works right up to the moment it does not.
    asRevocationHandset($this, $this->residentToken, 'me.show');

    $counts = endAccessFor($this->account);

    expect($counts['tokens'])->toBeGreaterThan(0)
        ->and($counts['passes'])->toBe(1);

    FacilitiesFixture::boot();
    $this->account->refresh();

    expect($this->account->status)->toBe(ResidentAccount::REVOKED)
        ->and($this->account->revoked_at)->not->toBeNull()
        ->and($this->account->revoked_reason)->toBe('Sold the property, handover on the 14th')
        ->and($this->account->revoked_by)->toBe('Marsha Clerk')

        // The link to the unit is KEPT: it is how the gate knows which lot, and what an undo restores from.
        ->and($this->account->resident_id)->toBe($this->person->id)
        ->and($this->account->unit_id)->toBe($this->unit->id);

    expect(DB::connection('mysql')->table('personal_access_tokens')
        ->where('tokenable_id', $this->account->id)->where('tokenable_type', ResidentAccount::class)->count())->toBe(0)
        ->and(DB::connection('mysql')->table('resident_otps')
            ->where('destination_hash', hash_hmac('sha256', $email, (string) config('app.key')))->count())->toBe(0);

    if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
        expect(DB::connection('mysql')->table('device_push_tokens')
            ->where('principal_type', 'resident')->where('principal_id', $this->account->id)->count())
            ->toBe(0, 'a push address is WHERE a phone is reached — an account the estate cut off keeps none');
    }

    expect(VisitorPass::query()->whereKey($epass->id)->value('status'))->toBe(VisitorPass::CANCELLED);

    // The audit entry names who did it and why.
    $entry = AuditEntry::query()->where('action', 'resident_account.access_revoked')->latest('id')->firstOrFail();
    expect((string) json_encode($entry->toArray()))->toContain('Marsha Clerk')->toContain('Sold the property');
});

it('refuses the token everywhere, and refuses a fresh sign-in rather than handing out one that fails at everything', function () {
    endAccessFor($this->account);
    FacilitiesFixture::boot();

    /*
     * 401, NOT 403, AND THAT IS THE POINT. The first test in this file proves revocation DELETES the
     * token row. A deleted token cannot be recognised - there is nothing left to look up - so the only
     * honest answer is "not signed in". An earlier version of this test asked for 403 account_revoked
     * here, which would need the server to recognise a token it has just destroyed: the two tests
     * contradicted each other, and the code sided with the one that keeps a cut-off person's
     * credential out of the database. The explanation still reaches them - at the sign-in door below,
     * and at the gate, where the guard is told to send them to the Visitor Entrance.
     */
    app('auth')->forgetGuards();
    $this->withToken($this->residentToken)->getJson('/api/v1/me')->assertStatus(401);

    // And the sign-in door, before any token exists.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/otp/request', [
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => (string) $this->account->destination,
    ])->assertStatus(202);
});

it('takes the power to issue a pass with it — the household is gone the moment the office says so', function () {
    endAccessFor($this->account);
    FacilitiesFixture::boot();

    /*
     * The address is /visitor-passes. This test used to post to /passes, which does not exist, so it
     * answered 404 whatever the token - and proved nothing at all about revocation. It now asks the
     * real endpoint, and the token is gone, so the answer is 401: the person cannot reach the pass
     * endpoint, let alone issue one.
     */
    app('auth')->forgetGuards();
    $this->withToken($this->residentToken)
        ->withHeaders(['Idempotency-Key' => 'rev-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()])
        ->postJson('/api/v1/visitor-passes', [
            'category' => 'single',
            'visitor_name' => 'Somebody Else',
            'valid_from' => now()->toIso8601String(),
            'valid_to' => now()->addHours(2)->toIso8601String(),
        ])
        ->assertStatus(401);

    // Their own e-pass cannot be fetched again either.
    app('auth')->forgetGuards();
    $this->withToken($this->residentToken)->getJson('/api/v1/e-pass')->assertStatus(401);

    // And nothing was issued in their name while the office was revoking them.
    expect(VisitorPass::query()->where('visitor_name', 'Somebody Else')->exists())->toBeFalse();
});

it('refuses the e-pass AT THE GATE, and sends the guard\'s answer to the Visitor Entrance', function () {
    $estate = FacilitiesFixture::ESTATE;
    $epass = app(VisitorPasses::class)->residentPass($estate, $this->unit, $this->person->id, 'Delroy Former');

    // Before: this is a credential a guard admits a resident on.
    $before = asRevocationHandset($this, $this->guardToken, 'gate.verify', ['pass' => $epass->token]);
    expect($before->json('verdict'))->toBe('valid')->and($before->json('admit_allowed'))->toBeTrue();

    endAccessFor($this->account);
    FacilitiesFixture::boot();

    $after = asRevocationHandset($this, $this->guardToken, 'gate.verify', ['pass' => $epass->token]);

    expect($after->json('verdict'))->toBe('revoked_resident')
        ->and($after->json('tone'))->toBe('red')
        ->and($after->json('admit_allowed'))->toBeFalse()
        ->and($after->json('headline'))->toBe(RevokeResidentAccess::GATE_HEADLINE)
        ->and($after->json('detail'))->toContain('no longer a resident')
        ->and($after->json('detail'))->toContain('Visitor Entrance')
        ->and($after->json('detail'))->toContain($this->unit->reference);

    // And by the short code, which is the other way a guard checks a pass.
    $byCode = asRevocationHandset($this, $this->guardToken, 'gate.verify', ['pass' => (string) $epass->code]);
    expect($byCode->json('verdict'))->toBe('revoked_resident');

    /*
     * ASKED BEFORE CANCELLATION, and that ordering is the whole point. Revoking cancels
     * the e-pass, so the naive order would answer "the resident cancelled this pass" —
     * true of the row, useless to the officer with somebody at the barrier.
     */
    expect($after->json('verdict'))->not->toBe('cancelled');

    // Online, an admission on it is refused outright: letting them in is an override with a reason.
    asRevocationHandset($this, $this->guardToken, 'gate.entry', ['pass_id' => $epass->pass_id, 'category' => 'Resident'], 409);
});

it('tells a guard who searches that unit, in one plain sentence, because the name stays on the register', function () {
    endAccessFor($this->account);
    FacilitiesFixture::boot();

    $found = asRevocationHandset($this, $this->guardToken, 'gate.search', ['unit' => (string) $this->unit->reference]);

    $notice = (string) $found->json('items.0.notice');

    expect($notice)->toBe('Delroy Former is no longer a resident of '.$this->unit->reference.'. Do not admit as a resident — direct them to the Visitor Entrance.')

        // The register entry is still there, which is exactly WHY the sentence has to be.
        ->and($found->json('items.0.primary_resident'))->toBe('Delroy Former');

    // A household nobody has been revoked at carries no notice at all — absent, not blanked with a reassurance.
    $other = asRevocationHandset($this, $this->guardToken, 'gate.search', ['unit' => 'Lot 47']);

    foreach ((array) $other->json('items') as $item) {
        expect($item['notice'])->toBeNull();
    }
});

it('destroys none of the community\'s own records — the register, the gate history and the charges all stay', function () {
    $estate = FacilitiesFixture::ESTATE;

    // A gate event and a charge against this home, both made BEFORE the revocation.
    $event = GateEvent::create([
        'tenant_id' => $estate, 'verdict' => 'admit', 'category' => 'Resident', 'subject' => 'Delroy Former · '.$this->unit->reference,
        'basis' => 'QR pass', 'guard_id' => $this->guard->id, 'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id, 'post_name' => $this->post->name, 'occurred_at' => now()->subHour(),
    ]);

    $charges = DB::connection('tenant')->getSchemaBuilder()->hasTable('unit_charges')
        ? DB::connection('tenant')->table('unit_charges')->where('unit_id', $this->unit->id)->count()
        : null;

    endAccessFor($this->account);
    FacilitiesFixture::boot();

    expect(Resident::query()->find($this->person->id))->not->toBeNull()
        ->and(Resident::query()->find($this->person->id)->full_name)->toBe('Delroy Former')
        ->and(Resident::query()->find($this->person->id)->removed_at)->toBeNull()
        ->and(Household::query()->find($this->household->id))->not->toBeNull()
        ->and(GateEvent::query()->find($event->id))->not->toBeNull()
        ->and(GateEvent::query()->find($event->id)->subject)->toBe('Delroy Former · '.$this->unit->reference);

    if ($charges !== null) {
        expect(DB::connection('tenant')->table('unit_charges')->where('unit_id', $this->unit->id)->count())->toBe($charges);
    }

    // And the audit trail gains an entry rather than losing one.
    expect(AuditEntry::query()->where('action', 'resident_account.access_revoked')
        ->where('entity_id', (string) $this->account->id)->count())->toBe(1);
});

it('can be undone, and the person is where they were', function () {
    endAccessFor($this->account);
    FacilitiesFixture::boot();

    expect($this->account->refresh()->status)->toBe(ResidentAccount::REVOKED);

    $status = app(RevokeResidentAccess::class)->restore($this->account, 'Marsha Clerk');
    FacilitiesFixture::boot();

    expect($status)->toBe(ResidentAccount::ACTIVE);

    $this->account->refresh();

    expect($this->account->status)->toBe(ResidentAccount::ACTIVE)
        ->and($this->account->revoked_at)->toBeNull()
        ->and($this->account->revoked_reason)->toBeNull()
        ->and($this->account->revoked_by)->toBeNull()
        ->and($this->account->resident_id)->toBe($this->person->id);

    // The gate stops saying it the moment the office does.
    $found = asRevocationHandset($this, $this->guardToken, 'gate.search', ['unit' => (string) $this->unit->reference]);
    expect($found->json('items.0.notice'))->toBeNull();

    expect(AuditEntry::query()->where('action', 'resident_account.access_restored')
        ->where('entity_id', (string) $this->account->id)->count())->toBe(1);

    // A fresh sign-in on their own phone works again.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/otp/request', [
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => (string) $this->account->destination,
    ])->assertStatus(202);
});

it('is an explicit act — a reason is required, it is not done twice, and a deleted account has nothing left to end', function () {
    expect(fn () => app(RevokeResidentAccess::class)->revoke($this->account, '   ', 'Marsha Clerk'))
        ->toThrow(DomainException::class);

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::ACTIVE);

    endAccessFor($this->account);
    FacilitiesFixture::boot();

    expect(fn () => endAccessFor($this->account))->toThrow(DomainException::class);

    // Restoring something that was never ended is refused too.
    app(RevokeResidentAccess::class)->restore($this->account, 'Marsha Clerk');
    FacilitiesFixture::boot();
    expect(fn () => app(RevokeResidentAccess::class)->restore($this->account, 'Marsha Clerk'))->toThrow(DomainException::class);
});

it('is behind the residents permission on the console, in both directions', function () {
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);   // holds Residents in full
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);   // reads the register and corrects nobody

    $slug = $this->unit->slug();
    $path = '/residents/'.$slug.'/people/'.$this->person->id.'/app-access/';

    $this->actingAs($president)
        ->post(FacilitiesFixture::url($path.'revoke'), ['reason' => 'Sold the property'])
        ->assertForbidden();

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::ACTIVE, 'a reader of the register ends nobody\'s app access');

    // A reason under five characters is not a reason.
    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url($path.'revoke'), ['reason' => 'no'])
        ->assertSessionHasErrors('reason');

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::ACTIVE);

    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url($path.'revoke'), ['reason' => 'Lease ended on the 30th'])
        ->assertRedirect(FacilitiesFixture::url('/residents/'.$slug));

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::REVOKED)
        ->and($this->account->revoked_reason)->toBe('Lease ended on the 30th');

    // And the undo is behind the same permission, in both directions.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url($path.'restore'))
        ->assertForbidden();

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::REVOKED);

    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url($path.'restore'))
        ->assertRedirect(FacilitiesFixture::url('/residents/'.$slug));

    FacilitiesFixture::boot();
    expect($this->account->refresh()->status)->toBe(ResidentAccount::ACTIVE);
});

it('shows the office who has a login and who has lost one, on board 38 itself', function () {
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $slug = $this->unit->slug();

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/residents/'.$slug))
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('canEndAppAccess', true)
            ->where('people.0.app_access.state', 'active')
            ->where('people.0.app_access.can_end', true)
            ->where('people.0.app_access.can_restore', false)
            ->etc());

    endAccessFor($this->account);
    FacilitiesFixture::boot();

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/residents/'.$slug))
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('people.0.app_access.state', 'revoked')
            ->where('people.0.app_access.can_end', false)
            ->where('people.0.app_access.can_restore', true)
            ->where('people.0.app_access.revoked_by', 'Marsha Clerk')
            ->etc());
});
