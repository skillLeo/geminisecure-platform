<?php

declare(strict_types=1);

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Tenant;
use App\Notifications\ResidentInviteSent;
use App\Notifications\ResidentSignInCode;
use App\Services\Estate\InviteAddressRefused;
use App\Services\Estate\OnePrimaryIndex;
use App\Services\Estate\ResidentInvites;
use App\Services\Estate\Residents;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\RevokeResidentAccess;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\OwnEstate;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| An invite is recognised when its invitee signs in (household invites, stage 2)
|--------------------------------------------------------------------------
|
| The invitee proves the invited address with the one-time code, and that proof, matched to EXACTLY ONE live
| invite in this estate, is their claim: no form. The office confirms it with one tap, or — when the estate
| trusts its primary residents' invites — a primary's invite links them at once. D-155 to D-158.
|
| And the security argument (DESIGN §5.7): every other address, a lapsed or cancelled or never-sent invite, a
| register that changed since, another household, another ESTATE, two live invites, a replayed code, a revoked
| person, a stale offline add — each gets the ordinary flow, or a refusal, and never a link.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and everything it adds there is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8873');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Accept household', 'access_restricted' => false]);

    $primary = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Accept Andrea')->first();
    if ($primary === null) {
        $primary = new Resident;
        $primary->forceFill(['household_id' => $this->household->id, 'full_name' => 'Accept Andrea', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->where('id', '!=', $primary->id)->update(['is_primary' => false]);
    DB::connection('tenant')->table('residents')->where('id', $primary->id)->update(['is_primary' => true, 'removed_at' => null]);
    $this->primary = $primary->fresh();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'andrea.'.bin2hex(random_bytes(3)).'@accept.test',
        'full_name' => 'Accept Andrea', 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'resident_id' => $primary->id, 'unit_id' => $this->unit->id, 'device_uid' => 'accept-install-1', 'device_platform' => 'android',
    ]);
    $this->primaryAccount = $account;
    $this->token = $account->createToken('resident-app:accept-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    // Off, as every estate starts (D-156).
    EstateSetting::current()->forceFill(['trust_primary_invites' => false])->save();
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // Everything this file wrote to the shared estate: its claims, its members, their invites and passes, the switch.
    DB::connection('tenant')->table('unit_claims')->where('unit_id', $this->unit->id)->delete();
    $added = Resident::withRemoved()->where('household_id', $this->household->id)->whereKeyNot($this->primary->id)->pluck('id');
    DB::connection('tenant')->table('resident_invites')->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('visitor_passes')->whereIn('issued_by_resident_id', $added)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $added)->delete();
    EstateSetting::current()->forceFill(['trust_primary_invites' => false])->save();
});

/**
 * One call to the Resident API as a catalogue entry names it, held to that entry's contract.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function accCall(TestCase $test, string $name, array $params = [], array $body = [], ?int $status = null, ?string $token = null, ?string $deviceTime = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'acc-'.bin2hex(random_bytes(8)), 'X-Device-Time' => $deviceTime ?? now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($token ?? $test->token)->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** The primary adds a member with an email, and the invite goes (the fake mailer accepts it). */
function accAddInvited(TestCase $test, string $name, string $email, array $extra = []): int
{
    $added = accCall($test, 'members.store', [], ['full_name' => $name, 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email, ...$extra]);

    return (int) $added->json('id');
}

/** Sign in to an estate with an email address: ask for a code, read it off the fake mailer, prove it. */
function accSignIn(TestCase $test, string $email, string $estate = FacilitiesFixture::ESTATE, ?string $uid = null): TestResponse
{
    // Half a minute between sign-ins, as between people: the enrol throttle allows ten requests a minute an address.
    Carbon::setTestNow(Carbon::now()->addSeconds(31));

    app('auth')->forgetGuards();
    $test->postJson('/api/v1/auth/otp/request', ['estate' => $estate, 'channel' => 'email', 'destination' => $email])->assertStatus(202);

    $code = accLastCode($email);

    app('auth')->forgetGuards();
    $response = $test->postJson('/api/v1/auth/otp/verify', [
        'estate' => $estate, 'channel' => 'email', 'destination' => $email,
        'code' => $code, 'device_uid' => $uid ?? 'acc-'.bin2hex(random_bytes(4)), 'platform' => 'android',
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** The last sign-in code the fake mailer holds for an address. */
function accLastCode(string $email): string
{
    $code = null;

    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n, array $channels, object $notifiable) use ($email, &$code): bool {
        if (strtolower((string) ($notifiable->routes['mail'] ?? '')) === strtolower($email)) {
            $code = $n->code;
        }

        return true;
    });

    expect($code)->not->toBeNull();

    return (string) $code;
}

/**
 * A service called as a request would call it: inside the estate's tenancy, so `tenant()` names it — the
 * audit's estate, the rate limits' keys and every "in this estate" query read it.
 */
function accRun(Closure $fn): mixed
{
    $result = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->run($fn);
    FacilitiesFixture::boot();

    return $result;
}

function accEmail(string $who): string
{
    return $who.'.'.bin2hex(random_bytes(3)).'@accept.test';
}

function accAccount(string $email): ?ResidentAccount
{
    return ResidentAccount::query()->where('tenant_id', FacilitiesFixture::ESTATE)->where('channel', 'email')->where('destination', strtolower($email))->first();
}

/** A register entry's latest invite row. */
function accInvite(int $residentId): ?ResidentInvite
{
    FacilitiesFixture::boot();

    return ResidentInvite::query()->where('resident_id', $residentId)->orderByDesc('id')->first();
}

/** An ACTIVE app account linked to a register entry at this unit, as an approved sign-in leaves one. */
function accActiveAccount(object $test, int $residentId, string $email, string $name = 'Linked member'): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => strtolower($email),
        'full_name' => $name, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'resident_id' => $residentId, 'unit_id' => $test->unit->id, 'device_uid' => 'acc-linked-'.$residentId, 'device_platform' => 'android',
    ]);
}

