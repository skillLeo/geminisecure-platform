<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Notifications\AccountDeletionNotice;
use App\Notifications\ResidentSignInCode;
use App\Services\Estate\DeletedAccounts;
use App\Services\Estate\Dues;
use App\Services\ResidentApp\AccountDeletion;
use App\Services\ResidentApp\DeleteResidentAccount;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Deleting an account: recoverable, not gone — M18H (and M5 §4, which it replaces)
|--------------------------------------------------------------------------
|
| Deleting an account and deleting the data are two different things. An account moves to `deleted` and keeps
| everything: sign-in, the e-pass, the visitor passes it sent, its future bookings and its notifications stop at once;
| its dues, the gate log, incidents, ballots and the audit trail never do. The person restores it by signing in for the
| estate's first window (7 days), the office for the second (30); after that nothing of the person is left on it. The
| Community Super Admin can make that happen sooner — with the name typed and a reason — and nobody else can.
|
| These are the order's six proofs (§8), in order.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: every lot here is this file's own, every person and pass it makes is
| taken away afterwards, and the estate's office address is put back as it was.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 977');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Delete household', 'access_restricted' => false]);

    // Where "the estate office is told" goes.
    $setting = EstateSetting::current();
    $this->officeWas = $setting->enquiries_email;
    $setting->forceFill(['enquiries_email' => 'office.delete-test@example.com'])->save();
});

afterEach(function () {
    Carbon::setTestNow();
    FacilitiesFixture::boot();

    $households = Household::query()->whereIn('unit_id', [$this->unit->id, ...($this->extraUnits ?? [])])->pluck('id');
    VisitorPass::query()->whereIn('household_id', $households)->delete();
    Resident::withRemoved()->whereIn('household_id', $households)->delete();
    EstateSetting::current()->forceFill(['enquiries_email' => $this->officeWas])->save();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/**
 * A signed-in, active Resident App account, and the register entry it is: the household's primary unless told otherwise.
 *
 * @return array{0: ResidentAccount, 1: string, 2: Resident}
 */
function signedInResident(string $email, Household $household, bool $primary = true, string $name = 'Pat Deleting'): array
{
    FacilitiesFixture::boot();

    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => $name, 'email' => $email, 'phone' => '+1 876 555 0977', 'relationship' => 'owner', 'is_primary' => $primary, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => $email,
        'full_name' => $name,
        'resident_id' => $person->id,
        'unit_id' => $household->unit_id,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
        'device_uid' => 'delete-install-1',
        'device_platform' => 'android',
    ]);

    return [$account, $account->createToken('resident-app:delete-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken, $person];
}

/**
 * @param  array<string, mixed>  $body
 */
function asAccount(TestCase $test, ?string $token, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = $entry['write'] ? ['Idempotency-Key' => 'del-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $client = $token === null ? $test : $test->withToken($token);

    $response = $client->json($entry['method'], '/api/v1/'.$entry['uri'], $body, $headers);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** Ask for a sign-in code as the app does, and read it where it is issued. */
function deletionCodeFor(TestCase $test, string $email): string
{
    Notification::fake();
    $test->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email])->assertStatus(202);

    $code = null;
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$code): bool {
        $code = $n->code;

        return true;
    });
    FacilitiesFixture::boot();

    return (string) $code;
}

/** Sign in with a fresh code, on this install. */
function deletionSignIn(TestCase $test, string $email, int $status, string $device = 'delete-install-2'): TestResponse
{
    $code = deletionCodeFor($test, $email);
    app('auth')->forgetGuards();
    $response = $test->postJson('/api/v1/auth/otp/verify', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'code' => $code, 'device_uid' => $device, 'platform' => 'android']);
    $response->assertStatus($status);
    ApiContract::assertMatches($response, 'auth.otp.verify');
    FacilitiesFixture::boot();

    return $response;
}

/** A pass the person sent, still to be used. */
function deletionPass(Resident $person, Household $household): VisitorPass
{
    FacilitiesFixture::boot();

    return VisitorPass::query()->create([
        'pass_id' => (string) Str::uuid(),
        'unit_id' => $household->unit_id,
        'household_id' => $household->id,
        'issued_by_resident_id' => $person->id,
        'issued_by_name' => $person->full_name,
        'category' => 'single',
        'visitor_name' => 'Marcia James',
        'visitor_phone' => '876-555-0123',
        'purpose' => 'Family visit',
        'valid_from' => Carbon::now()->subHour(),
        'valid_to' => Carbon::now()->addDays(3),
        'single_use' => true,
        'nonce' => bin2hex(random_bytes(16)),
        'key_version' => 1,
        'token' => 'delete-test-'.bin2hex(random_bytes(6)),
        'code' => 'DELT-'.random_int(1000, 9999),
        'status' => VisitorPass::ACTIVE,
    ]);
}

