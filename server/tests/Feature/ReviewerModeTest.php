<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\Tenant;
use App\Notifications\ResidentSignInCode;
use App\Services\Devices\DeviceEnrolment;
use App\Services\ResidentApp\AccountDeletion;
use App\Services\Passes\VisitorPasses;
use App\Services\Reviewer\ReviewerMode;
use App\Support\SessionWindow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| REVIEWER MODE — M5 §3
|--------------------------------------------------------------------------
|
| Two apps that are login-walled with no public sign-up are rejected under
| Guideline 2.1 unless an App Store or Play reviewer can actually use them. So
| ONE account per app carries a `reviewer` flag on the SERVER, and that flag —
| and nothing else, and no build flag — opens four gates: a static sign-in code,
| device binding, the geofence, and the biometric check.
|
| This file proves two things, and the second is the one that matters.
|
|   1. Each bypass works for a flagged account and is IMPOSSIBLE for one without
|      the flag. Every use is written to the audit log.
|
|   2. TENANT ISOLATION IS NOT RELAXED FOR IT. The reviewer's tokens are walked
|      across the guard and resident endpoints against a REAL estate's own row
|      ids, and every one answers exactly as an id nobody has. Invariant 1 holds
|      for the demonstration estate exactly as for any other.
|
*/

/** The demonstration estate this file provisions: a tenant like any other. */
const REVIEW_ESTATE = 'reviewdemo';

/**
 * The demonstration estate, built once per process.
 *
 * Its central row is committed rather than rolled back — tenancy resolves a tenant from the
 * central database on every request, so a row inside this file's transaction would vanish the
 * moment the first request ended. The tenant's own database is built by the REAL tenant
 * migrations, for the reason `FacilitiesFixture` gives: a hand-built schema proves nothing
 * about the one an estate actually runs.
 */
