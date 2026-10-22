<?php

declare(strict_types=1);

use App\Models\DevicePushToken;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Devices\DeviceResume;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F3 — a signed-out guard gets back in with a fingerprint, never a code
|--------------------------------------------------------------------------
|
| The enrolment code binds a phone once. After it, the phone signs a one-time challenge with the key it was bound
| with, and the guard is confirmed by the phone's fingerprint (`biometric`) or a supervisor's PIN checked here
| (`supervisor_pin`); `check` only asks whether the install is still bound. A phone that is no longer the guard's is
| told which case it is, in one line, with the date: replaced by another phone a supervisor approved, or taken out of
| service by the office. The phone's half is the mobile repository's `apps/guard/src/features/unlock/__tests__`.
|
*/

const M32_RESUME_PIN = '482913';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Resume Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Andre Resume', 'employee_number' => 'GS-RSM-1', 'psra_number' => 'PSRA-RSM-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A phone's own key pair, as the Guard App makes it: the secret stays here, the public half goes to the server. */
function m32ResumePhone(string $uid): array
{
    $pair = sodium_crypto_sign_keypair();

    return ['uid' => $uid, 'secret' => sodium_crypto_sign_secretkey($pair), 'public' => sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
}

/** Enrolled with a one-time code, over the API, as on the phone. Returns the token. */
function m32ResumeEnrol(object $test, Guard $guard, array $phone): string
{
    $token = $test->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => app(DeviceEnrolment::class)->issueCode($guard), 'device_uid' => $phone['uid'], 'platform' => 'android', 'public_key' => $phone['public'],
    ])->assertCreated()->json('token');
    FacilitiesFixture::boot();

    return (string) $token;
}

/** Ask for a challenge, sign it with the phone's key, and send it with the method — what the phone does. */
function m32Resume(object $test, array $phone, string $method, array $extra = [], ?string $signWith = null)
{
    $challenge = $test->postJson('/api/v1/devices/challenge', ['device_uid' => $phone['uid']])->assertOk();
    ApiContract::assertMatches($challenge, 'devices.challenge');

    $signature = sodium_bin2base64(sodium_crypto_sign_detached(DeviceResume::message($phone['uid'], (string) $challenge->json('challenge')), $signWith ?? $phone['secret']), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    app('auth')->forgetGuards();
    $answer = $test->postJson('/api/v1/devices/resume', ['device_uid' => $phone['uid'], 'challenge' => $challenge->json('challenge'), 'signature' => $signature, 'method' => $method, ...$extra]);
    FacilitiesFixture::boot();
    app('auth')->forgetGuards();

    return $answer;
}

function m32ResumeWorks(object $test, string $token, int $status = 200): void
{
    app('auth')->forgetGuards();
    $test->withToken($token)->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertStatus($status);
    FacilitiesFixture::boot();
    app('auth')->forgetGuards();
}

it('signs a guard back in after the 30-minute sign-out with the phone\'s own key — no code — and keeps its alerts', function () {
    $phone = m32ResumePhone('rsm-phone-1');
    $old = m32ResumeEnrol($this, $this->guard, $phone);
    $oldId = (int) DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $this->guard->id)->value('id');
    $push = DevicePushToken::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $this->guard->id, 'app' => 'guard',
        'access_token_id' => $oldId, 'expo_token' => 'ExponentPushToken[m32-resume-1]', 'platform' => 'android', 'last_registered_at' => now(),
    ]);

    // Thirty minutes with nothing sent, off duty: the session is over, and says so.
    $this->travel(31)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($old)->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
    FacilitiesFixture::boot();

    $answer = m32Resume($this, $phone, 'biometric')->assertOk()->assertJsonPath('state', 'resumed')
        ->assertJsonPath('guard.name', 'Andre Resume')->assertJsonPath('site.timezone', 'America/Jamaica');
    ApiContract::assertMatches($answer, 'devices.resume');

    m32ResumeWorks($this, (string) $answer->json('token'));
    $newId = (int) DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $this->guard->id)->value('id');

    expect(DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_type', Guard::class)->where('tokenable_id', $this->guard->id)->count())->toBe(1)
        ->and($newId)->not->toBe($oldId)
        // The push address moved to the new sign-in in the same breath: no alert falls between the two.
        ->and((int) $push->fresh()->access_token_id)->toBe($newId)
        ->and($push->fresh()->revoked_at)->toBeNull()
        ->and($this->guard->fresh()->device_uid)->toBe('rsm-phone-1');

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'device.resumed')->where('entity_id', (string) $this->guard->id)->first();
    expect(json_decode((string) $entry->after, true))->toBe(['method' => 'biometric', 'device_uid' => 'rsm-phone-1']);
});