/**
 * The Deleted accounts tab's board, read the way the console reads it: inside the estate.
 *
 * @return array<string, mixed>
 */
function deletedBoard(bool $maySeeMoney = false, string $search = '', bool $onlyExpiring = false): array
{
    tenancy()->end();
    FacilitiesFixture::boot();

    $board = tenancy()->find(FacilitiesFixture::ESTATE)->run(fn (): array => app(DeletedAccounts::class)->board(FacilitiesFixture::ESTATE, $search, $onlyExpiring, maySeeMoney: $maySeeMoney));
    FacilitiesFixture::boot();

    return $board;
}

/** The estate-calendar date, n days after an instant. */
function deletionDay(Carbon $at, int $days): string
{
    return $at->copy()->addDays($days)->setTimezone(SiteTime::zone(FacilitiesFixture::ESTATE))->toDateString();
}

it('1 · deleting stops what must stop, keeps everything else, emails the person and the office, and lists the row (M18H §1.3, §1.4, §2.2, §3)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token, $person] = signedInResident($email, $this->household);
    $pass = deletionPass($person, $this->household);

    // Somewhere to be pushed to, and a sign-in code in flight — both must be gone afterwards.
    DB::connection('mysql')->table('device_push_tokens')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => 'resident', 'principal_id' => $account->id,
        'app' => 'resident', 'expo_token' => 'ExponentPushToken[delete-'.bin2hex(random_bytes(4)).']', 'platform' => 'android',
        'last_registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('mysql')->table('resident_otps')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email',
        'destination_hash' => hash_hmac('sha256', $email, (string) config('app.key')),
        'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // A mis-tap cannot do this: the app must send back the word it showed.
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'yes'], 422);
    expect($account->refresh()->deleted_at)->toBeNull('a wrong confirmation must change nothing');

    $at = Carbon::now();
    $answer = asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);
    expect($answer->json('deleted'))->toBeTrue()
        ->and($answer->json('reference'))->toMatch('/^GS-[A-Z2-9]{6}$/')
        ->and($answer->json('restore_until'))->toBe(deletionDay($at, 7))
        ->and($answer->json('permanent_on'))->toBe(deletionDay($at, 30));

    $account->refresh();

    // A STATE: nothing of the person is erased on the day.
    expect($account->status)->toBe(ResidentAccount::DELETED)
        ->and($account->isDeleted())->toBeTrue()
        ->and($account->destination)->toBe($email)
        ->and($account->full_name)->toBe('Pat Deleting')
        ->and($account->resident_id)->toBe($person->id);

    // Every phone loses access at once; no push address and no code survives.
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    expect(DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $account->id)->where('tokenable_type', ResidentAccount::class)->count())->toBe(0)
        ->and(DB::connection('mysql')->table('resident_otps')->where('destination_hash', hash_hmac('sha256', $email, (string) config('app.key')))->count())->toBe(0)
        ->and(DB::connection('mysql')->table('device_push_tokens')->where('principal_type', 'resident')->where('principal_id', $account->id)->count())->toBe(0);

    // Off the ACTIVE register — removed, not erased — and the pass they sent refused at every gate.
    FacilitiesFixture::boot();
    expect(Resident::query()->find($person->id))->toBeNull()
        ->and(Resident::withRemoved()->find($person->id)->full_name)->toBe('Pat Deleting')
        ->and(Resident::withRemoved()->find($person->id)->removed_at)->not->toBeNull()
        ->and(VisitorPass::query()->find($pass->id)->status)->not->toBe(VisitorPass::ACTIVE)
        ->and($this->household->refresh()->unit_id)->toBe($this->unit->id);

    // §2.2: the person is emailed at once, with the day to sign in by; §4.1: the office is told, and that it was the primary.
    Notification::assertSentOnDemand(AccountDeletionNotice::class, fn (AccountDeletionNotice $n, array $channels, object $to): bool => $n->kind === AccountDeletionNotice::DELETED
        && $to->routes['mail'] === $email
        && $n->facts['self_until'] === AccountDeletion::dayWords(deletionDay($at, 7)));
    Notification::assertSentOnDemand(AccountDeletionNotice::class, fn (AccountDeletionNotice $n, array $channels, object $to): bool => $n->kind === AccountDeletionNotice::DELETED_OFFICE
        && $to->routes['mail'] === 'office.delete-test@example.com'
        && ($n->facts['was_primary'] ?? false) === true
        && ($n->facts['passes'] ?? 0) >= 1);

    // §3: the Deleted accounts tab has the row, with its days left.
    $row = collect(deletedBoard()['rows'])->firstWhere('id', $account->id);
    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Pat Deleting')
        ->and($row['unit'])->toBe('Lot 977')
        ->and($row['days_left'])->toBe(30)
        ->and($row['deleted_by'])->toBe('The resident, in the app')
        ->and($row['can_self_restore'])->toBeTrue();

    // M32 F4: the tab's search finds them by phone however it is typed and by their household, as the register does.
    foreach (['876 555 0977', '+18765550977', 'DELETE HOUSEHOLD', 'Lot 977', $email] as $query) {
        expect(collect(deletedBoard(search: $query)['rows'])->pluck('id')->all())->toContain($account->id);
    }
    expect(collect(deletedBoard(search: '876 555 0000')['rows'])->pluck('id')->all())->not->toContain($account->id);

    // The audit log outlives the account, so it must not carry the person.
    $entry = AuditEntry::query()->where('action', 'resident_account.deleted')->latest('id')->firstOrFail();
    expect(json_encode($entry->toArray()))->not->toContain($email)->not->toContain('Pat Deleting');
});