function reviewEstate(): Tenant
{
    static $built = false;

    if (! $built) {
        $database = config('tenancy.database.prefix').REVIEW_ESTATE;
        $owner = DB::connection('mysql_owner');

        $owner->statement('DROP DATABASE IF EXISTS `'.$database.'`');
        $owner->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::connection('mysql')->table('domains')->where('tenant_id', REVIEW_ESTATE)->delete();
        DB::connection('mysql')->table('tenants')->where('id', REVIEW_ESTATE)->delete();

        /*
         * Written raw, without `EstateProvisioner`, for the reason `EstateSettingsTest`
         * gives: provisioning creates a dedicated MySQL user per estate, and a suite that
         * rebuilds its estate on every run must not accumulate one per run. With no
         * `db_username` on the row, stancl falls through to the template connection.
         */
        DB::connection('mysql')->table('tenants')->insert([
            'id' => REVIEW_ESTATE,
            'name' => 'Reviewer Demo Estate (demonstration data)',
            'receipt_prefix' => 'RVW',
            'parish' => 'St. Ann',
            'status' => 'active',
            'demo' => true,
            'provisioned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('domains')->insert([
            'domain' => REVIEW_ESTATE, 'tenant_id' => REVIEW_ESTATE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('tenants:migrate', ['--tenants' => [REVIEW_ESTATE]]);

        /*
         * THE DEMONSTRATION ESTATE'S ROW IDS ARE REBASED HIGH, and §5 below does not work
         * without it.
         *
         * Row ids are per DATABASE. Left alone, the demonstration estate's first household is
         * id 1 — and so is a real estate's. Probing the reviewer's token with the real
         * estate's household 1 would then prove nothing at all: the reviewer's OWN household
         * really is 1 in its own database, so a 200 is the correct answer and the isolation
         * assertion is meaningless. Rebasing this estate's sequences to 5000 makes every id
         * it holds one no real estate here has, so a probe with a real id is unambiguous.
         *
         * Run as the schema OWNER, against this file's own database, which it drops and
         * rebuilds on every process: an estate's own MySQL user has no ALTER, and none is
         * being given one.
         */
        foreach (['units', 'households', 'residents', 'notices', 'meetings', 'charges', 'payments', 'visitor_passes', 'gate_approval_requests', 'maintenance_tickets', 'amenity_bookings', 'ballots'] as $table) {
            try {
                $owner->statement('ALTER TABLE `'.$database.'`.`'.$table.'` AUTO_INCREMENT = 5000');
            } catch (Throwable) {
                // A table this platform's tenant schema does not have is nothing to rebase.
            }
        }

        Tenant::findOrFail(REVIEW_ESTATE)->run(function (): void {
            $unit = Unit::query()->firstOrCreate(
                ['reference' => 'Demo Lot 1'],
                ['block' => 'Phase 1', 'street' => 'Demonstration Way', 'type' => 'residential', 'status' => 'occupied'],
            );

            $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Demonstration household', 'access_restricted' => false]);

            Resident::query()->firstOrCreate(
                ['household_id' => $household->id, 'email' => 'demo.resident@reviewdemo.test'],
                ['full_name' => 'Demonstration Resident', 'relationship' => 'owner', 'is_primary' => true],
            );

            Notice::query()->firstOrCreate(
                ['title' => 'Demonstration notice'],
                ['kind' => Notice::GENERAL, 'body' => 'Nobody lives here.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'Demo Secretary', 'published_at' => now()->subDay()],
            );
        });

        FacilitiesFixture::boot();

        $built = true;
    }

    return Tenant::findOrFail(REVIEW_ESTATE);
}

/** An Ed25519 public key in the form `devices.enrol` takes. */
function reviewKey(): string
{
    return sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

/**
 * Call a catalogue endpoint by name, the way the contract walks do.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function reviewCall(TestCase $test, ?string $token, string $name, array $params = [], array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'rev-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $client = $token === null ? $test : $test->withToken($token);

    $response = $entry['method'] === 'GET'
        ? $client->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $client->json($entry['method'], $uri, $body, $headers);

    ApiContract::assertMatches($response, $name);

    FacilitiesFixture::boot();

    return $response;
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // Committed, and before the transaction: tenancy reads the tenant row on every request.
    $this->demo = reviewEstate();

    DB::connection('mysql')->beginTransaction();

    /*
     * THE ONLY DEMONSTRATION COMMUNITY IN THIS FILE IS THIS FILE'S OWN.
     *
     * `demo:seed` builds a real one on any platform it is run against, and this suite shares
     * its database with the developer's. Without this the two would be indistinguishable:
     * `demo.enter` would answer whichever came first, and "there is no demonstration community"
     * could never be asserted at all. Inside the transaction, so it is taken back in `afterEach`
     * and nobody's estate is left altered.
     */
    DB::connection('mysql')->table('tenants')->where('id', '!=', REVIEW_ESTATE)->where('demo', true)->update(['demo' => false]);

    $this->withoutVite();
    Notification::fake();

    $this->post = Post::query()->firstOrCreate(
        ['tenant_id' => REVIEW_ESTATE, 'name' => 'Demo Gate'],
        ['type' => 'gate', 'is_active' => true],
    );

    // SURVEYED. The reviewer's geofence is satisfied from these coordinates; a post with none
    // would leave the bypass with nothing to stand at.
    $this->post->forceFill(['latitude' => 18.4762, 'longitude' => -77.9194, 'geofence_radius_m' => 60, 'is_active' => true])->save();

    $this->reviewer = Guard::create([
        'full_name' => 'Review Officer (demonstration account)', 'employee_number' => 'GS-RVW-1', 'psra_number' => 'PSRA-RVW-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => REVIEW_ESTATE, 'post_id' => $this->post->id,
    ]);
    $this->reviewer->forceFill(['reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::GUARD_CODE)])->save();

    // The same estate, the same post, the same static code in its column — and NO flag.
    $this->ordinary = Guard::create([
        'full_name' => 'Ordinary Officer', 'employee_number' => 'GS-RVW-2', 'psra_number' => 'PSRA-RVW-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => REVIEW_ESTATE, 'post_id' => $this->post->id,
    ]);
    $this->ordinary->forceFill(['reviewer' => false, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::GUARD_CODE)])->save();

    $this->shift = Shift::create([
        'tenant_id' => REVIEW_ESTATE, 'guard_id' => $this->reviewer->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => Shift::ROSTERED,
    ]);

    $this->ordinaryShift = Shift::create([
        'tenant_id' => REVIEW_ESTATE, 'guard_id' => $this->ordinary->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => Shift::ROSTERED,
    ]);

    /** @var array{unit: int, resident: int, household: int} $home */
    $home = $this->demo->run(function (): array {
        $unit = Unit::query()->where('reference', 'Demo Lot 1')->firstOrFail();
        $household = Household::query()->where('unit_id', $unit->id)->firstOrFail();
        $resident = Resident::query()->where('household_id', $household->id)->firstOrFail();

        return ['unit' => (int) $unit->id, 'resident' => (int) $resident->id, 'household' => (int) $household->id];
    });

    FacilitiesFixture::boot();

    $this->home = $home;

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'full_name' => 'Review Resident (demonstration account)', 'status' => ResidentAccount::ACTIVE,
        'resident_id' => $home['resident'], 'unit_id' => $home['unit'], 'verified_at' => now(),
    ]);
    $this->account->forceFill(['reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::RESIDENT_CODE)])->save();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 1. The static sign-in code, and device binding
 ───────────────────────────────────────────────────────────────────────── */

it('enrols a reviewer\'s handset on a static code that never expires, and refuses the same code to a guard without the flag', function () {
    // No row in `device_enrolment_codes` exists for this code, and none is ever written:
    // the reviewer's code is static and is not spent by being used.
    expect(DB::connection('mysql')->table('device_enrolment_codes')->where('guard_id', $this->reviewer->id)->count())->toBe(0);

    $first = reviewCall($this, null, 'devices.enrol', [], [
        'enrolment_code' => ReviewerMode::GUARD_CODE, 'device_uid' => 'reviewer-phone-1', 'platform' => 'ios', 'public_key' => reviewKey(),
    ]);

    $first->assertCreated()->assertJsonPath('status', 'enrolled')->assertJsonPath('reviewer', true);
    expect($first->json('abilities'))->toBe(AppMatrix::abilitiesFor(AppMatrix::GUARD))
        ->and($first->json('site.id'))->toBe(REVIEW_ESTATE)
        ->and($this->reviewer->refresh()->device_uid)->toBe('reviewer-phone-1');

    /*
     * DEVICE BINDING AUTO-ENROLS. A guard who already has a handset normally gets a rebind a
     * supervisor must approve — a person a reviewer cannot telephone. A SECOND, different
     * handset presenting the reviewer's code is bound at once instead.
     */
    $second = reviewCall($this, null, 'devices.enrol', [], [
        'enrolment_code' => ReviewerMode::GUARD_CODE, 'device_uid' => 'reviewer-phone-2', 'platform' => 'android', 'public_key' => reviewKey(),
    ]);

    $second->assertCreated()->assertJsonPath('status', 'enrolled');
    expect($this->reviewer->refresh()->device_uid)->toBe('reviewer-phone-2');

    // …and the code still works a year from now: nothing about it expires.
    $this->travel(400)->days();
    reviewCall($this, null, 'devices.enrol', [], [
        'enrolment_code' => ReviewerMode::GUARD_CODE, 'device_uid' => 'reviewer-phone-3', 'platform' => 'ios', 'public_key' => reviewKey(),
    ])->assertCreated();
    $this->travelBack();

    /* ── IMPOSSIBLE WITHOUT THE FLAG ───────────────────────────────── */

    // The ordinary guard holds the same hash in its column. Clear the reviewer's flag and the
    // code is nothing to anybody: the hash alone never grants the bypass.
    $this->reviewer->forceFill(['reviewer' => false])->save();

    reviewCall($this, null, 'devices.enrol', [], [
        'enrolment_code' => ReviewerMode::GUARD_CODE, 'device_uid' => 'somebody-elses-phone', 'platform' => 'ios', 'public_key' => reviewKey(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'enrolment_code_invalid');

    expect($this->ordinary->refresh()->device_uid)->toBeNull();

    /* ── AND EVERY USE IS IN THE AUDIT LOG ─────────────────────────── */

    $entries = AuditEntry::query()->where('entity_type', 'Guard')->where('entity_id', (string) $this->reviewer->id)->pluck('action');

    expect($entries)->toContain('reviewer.'.ReviewerMode::STATIC_CODE)
        ->and($entries)->toContain('reviewer.'.ReviewerMode::DEVICE_BINDING)
        // EACH TIME, not the first: three enrolments, three of each.
        ->and($entries->filter(fn (string $a): bool => $a === 'reviewer.'.ReviewerMode::DEVICE_BINDING)->count())->toBe(3);
});

/* ─────────────────────────────────────────────────────────────────────────
 | 2. The geofence, and the passcode identity path
 ───────────────────────────────────────────────────────────────────────── */

it('satisfies a reviewer\'s geofence from the site, and judges everybody else by their handset', function () {
    $token = app(DeviceEnrolment::class)->enrol($this->reviewer, 'Review handset')['token'];

    // NO COORDINATES AT ALL — a reviewer thousands of miles away who has not even granted
    // location. The check is stood at the post's own surveyed position instead.
    $pre = reviewCall($this, $token, 'shifts.preflight', ['shift' => $this->shift->id], []);

    $pre->assertOk()
        ->assertJsonPath('within_geofence', true)
        ->assertJsonPath('allowed', true)
        ->assertJsonPath('reviewer', true)
        // The biometric bypass: the phone's PASSCODE is an acceptable confirmation, so a
        // review device with no Face ID is not sent to a supervisor override it cannot get.
        ->assertJsonPath('passcode_identity', true);

    expect((float) $pre->json('distance_m'))->toBe(0.0);

    /* ── IMPOSSIBLE WITHOUT THE FLAG ───────────────────────────────── */

    $ordinaryToken = app(DeviceEnrolment::class)->enrol($this->ordinary, 'Ordinary handset')['token'];

    $theirs = reviewCall($this, $ordinaryToken, 'shifts.preflight', ['shift' => $this->ordinaryShift->id], []);

    $theirs->assertOk()
        ->assertJsonPath('within_geofence', false)
        ->assertJsonPath('allowed', false)
        ->assertJsonPath('reviewer', false)
        ->assertJsonPath('passcode_identity', false);

    // Even standing in Kingston, an ordinary handset is judged on what it sent.
    reviewCall($this, $ordinaryToken, 'shifts.preflight', ['shift' => $this->ordinaryShift->id], [
        'latitude' => 17.9714, 'longitude' => -76.7920,
    ])->assertOk()->assertJsonPath('within_geofence', false);

    // The reviewer's own flag withdrawn: the very same request is refused the same way.
    $this->reviewer->forceFill(['reviewer' => false])->save();
    reviewCall($this, $token, 'shifts.preflight', ['shift' => $this->shift->id], [])
        ->assertOk()->assertJsonPath('within_geofence', false)->assertJsonPath('reviewer', false)->assertJsonPath('passcode_identity', false);

    /* ── AND EVERY USE IS IN THE AUDIT LOG ─────────────────────────── */

    $entries = AuditEntry::query()->where('entity_type', 'Guard')->where('entity_id', (string) $this->reviewer->id)->pluck('action');

    expect($entries)->toContain('reviewer.'.ReviewerMode::GEOFENCE)
        ->and($entries)->toContain('reviewer.'.ReviewerMode::PASSCODE_IDENTITY)
        // One pre-flight as a reviewer, one after the flag was withdrawn: one entry, not two.
        ->and($entries->filter(fn (string $a): bool => $a === 'reviewer.'.ReviewerMode::GEOFENCE)->count())->toBe(1);

    expect(AuditEntry::query()->where('entity_id', (string) $this->ordinary->id)->where('action', 'like', 'reviewer.%')->count())->toBe(0);
});

/* ─────────────────────────────────────────────────────────────────────────
 | 3. The Resident App's static code
 ───────────────────────────────────────────────────────────────────────── */

it('signs a reviewer in with a static six digits, sends nothing, and refuses those digits to any other account', function () {
    // Asking for a code answers exactly as it does for any address — the endpoint must never
    // say who exists — but nothing is issued and nothing is posted.
    reviewCall($this, null, 'auth.otp.request', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
    ])->assertStatus(202)->assertJsonPath('sent', true);

    Notification::assertNothingSent();
    expect(DB::connection('mysql')->table('resident_otps')->where('tenant_id', REVIEW_ESTATE)->count())->toBe(0);

    $verify = reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => strtoupper(ReviewerMode::RESIDENT_EMAIL),
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'review-install-1', 'platform' => 'ios',
    ]);

    $verify->assertOk()
        ->assertJsonPath('reviewer', true)
        ->assertJsonPath('read_only', false)
        ->assertJsonPath('estate.id', REVIEW_ESTATE)
        ->assertJsonPath('next', 'home');

    // It still works months later. A one-time code would have made the review notes wrong the
    // day after they were written.
    $this->travel(200)->days();
    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'review-install-2', 'platform' => 'ios',
    ])->assertOk();
    $this->travelBack();

    /* ── IMPOSSIBLE WITHOUT THE FLAG ───────────────────────────────── */

    // An ordinary account on the same estate, carrying the same hash in its column.
    $ordinary = ResidentAccount::query()->create([
        'tenant_id' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'neighbour@reviewdemo.test',
        'status' => ResidentAccount::ACTIVE, 'resident_id' => $this->home['resident'], 'unit_id' => $this->home['unit'], 'verified_at' => now(),
    ]);
    $ordinary->forceFill(['reviewer' => false, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::RESIDENT_CODE)])->save();

    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'neighbour@reviewdemo.test',
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'neighbour-install', 'platform' => 'ios',
    ])->assertStatus(422)->assertJsonPath('error.code', 'otp_expired');

    // And asking for a code at that address DOES issue and send one, the ordinary way.
    reviewCall($this, null, 'auth.otp.request', [], ['estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'neighbour@reviewdemo.test'])->assertStatus(202);
    Notification::assertSentOnDemand(ResidentSignInCode::class);

    // …and the reviewer's six digits are not that code.
    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'neighbour@reviewdemo.test',
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'neighbour-install', 'platform' => 'ios',
    ])->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');

    // The flag withdrawn from the reviewer's own account, and the static code stops working.
    $this->account->forceFill(['reviewer' => false])->save();
    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'review-install-3', 'platform' => 'ios',
    ])->assertStatus(422);

    expect(AuditEntry::query()->where('entity_type', 'ResidentAccount')->where('entity_id', (string) $this->account->id)->pluck('action'))
        ->toContain('reviewer.'.ReviewerMode::STATIC_CODE);
});