function accAudit(string $action, ?string $entityId = null): ?AuditEntry
{
    return AuditEntry::query()
        ->where('tenant_id', FacilitiesFixture::ESTATE)
        ->where('action', $action)
        ->when($entityId !== null, static fn ($q) => $q->where('entity_id', $entityId))
        ->orderByDesc('id')
        ->first();
}

/* ------------------------------------------------------------------ */
/* T-ACC-1 · the invited address signs in, and the claim is made */
/* ------------------------------------------------------------------ */

it('makes the claim from the invite when the invited address signs in — no form, the register\'s name, the office confirms (T-ACC-1)', function () {
    $email = accEmail('soft');
    $member = accAddInvited($this, 'Soft Dev', $email);

    $signIn = accSignIn($this, $email)->assertOk();

    expect($signIn->json('next'))->toBe('await_approval')
        ->and($signIn->json('account.status'))->toBe('pending')
        ->and($signIn->json('claim.status'))->toBe('pending')
        ->and($signIn->json('claim.submitted_name'))->toBe('Soft Dev')
        ->and($signIn->json('claim.invited_by'))->toBe('Accept Andrea')
        ->and($signIn->json('claim.invited_at'))->toMatch('/\+00:00$/');
    ApiContract::assertMatches($signIn, 'auth.otp.verify');

    $invite = accInvite($member);
    $claim = UnitClaim::query()->findOrFail($signIn->json('claim.id'));

    expect($claim->invite_id)->toBe($invite->id)
        ->and($claim->household_id)->toBe($this->household->id)
        ->and($claim->unit_id)->toBe($this->unit->id)
        ->and($claim->match_result)->toBe('exact')
        ->and($claim->claim_type)->toBe(UnitClaim::TYPE_MEMBER)
        ->and($claim->review_flag)->toContain('Invited by Accept Andrea')
        ->and($invite->claimed_at)->not->toBeNull()
        ->and($invite->claim_id)->toBe($claim->id)
        ->and(ResidentInvites::outcome($invite)['status'])->toBe('claimed')
        ->and(accAudit('resident.invite_accepted', (string) accAccount($email)->id)?->after)->toMatchArray(['invite_id' => $invite->id, 'claim_id' => $claim->id, 'trusted' => false]);
});

/* ------------------------------------------------------------------ */
/* T-ACC-2 · approval binds by the invite */
/* ------------------------------------------------------------------ */