it('2 · signing in on day 2 offers the restore, and "Restore my account" puts everything back and tells the office (M18H §2.3)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token, $person] = signedInResident($email, $this->household);
    $pass = deletionPass($person, $this->household);
    $deletedOn = Carbon::now();
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    $refused = deletionSignIn($this, $email, 409);
    expect($refused->json('error.code'))->toBe('account_deleted')
        ->and($refused->json('error.message'))->toBe('This account was deleted on '.AccountDeletion::dayWords(deletionDay($deletedOn, 0), false).'. You can restore it until '.AccountDeletion::dayWords(deletionDay($deletedOn, 7), false).' and nothing will be lost.')
        ->and($refused->json('error.details.can_restore'))->toBeTrue()
        ->and($refused->json('error.details.restore_until'))->toBe(deletionDay($deletedOn, 7))
        ->and($refused->json('error.details.office_email'))->toBe('office.delete-test@example.com')
        ->and($refused->json('error.details.ticket'))->toBeString()
        ->and($refused->json('token'))->toBeNull('no token until they choose: the account stays deleted');
    expect($account->refresh()->status)->toBe(ResidentAccount::DELETED);

    $ticket = (string) $refused->json('error.details.ticket');

    // The ticket is this phone's, and nobody else's.
    asAccount($this, null, 'auth.restore', ['estate' => FacilitiesFixture::ESTATE, 'ticket' => $ticket, 'device_uid' => 'somebody-else', 'platform' => 'android'], 422);

    $back = asAccount($this, null, 'auth.restore', ['estate' => FacilitiesFixture::ESTATE, 'ticket' => $ticket, 'device_uid' => 'delete-install-2', 'platform' => 'android']);
    expect($back->json('restored'))->toBeTrue()
        ->and($back->json('next'))->toBe('home')
        ->and($back->json('account.id'))->toBe($account->id)
        ->and($back->json('account.status'))->toBe(ResidentAccount::ACTIVE);

    // Unit, household, register entry: all back. The pass the deletion stopped stays stopped — the screen says so.
    $account->refresh();
    FacilitiesFixture::boot();
    expect($account->resident_id)->toBe($person->id)
        ->and($account->unit_id)->toBe($this->unit->id)
        ->and($account->deleted_at)->toBeNull()
        ->and(Resident::query()->find($person->id))->not->toBeNull()
        ->and(VisitorPass::query()->find($pass->id)->status)->not->toBe(VisitorPass::ACTIVE);

    // The token works: the home is theirs again.
    asAccount($this, (string) $back->json('token'), 'me.show');

    // A ticket is spent by the restore.
    asAccount($this, null, 'auth.restore', ['estate' => FacilitiesFixture::ESTATE, 'ticket' => $ticket, 'device_uid' => 'delete-install-2', 'platform' => 'android'], 422);

    Notification::assertSentOnDemand(AccountDeletionNotice::class, fn (AccountDeletionNotice $n, array $channels, object $to): bool => $n->kind === AccountDeletionNotice::RESTORED_OFFICE
        && $to->routes['mail'] === 'office.delete-test@example.com');
});