/*
 * THE FLAG IS NEVER SOMETHING A FORM CAN SET.
 *
 * `reviewer` + `reviewer_code_hash` together walk an account past the static-code check, and on
 * the Guard App's half past the geofence, the device binding and the biometric check as well. One
 * `fill()` of a validated form that happened to carry those keys would hand a permanent bypass to
 * whoever sent it. `guards` is protected by an explicit `$fillable`; `resident_accounts` uses
 * `$guarded`, which has to NAME them — and this is what says it still does.
 */
it('never grants reviewer status through mass assignment, on either kind of account', function () {
    $account = ResidentAccount::query()->create([
        'tenant_id' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'chancer@reviewdemo.test',
        'status' => ResidentAccount::ACTIVE, 'resident_id' => $this->home['resident'], 'unit_id' => $this->home['unit'],
        'verified_at' => now(),
        // What a request body would carry if anything ever passed one straight through.
        'reviewer' => true,
        'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::RESIDENT_CODE),
    ]);

    expect($account->refresh()->reviewer)->toBeFalse()
        ->and($account->reviewer_code_hash)->toBeNull()
        ->and(ReviewerMode::isReviewer($account))->toBeFalse();

    // …and filling an existing one does not grant it either.
    $this->account->forceFill(['reviewer' => false, 'reviewer_code_hash' => null])->save();
    $this->account->fill(['reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash('ANYTHING')])->save();

    expect(ResidentAccount::query()->find($this->account->id)?->reviewer)->toBeFalse();

    // The Guard App's half, the same way.
    $guard = Guard::create([
        'full_name' => 'Chancer', 'employee_number' => 'GS-RVW-9', 'psra_number' => 'PSRA-RVW-9',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => REVIEW_ESTATE, 'post_id' => $this->post->id,
        'reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::GUARD_CODE),
    ]);

    expect($guard->refresh()->reviewer)->toBeFalse()
        ->and($guard->reviewer_code_hash)->toBeNull();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 4. "Explore a demo community" — read-only in the token
 ───────────────────────────────────────────────────────────────────────── */

it('hands the demo path a read-only token, and refuses every write at the ability', function () {
    $listed = collect(reviewCall($this, null, 'estates.index')->assertOk()->json('items'));

    expect($listed->firstWhere('id', REVIEW_ESTATE)['demo'])->toBeTrue()
        ->and($listed->firstWhere('id', FacilitiesFixture::ESTATE)['demo'])->toBeFalse();

    $demo = reviewCall($this, null, 'demo.enter')->assertOk();

    $demo->assertJsonPath('read_only', true)->assertJsonPath('reviewer', true)->assertJsonPath('estate.id', REVIEW_ESTATE);

    $token = (string) $demo->json('token');

    expect($demo->json('abilities'))->toBe(AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT))
        ->and($demo->json('abilities'))->not->toContain('passes:write')
        ->and($demo->json('abilities'))->not->toContain('household:write');

    // Reading works …
    reviewCall($this, $token, 'me.show')->assertOk()->assertJsonPath('estate.id', REVIEW_ESTATE);
    reviewCall($this, $token, 'notices.index')->assertOk();

    /*
     * … and EVERY write the Resident App has is refused at the route's own ability
     * middleware, before a controller is reached. This is why read-only lives in the token
     * rather than in the screens: there is nothing to remember to disable.
     */
    $writes = collect(Catalogue::endpoints())
        ->filter(fn (array $e): bool => $e['capability'] !== null
            && $e['access'] === AppMatrix::WRITE
            && in_array(AppMatrix::RESIDENT, $e['apps'], true));

    expect($writes->count())->toBeGreaterThan(15);

    foreach ($writes as $name => $entry) {
        app('auth')->forgetGuards();
        $uri = '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']);
        $response = $this->withToken($token)->json($entry['method'], $uri, [], ['Idempotency-Key' => 'ro-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        expect($response->status())->toBe(403, "[{$name}] answered a read-only demo token with {$response->status()}")
            ->and($response->json('error.code'))->toBe('missing_ability', "[{$name}] refused a read-only demo token for the wrong reason");

        FacilitiesFixture::boot();
    }

    // Deleting the account is a write too — a demonstration session cannot delete anything.
    expect(ResidentAccount::query()->find($this->account->id)?->deleted_at)->toBeNull();

    expect(AuditEntry::query()->where('entity_type', 'ResidentAccount')->where('entity_id', (string) $this->account->id)->pluck('action'))
        ->toContain('reviewer.'.ReviewerMode::DEMO_SESSION);
});

/*
 * TWO DEMO SESSIONS AT ONCE (B060). The token was named from the demonstration ACCOUNT alone, and
 * every caller is handed that same account, so each "Explore a demo community" deleted every other
 * device's demo token before issuing its own: a reviewer on an iPad was signed out the moment a
 * reviewer on an iPhone — or any member of the public — tapped the same button. The app reads that
 * 401 as "signed in on another phone". Now each session is its own; an install that names itself
 * replaces only its own; and a session dead long past the idle window is swept up by its age.
 */
it('lets two demo sessions live side by side, and replaces only the asking install\'s own', function () {
    $iphone = (string) reviewCall($this, null, 'demo.enter')->assertOk()->json('token');
    $ipad = (string) reviewCall($this, null, 'demo.enter')->assertOk()->json('token');

    // Neither signed the other out.
    reviewCall($this, $iphone, 'me.show')->assertOk()->assertJsonPath('estate.id', REVIEW_ESTATE);
    reviewCall($this, $ipad, 'me.show')->assertOk()->assertJsonPath('estate.id', REVIEW_ESTATE);

    // An install that says who it is gets one session, and asking again replaces that one only.
    $first = (string) reviewCall($this, null, 'demo.enter', [], ['device_uid' => 'review-pixel-1'])->assertOk()->json('token');
    $again = (string) reviewCall($this, null, 'demo.enter', [], ['device_uid' => 'review-pixel-1'])->assertOk()->json('token');

    reviewCall($this, $first, 'me.show')->assertUnauthorized();
    reviewCall($this, $again, 'me.show')->assertOk();
    reviewCall($this, $iphone, 'me.show')->assertOk();
    reviewCall($this, $ipad, 'me.show')->assertOk();

    // Still read-only, every one of them.
    expect(reviewCall($this, null, 'demo.enter', [], ['device_uid' => 'review-pixel-2'])->json('abilities'))
        ->toBe(AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT));
});

it('sweeps up demo sessions dead a day past the idle window, and leaves the reviewer\'s own sign-in alone', function () {
    // The full demonstration account, signed in with its static code on a phone of its own.
    $signedIn = (string) reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'reviewer-own-phone', 'platform' => 'ios',
    ])->assertOk()->json('token');

    $abandoned = (string) reviewCall($this, null, 'demo.enter')->assertOk()->json('token');
    $abandonedId = (int) explode('|', $abandoned, 2)[0];

    // Idle well past the window: that session is over, and says so with its own code.
    $this->travel(SessionWindow::minutes() + 5)->minutes();
    reviewCall($this, $abandoned, 'me.show')->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');

    // A day later still, the next visitor's entry sweeps it away; nobody else's session goes with it.
    $this->travel(25)->hours();

    $fresh = (string) reviewCall($this, null, 'demo.enter')->assertOk()->json('token');

    expect(DB::connection('mysql')->table('personal_access_tokens')->where('id', $abandonedId)->exists())->toBeFalse()
        ->and(DB::connection('mysql')->table('personal_access_tokens')->where('id', (int) explode('|', $signedIn, 2)[0])->exists())->toBeTrue();

    reviewCall($this, $fresh, 'me.show')->assertOk();
});