it('answers a check with whether the phone is still bound, and issues nothing', function () {
    $phone = m32ResumePhone('rsm-phone-2');
    m32ResumeEnrol($this, $this->guard, $phone);
    $tokens = DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $this->guard->id)->pluck('id')->all();

    $answer = m32Resume($this, $phone, 'check')->assertOk()->assertJsonPath('state', 'bound')->assertJsonMissingPath('token');
    ApiContract::assertMatches($answer, 'devices.resume');

    expect(DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $this->guard->id)->pluck('id')->all())->toBe($tokens);
});

it('tells a wrong signature and an install nobody bound the same thing: this phone has not been set up', function () {
    $phone = m32ResumePhone('rsm-phone-3');
    m32ResumeEnrol($this, $this->guard, $phone);
    $stranger = m32ResumePhone('rsm-phone-3');

    m32Resume($this, $phone, 'biometric', signWith: $stranger['secret'])->assertForbidden()
        ->assertJsonPath('error.code', 'device_not_enrolled')
        ->assertJsonPath('error.message', 'This phone has not been set up for GeminiSecure yet. Ask the office for a code.');

    m32Resume($this, m32ResumePhone('rsm-nobody'), 'check')->assertForbidden()->assertJsonPath('error.code', 'device_not_enrolled');

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'device.resumed')->exists())->toBeFalse();
});

it('takes a challenge once, and not after two minutes', function () {
    $phone = m32ResumePhone('rsm-phone-4');
    m32ResumeEnrol($this, $this->guard, $phone);

    $challenge = (string) $this->postJson('/api/v1/devices/challenge', ['device_uid' => $phone['uid']])->json('challenge');
    $signed = sodium_bin2base64(sodium_crypto_sign_detached(DeviceResume::message($phone['uid'], $challenge), $phone['secret']), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $send = fn () => $this->postJson('/api/v1/devices/resume', ['device_uid' => $phone['uid'], 'challenge' => $challenge, 'signature' => $signed, 'method' => 'check']);

    $send()->assertOk();
    FacilitiesFixture::boot();
    $send()->assertUnprocessable()->assertJsonPath('error.code', 'challenge_expired');
    FacilitiesFixture::boot();

    $late = (string) $this->postJson('/api/v1/devices/challenge', ['device_uid' => $phone['uid']])->json('challenge');
    $this->travel(DeviceResume::CHALLENGE_SECONDS + 1)->seconds();
    $this->postJson('/api/v1/devices/resume', [
        'device_uid' => $phone['uid'], 'challenge' => $late, 'method' => 'check',
        'signature' => sodium_bin2base64(sodium_crypto_sign_detached(DeviceResume::message($phone['uid'], $late), $phone['secret']), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
    ])->assertUnprocessable()->assertJsonPath('error.code', 'challenge_expired');
});

it('tells a phone a supervisor replaced when, and that it was their supervisor — on the estate\'s calendar', function () {
    $first = m32ResumePhone('rsm-old-phone');
    $second = m32ResumePhone('rsm-new-phone');
    m32ResumeEnrol($this, $this->guard, $first);

    // Another phone, a code, a supervisor's approval, collected: the move the console makes.
    $pending = $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => app(DeviceEnrolment::class)->issueCode($this->guard), 'device_uid' => $second['uid'], 'platform' => 'android', 'public_key' => $second['public'],
    ])->assertStatus(202);
    FacilitiesFixture::boot();

    Carbon::setTestNow(Carbon::parse('2026-10-02 23:30:00', 'UTC'));
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->actingAs($director)->post('/guards/'.$this->guard->id.'/device-rebinds/'.$pending->json('rebind_request_id').'/approve')->assertRedirect();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/devices/enrol/'.$pending->json('rebind_request_id').'/collect', ['claim_secret' => $pending->json('claim_secret'), 'device_uid' => $second['uid']])->assertOk()->assertJsonPath('status', 'approved');
    FacilitiesFixture::boot();
    Carbon::setTestNow();

    // 23:30 UTC on 2 October is still 2 October in Kingston (UTC−5): the estate's day, not the server's.
    m32Resume($this, $first, 'check')->assertForbidden()
        ->assertJsonPath('error.code', 'device_removed')
        ->assertJsonPath('error.message', 'Your supervisor removed this phone on 2 October. Ask the office for a new code.')
        ->assertJsonPath('error.details.removed_by', 'supervisor')
        ->assertJsonPath('error.details.removed_at', '2026-10-02T23:30:00+00:00');

    expect(DB::connection('mysql')->table('device_bindings')->where('device_uid', 'rsm-old-phone')->first())
        ->toMatchObject(['ended_reason' => 'replaced', 'ended_by' => 'supervisor', 'ended_by_name' => $director->name]);

    // And the phone that replaced it signs in as itself.
    m32Resume($this, $second, 'check')->assertOk()->assertJsonPath('state', 'bound');
});