it('3 · on day 10 only the office can restore it, and the office does (M18H §2.4, §3)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token] = signedInResident($email, $this->household);
    $deletedOn = Carbon::now();
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);

    Carbon::setTestNow(Carbon::now()->addDays(10));

    $refused = deletionSignIn($this, $email, 409);
    expect($refused->json('error.message'))->toBe('This account was deleted on '.AccountDeletion::dayWords(deletionDay($deletedOn, 0), false).'. Only the estate office can restore it now. Contact them and they can bring it back until '.AccountDeletion::dayWords(deletionDay($deletedOn, 30), false).'.')
        ->and($refused->json('error.details.can_restore'))->toBeFalse()
        ->and($refused->json('error.details.ticket'))->toBeNull();

    // The office's tab says the same, and restores it.
    $office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->actingAs($office)->get(FacilitiesFixture::url('/residents/deleted-accounts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Residents/DeletedAccounts')
            ->where('rows', fn ($rows): bool => collect($rows)->contains(fn (array $r): bool => $r['id'] === $account->id && $r['days_left'] === 20 && $r['can_self_restore'] === false && $r['can_restore'] === true)));

    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/restore'))->assertRedirect();
    FacilitiesFixture::boot();

    expect($account->refresh()->status)->toBe(ResidentAccount::ACTIVE)
        ->and($account->restored_by)->toBe($office->name);

    Notification::assertSentOnDemand(AccountDeletionNotice::class, fn (AccountDeletionNotice $n, array $channels, object $to): bool => $n->kind === AccountDeletionNotice::RESTORED && $to->routes['mail'] === $email);

    // Signing in again is an ordinary sign-in.
    expect(deletionSignIn($this, $email, 200)->json('account.id'))->toBe($account->id);
});

it('4 · deleting permanently is the Community Super Admin\'s, with the name typed and a reason — and leaves no name, drops no row (M18H §5)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token, $person] = signedInResident($email, $this->household);
    $pass = deletionPass($person, $this->household);
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);
    $url = FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/delete-permanently');

    // The Secretary holds Residents update and can restore — not this.
    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))->post($url, ['confirm_name' => 'Pat Deleting', 'reason' => 'They asked for everything to be erased.'])->assertForbidden();

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->actingAs($admin)->post($url, ['confirm_name' => 'Pat Deletin', 'reason' => 'They asked for everything to be erased.'])->assertSessionHasErrors('confirm_name');
    $this->actingAs($admin)->post($url, ['confirm_name' => 'Pat Deleting', 'reason' => 'Asked'])->assertSessionHasErrors('reason');
    FacilitiesFixture::boot();
    expect($account->refresh()->anonymised_at)->toBeNull('a refused confirmation changes nothing');

    $this->actingAs($admin)->post($url, ['confirm_name' => '  pat deleting ', 'reason' => 'They asked for everything to be erased.'])->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    // The name is gone everywhere it was: the account and the register entry.
    $account->refresh();
    $entry = Resident::withRemoved()->find($person->id);
    expect($account->anonymised_at)->not->toBeNull()
        ->and($account->full_name)->toBeNull()
        ->and($account->destination)->toBe('deleted.'.$account->id.'@account.invalid')
        ->and($account->device_uid)->toBeNull()
        ->and($entry)->not->toBeNull('the row stays: the gate log and the ledger point at it')
        ->and($entry->full_name)->toBe(AccountDeletion::REMOVED_NAME.' · '.$account->deleted_reference)
        ->and($entry->email)->toBeNull()
        ->and($entry->phone)->toBeNull()
        // The pass row — what the gate log points at — is still there, with its times.
        ->and(VisitorPass::query()->find($pass->id))->not->toBeNull();

    // Nothing left to list, and nothing to restore.
    expect(collect(deletedBoard()['rows'])->firstWhere('id', $account->id))->toBeNull();
    $this->actingAs($admin)->post(FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/restore'))->assertNotFound();

    // The address is nobody's now: signing in with it is a stranger's first sign-in (§2.5).
    FacilitiesFixture::boot();
    expect(deletionSignIn($this, $email, 200)->json('account.id'))->not->toBe($account->id);

    // Who, when, why — and no name.
    $audit = AuditEntry::query()->where('action', 'resident_account.deleted_permanently')->where('entity_id', (string) $account->id)->firstOrFail();
    expect(json_encode($audit->after))->toContain('They asked for everything to be erased.')
        ->and(json_encode($audit->toArray()))->not->toContain('Pat Deleting')->not->toContain($email);
});