it('binds the approval to the invite\'s own register entry after the office renamed it, and refuses in words once it was removed (T-ACC-2)', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $email = accEmail('rename');
    $member = accAddInvited($this, 'Sofy Dev', $email);
    $claimId = accSignIn($this, $email)->assertOk()->json('claim.id');

    // The office corrects the spelling on the register after the claim was made.
    DB::connection('tenant')->table('residents')->where('id', $member)->update(['full_name' => 'Sophie Dev']);
    $before = Resident::withRemoved()->where('household_id', $this->household->id)->count();

    $approved = accRun(fn () => app(Residents::class)->approveClaim(UnitClaim::query()->findOrFail($claimId), $office));

    expect($approved->resolved_resident_id)->toBe($member)
        ->and(Resident::withRemoved()->where('household_id', $this->household->id)->count())->toBe($before)
        ->and(Resident::query()->findOrFail($member)->status)->toBe(Resident::VERIFIED)
        ->and(accAudit('unit_claim.approved', (string) $claimId)?->after)->toMatchArray(['resolved_resident_id' => $member, 'invite_id' => accInvite($member)->id, 'trusted' => false]);

    // And another: its person removed before the office got to it.
    $email2 = accEmail('gone');
    $gone = accAddInvited($this, 'Gone Dev', $email2);
    $claim2 = accSignIn($this, $email2)->assertOk()->json('claim.id');
    DB::connection('tenant')->table('residents')->where('id', $gone)->update(['removed_at' => now()]);

    expect(fn () => accRun(fn () => app(Residents::class)->approveClaim(UnitClaim::query()->findOrFail($claim2), $office)))
        ->toThrow(DomainException::class, 'Gone Dev was removed from the household');
    FacilitiesFixture::boot();

    expect(UnitClaim::query()->findOrFail($claim2)->status)->toBe(UnitClaim::PENDING)
        ->and(Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Gone Dev')->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* T-ACC-3 · the ordinary flow, for everything that is not the invite */
/* ------------------------------------------------------------------ */

it('gives the ordinary flow to another address, a lapsed, cancelled or never-sent invite, a changed register email and a removed person (T-ACC-3)', function () {
    $ordinary = function (string $email): void {
        $signIn = accSignIn($this, $email)->assertOk();

        expect($signIn->json('next'))->toBe('claim_unit')
            ->and($signIn->json('claim'))->toBeNull()
            ->and(accAccount($email)?->claim_id)->toBeNull();
    };

    // Another address: the invite is to somebody else.
    accAddInvited($this, 'Addressed Dev', accEmail('addressed'));
    $ordinary(accEmail('stranger'));

    // Lapsed.
    $lapsed = accEmail('lapsed');
    $lapsedId = accAddInvited($this, 'Lapsed Dev', $lapsed);
    accInvite($lapsedId)->forceFill(['expires_at' => now()->subMinute()])->save();
    $ordinary($lapsed);

    // Cancelled by the primary.
    $cancelled = accEmail('cancelled');
    $cancelledId = accAddInvited($this, 'Cancelled Dev', $cancelled);
    accCall($this, 'members.invite_cancel', ['member' => $cancelledId]);
    $ordinary($cancelled);

    // A never-sent row: an email on it, no expiry, no attempt (defect 1 — `isOpen()` would have counted it).
    $never = accEmail('never');
    $neverId = (int) accCall($this, 'members.store', [], ['full_name' => 'Never Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $never, 'invite' => false])->json('id');
    FacilitiesFixture::boot();
    (new ResidentInvite(['resident_id' => $neverId, 'unit_id' => $this->unit->id, 'channels' => 'email']))
        ->forceFill(['token' => (string) Str::uuid(), 'email' => $never, 'expires_at' => null, 'attempts' => 0])->save();
    $ordinary($never);

    // The office changed the register's email after the invite went.
    $changed = accEmail('changed');
    $changedId = accAddInvited($this, 'Changed Dev', $changed);
    DB::connection('tenant')->table('residents')->where('id', $changedId)->update(['email' => accEmail('elsewhere')]);
    $ordinary($changed);

    // The invite's person was removed.
    $removed = accEmail('removed');
    $removedId = accAddInvited($this, 'Removed Dev', $removed);
    DB::connection('tenant')->table('residents')->where('id', $removedId)->update(['removed_at' => now()]);
    $ordinary($removed);
});

/* ------------------------------------------------------------------ */
/* T-ACC-4 · another estate, another household, two live invites */
/* ------------------------------------------------------------------ */

function accOtherEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('accepttest', 'ACT', 'Accept Test Estate');
}

it('never matches an invite from a sign-in to ANOTHER estate with the same address (T-ACC-4)', function () {
    $email = accEmail('twoestates');
    accAddInvited($this, 'Two Estates Dev', $email);

    // The other estate exists, is active, and holds no invite: its own database is the only one its sign-in reads.
    accOtherEstate()->boot();
    FacilitiesFixture::boot();

    $signIn = accSignIn($this, $email, 'accepttest')->assertOk();

    expect($signIn->json('next'))->toBe('claim_unit')
        ->and($signIn->json('claim'))->toBeNull()
        ->and($signIn->json('estate.id'))->toBe('accepttest');

    // The facilities estate's invite is untouched: still live, still for its own sign-in.
    expect(ResidentInvites::outcome(accInvite(Resident::query()->where('full_name', 'Two Estates Dev')->firstOrFail()->id))['status'])->toBe('sent');
});

it('never matches when the invite\'s person moved to another household, nor when two live invites share the address (T-ACC-4)', function () {
    // Moved: the register entry is now in another household at another unit.
    $moved = accEmail('moved');
    $movedId = accAddInvited($this, 'Moved Dev', $moved);
    $elsewhere = FacilitiesFixture::scratchUnit('Lot 8874');
    $other = Household::query()->firstOrCreate(['unit_id' => $elsewhere->id], ['name' => 'Elsewhere household', 'access_restricted' => false]);
    DB::connection('tenant')->table('residents')->where('id', $movedId)->update(['household_id' => $other->id]);

    try {
        expect(accSignIn($this, $moved)->assertOk()->json('next'))->toBe('claim_unit');
    } finally {
        FacilitiesFixture::boot();
        DB::connection('tenant')->table('residents')->where('id', $movedId)->update(['household_id' => $this->household->id]);
        DB::connection('tenant')->table('households')->where('id', $other->id)->delete();
    }

    // Two live invites for one address (INV-2): neither is matched, and the ambiguity is on the record.
    $shared = accEmail('shared');
    $a = accAddInvited($this, 'Shared One', $shared);
    $b = accAddInvited($this, 'Shared Two', accEmail('placeholder'));
    FacilitiesFixture::boot();
    // The second row made to point at the same address directly — G4 now refuses to leave two through `send()`.
    accInvite($b)->forceFill(['email' => $shared])->save();
    DB::connection('tenant')->table('residents')->where('id', $b)->update(['email' => $shared]);

    $signIn = accSignIn($this, $shared)->assertOk();

    expect($signIn->json('next'))->toBe('claim_unit')
        ->and(accInvite($a)->claimed_at)->toBeNull()
        ->and(accInvite($b)->claimed_at)->toBeNull()
        ->and(accAudit('resident.invite_ambiguous', (string) accAccount($shared)->id)?->after['invite_ids'])->toEqualCanonicalizing([accInvite($a)->id, accInvite($b)->id]);
});

/* ------------------------------------------------------------------ */
/* T-ACC-5 · replay */
/* ------------------------------------------------------------------ */

it('refuses a replayed code, and a second sign-in makes no second claim and never activates a pending account (T-ACC-5)', function () {
    $email = accEmail('replay');
    $member = accAddInvited($this, 'Replay Dev', $email);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email])->assertStatus(202);
    $code = accLastCode($email);
    $verify = fn () => $this->postJson('/api/v1/auth/otp/verify', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'code' => $code, 'device_uid' => 'replay-1', 'platform' => 'android']);

    app('auth')->forgetGuards();
    $first = $verify()->assertOk();
    app('auth')->forgetGuards();
    $verify()->assertStatus(422)->assertJsonPath('error.code', 'otp_expired');
    FacilitiesFixture::boot();

    // A fresh code, a second sign-in: the same claim, not another, and still pending.
    $again = accSignIn($this, $email, uid: 'replay-2')->assertOk();

    expect($again->json('claim.id'))->toBe($first->json('claim.id'))
        ->and($again->json('next'))->toBe('await_approval')
        ->and(UnitClaim::query()->where('invite_id', accInvite($member)->id)->count())->toBe(1)
        ->and(accAccount($email)->status)->toBe(ResidentAccount::PENDING);
});

/* ------------------------------------------------------------------ */
/* T-ACC-6 · trust */
/* ------------------------------------------------------------------ */