it('tells a phone the office took out of service that the office did', function () {
    $phone = m32ResumePhone('rsm-phone-5');
    m32ResumeEnrol($this, $this->guard, $phone);

    Carbon::setTestNow(Carbon::parse('2026-10-03 14:00:00', 'UTC'));
    app(DeviceEnrolment::class)->revoke($this->guard->fresh());
    Carbon::setTestNow();

    m32Resume($this, $phone, 'biometric')->assertForbidden()
        ->assertJsonPath('error.code', 'device_removed')
        ->assertJsonPath('error.message', 'The office removed this phone on 3 October. Ask the office for a new code.')
        ->assertJsonPath('error.details.removed_by', 'office');

    expect(DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_id', $this->guard->id)->count())->toBe(0);
});

it('tells a guard who is not on active duty so, and lets nothing in', function () {
    $phone = m32ResumePhone('rsm-phone-6');
    m32ResumeEnrol($this, $this->guard, $phone);
    $this->guard->forceFill(['status' => 'suspended'])->save();

    m32Resume($this, $phone, 'biometric')->assertForbidden()
        ->assertJsonPath('error.code', 'guard_not_active')
        ->assertJsonPath('error.message', 'Andre Resume is not on active duty, so this phone cannot sign in for them. Ask the office.');
});

it('lets a supervisor\'s PIN sign the guard back in where the phone cannot match a fingerprint, and records whose', function () {
    $phone = m32ResumePhone('rsm-phone-7');
    m32ResumeEnrol($this, $this->guard, $phone);
    // A dispatcher holds `gemini.guard_workforce.update` across every estate: a supervisor (GuardOverrideContractTest).
    $supervisor = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($supervisor, M32_RESUME_PIN);

    m32Resume($this, $phone, 'supervisor_pin', ['supervisor' => $supervisor->email, 'pin' => '000000'])->assertForbidden()
        ->assertJsonPath('error.code', 'override_refused');
    expect((int) DB::connection('mysql')->table('supervisor_override_pins')->where('user_id', $supervisor->id)->value('failed_attempts'))->toBe(1);

    m32Resume($this, $phone, 'supervisor_pin', ['supervisor' => $supervisor->email])->assertUnprocessable()->assertJsonValidationErrors('pin');

    $answer = m32Resume($this, $phone, 'supervisor_pin', ['supervisor' => strtoupper($supervisor->email), 'pin' => M32_RESUME_PIN])->assertOk()->assertJsonPath('state', 'resumed');
    m32ResumeWorks($this, (string) $answer->json('token'));

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'device.resumed')->where('entity_id', (string) $this->guard->id)->first();
    expect(json_decode((string) $entry->after, true))->toBe(['method' => 'supervisor_pin', 'device_uid' => 'rsm-phone-7', 'supervisor' => $supervisor->id, 'supervisor_name' => $supervisor->name]);
});