it('4b · after the office\'s window the nightly run anonymises it, and signing in no longer offers anything (M18H §1.2, §2.5)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token] = signedInResident($email, $this->household);
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);

    Carbon::setTestNow(Carbon::now()->addDays(29));
    expect(app(AccountDeletion::class)->anonymiseExpired())->toBe(0);

    Carbon::setTestNow(Carbon::now()->addDays(2));
    $this->artisan('accounts:anonymise-expired')->assertSuccessful();
    FacilitiesFixture::boot();

    expect($account->refresh()->anonymised_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'resident_account.anonymised')->where('entity_id', (string) $account->id)->exists())->toBeTrue();

    expect(deletionSignIn($this, $email, 200)->json('account.id'))->not->toBe($account->id);
});

it('5 · a primary resident who owes stays on the arrears report, and the household is listed for a new primary (M18H §3, §4.1)', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9781', 'Fx Deletion Debt');
    $this->extraUnits = [$unit->id];
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Owing household', 'access_restricted' => false]);

    // This lot is this file's own: the ledger is append-only, so its debt stays with it (FacilitiesFixture::scratchUnit).
    app(Dues::class)->charge($unit, Money::of('3400.00', 'JMD'), 'Maintenance fee — deletion test', Carbon::now()->subDays(40)->toDateString());
    $owed = app(Dues::class)->unitBalances(null, [$unit->id])[$unit->id] ?? 0;
    expect($owed)->toBeGreaterThan(0);

    $email = 'delete.owing.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token] = signedInResident($email, $household, true, 'Owen Owing');
    [, , $member] = signedInResident('delete.member.'.bin2hex(random_bytes(3)).'@example.com', $household, false, 'Mia Member');

    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);
    FacilitiesFixture::boot();

    // The debt did not go with the account.
    expect(app(Dues::class)->unitBalances(null, [$unit->id])[$unit->id] ?? 0)->toBe($owed)
        ->and(collect(app(Dues::class)->arrearsBoard()['rows'])->pluck('id')->all())->toContain($unit->id);

    // The member is untouched (§4.2); the household is on the exceptions list until somebody is made primary.
    $board = deletedBoard(maySeeMoney: true);
    expect(Resident::query()->find($member->id))->not->toBeNull()
        ->and(collect($board['orphaned'])->pluck('household_id')->all())->toContain((int) $household->id);

    // And the row says what the lot owes.
    $row = collect($board['rows'])->firstWhere('id', $account->id);
    expect($row['balance']['owes'])->toBeTrue()
        ->and($row['balance']['minor'])->toBe($owed)
        ->and($row['was_primary'])->toBeTrue();
});

it('6 · every one of these acts is in the audit log', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token] = signedInResident($email, $this->household);
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);

    $office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/restore'))->assertRedirect();
    FacilitiesFixture::boot();

    // Deleted again, and this time the office makes it permanent.
    $again = $account->refresh()->createToken('resident-app:delete-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    asAccount($this, $again, 'me.delete_account', ['confirm' => 'DELETE']);
    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/delete-permanently'), ['confirm_name' => 'Pat Deleting', 'reason' => 'The resident asked for erasure in writing.'])->assertRedirect();
    FacilitiesFixture::boot();

    $actions = AuditEntry::query()->where('entity_type', 'ResidentAccount')->where('entity_id', (string) $account->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['resident_account.deleted', 'resident_account.restored', 'resident_account.deleted', 'resident_account.deleted_permanently']);
});