/*
 * A STORE REVIEW AFTER LAUNCH (found M17). On a launched system the demonstration switch is OFF and only the review
 * window is opened for a submission. The pick list hid a demonstration estate whenever the demonstration switch was
 * off, while `demo.enter` and the static code answered to the review window — so during every review after launch the
 * reviewer could not choose the estate the notes send them to, and the app, seeing no demonstration estate in the
 * list, drew no "Explore a demo community" door. Both ways in were shut. The list now asks the same question
 * `ReviewerMode::estate()` asks.
 */
it('offers the demonstration estate to a store reviewer on a launched system while the review window is open', function () {
    config(['demo.enabled' => false, 'demo.review' => true]);

    expect(collect(reviewCall($this, null, 'estates.index')->assertOk()->json('items'))->firstWhere('id', REVIEW_ESTATE)['demo'] ?? null)->toBeTrue();
    reviewCall($this, null, 'demo.enter')->assertOk()->assertJsonPath('estate.id', REVIEW_ESTATE);
    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'review-after-launch', 'platform' => 'ios',
    ])->assertOk();

    // The window closed — the launch posture — and the estate is gone from the list and the door with it.
    config(['demo.review' => false]);

    expect(collect(reviewCall($this, null, 'estates.index')->json('items'))->pluck('id'))->not->toContain(REVIEW_ESTATE);
    reviewCall($this, null, 'demo.enter')->assertNotFound()->assertJsonPath('error.code', 'demo_unavailable');
});