it('makes a phone set up for another officer theirs alone: the last officer\'s binding and session end', function () {
    $phone = m32ResumePhone('rsm-shared');
    $firstToken = m32ResumeEnrol($this, $this->guard, $phone);
    $other = Guard::create([
        'full_name' => 'Marcia Shared', 'employee_number' => 'GS-RSM-2', 'psra_number' => 'PSRA-RSM-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->guard->post_id,
    ]);
    $theirs = m32ResumePhone('rsm-shared');
    m32ResumeEnrol($this, $other, $theirs);

    m32ResumeWorks($this, $firstToken, 401);
    expect($this->guard->fresh()->deviceIsBound())->toBeFalse()
        ->and(DB::connection('mysql')->table('device_bindings')->where('guard_id', $this->guard->id)->value('ended_reason'))->toBe('replaced');

    m32Resume($this, $theirs, 'check')->assertOk()->assertJsonPath('guard.name', 'Marcia Shared');
});

it('records every phone bound when the migration runs, and an install on two guards\' rows as the later one\'s', function () {
    // Phone 2 on production: one install, one key, on Andre's row (3 October) and then the reviewer account's (4 October).
    $phone = m32ResumePhone('rsm-two-rows');
    $reviewer = Guard::create([
        'full_name' => 'Review Officer', 'employee_number' => 'GS-RSM-R', 'psra_number' => 'PSRA-RSM-R',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->guard->post_id,
    ]);
    foreach ([[$this->guard, '2026-10-03 14:43:59'], [$reviewer, '2026-10-04 14:31:40']] as [$guard, $at]) {
        $guard->forceFill(['device_id' => (string) Str::uuid(), 'device_uid' => $phone['uid'], 'device_public_key' => $phone['public'], 'device_enrolled_at' => $at])->save();
    }

    $migration = require base_path('database/migrations/2026_10_21_100000_a_guards_phone_remembers_being_removed.php');
    $migration->backfill([$phone['uid']], 'mysql');

    $rows = DB::connection('mysql')->table('device_bindings')->where('device_uid', $phone['uid'])->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->guard_id)->toBe($this->guard->id)
        ->and($rows[0]->ended_reason)->toBe('replaced')
        ->and((string) $rows[0]->ended_at)->toBe('2026-10-04 14:31:40')
        ->and((int) $rows[1]->guard_id)->toBe($reviewer->id)
        ->and($rows[1]->ended_at)->toBeNull();

    // The phone now signs in as the account it was bound to last — the server's own record today.
    m32Resume($this, $phone, 'check')->assertOk()->assertJsonPath('guard.name', 'Review Officer');
});

it('signs back in a phone bound before bindings were kept, and records its binding then', function () {
    $phone = m32ResumePhone('rsm-early');
    m32ResumeEnrol($this, $this->guard, $phone);
    // As a phone bound between the migration and this code: on the guard's row, and nowhere else.
    DB::connection('mysql')->table('device_bindings')->where('guard_id', $this->guard->id)->delete();

    m32Resume($this, $phone, 'biometric')->assertOk()->assertJsonPath('state', 'resumed');

    expect(DB::connection('mysql')->table('device_bindings')->where('device_uid', 'rsm-early')->whereNull('ended_at')->count())->toBe(1);
});