it('answers a second deletion of the same account with the first one\'s reference and time, not an error', function () {
    [$account, $token] = signedInResident('delete.'.bin2hex(random_bytes(3)).'@example.com', $this->household);

    $first = asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);
    $again = app(DeleteResidentAccount::class)->delete($account->refresh());

    expect($account->deleted_at)->toBeInstanceOf(Carbon::class)
        ->and($again['reference'])->toBe($first->json('reference'))
        ->and(Carbon::parse($again['deleted_at'])->equalTo(Carbon::parse((string) $first->json('deleted_at'))))->toBeTrue();
});

it('says a refused restore where the Restore dialog shows it, never as a flash that closes the dialog (Hassam, 12 Oct)', function () {
    $email = 'delete.'.bin2hex(random_bytes(3)).'@example.com';
    [$account, $token] = signedInResident($email, $this->household);
    asAccount($this, $token, 'me.delete_account', ['confirm' => 'DELETE']);

    $office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $url = FacilitiesFixture::url('/residents/deleted-accounts/'.$account->id.'/restore');

    // Restored by somebody else a moment ago: the second press is refused in words, not a page that is not there.
    $this->actingAs($office)->post($url)->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    $this->actingAs($office)->post($url)->assertRedirect()
        ->assertSessionHasErrors(['restore' => 'This account is not deleted, so there is nothing to restore.'])
        ->assertSessionMissing('error');
    FacilitiesFixture::boot();

    // Deleted again, and the office's window gone by: refused in the same place.
    $again = $account->refresh()->createToken('resident-app:delete-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    asAccount($this, $again, 'me.delete_account', ['confirm' => 'DELETE']);
    Carbon::setTestNow(Carbon::now()->addDays(31));

    $this->actingAs($office)->post($url)->assertRedirect()->assertSessionHasErrors('restore')->assertSessionMissing('error');
    FacilitiesFixture::boot();
    expect($account->refresh()->isDeleted())->toBeTrue('a refused restore changes nothing');
});

it('counts an account as expiring only while it can still be restored, and the filter lists only those (the hub\'s finding, 12 Oct)', function () {
    $office = AccountDeletion::windowsFor(FacilitiesFixture::ESTATE)['office'];

    // Ada's office window has run out; Ben, deleted five days after her, has five days left of his.
    [$ada, $adaToken] = signedInResident('delete.ada.'.bin2hex(random_bytes(3)).'@example.com', $this->household, true, 'Ada Expired');
    asAccount($this, $adaToken, 'me.delete_account', ['confirm' => 'DELETE']);

    Carbon::setTestNow(Carbon::now()->addDays(5));
    [$ben, $benToken] = signedInResident('delete.ben.'.bin2hex(random_bytes(3)).'@example.com', $this->household, false, 'Ben Expiring');
    asAccount($this, $benToken, 'me.delete_account', ['confirm' => 'DELETE']);

    Carbon::setTestNow(Carbon::now()->addDays($office - 5)->addHour());

    $board = deletedBoard();
    $rows = collect($board['rows'])->keyBy('id');

    expect($rows[$ada->id]['can_restore'])->toBeFalse('the premise: Ada can no longer be restored')
        ->and($rows[$ben->id]['can_restore'])->toBeTrue()
        ->and($rows[$ben->id]['days_left'])->toBe(5);

    // "Expiring within 7 days", pressed: Ben, never Ada, whose time has already run out.
    $filtered = collect(deletedBoard(onlyExpiring: true)['rows'])->pluck('id')->all();

    expect(in_array($ada->id, $filtered, true))->toBeFalse('an account past its window is listed as expiring')
        ->and(in_array($ben->id, $filtered, true))->toBeTrue()
        // The count above the list is the list the filter shows, and it holds no account that cannot come back.
        ->and($board['expiring'])->toBe(count($filtered))
        ->and(collect($board['rows'])->filter(static fn (array $r): bool => ! $r['can_restore'])->pluck('id')->intersect($filtered)->all())->toBe([]);
});

it('is a Resident App endpoint only — a guard can never delete somebody\'s account', function () {
    // routes/api.php registers the ability and the device middleware FROM this entry, so the catalogue is the gate.
    expect(Catalogue::endpoints()['me.delete_account']['apps'])->not->toContain('guard')
        ->and(Catalogue::endpoints()['me.delete_account']['write'])->toBeTrue()
        ->and(Catalogue::endpoints()['me.delete_account']['offline'])->toContain('never queued')
        ->and(Catalogue::endpoints()['auth.restore']['apps'])->toBe([]);
});