it('links a primary\'s invitee at once when the estate trusts them, and only a primary\'s (T-ACC-6)', function () {
    EstateSetting::current()->forceFill(['trust_primary_invites' => true])->save();

    $email = accEmail('trusted');
    $member = accAddInvited($this, 'Trusted Dev', $email);

    $signIn = accSignIn($this, $email)->assertOk();

    expect($signIn->json('next'))->toBe('home')
        ->and($signIn->json('account.status'))->toBe('active')
        ->and(Resident::query()->findOrFail($member)->status)->toBe(Resident::VERIFIED)
        ->and(UnitClaim::query()->findOrFail($signIn->json('claim.id'))->reviewed_by_name)->toBe('Trusted invite from Accept Andrea (estate setting)')
        ->and(accAudit('resident.invite_accepted', (string) accAccount($email)->id)?->after['trusted'])->toBeTrue()
        ->and(accAudit('unit_claim.approved', (string) $signIn->json('claim.id'))?->after['trusted'])->toBeTrue();

    // The office's own invite always waits.
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $officeEmail = accEmail('office');
    $officeMember = (int) accCall($this, 'members.store', [], ['full_name' => 'Office Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $officeEmail, 'invite' => false])->json('id');
    accRun(fn () => app(ResidentInvites::class)->send(Resident::query()->findOrFail($officeMember), $office->name, null, $office));

    $officeSignIn = accSignIn($this, $officeEmail)->assertOk();
    expect($officeSignIn->json('next'))->toBe('await_approval')
        ->and($officeSignIn->json('claim.invited_by'))->toBe('the estate office');
});

it('does not trust an invite once its sender stopped being primary, and trusts the second of two current primaries (T-ACC-6, G11)', function () {
    EstateSetting::current()->forceFill(['trust_primary_invites' => true])->save();

    // Sent by the primary; then primacy moved to somebody else before the invitee signed in.
    $email = accEmail('demoted');
    accAddInvited($this, 'Demoted Dev', $email);
    DB::connection('tenant')->table('residents')->where('id', $this->primary->id)->update(['is_primary' => false]);

    expect(accSignIn($this, $email)->assertOk()->json('next'))->toBe('await_approval');

    DB::connection('tenant')->table('residents')->where('id', $this->primary->id)->update(['is_primary' => true]);

    // Two current primaries (the past left some): the SECOND sends, and is trusted — their own row says primary.
    // The past had no one-primary index (M47 §14a), so it is lifted around the pair and put back after it.
    OnePrimaryIndex::drop('tenant');
    $second = new Resident;
    $second->forceFill(['household_id' => $this->household->id, 'full_name' => 'Second Primary', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();

    try {
        DB::connection('tenant')->table('residents')->where('id', $second->id)->update(['is_primary' => true]);
        $secondAccount = accActiveAccount($this, $second->id, accEmail('second'));
        $secondToken = $secondAccount->createToken('resident-app:second', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

        $email2 = accEmail('bysecond');
        accCall($this, 'members.store', [], ['full_name' => 'By Second Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email2], token: $secondToken);

        expect(accSignIn($this, $email2)->assertOk()->json('next'))->toBe('home');
    } finally {
        DB::connection('tenant')->table('residents')->where('id', $second->id)->update(['is_primary' => false]);
        OnePrimaryIndex::ensure('tenant');
    }
});

it('does not trust a revoked name at the unit — exact or a near miss — when the office revoked AFTER the invite went (T-ACC-6, G3)', function () {
    EstateSetting::current()->forceFill(['trust_primary_invites' => true])->save();

    // "X Dev" (R1) has app access; the primary invites a new row "x dev" (R2) at a new address.
    $r1Email = accEmail('xdev');
    $r1 = (int) accCall($this, 'members.store', [], ['full_name' => 'X Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $r1Email, 'invite' => false])->json('id');
    $r1Account = accActiveAccount($this, $r1, $r1Email);

    $r2Email = accEmail('xdev2');
    // A different person of the same name: their own phone. The same name AND phone is the same person, and since M54
    // the household cannot add them twice.
    accAddInvited($this, 'x dev', $r2Email, ['phone' => '876 555 0101']);
    $nearEmail = accEmail('sofdev');
    accAddInvited($this, 'Sof Dev', $nearEmail);

    // THEN the office ends R1's access.
    accRun(fn () => app(RevokeResidentAccess::class)->revoke($r1Account->fresh(), 'Moved out; not to be admitted.', 'Estate office'));

    $signIn = accSignIn($this, $r2Email)->assertOk();

    expect($signIn->json('next'))->toBe('await_approval')
        ->and(accAccount($r2Email)->status)->toBe(ResidentAccount::PENDING)
        ->and(accAudit('resident.invite_accepted', (string) accAccount($r2Email)->id)?->after['trusted'])->toBeFalse();

    $card = collect(accRun(fn () => app(Residents::class)->claimsBoard(false))['claims'])->firstWhere('id', $signIn->json('claim.id'));
    expect($card['notes']['revoked_name_on'])->not->toBeNull()
        ->and($card['notes']['invited']['approve_label'])->toBe('Approve · invited by Accept Andrea');

    // A near miss is not linked either: ANY revoked account at the unit stops trust.
    expect(accSignIn($this, $nearEmail)->assertOk()->json('next'))->toBe('await_approval');
});

/* ------------------------------------------------------------------ */
/* T-ACC-7 · removed and revoked people invited again */
/* ------------------------------------------------------------------ */

it('reopens a removed member\'s suspended account when they are invited again, and never trusts it (T-ACC-7)', function () {
    EstateSetting::current()->forceFill(['trust_primary_invites' => true])->save();

    $email = accEmail('returning');
    $first = (int) accCall($this, 'members.store', [], ['full_name' => 'Returning Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email, 'invite' => false])->json('id');
    $account = accActiveAccount($this, $first, $email);

    accCall($this, 'members.destroy', ['member' => $first]);
    expect($account->fresh()->status)->toBe(ResidentAccount::SUSPENDED);

    $again = accAddInvited($this, 'Returning Dev', $email);
    $signIn = accSignIn($this, $email)->assertOk();

    expect($signIn->json('next'))->toBe('await_approval')
        ->and($account->fresh()->status)->toBe(ResidentAccount::PENDING)
        ->and($account->fresh()->resident_id)->toBeNull()
        ->and(UnitClaim::query()->findOrFail($signIn->json('claim.id'))->invite_id)->toBe(accInvite($again)->id)
        ->and(accAudit('resident_account.reopened_by_invite', (string) $account->id))->not->toBeNull()
        ->and(accAudit('resident.invite_accepted', (string) $account->id)?->after['trusted'])->toBeFalse();
});

it('never reopens a REVOKED account, and refuses to invite its address in the generic sentence while the audit keeps the reason (T-ACC-7)', function () {
    $email = accEmail('revoked');
    $x = (int) accCall($this, 'members.store', [], ['full_name' => 'Revoked Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email, 'invite' => false])->json('id');
    $account = accActiveAccount($this, $x, $email, 'Revoked Dev');

    FacilitiesFixture::boot();
    accRun(fn () => app(RevokeResidentAccess::class)->revoke($account->fresh(), 'Not to be admitted.', 'Estate office'));

    $refused = accCall($this, 'members.invite', ['member' => $x], [], 409)->assertJsonPath('error.code', 'invite_address_refused');
    expect($refused->json('error.message'))->toBe(InviteAddressRefused::GENERIC)
        ->and(accAudit('resident.invite_refused', (string) $x)?->after['reason'])->toBe('revoked_account');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email])->assertStatus(202);
    $code = accLastCode($email);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/otp/verify', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'code' => $code, 'device_uid' => 'revoked-1', 'platform' => 'android'])
        ->assertStatus(403)->assertJsonPath('error.code', 'account_revoked');

    expect($account->fresh()->status)->toBe(ResidentAccount::REVOKED);
});

it('keeps a revoked person revoked when the household removes them, and the central repair puts back the ones overwritten before (T-ACC-7b, G1)', function () {
    $email = accEmail('keptrevoked');
    $x = (int) accCall($this, 'members.store', [], ['full_name' => 'Kept Revoked', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email, 'invite' => false])->json('id');
    $account = accActiveAccount($this, $x, $email, 'Kept Revoked');

    FacilitiesFixture::boot();
    accRun(fn () => app(RevokeResidentAccess::class)->revoke($account->fresh(), 'Not to be admitted.', 'Estate office'));
    accCall($this, 'members.destroy', ['member' => $x]);

    expect($account->fresh()->status)->toBe(ResidentAccount::REVOKED)
        ->and($account->fresh()->unit_id)->toBe($this->unit->id)
        ->and(RevokeResidentAccess::revokedNamesByUnit(FacilitiesFixture::ESTATE, [$this->unit->id])[$this->unit->id] ?? [])->toContain('Kept Revoked');

    // The primary adds them again with the same address: added, the invite not sent, in the generic words.
    $again = accCall($this, 'members.store', [], ['full_name' => 'Kept Revoked', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email]);
    expect($again->json('invite.status'))->toBe('not_sent')
        ->and($again->json('invite.reason'))->toBe(InviteAddressRefused::GENERIC);
    accCall($this, 'members.invite', ['member' => $again->json('id')], [], 409)->assertJsonPath('error.code', 'invite_address_refused');

    // The office can still give it back.
    expect(accRun(fn () => app(RevokeResidentAccess::class)->restore($account->fresh(), 'Estate office')))->toBe(ResidentAccount::ACTIVE);

    // THE CENTRAL REPAIR (G1): a suspended + revoked_at row back to revoked; a deleted account left alone; idempotent.
    $overwritten = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => accEmail('overwritten'), 'status' => ResidentAccount::SUSPENDED]);
    $overwritten->forceFill(['revoked_at' => now()->subDay()])->save();
    $deleted = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => accEmail('deleted'), 'status' => ResidentAccount::SUSPENDED]);
    $deleted->forceFill(['revoked_at' => now()->subDay(), 'deleted_at' => now()])->save();

    $repair = require base_path('database/migrations/2026_09_26_110500_a_revoked_account_removed_from_its_household_stays_revoked.php');
    $repair->up();
    $repair->up();

    expect($overwritten->fresh()->status)->toBe(ResidentAccount::REVOKED)
        ->and(DB::connection('mysql')->table('resident_accounts')->where('id', $deleted->id)->value('status'))->toBe(ResidentAccount::SUSPENDED);
});

/* ------------------------------------------------------------------ */
/* T-ACC-8 · pending stays pending */
/* ------------------------------------------------------------------ */

it('lets a pending invited account reach only what a pending account reaches (T-ACC-8)', function () {
    $email = accEmail('pending');
    accAddInvited($this, 'Pending Dev', $email);

    $token = accSignIn($this, $email)->assertOk()->json('token');

    accCall($this, 'epass.show', [], [], 403, token: $token)->assertJsonPath('error.code', 'account_pending');
});

/* ------------------------------------------------------------------ */
/* T-ACC-9 · removal, revocation and rejection take the invite with them */
/* ------------------------------------------------------------------ */

it('rejects a removed member\'s pending invite-claim and suspends that account; revocation cancels an open invite (T-ACC-9)', function () {
    $email = accEmail('removedclaim');
    $member = accAddInvited($this, 'Removed Claim Dev', $email);
    $claimId = accSignIn($this, $email)->assertOk()->json('claim.id');

    accCall($this, 'members.destroy', ['member' => $member]);

    expect(UnitClaim::query()->findOrFail($claimId)->status)->toBe(UnitClaim::REJECTED)
        ->and(accAccount($email)->status)->toBe(ResidentAccount::SUSPENDED)
        ->and(accAccount($email)->tokens()->count())->toBe(0)
        ->and(accAudit('household.member_removed', (string) $member)?->after['claims_rejected'] ?? null)->toBe(1);

    // Revocation cancels the person's open invite, so an old link cannot bring them back.
    $open = accEmail('openinvite');
    $withInvite = accAddInvited($this, 'Open Invite Dev', $open);
    $account = accActiveAccount($this, $withInvite, accEmail('openinvite-account'));
    FacilitiesFixture::boot();
    accRun(fn () => app(RevokeResidentAccess::class)->revoke($account->fresh(), 'Not to be admitted.', 'Estate office'));

    expect(ResidentInvites::outcome(accInvite($withInvite))['status'])->toBe('cancelled')
        ->and(accAudit('resident.invite_cancelled', (string) $withInvite)?->after['why'] ?? null)->toBe('access_revoked');
});

it('cancels a removed member\'s own e-pass (T-ACC-9)', function () {
    $holderEmail = accEmail('passholder');
    $member = (int) accCall($this, 'members.store', [], ['full_name' => 'Pass Holder Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $holderEmail, 'invite' => false])->json('id');
    $account = accActiveAccount($this, $member, $holderEmail);
    $memberToken = $account->createToken('resident-app:pass', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    accCall($this, 'epass.show', [], [], token: $memberToken);
    FacilitiesFixture::boot();
    expect(VisitorPass::query()->where('issued_by_resident_id', $member)->where('status', VisitorPass::ACTIVE)->count())->toBe(1);

    accCall($this, 'members.destroy', ['member' => $member]);

    expect(VisitorPass::query()->where('issued_by_resident_id', $member)->where('status', VisitorPass::ACTIVE)->count())->toBe(0)
        ->and(VisitorPass::query()->where('issued_by_resident_id', $member)->where('status', VisitorPass::CANCELLED)->whereNotNull('cancelled_at')->count())->toBe(1);
});

it('cancels the invite when the office rejects its claim, and a fresh invite may then go (T-ACC-9)', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $email = accEmail('rejected');
    $member = accAddInvited($this, 'Rejected Dev', $email);
    $claimId = accSignIn($this, $email)->assertOk()->json('claim.id');

    accRun(fn () => app(Residents::class)->rejectClaim(UnitClaim::query()->findOrFail($claimId), 'Not somebody the household knows.', $office));

    expect(ResidentInvites::outcome(accInvite($member))['status'])->toBe('cancelled')
        ->and(accAudit('resident.invite_cancelled', (string) $member)?->after['why'] ?? null)->toBe('claim_rejected');

    // The primary sends a fresh one, and it goes.
    Carbon::setTestNow(now()->addMinutes(6));
    expect(accCall($this, 'members.invite', ['member' => $member])->json('invite.status'))->toBe('sent');
});

/* ------------------------------------------------------------------ */
/* T-ACC-10 · T-ACC-11 · the two limits */
/* ------------------------------------------------------------------ */

it('sends one address at most five invites a day, across rows, and names when it can go again (T-ACC-10)', function () {
    $start = Carbon::now()->startOfSecond();
    Carbon::setTestNow($start);

    $email = accEmail('capped');
    $member = accAddInvited($this, 'Capped Dev', $email);                                        // 1
    Carbon::setTestNow($start->copy()->addSeconds(61));
    accCall($this, 'members.invite', ['member' => $member]);                                      // 2
    accCall($this, 'members.invite_cancel', ['member' => $member]);
    accCall($this, 'members.invite', ['member' => $member]);                                      // 3, a fresh row
    Carbon::setTestNow($start->copy()->addSeconds(61 + 61));
    accCall($this, 'members.invite', ['member' => $member]);                                      // 4
    Carbon::setTestNow($start->copy()->addSeconds(61 + 61 + 121));
    accCall($this, 'members.invite', ['member' => $member]);                                      // 5
    Carbon::setTestNow($start->copy()->addSeconds(61 + 61 + 121 + 301));

    $sixth = accCall($this, 'members.invite', ['member' => $member], [], 409)->assertJsonPath('error.code', 'invite_wait');

    expect($sixth->json('error.message'))->toContain('five invites today')
        ->and($sixth->json('error.message'))->toMatch('/\d{1,2}:\d{2}\s?[AP]M/');

    Notification::assertSentOnDemandTimes(ResidentInviteSent::class, 5);
});

it('stops a primary\'s app invites for the day after five address refusals, in the same words, and never the office (T-ACC-11, G5)', function () {
    // Five addresses already in use for another home here.
    $inUse = [];
    foreach (range(1, 5) as $i) {
        $inUse[$i] = accEmail('inuse'.$i);
        accActiveAccount($this, $this->primary->id, $inUse[$i]);
    }

    foreach ($inUse as $i => $address) {
        $refused = accCall($this, 'members.store', [], ['full_name' => 'Refusal '.$i, 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $address]);
        expect($refused->json('invite.status'))->toBe('not_sent')
            ->and($refused->json('invite.reason'))->toBe(InviteAddressRefused::GENERIC);
    }

    // A perfectly good address now gets the SAME sentence, and the audit says why.
    $good = accEmail('good');
    $goodMember = (int) accCall($this, 'members.store', [], ['full_name' => 'Good Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $good])->json('id');
    FacilitiesFixture::boot();
    expect(ResidentInvites::outcome(accInvite($goodMember))['status'])->toBe('not_sent')
        ->and(accInvite($goodMember)->refused_reason)->toBe('refusal_limit')
        ->and(accAudit('resident.invite_refused', (string) $goodMember)?->after['reason'])->toBe('refusal_limit');

    $limited = accCall($this, 'members.invite', ['member' => $goodMember], [], 409)->assertJsonPath('error.code', 'invite_address_refused');
    expect($limited->json('error.message'))->toBe(InviteAddressRefused::GENERIC);

    // The office is never limited, and reads the real reason.
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $refusedMember = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Refusal 1')->firstOrFail();
    $this->actingAs($office)
        ->post(FacilitiesFixture::url('/residents/'.$this->unit->slug().'/people/'.$refusedMember->id.'/invite'))
        ->assertRedirect()
        ->assertSessionHasErrors(['invite' => sprintf('%s already signs in to the Resident App for another home here, so it cannot be invited for %s. Use their own address.', $inUse[1], 'Refusal 1')]);
    FacilitiesFixture::boot();

    $this->actingAs($office)
        ->post(FacilitiesFixture::url('/residents/'.$this->unit->slug().'/people/'.$goodMember.'/invite'))
        ->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(ResidentInvites::outcome(accInvite($goodMember))['status'])->toBe('sent');

    // A day later — signed in afresh, as a phone idle for a day is — the primary's app invites go again.
    Carbon::setTestNow(now()->addDay()->addMinute());
    $this->token = $this->primaryAccount->createToken('resident-app:accept-install-2', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    $later = accCall($this, 'members.store', [], ['full_name' => 'Later Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => accEmail('later')]);
    expect($later->json('invite.status'))->toBe('sent');
});

it('does not limit another primary in the same estate for this primary\'s refusals (T-ACC-11)', function () {
    foreach (range(1, 5) as $i) {
        $address = accEmail('other-inuse'.$i);
        accActiveAccount($this, $this->primary->id, $address);
        accCall($this, 'members.store', [], ['full_name' => 'Other Refusal '.$i, 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $address]);
    }

    // Another household, its own primary.
    $unit = FacilitiesFixture::scratchUnit('Lot 8875');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Neighbour household', 'access_restricted' => false]);
    $neighbour = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->first();
    if ($neighbour === null) {
        $neighbour = new Resident;
        $neighbour->forceFill(['household_id' => $household->id, 'full_name' => 'Neighbour Primary', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => accEmail('neighbour'), 'full_name' => 'Neighbour Primary',
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(), 'resident_id' => $neighbour->id, 'unit_id' => $unit->id, 'device_uid' => 'neighbour-1', 'device_platform' => 'android',
    ]);
    $token = $account->createToken('resident-app:neighbour-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    try {
        $sent = accCall($this, 'members.store', [], ['full_name' => 'Neighbour Child', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => accEmail('neighbourchild')], token: $token);
        expect($sent->json('invite.status'))->toBe('sent');
    } finally {
        FacilitiesFixture::boot();
        $theirs = Resident::withRemoved()->where('household_id', $household->id)->pluck('id');
        DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $theirs)->delete();
        DB::connection('tenant')->table('residents')->whereIn('id', $theirs)->delete();
        DB::connection('tenant')->table('households')->where('id', $household->id)->delete();
    }
});

/* ------------------------------------------------------------------ */
/* T-ACC-12 · T-ACC-13 · T-ACC-14 */
/* ------------------------------------------------------------------ */

it('refuses to invite a new row that carries the name of somebody the office cut off here (T-ACC-12, G3)', function () {
    $namedEmail = accEmail('named');
    $x = (int) accCall($this, 'members.store', [], ['full_name' => 'Named Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $namedEmail, 'invite' => false])->json('id');
    $account = accActiveAccount($this, $x, $namedEmail);
    FacilitiesFixture::boot();
    accRun(fn () => app(RevokeResidentAccess::class)->revoke($account->fresh(), 'Not to be admitted.', 'Estate office'));

    // The cut-off person's name on a new row with another phone: a row of the same name and phone would be refused
    // outright since M54 ("Named Dev is already in your household"); this is the row G3 is about.
    $again = accCall($this, 'members.store', [], ['full_name' => 'Named Dev', 'relationship' => 'child', 'phone' => '876 555 0102', 'email' => accEmail('named-new')]);

    expect($again->json('invite.status'))->toBe('not_sent')
        ->and($again->json('invite.reason'))->toBe(InviteAddressRefused::GENERIC);
    FacilitiesFixture::boot();
    expect(accInvite((int) $again->json('id'))->refused_reason)->toBe('revoked_name')
        ->and(accAudit('resident.invite_refused', (string) $again->json('id'))?->after['reason'])->toBe('revoked_name');

    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $this->actingAs($office)
        ->post(FacilitiesFixture::url('/residents/'.$this->unit->slug().'/people/'.$again->json('id').'/invite'))
        ->assertRedirect()
        ->assertSessionHasErrors('invite');

    expect(session('errors')->first('invite'))
        ->toStartWith('App access for Named Dev at Lot 8873 was ended by the estate office on '.SiteTime::local(now(), FacilitiesFixture::ESTATE)->format('j M Y'));
});

it('keeps one live invite per address per household: the household cannot add the address twice, and beside the office\'s add the older is superseded (T-ACC-13, G4)', function () {
    $email = accEmail('twice');
    $first = accAddInvited($this, 'Twice Dev', $email);

    // Since M54 the household cannot add a second row for the same address: it is the same person, already here.
    $twice = accCall($this, 'members.store', [], ['full_name' => 'Twice Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email], 409);
    expect($twice->json('error.code'))->toBe('member_exists')
        ->and(ResidentInvites::outcome(accInvite($first))['status'])->toBe('sent');

    $signIn = accSignIn($this, $email)->assertOk();
    expect(UnitClaim::query()->findOrFail($signIn->json('claim.id'))->invite_id)->toBe(accInvite($first)->id)
        ->and(accAudit('resident.invite_ambiguous', (string) accAccount($email)->id))->toBeNull();

    // Where two live invites to one address can still meet, the older is superseded: the office's Add resident beside a
    // primary's add.
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $shared = accEmail('beside');
    $byPrimary = accAddInvited($this, 'Beside Dev', $shared);
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents'), [
        'phase' => $this->unit->block ?? 'Phase 9', 'lot' => 'Lot 8873', 'full_name' => 'Beside Dev', 'email' => $shared, 'phone' => '876 555 0100', 'verification' => 'invite',
        // Lot 8873 has its household already (M32 F1): the office adds this person as a member of it.
        'household_choice' => 'member',
    ])->assertRedirect();
    FacilitiesFixture::boot();

    $byOffice = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Beside Dev')->whereKeyNot($byPrimary)->firstOrFail();
    expect(ResidentInvites::outcome(accInvite($byPrimary))['status'])->toBe('cancelled')
        ->and(ResidentInvites::outcome(accInvite($byOffice->id))['status'])->toBe('sent');
});

it('records a refused send on the row: the app reads the generic words, the office the real reason (T-ACC-14, G7)', function () {
    $inUse = accEmail('recorded');
    accActiveAccount($this, $this->primary->id, $inUse);

    $added = accCall($this, 'members.store', [], ['full_name' => 'Recorded Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $inUse]);
    expect($added->status())->toBe(201)
        ->and($added->json('invite.status'))->toBe('not_sent')
        ->and($added->json('invite.reason'))->toBe(InviteAddressRefused::GENERIC)
        ->and($added->json('invite.expires_at'))->toBeNull()
        ->and($added->json('invite.attempts'))->toBe(0);

    // The office's Add resident with the same address: its message names the real reason.
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents'), [
        'phase' => $this->unit->block ?? 'Phase 9', 'lot' => 'Lot 8873', 'full_name' => 'Office Recorded', 'email' => $inUse, 'phone' => '876 555 0100', 'verification' => 'invite',
        'household_choice' => 'member',
    ])->assertRedirect(FacilitiesFixture::url('/residents/'.$this->unit->slug()))
        ->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'already signs in to the Resident App for another home'))
        ->assertSessionHas('invite_outcome', fn (array $o): bool => $o['state'] === 'not_sent' && str_contains($o['message'], 'already signs in'));
    FacilitiesFixture::boot();

    // The household page carries `office_reason`; the app's member list never does.
    $this->actingAs($office)->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))->assertOk()
        ->assertInertia(fn ($page) => $page->where('people', fn ($people): bool => str_contains(
            (string) (collect($people)->firstWhere('id', (int) $added->json('id'))['invite']['office_reason'] ?? ''),
            'already signs in to the Resident App for another home',
        )));
    FacilitiesFixture::boot();

    $list = accCall($this, 'members.index');
    expect(json_encode($list->json()))->not->toContain('office_reason')->not->toContain('refused_reason');
});

/* ------------------------------------------------------------------ */
/* T-ACC-15 · the office chooses the level */
/* ------------------------------------------------------------------ */

it('gives the level the office chooses on approval, and never to the primary (T-ACC-15, G8)', function () {
    config(['services.resident_app.access_levels' => true]);
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // An ordinary self-claim for this household under a name the register does not hold: a new entry.
    $claim = function (string $name): UnitClaim {
        $account = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => accEmail('self'), 'status' => ResidentAccount::PENDING]);

        return accRun(fn () => app(ResidentAccounts::class)->claim($account, ['full_name' => $name, 'lot' => 'Lot 8873'])['claim']);
    };

    $dependent = $claim('Level Dependent');
    expect($dependent->claim_type)->toBe(UnitClaim::TYPE_MEMBER);
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/claims/'.$dependent->id.'/approve'), ['access_level' => 'dependent'])->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    $entry = Resident::query()->findOrFail(UnitClaim::query()->findOrFail($dependent->id)->resolved_resident_id);
    expect($entry->access_level)->toBe('dependent')
        ->and(accAudit('household.member_level_changed', (string) $entry->id)?->after['via'])->toBe('claim')
        ->and(accAudit('unit_claim.approved', (string) $dependent->id)?->after['access_level'])->toBe('dependent');

    // No level: Member.
    $plain = $claim('Level Plain');
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/claims/'.$plain->id.'/approve'))->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Resident::query()->findOrFail(UnitClaim::query()->findOrFail($plain->id)->resolved_resident_id)->access_level)->toBe('member');

    // A claim that resolves to the primary: the level is ignored.
    $mine = $claim('Accept Andrea');
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/claims/'.$mine->id.'/approve'), ['access_level' => 'helper'])->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(UnitClaim::query()->findOrFail($mine->id)->resolved_resident_id)->toBe($this->primary->id)
        ->and(AccessLevels::of($this->primary->fresh()))->toBe('member');

    // Somebody who approves but may not change a person's access: the posted level is dropped.
    // The Treasurer reads Residents and holds no update; given approve alone, they approve and choose nothing.
    $approver = FacilitiesFixture::viewer(Role::TREASURER);
    $approver->givePermissionTo('estate.residents.approve');
    $approver = $approver->fresh();
    expect($approver->can('estate.residents.approve'))->toBeTrue()
        ->and($approver->can('estate.residents.update'))->toBeFalse();

    $dropped = $claim('Level Dropped');
    $this->actingAs($approver)->post(FacilitiesFixture::url('/residents/claims/'.$dropped->id.'/approve'), ['access_level' => 'helper'])->assertRedirect();
    FacilitiesFixture::boot();
    expect(Resident::query()->findOrFail(UnitClaim::query()->findOrFail($dropped->id)->resolved_resident_id)->access_level)->toBe('member');
});

/* ------------------------------------------------------------------ */
/* T-ACC-16 · no invite from a stale offline add */
/* ------------------------------------------------------------------ */

it('sends no invite for an add queued offline more than 15 minutes ago, and says so; a fresh add sends (T-ACC-16, G14)', function () {
    $stale = accCall($this, 'members.store', [], ['full_name' => 'Offline Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => accEmail('offline'), 'invite' => true], deviceTime: now()->subMinutes(20)->toIso8601String());

    expect($stale->status())->toBe(201)
        ->and($stale->json('invite.status'))->toBe('not_sent')
        ->and($stale->json('invite.reason'))->toBe('Saved on the phone while it was offline, so no invite went with it. Send it now.');
    FacilitiesFixture::boot();
    expect(accInvite((int) $stale->json('id'))->refused_reason)->toBe('stale_offline_add');
    Notification::assertSentOnDemandTimes(ResidentInviteSent::class, 0);

    $fresh = accCall($this, 'members.store', [], ['full_name' => 'Online Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => accEmail('online'), 'invite' => true], deviceTime: now()->subMinutes(2)->toIso8601String());
    expect($fresh->json('invite.status'))->toBe('sent');
    Notification::assertSentOnDemandTimes(ResidentInviteSent::class, 1);
});

/* ------------------------------------------------------------------ */
/* T-MAIL-1 */
/* ------------------------------------------------------------------ */

it('names the estate, the inviter, the address and the date in the invite, and never the home (T-MAIL-1)', function () {
    $email = accEmail('mail');
    accAddInvited($this, 'Mail Dev', $email);

    $estate = (string) Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->name;

    Notification::assertSentOnDemand(ResidentInviteSent::class, function (ResidentInviteSent $n, array $channels, object $notifiable) use ($email, $estate): bool {
        $mail = $n->toMail($notifiable);
        $text = implode(' ', array_map('strval', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]));

        return ($notifiable->routes['mail'] ?? null) === $email
            && str_contains($text, $estate)
            && str_contains($text, 'Accept Andrea')
            && str_contains($text, $email)
            && $n->lapsesOn !== '' && str_contains($text, 'lapses on '.$n->lapsesOn)
            && ! str_contains($text, 'Lot 8873')
            && ! str_contains($text, '8873');
    });
});