it('says there is no demo community rather than inventing one', function () {
    DB::connection('mysql')->table('tenants')->where('id', REVIEW_ESTATE)->update(['demo' => false]);

    reviewCall($this, null, 'demo.enter')->assertNotFound()->assertJsonPath('error.code', 'demo_unavailable');

    // …and the pick-list then offers no demonstration estate, so the app has nothing to draw
    // the control from. A button that could only ever fail is a dead control.
    expect(collect(reviewCall($this, null, 'estates.index')->json('items'))->pluck('demo')->filter())->toBeEmpty();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 4b. DELETING THE ACCOUNT — the one act that could end the whole review
 |
 | Both stores require in-app account deletion (M5 §4) and both CHECK it, so a
 | reviewer taps it. Run ordinarily on the demonstration account it would rename
 | the row, drop its name and suspend it — which kills the static sign-in code
 | quoted in `STORE_REVIEW_NOTES.md` AND `demo.enter` with it. The Apple reviewer
 | would be fine; the Play reviewer the following week would find no way in at all.
 ───────────────────────────────────────────────────────────────────────── */

it('keeps the demonstration account when a reviewer deletes it, and deletes an ordinary one exactly as before', function () {
    $token = (string) reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'deletion-install', 'platform' => 'ios',
    ])->assertOk()->json('token');

    // The confirmation word is still what guards it: a mis-tap is refused before anything else.
    reviewCall($this, $token, 'me.delete_account', [], ['confirm' => 'nope'])
        ->assertStatus(422)->assertJsonPath('error.code', 'confirm_required');

    $refused = reviewCall($this, $token, 'me.delete_account', [], ['confirm' => 'DELETE']);

    $refused->assertStatus(409)->assertJsonPath('error.code', 'demo_account_kept');
    expect((string) $refused->json('error.message'))->toContain('demonstration account');

    /* ── AND THE ACCOUNT IS EXACTLY AS IT WAS ──────────────────────── */

    $after = ResidentAccount::query()->find($this->account->id);

    expect($after?->deleted_at)->toBeNull()
        ->and($after?->deleted_reference)->toBeNull()
        ->and($after?->destination)->toBe(ReviewerMode::RESIDENT_EMAIL)
        ->and($after?->full_name)->toBe('Review Resident (demonstration account)')
        ->and($after?->status)->toBe(ResidentAccount::ACTIVE);

    // …so the NEXT reviewer's two doors both still open: the static code, and "Explore a demo community".
    reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'next-reviewer-install', 'platform' => 'ios',
    ])->assertOk()->assertJsonPath('reviewer', true);

    reviewCall($this, null, 'demo.enter')->assertOk()->assertJsonPath('read_only', true);

    // Every reviewer-mode act is written down, this one included.
    expect(AuditEntry::query()->where('entity_type', 'ResidentAccount')->where('entity_id', (string) $this->account->id)->pluck('action'))
        ->toContain('reviewer.'.ReviewerMode::DELETION_DECLINED);

    /* ── IMPOSSIBLE WITHOUT THE FLAG ───────────────────────────────── */

    // The same estate, the same screen, the same word — and no flag. It deletes, as M18H says: a state it can come back from.
    $ordinary = ResidentAccount::query()->create([
        'tenant_id' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => 'leaver@reviewdemo.test',
        'full_name' => 'A Leaver', 'status' => ResidentAccount::ACTIVE,
        'resident_id' => $this->home['resident'], 'unit_id' => $this->home['unit'], 'verified_at' => now(),
    ]);
    $ordinary->forceFill(['reviewer' => false])->save();

    $theirToken = $ordinary->createToken('resident-app:leaver', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    reviewCall($this, $theirToken, 'me.delete_account', [], ['confirm' => 'DELETE'])
        ->assertOk()->assertJsonPath('deleted', true);

    $gone = ResidentAccount::query()->find($ordinary->id);

    expect($gone?->deleted_at)->not->toBeNull()
        ->and($gone?->status)->toBe(ResidentAccount::DELETED)
        // Recoverable (M18H §1.1): nothing of the person is erased until the estate's window closes.
        ->and($gone?->destination)->toBe('leaver@reviewdemo.test');

    // And withdrawing the flag makes the demonstration account ordinary again: nothing is special-cased
    // by name, id or estate — the flag is the whole of it.
    $this->account->forceFill(['reviewer' => false])->save();

    reviewCall($this, $token, 'me.delete_account', [], ['confirm' => 'DELETE'])->assertOk()->assertJsonPath('deleted', true);
    expect(ResidentAccount::query()->find($this->account->id)?->deleted_at)->not->toBeNull();

    // The demonstration estate is committed and shared: both deletions took Demo Lot 1's resident off the active
    // register, and the office's restore is what puts them back for every test after this one.
    foreach ([$this->account->id, $ordinary->id] as $id) {
        app(AccountDeletion::class)->restore(ResidentAccount::query()->findOrFail($id), AccountDeletion::BY_OFFICE);
    }
    FacilitiesFixture::boot();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 5. THE TEST THAT MATTERS
 ───────────────────────────────────────────────────────────────────────── */

it('reads the demonstration estate and not one row of any real tenant\'s, whichever id it is handed', function () {
    $real = FacilitiesFixture::ESTATE;

    /* ── Rows that genuinely belong to the REAL estate ──────────────── */

    /** @var array<string, int> $realIds */
    $realIds = FacilitiesFixture::platform()->run(function (): array {
        /*
         * A LOT OF THIS FILE'S OWN, and high-numbered on purpose. Row ids are per DATABASE,
         * so the demonstration estate's first household is id 1 and so is the real estate's:
         * probing with id 1 would prove nothing, because the reviewer's own household really
         * is 1 in its own estate. Every real id below is one the demonstration estate does
         * not have, and the probe asserts that before it uses it.
         */
        $unit = Unit::query()->firstOrCreate(
            ['reference' => 'Lot 9901'],
            ['block' => 'Phase 9', 'street' => 'Phase 9 Drive', 'type' => 'residential', 'status' => 'occupied'],
        );
        $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Real household', 'access_restricted' => false]);
        $resident = Resident::query()->firstOrCreate(
            ['household_id' => $household->id, 'email' => 'real.resident@facilitiestest.test'],
            ['full_name' => 'Real Resident', 'relationship' => 'owner', 'is_primary' => true],
        );

        $notice = new Notice;
        $notice->forceFill([
            'kind' => Notice::GENERAL, 'title' => 'Real estate notice '.bin2hex(random_bytes(3)), 'body' => 'A real community\'s business.',
            'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'Estate Secretary', 'published_at' => now()->subHour(),
        ])->save();

        $meeting = new Meeting;
        $meeting->forceFill([
            'type' => Meeting::AGM, 'title' => 'Real AGM '.bin2hex(random_bytes(3)), 'starts_at' => now()->addDays(14), 'venue' => 'Club House',
            'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now()->subHour(),
        ])->save();

        $charge = Charge::query()->create([
            'unit_id' => $unit->id, 'type' => 'dues', 'period' => now()->format('Y-m'), 'reference' => 'RVW-'.strtoupper(bin2hex(random_bytes(4))),
            'description' => 'Maintenance fee', 'amount_minor' => 9_900_00, 'currency' => 'JMD', 'due_on' => now()->addDays(7)->toDateString(), 'status' => 'outstanding',
        ]);

        $payment = new Payment;
        $payment->forceFill([
            'unit_id' => $unit->id, 'receipt_no' => 'FXT-RVW-'.strtoupper(bin2hex(random_bytes(4))), 'amount_minor' => 9_900_00, 'currency' => 'JMD',
            'method' => Payment::BANK, 'reference' => 'A real receipt', 'received_at' => now()->subDay(), 'entered_at' => now(), 'status' => 'recorded',
        ])->save();

        $pass = app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $unit, (int) $resident->id, (string) $resident->full_name, [
            'category' => 'single', 'visitor_name' => 'A real visitor', 'valid_from' => now(), 'valid_to' => now()->addHours(4),
        ]);

        $approval = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
            'unit_id' => $unit->id, 'household_id' => $household->id, 'guard_id' => 1, 'guard_name' => 'A real guard', 'post_name' => 'Main Gate',
            'visitor_name' => 'A real caller', 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'unit' => (int) $unit->id, 'household' => (int) $household->id, 'member' => (int) $resident->id,
            'notice' => (int) $notice->id, 'meeting' => (int) $meeting->id, 'invoice' => (int) $charge->id,
            'payment' => (int) $payment->id, 'pass' => (int) $pass->id, 'approval' => (int) $approval,
        ];
    });

    FacilitiesFixture::boot();

    $this->realIds = $realIds;

    $realShift = Shift::query()->create([
        'tenant_id' => $real, 'guard_id' => null, 'post_id' => Post::query()->firstOrCreate(
            ['tenant_id' => $real, 'name' => 'Real Gate'], ['type' => 'gate', 'is_active' => true],
        )->id,
        'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(8), 'status' => Shift::OPEN,
    ]);

    /* ── The reviewer's two tokens ──────────────────────────────────── */

    $guardToken = app(DeviceEnrolment::class)->enrol($this->reviewer, 'Review handset')['token'];

    $residentToken = (string) reviewCall($this, null, 'auth.otp.verify', [], [
        'estate' => REVIEW_ESTATE, 'channel' => 'email', 'destination' => ReviewerMode::RESIDENT_EMAIL,
        'code' => ReviewerMode::RESIDENT_CODE, 'device_uid' => 'isolation-install', 'platform' => 'ios',
    ])->assertOk()->json('token');

    /*
     * AN ID NOBODY HAS. Every probe below is made TWICE — once with a real tenant's own row
     * id and once with this — and the two answers must be identical, status and error code.
     * That is what "indistinguishable" means: not merely refused, but refused in a way that
     * says nothing about whether the row exists anywhere on the platform.
     */
    $nowhere = 987_654_321;

    $mine = [$this->home['household'], $this->home['unit'], $this->home['resident'], $this->shift->id];

    $identical = function (string $name, string $param, int $realId, ?string $token, array $body = []) use ($nowhere, $mine): void {
        /*
         * A probe with an id the reviewer legitimately owns in its OWN estate would prove
         * nothing — ids are per database, and answering it is correct. `in_array` and not
         * `->not->toContain(…)`: `toContain` takes VARIADIC needles, so a message passed
         * beside the id becomes a second needle, and the negation then passes the moment the
         * MESSAGE is absent from the array — which it always is. The guard read green while
         * the collision it exists to catch went straight past it.
         */
        expect(in_array($realId, $mine, true))->toBeFalse(
            "[{$name}] was probed with id {$realId}, which the demonstration estate also holds (".json_encode($mine).') — the probe would prove nothing'
        );

        $theirs = reviewCall($this, $token, $name, [$param => $realId], $body);
        $nobody = reviewCall($this, $token, $name, [$param => $nowhere], $body);

        expect($theirs->status())->toBe(
            $nobody->status(),
            "[{$name}] answered another tenant's {$param} {$realId} with {$theirs->status()} and an id nobody has with {$nobody->status()}. The demonstration estate's own ids are ".json_encode($mine).'. Body: '.substr((string) $theirs->getContent(), 0, 300)
        );

        expect($theirs->json('error.code'))->toBe(
            $nobody->json('error.code'),
            "[{$name}] answered another tenant's {$param} with `".(string) $theirs->json('error.code').'` and an id nobody has with `'.(string) $nobody->json('error.code').'`'
        );

        // And whatever it answered, it was not the row.
        expect($theirs->status())->toBeGreaterThanOrEqual(400, "[{$name}] ANSWERED another tenant's {$param}");
    };

    /* ── The Guard App's side ───────────────────────────────────────── */

    $current = reviewCall($this, $guardToken, 'shifts.me.current')->assertOk();
    expect($current->json('shift.site.id'))->toBe(REVIEW_ESTATE)
        ->and($current->json('shift.id'))->toBe($this->shift->id);

    $identical('shifts.preflight', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.break.start', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.break.end', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.claim', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.clock_in', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.clock_out', 'shift', (int) $realShift->id, $guardToken);
    $identical('shifts.clock_in_override', 'shift', (int) $realShift->id, $guardToken, ['pin' => '000000']);

    // A SITE id is the bluntest probe there is: the real estate's subdomain, by name.
    foreach (['patrol.checkpoints', 'passes.keys', 'orders.current'] as $name) {
        $theirs = reviewCall($this, $guardToken, $name, ['site' => $real]);
        $nobody = reviewCall($this, $guardToken, $name, ['site' => 'nosuchestate']);

        expect($theirs->status())->toBe($nobody->status(), "[{$name}] told a demonstration handset that [{$real}] exists")
            ->and($theirs->json('error.code'))->toBe($nobody->json('error.code'))
            ->and($theirs->status())->toBeGreaterThanOrEqual(400);
    }

    // Nothing a list answers belongs to anybody else.
    foreach (['shifts.open', 'incidents.me', 'messages.index', 'gate.activity', 'payslips.me', 'requests.index', 'standing_orders.index'] as $name) {
        $items = collect(reviewCall($this, $guardToken, $name)->assertOk()->json('items') ?? []);

        expect($items->pluck('site.id')->filter()->unique()->reject(fn ($id): bool => $id === REVIEW_ESTATE))->toBeEmpty("[{$name}] answered with another estate's rows");
    }

    /* ── The Resident App's side ────────────────────────────────────── */

    $me = reviewCall($this, $residentToken, 'me.show')->assertOk();
    expect($me->json('estate.id'))->toBe(REVIEW_ESTATE)
        ->and($me->json('household.id'))->toBe($this->home['household']);

    $identical('households.balance', 'household', $realIds['household'], $residentToken);
    $identical('households.statement', 'household', $realIds['household'], $residentToken);
    $identical('notices.read', 'notice', $realIds['notice'], $residentToken);
    $identical('meetings.rsvp', 'meeting', $realIds['meeting'], $residentToken, ['response' => 'yes']);
    $identical('invoices.pay_intent', 'invoice', $realIds['invoice'], $residentToken);
    $identical('payments.receipt', 'payment', $realIds['payment'], $residentToken);
    $identical('visitor_passes.cancel', 'pass', $realIds['pass'], $residentToken);
    $identical('visitor_passes.share', 'pass', $realIds['pass'], $residentToken, ['channel' => 'whatsapp']);
    $identical('visitor_passes.update', 'pass', $realIds['pass'], $residentToken, ['vehicle_plate' => '1234 AB']);
    $identical('members.destroy', 'member', $realIds['member'], $residentToken);
    $identical('approvals.respond', 'approval', $realIds['approval'], $residentToken, ['decision' => 'approve']);

    // The real estate's rows are all still exactly as they were: nothing was read, and
    // nothing was written either.
    FacilitiesFixture::platform()->run(function () use ($realIds): void {
        expect(VisitorPass::query()->find($realIds['pass'])?->status)->toBe(VisitorPass::ACTIVE)
            ->and(Resident::query()->find($realIds['member']))->not->toBeNull()
            ->and((string) DB::connection('tenant')->table('gate_approval_requests')->where('id', $realIds['approval'])->value('status'))->toBe('pending');
    });

    FacilitiesFixture::boot();

    // And the lists a resident reads hold the demonstration estate's rows and no others.
    expect(collect(reviewCall($this, $residentToken, 'notices.index')->assertOk()->json('items'))->pluck('id'))->not->toContain($realIds['notice']);
    expect(collect(reviewCall($this, $residentToken, 'meetings.index')->assertOk()->json('items'))->pluck('id'))->not->toContain($realIds['meeting']);
    expect(collect(reviewCall($this, $residentToken, 'invoices.index')->assertOk()->json('items'))->pluck('id'))->not->toContain($realIds['invoice']);
    expect(collect(reviewCall($this, $residentToken, 'payments.index')->assertOk()->json('items'))->pluck('id'))->not->toContain($realIds['payment']);
    expect(collect(reviewCall($this, $residentToken, 'visitor_passes.index')->assertOk()->json('items'))->pluck('id'))->not->toContain($realIds['pass']);
    expect(collect(reviewCall($this, $residentToken, 'tickets.index')->assertOk()->json('items'))->pluck('id'))->toBeEmpty();
});

afterEach(function () {
    // The real estate's database is COMMITTED, not rolled back (FacilitiesFixture), so the
    // rows this file made there are taken back by hand — the rule every file that writes to
    // the shared estate follows.
    if (! isset($this->realIds)) {
        return;
    }

    $ids = $this->realIds;

    FacilitiesFixture::platform()->run(function () use ($ids): void {
        VisitorPass::query()->whereKey($ids['pass'])->delete();
        DB::connection('tenant')->table('gate_approval_requests')->where('id', $ids['approval'])->delete();
        Notice::query()->whereKey($ids['notice'])->delete();
        Meeting::query()->whereKey($ids['meeting'])->delete();
        Charge::query()->whereKey($ids['invoice'])->delete();
        Payment::query()->whereKey($ids['payment'])->delete();
        // Lot 9901, its household and its resident stay: nothing else stands on them, and
        // removing a unit from a committed estate is exactly the kind of mark a later file
        // would trip over (M4 §4, defect 3).
    });

    FacilitiesFixture::boot();
});
